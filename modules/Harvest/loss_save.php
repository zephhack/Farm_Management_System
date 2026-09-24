<?php
/**
 * Module 11 - Add a field loss to a draft harvest.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'agronomist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$harvestId   = (int) post('harvest_id', 0);
$lossType    = post('loss_type');
$qtyLost     = (float) post('qty_lost', 0);
$unitId      = (int) post('unit_id', 0);
$estValue    = (float) post('est_value', 0);
$description = post('description') ?: null;

$validTypes = ['pest', 'disease', 'weather', 'mechanical', 'handling', 'theft', 'overripe', 'other'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status, gross_qty, unit_id FROM harvests WHERE harvest_id = :id FOR UPDATE');
    $stmt->execute([':id' => $harvestId]);
    $harvest = $stmt->fetch();

    if (!$harvest) {
        throw new RuntimeException('That harvest record no longer exists.');
    }

    if ($harvest['status'] !== 'draft') {
        throw new RuntimeException('Losses can only be added while the harvest is a draft.');
    }

    if (!in_array($lossType, $validTypes, true)) {
        throw new RuntimeException('Choose a valid cause of loss.');
    }

    if ($qtyLost <= 0) {
        throw new RuntimeException('Quantity lost must be greater than zero.');
    }

    if ($unitId !== (int) $harvest['unit_id']) {
        throw new RuntimeException('Record the loss in the same unit as the harvest.');
    }

    /* Total losses cannot exceed what was picked. */
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_lost), 0) FROM harvest_losses WHERE harvest_id = :id');
    $stmt->execute([':id' => $harvestId]);
    $alreadyLost = (float) $stmt->fetchColumn();

    if ($alreadyLost + $qtyLost > (float) $harvest['gross_qty']) {
        $remaining = (float) $harvest['gross_qty'] - $alreadyLost;
        throw new RuntimeException('Total losses would exceed the gross quantity. At most '
            . fmt_qty($remaining) . ' can still be written off.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO harvest_losses
           (harvest_id, loss_type, qty_lost, unit_id, est_value, description, recorded_by)
         VALUES
           (:harvest_id, :loss_type, :qty_lost, :unit_id, :est_value, :description, :user_id)'
    );

    $stmt->execute([
        ':harvest_id'  => $harvestId,
        ':loss_type'   => $lossType,
        ':qty_lost'    => $qtyLost,
        ':unit_id'     => $unitId,
        ':est_value'   => $estValue,
        ':description' => $description,
        ':user_id'     => current_user()['user_id'],
    ]);

    recalc_harvest_net($harvestId);

    $pdo->commit();
    flash('success', 'Loss recorded. Net quantity updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("view.php?id=$harvestId");
