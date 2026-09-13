<?php
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) { $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php'; }
require_once $db_path;
if (!isset($conn) && isset($pdo)) { $conn = $pdo; }

// Fetch Labour Costs Analytics
$cost_summary = $conn->query("
    SELECT 
        SUM(p.net_pay) as total_payroll,
        AVG(p.net_pay) as avg_payroll,
        COUNT(p.id) as total_payouts
    FROM payroll_records p
")->fetch_assoc();

$payrolls = $conn->query("
    SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) as worker_name, e.role 
    FROM payroll_records p 
    JOIN employees e ON p.employee_id = e.id 
    ORDER BY p.pay_period_end DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Labour Costs & Payroll Integration - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #1b4d3e; --primary-dark: #123529; --light-bg: #f4f7f5; --text-dark: #1e293b; --text-light: #64748b; --white: #ffffff; --border: #cbd5e1; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; }
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; text-align: left; }
        th { background: #f8fafc; color: var(--primary-dark); }
    </style>
</head>
<body>
    <h1 style="margin-bottom: 20px; color: var(--primary-dark);">Labour Costs & Payroll Overview</h1>
    
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Payroll Expense</small>
            <h3><?= number_format($cost_summary['total_payroll'] ?? 0, 2) ?> ZMW</h3>
        </div>
        <div class="stat-card">
            <small>Average Payout</small>
            <h3><?= number_format($cost_summary['avg_payroll'] ?? 0, 2) ?> ZMW</h3>
        </div>
        <div class="stat-card">
            <small>Total Disbursements</small>
            <h3><?= $cost_summary['total_payouts'] ?? 0 ?></h3>
        </div>
    </div>

    <div class="card">
        <h2>Payroll Disbursements Log</h2>
        <table>
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Role</th>
                    <th>Pay Period</th>
                    <th>Base Pay</th>
                    <th>Overtime</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!empty($payrolls)): ?>
                    <?php foreach($payrolls as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['worker_name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['role']) ?></td>
                        <td><?= $p['pay_period_start'] ?> to <?= $p['pay_period_end'] ?></td>
                        <td><?= number_format($p['base_pay'], 2) ?> ZMW</td>
                        <td><?= number_format($p['overtime_pay'], 2) ?> ZMW</td>
                        <td><?= number_format($p['deductions'], 2) ?> ZMW</td>
                        <td><strong><?= number_format($p['net_pay'], 2) ?> ZMW</strong></td>
                        <td><?= $p['payment_status'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; padding: 20px; color: var(--text-light);">No payroll records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>