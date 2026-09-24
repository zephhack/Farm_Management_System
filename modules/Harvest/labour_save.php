<?php
/**
 * Module 11 - Attach a worker to a draft harvest.
 * Pay is calculated server-side from the basis, never trusted from the form.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$harvestId = (int) post('harvest_id', 0);
$workerId  = (int) post('worker_id', 0);
$payBasis  = post('pay_basis', 'daily');
$hours     = (float) post('hours_worked', 0);
$qtyPicked = post('qty_picked') !== '' && post('qty_picked') !== null ? (float) post('qty_picked') : null;
$rate      = (float) post('rate', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM harvests WHERE harvest_id = :id FOR UPDATE');
    $stmt->execute([':id' => $harvestId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That harvest record no longer exists.');
    }

    if ($status !== 'draft') {
        throw new RuntimeException('Labour can only be recorded while the harvest is a draft.');
    }

    if (!in_array($payBasis, ['hourly', 'daily', 'piece_rate'], true)) {
        throw new RuntimeException('Choose a valid pay basis.');
    }

    if ($rate <= 0) {
        throw new RuntimeException('Enter the rate paid.');
    }

    /* Work out the pay from the basis. */
    switch ($payBasis) {
        case 'hourly':
            if ($hours <= 0) {
                throw new RuntimeException('Enter the hours worked for an hourly rate.');
            }
            $totalPay = $rate * $hours;
            break;

        case 'piece_rate':
            if ($qtyPicked === null || $qtyPicked <= 0) {
                throw new RuntimeException('Enter the quantity picked for a piece rate.');
            }
            $totalPay = $rate * $qtyPicked;
            break;

        default: // daily
            $totalPay = $rate;
            break;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO harvest_labour
           (harvest_id, worker_id, hours_worked, qty_picked, rate, pay_basis, total_pay)
         VALUES
           (:harvest_id, :worker_id, :hours, :qty_picked, :rate, :pay_basis, :total_pay)
         ON DUPLICATE KEY UPDATE
           hours_worked = VALUES(hours_worked),
           qty_picked   = VALUES(qty_picked),
           rate         = VALUES(rate),
           pay_basis    = VALUES(pay_basis),
           total_pay    = VALUES(total_pay)'
    );

    $stmt->execute([
        ':harvest_id' => $harvestId,
        ':worker_id'  => $workerId,
        ':hours'      => $hours,
        ':qty_picked' => $qtyPicked,
        ':rate'       => $rate,
        ':pay_basis'  => $payBasis,
        ':total_pay'  => round($totalPay, 2),
    ]);

    $pdo->commit();
    flash('success', 'Worker added. Pay calculated as ' . fmt_money($totalPay) . '.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("view.php?id=$harvestId");
