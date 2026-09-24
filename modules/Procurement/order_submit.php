<?php
/**
 * Module 14 - Submit a draft purchase order for approval.
 * This only changes status; the approval decision itself is recorded
 * separately in procurement_approvals by approve.php, so there's an
 * audit trail of who approved what and when.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId = (int) post('po_id', 0);
$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE po_id = :id FOR UPDATE');
    $stmt->execute([':id' => $poId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('Only draft orders can be submitted for approval.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE po_id = :id');
    $stmt->execute([':id' => $poId]);
    if ((int) $stmt->fetchColumn() === 0) {
        throw new RuntimeException('Add at least one item before submitting.');
    }

    $pdo->prepare("UPDATE purchase_orders SET status = 'pending_approval' WHERE po_id = :id")
        ->execute([':id' => $poId]);

    $pdo->commit();
    flash('success', 'Submitted for approval.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$poId");
