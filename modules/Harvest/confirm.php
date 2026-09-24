<?php
/**
 * Module 11 -> 15 bridge.
 *
 * Confirming a harvest does four things in ONE transaction:
 *   1. creates a produce batch (the traceable lot other modules sell and value)
 *   2. writes an 'in' stock movement, which the DB trigger applies to the batch quantity
 *   3. posts the harvest labour cost to the finance ledger
 *   4. locks the harvest record so it can no longer be edited
 *
 * If any step fails, everything rolls back and no half-created batch is left behind.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'agronomist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$harvestId  = (int) post('harvest_id', 0);
$facilityId = (int) post('facility_id', 0);

if ($harvestId <= 0 || $facilityId <= 0) {
    flash('error', 'Choose the store this produce is going into.');
    redirect("view.php?id=$harvestId");
}

$pdo = db();

try {
    $pdo->beginTransaction();

    /* Lock the harvest so two people cannot confirm it at the same time. */
    $stmt = $pdo->prepare(
        "SELECT h.*, cc.crop_id, cc.variety_id
           FROM harvests h
           JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
          WHERE h.harvest_id = :id
          FOR UPDATE"
    );
    $stmt->execute([':id' => $harvestId]);
    $harvest = $stmt->fetch();

    if (!$harvest) {
        throw new RuntimeException('That harvest record no longer exists.');
    }

    if ($harvest['status'] !== 'draft') {
        throw new RuntimeException('This harvest has already been ' . $harvest['status'] . '.');
    }

    if ((float) $harvest['net_qty'] <= 0) {
        throw new RuntimeException('Net quantity is zero. Check the gross figure and the recorded losses.');
    }

    /* The store must exist and be open. */
    $stmt = $pdo->prepare(
        'SELECT facility_id, facility_name FROM storage_facilities
          WHERE facility_id = :id AND is_active = 1'
    );
    $stmt->execute([':id' => $facilityId]);
    $facility = $stmt->fetch();

    if (!$facility) {
        throw new RuntimeException('That storage facility is not available.');
    }

    /* ---------- Unit cost ------------------------------------------------
       Direct production costs already booked against this crop cycle, plus
       this harvest's own labour, divided by the net quantity. Module 13 uses
       it to value stock and work out gross margin on every sale.           */

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ft.amount), 0)
           FROM financial_transactions ft
           JOIN finance_categories fc ON fc.category_id = ft.category_id
          WHERE ft.cycle_id = :cycle_id
            AND ft.txn_type = 'expense'
            AND fc.cost_class = 'direct'"
    );
    $stmt->execute([':cycle_id' => $harvest['cycle_id']]);
    $cycleCosts = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total_pay), 0) FROM harvest_labour WHERE harvest_id = :id'
    );
    $stmt->execute([':id' => $harvestId]);
    $labourCost = (float) $stmt->fetchColumn();

    $unitCost = ($cycleCosts + $labourCost) / (float) $harvest['net_qty'];

    /* ---------- 1. Create the batch --------------------------------------- */

    $batchCode = next_code('produce_batches', 'batch_code', 'BAT');

    $stmt = $pdo->prepare(
        "INSERT INTO produce_batches
           (batch_code, harvest_id, crop_id, variety_id, grade, unit_id,
            initial_qty, current_qty, unit_cost, facility_id,
            harvest_date, stored_on, status)
         VALUES
           (:code, :harvest_id, :crop_id, :variety_id, 'ungraded', :unit_id,
            :initial_qty, 0, :unit_cost, :facility_id,
            :harvest_date, CURDATE(), 'in_store')"
    );

    $stmt->execute([
        ':code'         => $batchCode,
        ':harvest_id'   => $harvestId,
        ':crop_id'      => $harvest['crop_id'],
        ':variety_id'   => $harvest['variety_id'],
        ':unit_id'      => $harvest['unit_id'],
        ':initial_qty'  => $harvest['net_qty'],
        ':unit_cost'    => round($unitCost, 4),
        ':facility_id'  => $facilityId,
        ':harvest_date' => $harvest['harvest_date'],
    ]);

    $batchId = (int) $pdo->lastInsertId();

    /* ---------- 2. Move the produce in ------------------------------------
       current_qty is deliberately left at 0 above: the trigger on
       stock_movements adds the quantity, so the ledger stays the single
       source of truth for how much is in store.                            */

    $stmt = $pdo->prepare(
        "INSERT INTO stock_movements
           (batch_id, movement_type, qty, unit_id, to_facility_id,
            reference_type, reference_id, remarks, recorded_by)
         VALUES
           (:batch_id, 'in', :qty, :unit_id, :facility_id,
            'harvest', :harvest_id, :remarks, :user_id)"
    );

    $stmt->execute([
        ':batch_id'   => $batchId,
        ':qty'        => $harvest['net_qty'],
        ':unit_id'    => $harvest['unit_id'],
        ':facility_id' => $facilityId,
        ':harvest_id' => $harvestId,
        ':remarks'    => 'Received from harvest ' . $harvest['harvest_code'],
        ':user_id'    => current_user()['user_id'],
    ]);

    /* ---------- 3. Post the labour cost ------------------------------------ */

    if ($labourCost > 0) {
        $stmt = $pdo->prepare(
            "SELECT category_id FROM finance_categories
              WHERE category_name = 'Labour' AND category_type = 'expense' LIMIT 1"
        );
        $stmt->execute();
        $categoryId = $stmt->fetchColumn();

        if ($categoryId) {
            $stmt = $pdo->prepare(
                'SELECT f.farm_id FROM crop_cycles cc
                   JOIN fields f ON f.field_id = cc.field_id
                  WHERE cc.cycle_id = :cycle_id'
            );
            $stmt->execute([':cycle_id' => $harvest['cycle_id']]);
            $farmId = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "INSERT INTO financial_transactions
                   (txn_no, farm_id, txn_type, category_id, txn_date, amount,
                    description, source_type, source_id, cycle_id, batch_id, recorded_by)
                 VALUES
                   (:txn_no, :farm_id, 'expense', :category_id, :txn_date, :amount,
                    :description, 'harvest_labour', :source_id, :cycle_id, :batch_id, :user_id)"
            );

            $stmt->execute([
                ':txn_no'      => next_code('financial_transactions', 'txn_no', 'TXN'),
                ':farm_id'     => $farmId,
                ':category_id' => $categoryId,
                ':txn_date'    => $harvest['harvest_date'],
                ':amount'      => $labourCost,
                ':description' => 'Harvest labour for ' . $harvest['harvest_code'],
                ':source_id'   => $harvestId,
                ':cycle_id'    => $harvest['cycle_id'],
                ':batch_id'    => $batchId,
                ':user_id'     => current_user()['user_id'],
            ]);
        }
    }

    /* ---------- 4. Lock the record and tidy up ------------------------------ */

    $pdo->prepare("UPDATE harvests SET status = 'confirmed' WHERE harvest_id = :id")
        ->execute([':id' => $harvestId]);

    if ($harvest['plan_id']) {
        $pdo->prepare("UPDATE harvest_plans SET status = 'completed' WHERE plan_id = :id")
            ->execute([':id' => $harvest['plan_id']]);
    }

    $pdo->commit();

    flash('success', "Harvest confirmed. {$harvest['net_qty']} units are now in {$facility['facility_name']} as batch $batchCode.");
    redirect("view.php?id=$harvestId");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Could not confirm the harvest: ' . $e->getMessage());
    redirect("view.php?id=$harvestId");
}
