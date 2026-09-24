<?php
/**
 * Module 12/15 - Confirm a dispatch.
 *
 * For every line with a quantity > 0:
 *   - locks the batch row so two dispatches can't oversell the same stock
 *   - writes an 'out' stock_movement (the DB trigger reduces current_qty)
 *   - records the line in dispatch_items
 *   - raises sales_order_items.qty_delivered
 * Afterwards the order status becomes 'delivered' if every line is fully
 * delivered, or 'partially_delivered' if only some is.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$orderId      = (int) post('order_id', 0);
$dispatchDate = post('dispatch_date');
$vehicleReg   = post('vehicle_reg') ?: null;
$driverName   = post('driver_name') ?: null;
$receivedBy   = post('received_by') ?: null;
$lines        = $_POST['items'] ?? [];

if (!valid_date($dispatchDate)) {
    flash('error', 'Enter a valid dispatch date.');
    redirect("dispatch_new.php?order_id=$orderId");
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT status, destination_id FROM sales_orders WHERE order_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new RuntimeException('That order no longer exists.');
    }
    if (!in_array($order['status'], ['confirmed', 'partially_delivered'], true)) {
        throw new RuntimeException('Only confirmed orders can be dispatched.');
    }

    $anyDispatched = false;
    $dispatchId    = null;

    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? 0);
        $qty    = (float) ($line['qty'] ?? 0);

        if ($qty <= 0) {
            continue; // this line is being dispatched later
        }

        $stmt = $pdo->prepare(
            'SELECT soi.batch_id, soi.qty, soi.qty_delivered, soi.unit_id, pb.facility_id, pb.current_qty
               FROM sales_order_items soi
               JOIN produce_batches pb ON pb.batch_id = soi.batch_id
              WHERE soi.item_id = :item_id AND soi.order_id = :order_id
              FOR UPDATE'
        );
        $stmt->execute([':item_id' => $itemId, ':order_id' => $orderId]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new RuntimeException("Line item $itemId does not belong to this order.");
        }

        $remaining = (float) $item['qty'] - (float) $item['qty_delivered'];

        if ($qty > $remaining + 0.0001) {
            throw new RuntimeException('Dispatch quantity exceeds what is still owed on that line.');
        }
        if ($qty > (float) $item['current_qty'] + 0.0001) {
            throw new RuntimeException('Not enough stock left in that batch to dispatch that quantity.');
        }

        /* Create the dispatch header the first time we actually move something. */
        if ($dispatchId === null) {
            $dispatchNo = next_code('dispatches', 'dispatch_no', 'DSP');
            $stmt = $pdo->prepare(
                "INSERT INTO dispatches
                   (dispatch_no, order_id, facility_id, destination_id, dispatch_date,
                    vehicle_reg, driver_name, status, received_by, dispatched_by)
                 VALUES
                   (:no, :order_id, :facility_id, :destination_id, :date,
                    :vehicle, :driver, 'delivered', :received_by, :user_id)"
            );
            $stmt->execute([
                ':no'            => $dispatchNo,
                ':order_id'      => $orderId,
                ':facility_id'   => $item['facility_id'],
                ':destination_id' => $order['destination_id'],
                ':date'          => $dispatchDate,
                ':vehicle'       => $vehicleReg,
                ':driver'        => $driverName,
                ':received_by'   => $receivedBy,
                ':user_id'       => current_user()['user_id'],
            ]);
            $dispatchId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'INSERT INTO dispatch_items (dispatch_id, batch_id, qty, unit_id)
             VALUES (:dispatch_id, :batch_id, :qty, :unit_id)'
        )->execute([
            ':dispatch_id' => $dispatchId,
            ':batch_id'    => $item['batch_id'],
            ':qty'         => $qty,
            ':unit_id'     => $item['unit_id'],
        ]);

        /* The trigger on stock_movements reduces produce_batches.current_qty. */
        $pdo->prepare(
            "INSERT INTO stock_movements
               (batch_id, movement_type, qty, unit_id, from_facility_id,
                reference_type, reference_id, remarks, recorded_by)
             VALUES
               (:batch_id, 'out', :qty, :unit_id, :facility_id,
                'dispatch', :dispatch_id, :remarks, :user_id)"
        )->execute([
            ':batch_id'    => $item['batch_id'],
            ':qty'         => $qty,
            ':unit_id'     => $item['unit_id'],
            ':facility_id' => $item['facility_id'],
            ':dispatch_id' => $dispatchId,
            ':remarks'     => 'Dispatched on order ' . $orderId,
            ':user_id'     => current_user()['user_id'],
        ]);

        $pdo->prepare(
            'UPDATE sales_order_items SET qty_delivered = qty_delivered + :qty WHERE item_id = :id'
        )->execute([':qty' => $qty, ':id' => $itemId]);

        /* Mark the batch as fully moved out once nothing is left. */
        $pdo->prepare(
            "UPDATE produce_batches SET status = 'dispatched'
              WHERE batch_id = :id AND current_qty <= 0"
        )->execute([':id' => $item['batch_id']]);

        $anyDispatched = true;
    }

    if (!$anyDispatched) {
        throw new RuntimeException('Enter a quantity greater than zero on at least one line.');
    }

    /* Roll the order status up from its items. */
    $stmt = $pdo->prepare(
        "SELECT SUM(qty) AS total_qty, SUM(qty_delivered) AS delivered_qty
           FROM sales_order_items WHERE order_id = :id"
    );
    $stmt->execute([':id' => $orderId]);
    $totals = $stmt->fetch();

    $newStatus = ((float) $totals['delivered_qty'] >= (float) $totals['total_qty'] - 0.0001)
        ? 'delivered'
        : 'partially_delivered';

    $pdo->prepare('UPDATE sales_orders SET status = :status WHERE order_id = :id')
        ->execute([':status' => $newStatus, ':id' => $orderId]);

    $pdo->commit();
    flash('success', 'Dispatch confirmed. Stock levels updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Could not confirm the dispatch: ' . $e->getMessage());
    redirect("dispatch_new.php?order_id=$orderId");
}

redirect("order_view.php?id=$orderId");
