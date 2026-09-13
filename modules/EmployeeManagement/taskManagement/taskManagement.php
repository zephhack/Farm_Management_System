<?php
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) { $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php'; }
require_once $db_path;
if (!isset($conn) && isset($pdo)) { $conn = $pdo; }

$message = ""; $error = "";
$form_reset = false; // set true after any successful save (insert OR update)

// Auto-create table if not exists (matches ifms_db.sql schema)
$conn->query("CREATE TABLE IF NOT EXISTS farm_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(150) NOT NULL,
    assigned_employee_id INT NOT NULL,
    field_location VARCHAR(100) DEFAULT NULL,
    task_date DATE NOT NULL,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
    status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    target_units DECIMAL(10,2) DEFAULT 0.00,
    completed_units DECIMAL(10,2) DEFAULT 0.00,
    unit_type VARCHAR(50) DEFAULT 'units',
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (assigned_employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

// ---------------------------------------------------------
// HANDLE DELETE
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM farm_tasks WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Task deleted successfully.";
    } else {
        $error = "Failed to delete task: " . $stmt->error;
    }
    $stmt->close();
}

// ---------------------------------------------------------
// HANDLE CREATE / UPDATE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_name            = trim($_POST['task_name']);
    $assigned_employee_id = intval($_POST['assigned_employee_id']);
    $field_location       = trim($_POST['field_location']);
    $task_date            = $_POST['task_date'];
    $priority             = $_POST['priority'];
    $status               = $_POST['status'];
    $target_units         = floatval($_POST['target_units']);
    $completed_units      = floatval($_POST['completed_units']);
    $unit_type            = trim($_POST['unit_type']);
    $notes                = trim($_POST['notes']);
    $task_id              = isset($_POST['task_id']) ? intval($_POST['task_id']) : null;

    if (empty($task_name)) {
        $error = "Task description is required.";
    } elseif ($completed_units > $target_units && $target_units > 0) {
        $error = "Completed units cannot exceed target units.";
    } else {
        if ($task_id) {
            // Update existing task
            $stmt = $conn->prepare("UPDATE farm_tasks SET task_name = ?, assigned_employee_id = ?, field_location = ?, task_date = ?, priority = ?, status = ?, target_units = ?, completed_units = ?, unit_type = ?, notes = ? WHERE id = ?");
            $stmt->bind_param("sissssddssi", $task_name, $assigned_employee_id, $field_location, $task_date, $priority, $status, $target_units, $completed_units, $unit_type, $notes, $task_id);
            if ($stmt->execute()) {
                $message = "Task updated successfully.";
                $form_reset = true; // reset after update too
            } else {
                $error = "Failed to update task: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Insert new task
            $stmt = $conn->prepare("INSERT INTO farm_tasks (task_name, assigned_employee_id, field_location, task_date, priority, status, target_units, completed_units, unit_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sissssddss", $task_name, $assigned_employee_id, $field_location, $task_date, $priority, $status, $target_units, $completed_units, $unit_type, $notes);
            if ($stmt->execute()) {
                $message = "Task assigned successfully.";
                $form_reset = true;
            } else {
                $error = "Task assignment failed: " . $stmt->error;
            }
            $stmt->close();
        }
        $_POST = []; // clear POST so the form doesn't re-populate
    }
}

// ---------------------------------------------------------
// HANDLE EDIT MODE
// ---------------------------------------------------------
$edit_data = null;
if (!$form_reset && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM farm_tasks WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $edit_data = $res->fetch_assoc();
    }
    $stmt->close();
}

// ---------------------------------------------------------
// FETCH DATA & FILTERS
// ---------------------------------------------------------

