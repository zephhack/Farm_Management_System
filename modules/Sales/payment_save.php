<?php
/**
 * Module 12 - Record a payment received.
 * The invoice's amount_paid, balance and status are updated by the
 * trg_payment_after_insert trigger, not by this script — so this file
 * never has to duplicate that arithmetic.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('invoices.php');
}

csrf_check();

$invoiceId   = (int) post('invoice_id', 0);
$paymentDate = post('payment_date');
$amount      = (float) post('amount', 0);
$method      = post('method', 'bank_transfer');
$reference   = post('reference') ?: null;

$validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT customer_id, balance, status FROM invoices WHERE invoice_id = :id FOR UPDATE'
    );
    $stmt->execute([':id' => $invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new RuntimeException('That invoice no longer exists.');
    }
    if (!in_array($invoice['status'], ['unpaid', 'partially_paid', 'overdue'], true)) {
        throw new RuntimeException('This invoice is already settled.');
    }
    if (!valid_date($paymentDate)) {
        throw new RuntimeException('Enter a valid payment date.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($amount > (float) $invoice['balance'] + 0.01) {
        throw new RuntimeException('Payment exceeds the outstanding balance of ' . fmt_money($invoice['balance']) . '.');
    }
    if (!in_array($method, $validMethods, true)) {
        throw new RuntimeException('Choose a valid payment method.');
    }

    $receiptNo = next_code('payments_received', 'receipt_no', 'RCT');

    $pdo->prepare(
        "INSERT INTO payments_received
           (receipt_no, invoice_id, customer_id, payment_date, amount, method, reference, received_by)
         VALUES
           (:receipt_no, :invoice_id, :customer_id, :payment_date, :amount, :method, :reference, :user_id)"
    )->execute([
        ':receipt_no'   => $receiptNo,
        ':invoice_id'   => $invoiceId,
        ':customer_id'  => $invoice['customer_id'],
        ':payment_date' => $paymentDate,
        ':amount'       => $amount,
        ':method'       => $method,
        ':reference'    => $reference,
        ':user_id'      => current_user()['user_id'],
    ]);

    $pdo->commit();
    flash('success', "Payment $receiptNo of " . fmt_money($amount) . ' recorded.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("invoice_view.php?id=$invoiceId");
