<?php
/**
 * Module 13 - Insert a manually entered transaction.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'accountant', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('transactions.php');
}

csrf_check();

$txnType       = post('txn_type', 'expense');
$txnDate       = post('txn_date');
$categoryId    = (int) post('category_id', 0);
$amount        = (float) post('amount', 0);
$cycleId       = post('cycle_id') ? (int) post('cycle_id') : null;
$fieldId       = post('field_id') ? (int) post('field_id') : null;
$paymentMethod = post('payment_method', 'cash');
$description   = post('description');

$errors = [];

if (!in_array($txnType, ['income', 'expense'], true)) {
    $errors[] = 'Choose a valid transaction type.';
}
if (!valid_date($txnDate)) {
    $errors[] = 'Enter a valid date.';
} elseif ($txnDate > date('Y-m-d')) {
    $errors[] = 'The date cannot be in the future.';
}
if ($amount <= 0) {
    $errors[] = 'Amount must be greater than zero.';
}
if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'credit'], true)) {
    $errors[] = 'Choose a valid payment method.';
}
if ($description === '' || $description === null) {
    $errors[] = 'Enter a description.';
}

/* The category must exist and must match the chosen income/expense type,
   so an expense can never be posted under an income category by mistake. */
if ($categoryId > 0) {
    $stmt = db()->prepare('SELECT category_type FROM finance_categories WHERE category_id = :id');
    $stmt->execute([':id' => $categoryId]);
    $categoryType = $stmt->fetchColumn();

    if ($categoryType === false) {
        $errors[] = 'That category no longer exists.';
    } elseif ($categoryType !== $txnType) {
        $errors[] = "That category is for $categoryType, not $txnType.";
    }
} else {
    $errors[] = 'Choose a category.';
}

if ($errors) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect('transaction_form.php');
}

$farmId = db()->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();

$stmt = db()->prepare(
    "INSERT INTO financial_transactions
       (txn_no, farm_id, txn_type, category_id, txn_date, amount, description,
        payment_method, source_type, cycle_id, field_id, recorded_by)
     VALUES
       (:txn_no, :farm_id, :txn_type, :category_id, :txn_date, :amount, :description,
        :payment_method, 'manual', :cycle_id, :field_id, :user_id)"
);

$stmt->execute([
    ':txn_no'         => next_code('financial_transactions', 'txn_no', 'TXN'),
    ':farm_id'        => $farmId,
    ':txn_type'       => $txnType,
    ':category_id'    => $categoryId,
    ':txn_date'       => $txnDate,
    ':amount'         => $amount,
    ':description'    => $description,
    ':payment_method' => $paymentMethod,
    ':cycle_id'       => $cycleId,
    ':field_id'       => $fieldId,
    ':user_id'        => current_user()['user_id'],
]);

flash('success', ucfirst($txnType) . ' of ' . fmt_money($amount) . ' recorded.');
redirect('transactions.php');
