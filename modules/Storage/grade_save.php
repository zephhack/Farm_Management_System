<?php
/**
 * Module 15 - Grade a batch.
 *
 * Grading splits stock rather than editing the batch in place: a new
 * produce_batches row is created carrying the chosen grade, quantity moves
 * out of the source batch and into the new one through TWO stock_movements
 * (an 'out' on the source, an 'in' on the result), and a grading_records
 * row links the two. This keeps every batch's history honest — a grade-A
 * batch's ledger only ever shows grade-A movements, not a grade change
 * quietly rewritten into the same row.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('batches.php');
}

csrf_check();

$sourceBatchId = (int) post('batch_id', 0);
$grade         = post('grade');
$qty           = (float) post('qty', 0);
$remarks       = post('remarks') ?: null;

$validGrades = ['A', 'B', 'C', 'reject'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM produce_batches WHERE batch_id = :id FOR UPDATE');
    $stmt->execute([':id' => $sourceBatchId]);
    $source = $stmt->fetch();

    if (!$source) {
        throw new RuntimeException('That batch no longer exists.');
    }
    if ($source['grade'] !== 'ungraded') {
        throw new RuntimeException('This batch has already been graded.');
    }
    if (!in_array($grade, $validGrades, true)) {
        throw new RuntimeException('Choose a valid grade.');
    }
    if ($qty <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($qty > (float) $source['current_qty'] + 0.0001) {
        throw new RuntimeException('Only ' . fmt_qty($source['current_qty']) . ' is available to grade.');
    }

    /* Create the result batch, carrying the same cost and traceability. */
    $newCode = next_code('produce_batches', 'batch_code', 'BAT');

    $stmt = $pdo->prepare(
        "INSERT INTO produce_batches
           (batch_code, harvest_id, crop_id, variety_id, grade, unit_id,
            initial_qty, current_qty, unit_cost, facility_id,
            harvest_date, stored_on, status)
         VALUES
           (:code, :harvest_id, :crop_id, :variety_id, :grade, :unit_id,
            :qty, 0, :unit_cost, :facility_id,
            :harvest_date, CURDATE(), 'in_store')"
    );
    $stmt->execute([
        ':code' => $newCode, ':harvest_id' => $source['harvest_id'], ':crop_id' => $source['crop_id'],
        ':variety_id' => $source['variety_id'], ':grade' => $grade, ':unit_id' => $source['unit_id'],
        ':qty' => $qty, ':unit_cost' => $source['unit_cost'], ':facility_id' => $source['facility_id'],
        ':harvest_date' => $source['harvest_date'],
    ]);
    $resultBatchId = (int) $pdo->lastInsertId();

    /* Move the graded quantity: out of the source, into the result. */
    $pdo->prepare(
        "INSERT INTO stock_movements (batch_id, movement_type, qty, unit_id, reference_type, reference_id, remarks, recorded_by)
         VALUES (:batch_id, 'out', :qty, :unit_id, 'grading', :ref, :remarks, :user_id)"
    )->execute([
        ':batch_id' => $sourceBatchId, ':qty' => $qty, ':unit_id' => $source['unit_id'],
        ':ref' => $resultBatchId, ':remarks' => "Graded to $grade as $newCode", ':user_id' => current_user()['user_id'],
    ]);

    $pdo->prepare(
        "INSERT INTO stock_movements (batch_id, movement_type, qty, unit_id, to_facility_id, reference_type, reference_id, remarks, recorded_by)
         VALUES (:batch_id, 'in', :qty, :unit_id, :facility_id, 'grading', :ref, :remarks, :user_id)"
    )->execute([
        ':batch_id' => $resultBatchId, ':qty' => $qty, ':unit_id' => $source['unit_id'], ':facility_id' => $source['facility_id'],
        ':ref' => $sourceBatchId, ':remarks' => "Graded from {$source['batch_code']}", ':user_id' => current_user()['user_id'],
    ]);

    $pdo->prepare(
        "INSERT INTO grading_records (source_batch_id, result_batch_id, grade, qty, unit_id, graded_on, graded_by, remarks)
         VALUES (:source, :result, :grade, :qty, :unit_id, CURDATE(), :user_id, :remarks)"
    )->execute([
        ':source' => $sourceBatchId, ':result' => $resultBatchId, ':grade' => $grade,
        ':qty' => $qty, ':unit_id' => $source['unit_id'], ':user_id' => current_user()['user_id'], ':remarks' => $remarks,
    ]);

    /* current_qty reaching zero is enough for it to drop out of stock views;
       no status change is needed since "graded out" isn't a sale. */

    $pdo->commit();
    flash('success', "Graded $qty units as grade $grade into batch $newCode.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("batch_view.php?id=$sourceBatchId");