$workers = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, role FROM employees WHERE status = 'Active' ORDER BY first_name ASC")->fetch_all(MYSQLI_ASSOC);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_employee = isset($_GET['filter_employee']) ? intval($_GET['filter_employee']) : 0;
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_priority = isset($_GET['filter_priority']) ? $_GET['filter_priority'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(t.task_name LIKE ? OR t.field_location LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}
if ($filter_employee > 0) {
    $where_clauses[] = "t.assigned_employee_id = ?";
    $params[] = $filter_employee;
    $types .= "i";
}
if ($filter_status !== '') {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}
if ($filter_priority !== '') {
    $where_clauses[] = "t.priority = ?";
    $params[] = $filter_priority;
    $types .= "s";
}
if ($start_date !== '') {
    $where_clauses[] = "t.task_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date !== '') {
    $where_clauses[] = "t.task_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

$sql = "SELECT t.*, CONCAT(e.first_name, ' ', e.last_name) as worker_name, e.role 
        FROM farm_tasks t 
        JOIN employees e ON t.assigned_employee_id = e.id 
        WHERE $where_sql 
        ORDER BY t.task_date DESC, t.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_tasks = count($tasks);
$pending_count = 0; $in_progress_count = 0; $completed_count = 0; $cancelled_count = 0;
$urgent_count = 0;
$total_target = 0; $total_completed = 0;

foreach ($tasks as $t) {
    switch ($t['status']) {
        case 'Pending': $pending_count++; break;
        case 'In Progress': $in_progress_count++; break;
        case 'Completed': $completed_count++; break;
        case 'Cancelled': $cancelled_count++; break;
    }
    if ($t['priority'] === 'Urgent') $urgent_count++;
    $total_target += floatval($t['target_units']);
    $total_completed += floatval($t['completed_units']);
}

$completion_rate = ($total_target > 0) ? round(($total_completed / $total_target) * 100, 1) : 0;

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=farm_tasks_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Task', 'Worker', 'Role', 'Location', 'Date', 'Priority', 'Status', 'Target', 'Completed', 'Unit', 'Notes']);
    foreach ($tasks as $row) {
        fputcsv($output, [
            $row['id'],
            $row['task_name'],
            $row['worker_name'],
            $row['role'],
            $row['field_location'],
            $row['task_date'],
            $row['priority'],
            $row['status'],
            $row['target_units'],
            $row['completed_units'],
            $row['unit_type'],
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
    <title>Task Allocation & Management - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #1b4d3e; --primary-dark: #123529; --accent: #2e8b57; --light-bg: #f4f7f5; --text-dark: #1e293b; --text-light: #64748b; --white: #ffffff; --border: #cbd5e1; --danger: #ef4444; --warning: #f59e0b; --info: #0284c7; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; line-height: 1.5; }

        .header-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border); }
        .header-nav h1 { font-size: 1.6rem; color: var(--primary-dark); font-weight: 700; letter-spacing: -0.5px; }
        .header-actions { display: flex; gap: 12px; align-items: center; }

        .btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; background-color: var(--white); color: var(--primary-dark); font-weight: 600; font-size: 0.85rem; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.2s; }
        .btn-back:hover { background-color: var(--primary); color: var(--white); border-color: var(--primary); }

        .nav-tabs { display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
        .tab-item { padding: 10px 18px; font-weight: 600; color: var(--text-light); border: none; background: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; font-size: 0.88rem; }
        .tab-item.active { color: var(--primary); border-color: var(--primary); }

        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }

        .urgent-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 25px; }
        .urgent-banner h3 { color: #991b1b; font-size: 0.95rem; font-weight: 700; margin-bottom: 8px; }
        .urgent-list { list-style: none; padding-left: 0; }
        .urgent-list li { color: #991b1b; font-size: 0.85rem; padding: 4px 0; font-weight: 500; }

        .workspace { display: grid; grid-template-columns: 380px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select, textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff; }
        input:focus, select:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }
        textarea { resize: vertical; min-height: 60px; }

        .btn { background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer; width: 100%; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; display: inline-block; width: auto; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border); }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 130px; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-pending { background: #e2e8f0; color: #475569; }
        .badge-inprogress { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-low { background: #e2e8f0; color: #475569; }
        .badge-medium { background: #dbeafe; color: #1e40af; }
        .badge-high { background: #fef3c7; color: #b45309; }
        .badge-urgent { background: #fee2e2; color: #991b1b; }

        .progress-bar { display: flex; align-items: center; gap: 8px; }
        .progress-track { flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; min-width: 60px; }
        .progress-fill { height: 100%; background: var(--accent); border-radius: 3px; }
        .progress-fill.high { background: #22c55e; }
        .progress-fill.medium { background: #f59e0b; }
        .progress-fill.low { background: #ef4444; }

        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) { .workspace { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header-nav">
        <h1>Daily Task Allocation & Management</h1>
        <div class="header-actions">
            <a href="taskManagement.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn-back">Export CSV</a>
            <a href="/Farm_Management_System/index.php" class="btn-back">Return to Dashboard</a>
        </div>
    </div>

    <div class="nav-tabs">
        <button class="tab-item active">Task Log</button>
        <button class="tab-item">Workload Overview</button>
        <button class="tab-item">Field Map</button>
    </div>

    <?php 
    $urgent_tasks = array_filter($tasks, function($t) {
        return $t['priority'] === 'Urgent' && $t['status'] !== 'Completed' && $t['status'] !== 'Cancelled';
    });
    if (!empty($urgent_tasks)): 
    ?>
    <div class="urgent-banner">
        <h3>⚠️ Urgent Tasks Requiring Attention</h3>
        <ul class="urgent-list">
            <?php foreach(array_slice($urgent_tasks, 0, 5) as $ut): ?>
                <li><strong><?= htmlspecialchars($ut['task_name']) ?></strong> — assigned to <?= htmlspecialchars($ut['worker_name']) ?> at <?= htmlspecialchars($ut['field_location'] ?: 'N/A') ?> (<?= htmlspecialchars($ut['status']) ?>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="analytics-grid">
        <div class="stat-card"><small>Total Tasks</small><h3><?= $total_tasks ?></h3></div>
        <div class="stat-card"><small>Pending</small><h3><?= $pending_count ?></h3></div>
        <div class="stat-card"><small>In Progress</small><h3><?= $in_progress_count ?></h3></div>
        <div class="stat-card"><small>Completed</small><h3><?= $completed_count ?></h3></div>
        <div class="stat-card"><small>Completion Rate</small><h3><?= $completion_rate ?>%</h3></div>
    </div>

    <div class="workspace">
        <div class="card">
            <h2><?= $edit_data ? 'Edit Task' : 'Assign New Task' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form method="POST" id="taskForm" autocomplete="off">
                <?php if($edit_data): ?>
                    <input type="hidden" name="task_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Task Description *</label>
                    <input type="text" name="task_name" placeholder="e.g. Harvest maize in Block B" value="<?= $edit_data ? htmlspecialchars($edit_data['task_name']) : '' ?>" required>
                </div>

                <div class="form-group">
                    <label>Assign Worker *</label>
                    <select name="assigned_employee_id" required>
                        <option value="">-- Select Worker --</option>
                        <?php foreach($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= ($edit_data && $edit_data['assigned_employee_id'] == $w['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars($w['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Field Location</label>
                    <input type="text" name="field_location" placeholder="e.g. Pivot 3 / Block B" value="<?= $edit_data ? htmlspecialchars($edit_data['field_location']) : '' ?>">
                </div>

                <div class="form-group">
                    <label>Task Date *</label>
                    <input type="date" name="task_date" value="<?= $edit_data ? $edit_data['task_date'] : date('Y-m-d') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="Low" <?= ($edit_data && $edit_data['priority'] === 'Low') ? 'selected' : '' ?>>Low</option>
                            <option value="Medium" <?= (!$edit_data || $edit_data['priority'] === 'Medium') ? 'selected' : '' ?>>Medium</option>
                            <option value="High" <?= ($edit_data && $edit_data['priority'] === 'High') ? 'selected' : '' ?>>High</option>
                            <option value="Urgent" <?= ($edit_data && $edit_data['priority'] === 'Urgent') ? 'selected' : '' ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="Pending" <?= (!$edit_data || $edit_data['status'] === 'Pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= ($edit_data && $edit_data['status'] === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                            <option value="Completed" <?= ($edit_data && $edit_data['status'] === 'Completed') ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= ($edit_data && $edit_data['status'] === 'Cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Target Units</label>
                        <input type="number" step="0.01" name="target_units" value="<?= $edit_data ? $edit_data['target_units'] : '0.00' ?>">
                    </div>
                    <div class="form-group">
                        <label>Completed Units</label>
                        <input type="number" step="0.01" name="completed_units" value="<?= $edit_data ? $edit_data['completed_units'] : '0.00' ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Unit Type</label>
                    <input type="text" name="unit_type" placeholder="e.g. bags, hectares, rows" value="<?= $edit_data ? htmlspecialchars($edit_data['unit_type']) : 'units' ?>">
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Optional remarks..."><?= $edit_data ? htmlspecialchars($edit_data['notes']) : '' ?></textarea>
                </div>

                <button type="submit" class="btn"><?= $edit_data ? 'Update Task' : 'Allocate Task' ?></button>
                <?php if($edit_data): ?>
                    <a href="taskManagement.php" class="btn btn-secondary" style="margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h2>Assigned Task Logs</h2>

            <form method="GET" action="taskManagement.php" class="filter-bar">
                <div class="form-group">
                    <label>Search:</label>
                    <input type="text" name="search" placeholder="Task, worker, location..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>Worker:</label>
                    <select name="filter_employee">
                        <option value="0">All Workers</option>
                        <?php foreach($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= ($filter_employee == $w['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status:</label>
                    <select name="filter_status">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= ($filter_status === 'Pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="In Progress" <?= ($filter_status === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= ($filter_status === 'Completed') ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= ($filter_status === 'Cancelled') ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority:</label>
                    <select name="filter_priority">
                        <option value="">All Priorities</option>
                        <option value="Low" <?= ($filter_priority === 'Low') ? 'selected' : '' ?>>Low</option>
                        <option value="Medium" <?= ($filter_priority === 'Medium') ? 'selected' : '' ?>>Medium</option>
                        <option value="High" <?= ($filter_priority === 'High') ? 'selected' : '' ?>>High</option>
                        <option value="Urgent" <?= ($filter_priority === 'Urgent') ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>From:</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="form-group">
                    <label>To:</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div>
                    <button type="submit" class="btn btn-sm" style="padding: 8px 14px;">Filter</button>
                    <a href="taskManagement.php" class="btn btn-secondary btn-sm" style="padding: 8px 14px;">Reset</a>
                </div>
            </form>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Worker</th>
                            <th>Location</th>
                            <th>Date</th>
                            <th>Progress</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($tasks)): ?>
                            <?php foreach($tasks as $t): 
                                $target = floatval($t['target_units']);
                                $completed = floatval($t['completed_units']);
                                $pct = ($target > 0) ? min(round(($completed / $target) * 100), 100) : 0;
                                $pctClass = $pct >= 80 ? 'high' : ($pct >= 40 ? 'medium' : 'low');
                                $statusKey = strtolower(str_replace(' ', '', $t['status']));
                                $priorityKey = strtolower($t['priority']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($t['task_name']) ?></strong>
                                    <?php if(!empty($t['notes'])): ?>
                                        <br><small style="color: var(--text-light);"><?= htmlspecialchars(mb_strimwidth($t['notes'], 0, 40, '…')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($t['worker_name']) ?></strong><br>
                                    <small style="color: var(--text-light);"><?= htmlspecialchars($t['role']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($t['field_location'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($t['task_date']) ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-track">
                                            <div class="progress-fill <?= $pctClass ?>" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                        <small style="min-width: 70px; text-align: right;">
                                            <?= number_format($completed, 1) ?> / <?= number_format($target, 1) ?>
                                        </small>
                                    </div>
                                    <small style="color: var(--text-light);"><?= $pct ?>% complete</small>
                                </td>
                                <td><span class="badge badge-<?= $priorityKey ?>"><?= htmlspecialchars($t['priority']) ?></span></td>
                                <td><span class="badge badge-<?= $statusKey ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                                <td class="actions">
                                    <a href="taskManagement.php?action=edit&id=<?= $t['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="taskManagement.php?action=delete&id=<?= $t['id'] ?>" onclick="return confirm('Delete this task? This action cannot be undone.');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--text-light); padding: 25px;">No tasks found matching your filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Reset form after ANY successful save (insert OR update)
        <?php if (!empty($form_reset) && !$error): ?>
            document.getElementById('taskForm').reset();
            document.querySelector('input[name="task_date"]').value = '<?= date('Y-m-d') ?>';
            if (window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        <?php endif; ?>
    </script>
</body>
</html>