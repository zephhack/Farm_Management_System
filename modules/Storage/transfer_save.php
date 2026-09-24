<?php
/**
 * Module 15 - Transfer a batch to another store.
 * A single 'transfer' stock_movement carries both the from and to
 * facility; the trigger updates produce_batches.facility_id directly
 * (quantity doesn't change on a transfer, only location).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('batches.php');
}

csrf_check();

$batchId      = (int) post('batch_id', 0);
$toFacilityId = (int) post('to_facility_id', 0);
$remarks      = post('remarks') ?: null;

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT facility_id, current_qty, unit_id FROM produce_batches WHERE batch_id = :id FOR UPDATE');
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new RuntimeException('That batch no longer exists.');
    }
    if ((int) $batch['facility_id'] === $toFacilityId) {
        throw new RuntimeException('Choose a different store to transfer into.');
    }
    if ((float) $batch['current_qty'] <= 0) {
        throw new RuntimeException('There is no stock left in this batch to transfer.');
    }

    $stmt = $pdo->prepare('SELECT facility_name FROM storage_facilities WHERE facility_id = :id AND is_active = 1');
    $stmt->execute([':id' => $toFacilityId]);
    $destination = $stmt->fetch();

    if (!$destination) {
        throw new RuntimeException('That destination store is not available.');
    }

    $pdo->prepare(
        "INSERT INTO stock_movements
           (batch_id, movement_type, qty, unit_id, from_facility_id, to_facility_id,
            reference_type, remarks, recorded_by)
         VALUES
           (:batch_id, 'transfer', :qty, :unit_id, :from_id, :to_id, 'manual', :remarks, :user_id)"
    )->execute([
        ':batch_id' => $batchId, ':qty' => $batch['current_qty'], ':unit_id' => $batch['unit_id'],
        ':from_id' => $batch['facility_id'], ':to_id' => $toFacilityId,
        ':remarks' => $remarks, ':user_id' => current_user()['user_id'],
    ]);

    $pdo->commit();
    flash('success', "Batch transferred to {$destination['facility_name']}.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("batch_view.php?id=$batchId");
