<?php
/**
 * Module 13 - Add a category line to a budget.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('budgets.php');
}

csrf_check();

$budgetId      = (int) post('budget_id', 0);
$categoryId    = (int) post('category_id', 0);
$cycleId       = post('cycle_id') ? (int) post('cycle_id') : null;
$plannedAmount = (float) post('planned_amount', 0);
$notes         = post('notes') ?: null;

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM budgets WHERE budget_id = :id FOR UPDATE');
    $stmt->execute([':id' => $budgetId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That budget no longer exists.');
    }
    if ($status === 'closed') {
        throw new RuntimeException('This budget is closed and cannot be changed.');
    }
    if ($categoryId <= 0) {
        throw new RuntimeException('Choose a category.');
    }
    if ($plannedAmount <= 0) {
        throw new RuntimeException('Planned amount must be greater than zero.');
    }

    $pdo->prepare(
        "INSERT INTO budget_lines (budget_id, category_id, cycle_id, planned_amount, notes)
         VALUES (:budget_id, :category_id, :cycle_id, :amount, :notes)"
    )->execute([
        ':budget_id' => $budgetId, ':category_id' => $categoryId, ':cycle_id' => $cycleId,
        ':amount' => $plannedAmount, ':notes' => $notes,
    ]);

    $pdo->prepare(
        "UPDATE budgets SET total_planned = (
           SELECT COALESCE(SUM(planned_amount), 0) FROM budget_lines WHERE budget_id = :id
         ), status = IF(status = 'draft', 'active', status)
         WHERE budget_id = :id"
    )->execute([':id' => $budgetId]);

    $pdo->commit();
    flash('success', 'Line added.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("budget_view.php?id=$budgetId");
