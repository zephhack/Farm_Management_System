<?php
/**
 * Module 13 - Record a loan repayment.
 *
 * loans.amount_repaid and loans.status are updated by the database trigger
 * (trg_repayment_after_update) when this script updates loan_repayments —
 * this file never touches the loans table directly.
 *
 * Only the interest portion of the payment is posted to
 * financial_transactions as an expense. Repaying principal reduces a
 * liability, it isn't a cost of running the farm, so booking the whole
 * instalment as an expense would overstate spend and understate the
 * loan's true outstanding balance.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('loans.php');
}

csrf_check();

$loanId       = (int) post('loan_id', 0);
$repaymentId  = (int) post('repayment_id', 0);
$paidDate     = post('paid_date');
$amountPaid   = (float) post('amount_paid', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT * FROM loan_repayments WHERE repayment_id = :id AND loan_id = :loan_id FOR UPDATE'
    );
    $stmt->execute([':id' => $repaymentId, ':loan_id' => $loanId]);
    $repayment = $stmt->fetch();

    if (!$repayment) {
        throw new RuntimeException('That instalment was not found.');
    }
    if ($repayment['status'] === 'paid') {
        throw new RuntimeException('This instalment is already fully paid.');
    }
    if (!valid_date($paidDate)) {
        throw new RuntimeException('Enter a valid payment date.');
    }
    if ($amountPaid <= 0) {
        throw new RuntimeException('Amount paid must be greater than zero.');
    }

    $outstanding = (float) $repayment['amount_due'] - (float) $repayment['amount_paid'];
    if ($amountPaid > $outstanding + 0.01) {
        throw new RuntimeException('Amount exceeds what is left on this instalment: ' . fmt_money($outstanding) . '.');
    }

    $newPaidTotal = (float) $repayment['amount_paid'] + $amountPaid;
    $newStatus    = ($newPaidTotal >= (float) $repayment['amount_due'] - 0.01) ? 'paid' : 'partial';

    $pdo->prepare(
        'UPDATE loan_repayments SET amount_paid = :amount_paid, paid_date = :paid_date, status = :status
          WHERE repayment_id = :id'
    )->execute([
        ':amount_paid' => $newPaidTotal, ':paid_date' => $paidDate, ':status' => $newStatus, ':id' => $repaymentId,
    ]);

    /* Post only the interest share of THIS payment, proportional to how
       much of the instalment it covers. */
    $interestDue = (float) $repayment['interest_portion'];
    $shareOfInstalment = $amountPaid / max((float) $repayment['amount_due'], 0.01);
    $interestPortionPaid = round($interestDue * $shareOfInstalment, 2);

    if ($interestPortionPaid > 0) {
        $stmt = $pdo->prepare(
            "SELECT category_id FROM finance_categories WHERE category_name = 'Loan Interest' AND category_type = 'expense' LIMIT 1"
        );
        $stmt->execute();
        $categoryId = $stmt->fetchColumn();

        if ($categoryId) {
            $stmt = $pdo->prepare('SELECT farm_id, loan_ref FROM loans WHERE loan_id = :id');
            $stmt->execute([':id' => $loanId]);
            $loan = $stmt->fetch();

            $pdo->prepare(
                "INSERT INTO financial_transactions
                   (txn_no, farm_id, txn_type, category_id, txn_date, amount, description,
                    source_type, source_id, recorded_by)
                 VALUES
                   (:txn_no, :farm_id, 'expense', :category_id, :txn_date, :amount, :description,
                    'loan_repayment', :repayment_id, :user_id)"
            )->execute([
                ':txn_no' => next_code('financial_transactions', 'txn_no', 'TXN'), ':farm_id' => $loan['farm_id'],
                ':category_id' => $categoryId, ':txn_date' => $paidDate, ':amount' => $interestPortionPaid,
                ':description' => "Interest on {$loan['loan_ref']}", ':repayment_id' => $repaymentId,
                ':user_id' => current_user()['user_id'],
            ]);
        }
    }

    $pdo->commit();
    flash('success', fmt_money($amountPaid) . ' recorded against this instalment.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("loan_view.php?id=$loanId");
