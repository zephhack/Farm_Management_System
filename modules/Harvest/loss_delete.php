<?php
/**
 * Module 11 - Remove a loss from a draft harvest.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'agronomist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$lossId    = (int) post('loss_id', 0);
$harvestId = (int) post('harvest_id', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    /* The loss must belong to this harvest, and the harvest must still be a draft. */
    $stmt = $pdo->prepare(
        'SELECT h.status
           FROM harvest_losses l
           JOIN harvests h ON h.harvest_id = l.harvest_id
          WHERE l.loss_id = :loss_id AND l.harvest_id = :harvest_id'
    );
    $stmt->execute([':loss_id' => $lossId, ':harvest_id' => $harvestId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That loss record was not found.');
    }

    if ($status !== 'draft') {
        throw new RuntimeException('Losses cannot be removed from a confirmed harvest.');
    }

    $pdo->prepare('DELETE FROM harvest_losses WHERE loss_id = :id')->execute([':id' => $lossId]);

    recalc_harvest_net($harvestId);

    $pdo->commit();
    flash('success', 'Loss removed. Net quantity updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("view.php?id=$harvestId");
