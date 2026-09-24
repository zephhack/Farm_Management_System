<?php
/**
 * Module 11 - Delete a draft harvest.
 * Confirmed harvests are never deleted: they have produce and money attached.
 * Cancel them instead so the audit trail survives.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$harvestId = (int) post('harvest_id', 0);
$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status, harvest_code FROM harvests WHERE harvest_id = :id FOR UPDATE');
    $stmt->execute([':id' => $harvestId]);
    $harvest = $stmt->fetch();

    if (!$harvest) {
        throw new RuntimeException('That harvest record no longer exists.');
    }

    if ($harvest['status'] !== 'draft') {
        throw new RuntimeException('Only drafts can be deleted. Cancel the harvest instead so the record is kept.');
    }

    /* Losses and labour cascade via the foreign keys. */
    $pdo->prepare('DELETE FROM harvests WHERE harvest_id = :id')->execute([':id' => $harvestId]);

    $pdo->commit();
    flash('success', "Draft {$harvest['harvest_code']} deleted.");
    redirect('index.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
    redirect("view.php?id=$harvestId");
}
