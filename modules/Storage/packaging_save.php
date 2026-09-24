<?php
/**
 * Module 15 - Log packaging. The produce stays in the batch; this just
 * records that some of it was packed into a specific package type, and
 * captures the material cost for the overall cost picture.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('batches.php');
}

csrf_check();

$batchId       = (int) post('batch_id', 0);
$packageType   = post('package_type');
$packagesMade  = (int) post('packages_made', 0);
$qtyPacked     = (float) post('qty_packed', 0);
$materialCost  = (float) post('material_cost', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT current_qty, unit_id FROM produce_batches WHERE batch_id = :id FOR UPDATE');
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new RuntimeException('That batch no longer exists.');
    }
    if ($packageType === '' || $packageType === null) {
        throw new RuntimeException('Describe the package type.');
    }
    if ($packagesMade <= 0) {
        throw new RuntimeException('Number of packages must be greater than zero.');
    }
    if ($qtyPacked <= 0) {
        throw new RuntimeException('Quantity packed must be greater than zero.');
    }
    if ($qtyPacked > (float) $batch['current_qty'] + 0.0001) {
        throw new RuntimeException('Only ' . fmt_qty($batch['current_qty']) . ' is available to pack.');
    }
    if ($materialCost < 0) {
        throw new RuntimeException('Material cost cannot be negative.');
    }

    $pdo->prepare(
        "INSERT INTO packaging_records
           (batch_id, package_type, packages_made, qty_packed, unit_id, packed_on, material_cost, packed_by)
         VALUES
           (:batch_id, :type, :packages, :qty, :unit_id, CURDATE(), :cost, :user_id)"
    )->execute([
        ':batch_id' => $batchId, ':type' => $packageType, ':packages' => $packagesMade,
        ':qty' => $qtyPacked, ':unit_id' => $batch['unit_id'], ':cost' => $materialCost,
        ':user_id' => current_user()['user_id'],
    ]);

    $pdo->commit();
    flash('success', 'Packaging logged.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("batch_view.php?id=$batchId");
