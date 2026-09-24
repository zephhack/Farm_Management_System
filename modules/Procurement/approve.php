<?php
/**
 * Module 14 - Approve or reject a purchase order.
 * Records the decision in procurement_approvals (audit trail: who, when,
 * what was decided) as well as updating the order's own status.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId     = (int) post('po_id', 0);
$decision = post('decision');
$comments = post('comments') ?: null;

if (!in_array($decision, ['approved', 'rejected'], true)) {
    flash('error', 'Choose a valid decision.');
    redirect("order_view.php?id=$poId");
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE po_id = :id FOR UPDATE');
    $stmt->execute([':id' => $poId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    if ($status !== 'pending_approval') {
        throw new RuntimeException('This order is not awaiting approval.');
    }

    $pdo->prepare(
        "INSERT INTO procurement_approvals (po_id, approver_id, approval_level, decision, decided_at, comments)
         VALUES (:po_id, :approver_id, 1, :decision, NOW(), :comments)
         ON DUPLICATE KEY UPDATE decision = VALUES(decision), decided_at = VALUES(decided_at), comments = VALUES(comments)"
    )->execute([
        ':po_id' => $poId, ':approver_id' => current_user()['user_id'], ':decision' => $decision, ':comments' => $comments,
    ]);

    $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE po_id = :id')
        ->execute([':status' => $decision, ':id' => $poId]);

    $pdo->commit();
    flash('success', 'Purchase order ' . $decision . '.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$poId");
