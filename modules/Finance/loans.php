<?php
/**
 * Module 13 - Loan tracking.
 * Registering a loan posts the disbursed principal as income (cash coming
 * in), so it shows up in the ledger the same way a sale would, rather than
 * silently appearing as farm cash with no traceable source.
 */
$pageTitle = 'Loans';
$activeNav = 'loans';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'accountant']);
    csrf_check();

    $lenderName   = post('lender_name');
    $loanType     = post('loan_type', 'working_capital');
    $principal    = (float) post('principal', 0);
    $interestRate = (float) post('interest_rate', 0);
    $disbursedOn  = post('disbursed_on');
    $termMonths   = (int) post('term_months', 12);

    $validTypes = ['input_loan', 'equipment', 'working_capital', 'mortgage', 'other'];

    $errors = [];
    if ($lenderName === '' || $lenderName === null) { $errors[] = 'Enter the lender name.'; }
    if (!in_array($loanType, $validTypes, true))    { $errors[] = 'Choose a valid loan type.'; }
    if ($principal <= 0)                            { $errors[] = 'Principal must be greater than zero.'; }
    if ($interestRate < 0)                           { $errors[] = 'Interest rate cannot be negative.'; }
    if (!valid_date($disbursedOn))                   { $errors[] = 'Enter a valid disbursement date.'; }
    if ($termMonths <= 0)                            { $errors[] = 'Term must be at least one month.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('loans.php');
    }

    /* Simple flat-rate repayable total: principal + (principal * rate% * years). */
    $years          = $termMonths / 12;
    $totalRepayable = round($principal + ($principal * ($interestRate / 100) * $years), 2);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $loanRef = next_code('loans', 'loan_ref', 'LN');
        $farmId  = $pdo->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO loans
               (loan_ref, farm_id, lender_name, loan_type, principal, interest_rate,
                disbursed_on, term_months, total_repayable, status)
             VALUES
               (:ref, :farm_id, :lender, :type, :principal, :rate, :disbursed, :term, :total, 'active')"
        );
        $stmt->execute([
            ':ref' => $loanRef, ':farm_id' => $farmId, ':lender' => $lenderName, ':type' => $loanType,
            ':principal' => $principal, ':rate' => $interestRate, ':disbursed' => $disbursedOn,
            ':term' => $termMonths, ':total' => $totalRepayable,
        ]);
        $loanId = (int) $pdo->lastInsertId();

        /* Generate a level repayment schedule, with the principal/interest
           split precomputed so a repayment can post only its interest
           portion as an expense — repaying principal isn't a cost, it's a
           reduction of what's owed. */
        $installment      = round($totalRepayable / $termMonths, 2);
        $totalInterest    = $totalRepayable - $principal;
        $interestPortion  = round($totalInterest / $termMonths, 2);
        $principalPortion = round($installment - $interestPortion, 2);

        for ($i = 1; $i <= $termMonths; $i++) {
            $dueDate = date('Y-m-d', strtotime($disbursedOn . " + $i months"));
            $pdo->prepare(
                "INSERT INTO loan_repayments
                   (loan_id, due_date, amount_due, principal_portion, interest_portion, status)
                 VALUES (:loan_id, :due_date, :amount, :principal_portion, :interest_portion, 'pending')"
            )->execute([
                ':loan_id' => $loanId, ':due_date' => $dueDate, ':amount' => $installment,
                ':principal_portion' => $principalPortion, ':interest_portion' => $interestPortion,
            ]);
        }

        /* The cash received is real income to the farm, from a lending category. */
        $stmt = $pdo->prepare(
            "SELECT category_id FROM finance_categories WHERE category_name = 'Other Income' AND category_type = 'income' LIMIT 1"
        );
        $stmt->execute();
        $categoryId = $stmt->fetchColumn();

        if ($categoryId) {
            $pdo->prepare(
                "INSERT INTO financial_transactions
                   (txn_no, farm_id, txn_type, category_id, txn_date, amount, description,
                    source_type, source_id, recorded_by)
                 VALUES
                   (:txn_no, :farm_id, 'income', :category_id, :date, :amount, :description,
                    'loan', :loan_id, :user_id)"
            )->execute([
                ':txn_no' => next_code('financial_transactions', 'txn_no', 'TXN'), ':farm_id' => $farmId,
                ':category_id' => $categoryId, ':date' => $disbursedOn, ':amount' => $principal,
                ':description' => "Loan disbursed: $loanRef from $lenderName",
                ':loan_id' => $loanId, ':user_id' => current_user()['user_id'],
            ]);
        }

        $pdo->commit();
        flash('success', "Loan $loanRef registered with a $termMonths-month repayment schedule.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Could not save the loan: ' . $e->getMessage());
    }

    redirect('loans.php');
}

