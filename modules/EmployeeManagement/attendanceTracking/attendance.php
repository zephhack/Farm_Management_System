<?php
// 1. DYNAMIC DB ROUTING & SETUP (Matches Maintenance Module Routing)
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) {
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';
}
require_once $db_path;

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// Auto-create table matching exact ifms_db.sql schema
$conn->query("CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    log_date DATE NOT NULL,
    clock_in TIME DEFAULT NULL,
    clock_out TIME DEFAULT NULL,
    status ENUM('Present', 'Absent', 'Half-Day', 'On Leave') NOT NULL DEFAULT 'Present',
    hours_worked DECIMAL(5, 2) DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

$message = "";
$error = "";

// Delete Attendance Record
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM attendance_logs WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Attendance record deleted successfully.";
    } else {
        $error = "Failed to delete attendance record.";
    }
    $stmt->close();
}

// Create or Update Attendance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = intval($_POST['employee_id']);
    $log_date = $_POST['log_date'];
    $clock_in = !empty($_POST['clock_in']) ? $_POST['clock_in'] : null;
    $clock_out = !empty($_POST['clock_out']) ? $_POST['clock_out'] : null;
    $status = $_POST['status'];
    $notes = trim($_POST['notes']);
    $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : null;

    // Auto-calculate hours worked
    $hours_worked = 0.00;
    if ($clock_in && $clock_out) {
        $in = new DateTime($clock_in);
        $out = new DateTime($clock_out);
        $diff = $out->diff($in);
        $hours_worked = $diff->h + ($diff->i / 60);
        $hours_worked = round($hours_worked, 2);
    } elseif ($status === 'Half-Day') {
        $hours_worked = 4.00;
    }

    if ($log_id) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE attendance_logs SET employee_id = ?, log_date = ?, clock_in = ?, clock_out = ?, status = ?, hours_worked = ?, notes = ? WHERE id = ?");
        $stmt->bind_param("issssdsi", $employee_id, $log_date, $clock_in, $clock_out, $status, $hours_worked, $notes, $log_id);
        if ($stmt->execute()) {
            $message = "Attendance record updated successfully.";
        } else {
            $error = "Failed to update record: " . $stmt->error;
        }
        $stmt->close();
    } else {
        // Insert new record
        $stmt = $conn->prepare("INSERT INTO attendance_logs (employee_id, log_date, clock_in, clock_out, status, hours_worked, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssds", $employee_id, $log_date, $clock_in, $clock_out, $status, $hours_worked, $notes);
        if ($stmt->execute()) {
            $message = "Attendance record saved successfully.";
        } else {
            $error = "Failed to record attendance: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Check for Edit Mode
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM attendance_logs WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $edit_data = $res->fetch_assoc();
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 2. FETCH DATA & ANALYTICS
// ---------------------------------------------------------

// Fetch Active Employees for Dropdown
$employees_result = $conn->query("SELECT id, employee_code, first_name, last_name, role FROM employees WHERE status = 'Active' ORDER BY first_name ASC");
$employees = $employees_result ? $employees_result->fetch_all(MYSQLI_ASSOC) : [];

// Search and Date Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_employee = isset($_GET['filter_employee']) ? intval($_GET['filter_employee']) : 0;

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR a.notes LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}
if ($start_date !== '') {
    $where_clauses[] = "a.log_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date !== '') {
    $where_clauses[] = "a.log_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}
if ($filter_employee > 0) {
    $where_clauses[] = "a.employee_id = ?";
    $params[] = $filter_employee;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Attendance Logs
$sql = "SELECT a.*, e.employee_code, e.first_name, e.last_name, e.role 
        FROM attendance_logs a 
        LEFT JOIN employees e ON a.employee_id = e.id 
        WHERE $where_sql 
        ORDER BY a.log_date DESC, a.clock_in DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs_result = $stmt->get_result();
$logs = $logs_result ? $logs_result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Compute Analytics
$total_hours = 0;
$present_count = 0;
$absent_count = 0;
$half_day_count = 0;
$on_leave_count = 0;

foreach ($logs as $log) {
    $total_hours += floatval($log['hours_worked']);
    switch ($log['status']) {
        case 'Present': $present_count++; break;
        case 'Absent': $absent_count++; break;
        case 'Half-Day': $half_day_count++; break;
        case 'On Leave': $on_leave_count++; break;
    }
}
$log_count = count($logs);

// CSV Export Action
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_logs_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Employee Code', 'Employee Name', 'Role', 'Clock In', 'Clock Out', 'Hours Worked', 'Status', 'Notes']);
    foreach ($logs as $row) {
        fputcsv($output, [
            $row['id'],
            $row['log_date'],
            $row['employee_code'] ?? 'N/A',
            ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''),
            $row['role'] ?? 'N/A',
            $row['clock_in'] ?? '',
            $row['clock_out'] ?? '',
            $row['hours_worked'],
            $row['status'],
            $row['notes'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Tracking - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1b4d3e;
            --primary-dark: #123529;
            --accent: #2e8b57;
            --light-bg: #f4f7f5;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --border: #cbd5e1;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #0284c7;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; line-height: 1.5; }

        /* Navigation Header */
        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .header-nav h1 {
            font-size: 1.6rem;
            color: var(--primary-dark);
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background-color: var(--white);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s ease-in-out;
        }

        .btn-back svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 0.2s ease-in-out;
        }

        .btn-back:hover {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(27, 77, 62, 0.15);
            transform: translateY(-1px);
        }

        .btn-back:hover svg { transform: translateX(-3px); }

        /* Operational Mode Navigation Tabs */
        .nav-tabs { display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 20px; overflow-x: auto; }
        .tab-item {
            padding: 10px 18px; font-weight: 600; color: var(--text-light); border: none;
            background: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px;
            white-space: nowrap; font-size: 0.88rem;
        }
        .tab-item.active { color: var(--primary); border-color: var(--primary); }

        /* KPI Analytics Ribbon */
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card {
            background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }

        /* Alerts & Reminders Box */
        .reminders-card {
            background: #fffbe2; border: 1px solid #ffe58f; border-radius: 8px;
            padding: 16px; margin-bottom: 25px;
        }
        .reminders-card h3 { color: #856404; font-size: 0.95rem; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .reminders-list { list-style: none; padding-left: 0; }
        .reminders-list li { color: #856404; font-size: 0.85rem; padding: 4px 0; font-weight: 500; }

        /* Main Workspace Split Screen */
        .workspace { display: grid; grid-template-columns: 340px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        /* Status Alerts */
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        /* Form Controls */
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select, textarea {
            width: 100%; padding: 8px 12px; border: 1px solid var(--border);
            border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff;
        }
        textarea { resize: vertical; min-height: 75px; }
        input:focus, select:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }

        /* Buttons */
        .btn {
            background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px;
            font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; color: #ffffff; }

        /* Filter Toolbar */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border);
        }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }

        /* Table Design */
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;
        }
        .badge-present { background: #dcfce7; color: #166534; }
        .badge-absent { background: #fee2e2; color: #991b1b; }
        .badge-half-day { background: #fef3c7; color: #b45309; }
        .badge-on-leave { background: #e0f2fe; color: #0369a1; }

        .emp-name { font-weight: 600; color: var(--text-dark); }
        .emp-code { font-size: 0.78rem; color: var(--text-light); }
        .role-tag { font-size: 0.8rem; color: var(--text-light); }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) {
            .workspace { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <div class="header-nav">
        <h1>Attendance Tracking</h1>
        <div class="header-actions">
            <a href="attendance.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-secondary">Export CSV</a>
            <a href="/Farm_Management_System/index.php" class="btn-back">
                Return to Dashboard
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <button class="tab-item active">Daily Attendance Log</button>
        <button class="tab-item">Weekly Summary</button>
        <button class="tab-item">Leave Management</button>
    </div>

    <!-- Active Alerts Ribbon (e.g., Absenteeism Pattern) -->
    <?php 
    // Detect employees with 3+ absences in the filtered period
    $absence_alerts = [];
    $absence_sql = "SELECT e.first_name, e.last_name, COUNT(*) as absent_days 
                    FROM attendance_logs a 
                    JOIN employees e ON a.employee_id = e.id 
                    WHERE a.status = 'Absent' 
                    GROUP BY a.employee_id 
                    HAVING absent_days >= 2 
                    ORDER BY absent_days DESC";
    $absence_res = $conn->query($absence_sql);
    if ($absence_res && $absence_res->num_rows > 0) {
        while($row = $absence_res->fetch_assoc()) {
            $absence_alerts[] = $row;
        }
    }
    if (!empty($absence_alerts)):
    ?>
    <div class="reminders-card">
        <h3>⚠️ Attendance Alerts</h3>
        <ul class="reminders-list">
            <?php foreach($absence_alerts as $alert): ?>
                <li><strong><?= htmlspecialchars($alert['first_name'] . ' ' . $alert['last_name']) ?></strong> has <strong><?= $alert['absent_days'] ?> absence(s)</strong> recorded in the selected period. Please review.</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Analytics Operational Bar -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Hours Logged</small>
            <h3><?= number_format($total_hours, 1) ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">hrs</span></h3>
        </div>
        <div class="stat-card">
            <small>Total Records</small>
            <h3><?= $log_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Entries</span></h3>
        </div>
        <div class="stat-card">
            <small>Present</small>
            <h3><?= $present_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Days</span></h3>
        </div>
        <div class="stat-card">
            <small>Absent / Leave</small>
            <h3><?= $absent_count + $on_leave_count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Days</span></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form Sidebar -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Attendance Record' : 'Log Attendance' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="log_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Employee *</label>
                    <select name="employee_id" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= ($edit_data && $edit_data['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_code'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="log_date" value="<?= $edit_data ? $edit_data['log_date'] : date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Status *</label>
                    <select name="status" required>
                        <option value="Present" <?= ($edit_data && $edit_data['status'] === 'Present') ? 'selected' : '' ?>>Present</option>
                        <option value="Absent" <?= ($edit_data && $edit_data['status'] === 'Absent') ? 'selected' : '' ?>>Absent</option>
                        <option value="Half-Day" <?= ($edit_data && $edit_data['status'] === 'Half-Day') ? 'selected' : '' ?>>Half-Day</option>
                        <option value="On Leave" <?= ($edit_data && $edit_data['status'] === 'On Leave') ? 'selected' : '' ?>>On Leave</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Clock In</label>
                    <input type="time" name="clock_in" value="<?= $edit_data ? $edit_data['clock_in'] : '' ?>">
                </div>

                <div class="form-group">
                    <label>Clock Out</label>
                    <input type="time" name="clock_out" value="<?= $edit_data ? $edit_data['clock_out'] : '' ?>">
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Optional remarks..."><?= $edit_data ? htmlspecialchars($edit_data['notes']) : '' ?></textarea>
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Record' : 'Save Attendance' ?></button>
                <?php if($edit_data): ?>
                    <a href="attendance.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Audit Log Grid -->
        <div class="card">
            <h2>Attendance History</h2>

            <!-- Search and Date Filter Toolbar -->
            <form method="GET" action="attendance.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Employee/Notes:</label>
                    <input type="text" name="search" placeholder="Name, code, or notes..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>Employee:</label>
                    <select name="filter_employee">
                        <option value="0">All Employees</option>
                        <?php foreach($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= ($filter_employee == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Date:</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="form-group">
                    <label>To Date:</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="attendance.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>

            <!-- Attendance Log Data Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Hours</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach($logs as $log): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($log['log_date']) ?></strong></td>
                                <td>
                                    <div class="emp-name"><?= htmlspecialchars(($log['first_name'] ?? 'Unknown') . ' ' . ($log['last_name'] ?? '')) ?></div>
                                    <div class="emp-code"><?= htmlspecialchars($log['employee_code'] ?? 'N/A') ?></div>
                                </td>
                                <td><span class="role-tag"><?= htmlspecialchars($log['role'] ?? 'N/A') ?></span></td>
                                <td><?= $log['clock_in'] ? htmlspecialchars(date('H:i', strtotime($log['clock_in']))) : '—' ?></td>
                                <td><?= $log['clock_out'] ? htmlspecialchars(date('H:i', strtotime($log['clock_out']))) : '—' ?></td>
                                <td><strong><?= number_format($log['hours_worked'], 2) ?></strong></td>
                                <td>
                                    <?php
                                        $statusClass = 'badge-' . strtolower(str_replace(' ', '-', $log['status']));
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($log['status']) ?></span>
                                </td>
                                <td class="actions">
                                    <a href="attendance.php?action=edit&id=<?= $log['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="attendance.php?action=delete&id=<?= $log['id'] ?>" onclick="return confirm('Are you sure you want to delete this attendance record?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-light); padding: 25px;">No attendance records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>