<?php
/**
 * Module 13 - Loan detail: repayment schedule, and recording a repayment.
 * The loan's amount_repaid and status are kept correct by the database
 * trigger (trg_repayment_after_update), so this page only ever writes to
 * loan_repayments — never touches loans.amount_repaid directly.
 */
$activeNav = 'loans';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$loanId = get_int('id');
if (!$loanId) {
    redirect('loans.php');
}

$stmt = db()->prepare('SELECT * FROM loans WHERE loan_id = :id');
$stmt->execute([':id' => $loanId]);
$loan = $stmt->fetch();

if (!$loan) {
    flash('error', 'That loan does not exist.');
    redirect('loans.php');
}

$pageTitle = $loan['loan_ref'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare('SELECT * FROM loan_repayments WHERE loan_id = :id ORDER BY due_date');
$stmt->execute([':id' => $loanId]);
$repayments = $stmt->fetchAll();

$canManage = user_can(['admin', 'owner', 'accountant']);
$nextDue = null;
foreach ($repayments as $r) {
    if (in_array($r['status'], ['pending', 'overdue', 'partial'], true)) {
        $nextDue = $r;
        break;
    }
}
?>

<div class="page-head">
  <div>
    <h1><?= e($loan['loan_ref']) ?></h1>
    <p class="lede"><?= e($loan['lender_name']) ?> ·
      <?= e(str_replace('_', ' ', ucfirst($loan['loan_type']))) ?> ·
      disbursed <?= e(fmt_date($loan['disbursed_on'])) ?>
      <span class="status is-<?= $loan['status'] === 'settled' ? 'confirmed' : ($loan['status'] === 'defaulted' ? 'cancelled' : 'draft') ?>">
        <?= e(ucfirst($loan['status'])) ?>
      </span>
    </p>
  </div>
  <a class="btn" href="loans.php">Back to list</a>
</div>

<dl class="stats">
  <div><dt>Principal</dt><dd><?= fmt_money($loan['principal']) ?></dd></div>
  <div><dt>Total repayable</dt><dd><?= fmt_money($loan['total_repayable']) ?></dd></div>
  <div><dt>Repaid</dt><dd><?= fmt_money($loan['amount_repaid']) ?></dd></div>
  <div><dt>Outstanding</dt><dd><?= fmt_money($loan['outstanding']) ?></dd></div>
</dl>

<div class="panel">
  <header>Repayment schedule</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Due</th><th class="num">Amount due</th><th class="num">Paid</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
<?php foreach ($repayments as $r): ?>
        <tr>
          <td><?= e(fmt_date($r['due_date'])) ?></td>
          <td class="num"><?= fmt_money($r['amount_due']) ?></td>
          <td class="num"><?= fmt_money($r['amount_paid']) ?></td>
          <td><span class="status is-<?= $r['status'] === 'paid' ? 'confirmed' : ($r['status'] === 'overdue' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($r['status'])) ?></span></td>
          <td class="actions">
<?php if ($canManage && $r['status'] !== 'paid'): ?>
            <a class="btn btn-sm" href="#repay-<?= (int) $r['repayment_id'] ?>">Record payment</a>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage && $nextDue): ?>
<div class="panel" id="repay-<?= (int) $nextDue['repayment_id'] ?>">
  <header>Record a repayment — due <?= e(fmt_date($nextDue['due_date'])) ?></header>
  <div class="body">
    <form method="post" action="repayment_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="loan_id" value="<?= $loanId ?>">
      <input type="hidden" name="repayment_id" value="<?= (int) $nextDue['repayment_id'] ?>">
      <div class="filters">
        <div class="field">
          <label for="paid_date">Date paid</label>
          <input type="date" name="paid_date" id="paid_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="amount_paid">Amount paid</label>
          <input type="number" name="amount_paid" id="amount_paid" step="0.01" min="0.01"
                 value="<?= e((string) ($nextDue['amount_due'] - $nextDue['amount_paid'])) ?>" required>
        </div>
        <button class="btn btn-primary" type="submit">Record payment</button>
      </div>
      <p class="hint">Instalment due: <?= fmt_money($nextDue['amount_due'] - $nextDue['amount_paid']) ?> outstanding.</p>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
