<?php
/**
 * Module 15 -> 13 bridge.
 *
 * Recording spoilage does three things in one transaction:
 *   1. writes a 'spoilage' stock_movement (the trigger reduces current_qty,
 *      same as an ordinary 'out' movement)
 *   2. inserts the spoilage_records row for cause/disposal tracking
 *   3. posts the lost value to financial_transactions as a write-off,
 *      attributed back to the batch's crop cycle if it was harvested here
 *      (bought-in produce has no cycle to attribute to, so cycle_id is
 *      left NULL in that case rather than guessed at)
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('batches.php');
}

csrf_check();

$batchId        = (int) post('batch_id', 0);
$detectedOn     = post('detected_on');
$cause          = post('cause');
$qtySpoiled     = (float) post('qty_spoiled', 0);
$disposalMethod = post('disposal_method') ?: null;

$validCauses = ['rot', 'mould', 'pest', 'rodent', 'temperature', 'over_storage', 'handling', 'other'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT pb.*, h.cycle_id, f.farm_id
           FROM produce_batches pb
      LEFT JOIN harvests h ON h.harvest_id = pb.harvest_id
      LEFT JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
      LEFT JOIN fields f ON f.field_id = cc.field_id
          WHERE pb.batch_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new RuntimeException('That batch no longer exists.');
    }
    if (!valid_date($detectedOn)) {
        throw new RuntimeException('Enter a valid date.');
    }
    if (!in_array($cause, $validCauses, true)) {
        throw new RuntimeException('Choose a valid cause.');
    }
    if ($qtySpoiled <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($qtySpoiled > (float) $batch['current_qty'] + 0.0001) {
        throw new RuntimeException('Only ' . fmt_qty($batch['current_qty']) . ' is left in that batch.');
    }

    $valueLost = round($qtySpoiled * (float) $batch['unit_cost'], 2);

    /* 1. Stock ledger - the trigger reduces current_qty. */
    $pdo->prepare(
        "INSERT INTO stock_movements
           (batch_id, movement_type, qty, unit_id, from_facility_id, reference_type, remarks, recorded_by)
         VALUES
           (:batch_id, 'spoilage', :qty, :unit_id, :facility_id, 'spoilage', :remarks, :user_id)"
    )->execute([
        ':batch_id' => $batchId, ':qty' => $qtySpoiled, ':unit_id' => $batch['unit_id'],
        ':facility_id' => $batch['facility_id'], ':remarks' => ucfirst($cause) . ' spoilage',
        ':user_id' => current_user()['user_id'],
    ]);

    /* 2. Spoilage record. */
    $stmt = $pdo->prepare(
        "INSERT INTO spoilage_records
           (batch_id, facility_id, qty_spoiled, unit_id, cause, value_lost,
            detected_on, disposal_method, recorded_by)
         VALUES
           (:batch_id, :facility_id, :qty, :unit_id, :cause, :value,
            :detected_on, :disposal, :user_id)"
    );
    $stmt->execute([
        ':batch_id' => $batchId, ':facility_id' => $batch['facility_id'], ':qty' => $qtySpoiled,
        ':unit_id' => $batch['unit_id'], ':cause' => $cause, ':value' => $valueLost,
        ':detected_on' => $detectedOn, ':disposal' => $disposalMethod, ':user_id' => current_user()['user_id'],
    ]);
    $spoilageId = (int) $pdo->lastInsertId();

    /* 3. Finance write-off, only if there's a value to book. */
    if ($valueLost > 0) {
        $stmt = $pdo->prepare(
            "SELECT category_id FROM finance_categories
              WHERE category_name = 'Spoilage Write-off' AND category_type = 'expense' LIMIT 1"
        );
        $stmt->execute();
        $categoryId = $stmt->fetchColumn();

        if ($categoryId) {
            $farmId = $batch['farm_id'] ?: $pdo->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();

            $pdo->prepare(
                "INSERT INTO financial_transactions
                   (txn_no, farm_id, txn_type, category_id, txn_date, amount,
                    description, source_type, source_id, cycle_id, batch_id, recorded_by)
                 VALUES
                   (:txn_no, :farm_id, 'expense', :category_id, :txn_date, :amount,
                    :description, 'spoilage', :spoilage_id, :cycle_id, :batch_id, :user_id)"
            )->execute([
                ':txn_no' => next_code('financial_transactions', 'txn_no', 'TXN'),
                ':farm_id' => $farmId, ':category_id' => $categoryId, ':txn_date' => $detectedOn,
                ':amount' => $valueLost, ':description' => ucfirst($cause) . " spoilage in {$batch['batch_code']}",
                ':spoilage_id' => $spoilageId, ':cycle_id' => $batch['cycle_id'], ':batch_id' => $batchId,
                ':user_id' => current_user()['user_id'],
            ]);
        }
    }

    /* If nothing is left, the batch is no longer sellable stock. */
    $pdo->prepare(
        "UPDATE produce_batches SET status = 'spoiled' WHERE batch_id = :id AND current_qty <= 0"
    )->execute([':id' => $batchId]);

    $pdo->commit();
    flash('success', 'Spoilage recorded and ' . fmt_money($valueLost) . ' written off.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("batch_view.php?id=$batchId");