require_once __DIR__ . '/../../includes/header.php';

$loans = db()->query(
    "SELECT l.*,
            (SELECT COUNT(*) FROM loan_repayments WHERE loan_id = l.loan_id AND status = 'overdue') AS overdue_count
       FROM loans l ORDER BY l.disbursed_on DESC"
)->fetchAll();

/* Mark repayments overdue as of today - a light housekeeping check on page load. */
db()->exec("UPDATE loan_repayments SET status = 'overdue' WHERE status = 'pending' AND due_date < CURDATE()");

$canManage = user_can(['admin', 'owner', 'accountant']);
?>

<div class="page-head">
  <div>
    <h1>Loans</h1>
    <p class="lede">Borrowed capital and its repayment schedule.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Reference</th><th>Lender</th><th>Type</th>
          <th class="num">Principal</th><th class="num">Repayable</th><th class="num">Outstanding</th>
          <th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$loans): ?>
        <tr><td colspan="8" class="empty">No loans registered.</td></tr>
<?php endif; ?>
<?php foreach ($loans as $l): ?>
        <tr>
          <td><?= e($l['loan_ref']) ?></td>
          <td><?= e($l['lender_name']) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($l['loan_type']))) ?></td>
          <td class="num"><?= fmt_money($l['principal']) ?></td>
          <td class="num"><?= fmt_money($l['total_repayable']) ?></td>
          <td class="num"><?= fmt_money($l['outstanding']) ?></td>
          <td><span class="status is-<?= $l['status'] === 'settled' ? 'confirmed' : ($l['status'] === 'defaulted' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($l['status'])) ?><?= $l['overdue_count'] > 0 ? ' (' . (int) $l['overdue_count'] . ' overdue)' : '' ?>
          </span></td>
          <td class="actions"><a class="btn btn-sm" href="loan_view.php?id=<?= (int) $l['loan_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Register a loan</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid-2">
        <div class="field">
          <label for="lender_name">Lender</label>
          <input type="text" name="lender_name" id="lender_name" required maxlength="150">
        </div>
        <div class="field">
          <label for="loan_type">Type</label>
          <select name="loan_type" id="loan_type">
            <option value="working_capital">Working capital</option>
            <option value="input_loan">Input loan</option>
            <option value="equipment">Equipment</option>
            <option value="mortgage">Mortgage</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="principal">Principal</label>
          <input type="number" name="principal" id="principal" step="0.01" min="0.01" required>
        </div>
        <div class="field">
          <label for="interest_rate">Interest rate (% p.a.)</label>
          <input type="number" name="interest_rate" id="interest_rate" step="0.01" min="0" value="0">
        </div>
        <div class="field">
          <label for="term_months">Term (months)</label>
          <input type="number" name="term_months" id="term_months" step="1" min="1" value="12" required>
        </div>
      </div>
      <div class="field">
        <label for="disbursed_on">Disbursement date</label>
        <input type="date" name="disbursed_on" id="disbursed_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Register loan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
