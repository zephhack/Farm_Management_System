<?php
// Relative path resolution to reach db_config.php anywhere in project structure
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) {
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php';
}
require_once $db_path;

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// ---------------------------------------------------------
// AUTO-SCHEMA REPAIR (PREVENTS "UNKNOWN COLUMN" ERRORS)
// ---------------------------------------------------------
$columns_to_check = [
    'employee_code'   => "VARCHAR(50) DEFAULT NULL",
    'first_name'      => "VARCHAR(100) DEFAULT NULL",
    'last_name'       => "VARCHAR(100) DEFAULT NULL",
    'role'            => "VARCHAR(100) DEFAULT NULL",
    'phone'           => "VARCHAR(30) DEFAULT NULL",
    'employment_type' => "VARCHAR(50) DEFAULT 'Casual'",
    'hourly_rate'     => "DECIMAL(10,2) DEFAULT 0.00",
    'daily_rate'      => "DECIMAL(10,2) DEFAULT NULL",
    'status'          => "VARCHAR(50) NOT NULL DEFAULT 'Active'",
    'date_joined'     => "DATE DEFAULT NULL"
];

foreach ($columns_to_check as $col => $definition) {
    $chk = $conn->query("SHOW COLUMNS FROM employees LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN $col $definition");
    }
}

$message = isset($_GET['msg']) ? trim($_GET['msg']) : "";
$error = "";

// ---------------------------------------------------------
// 1. HANDLE DELETE ACTION
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        header("Location: employeeReg.php?msg=" . urlencode("Employee record deleted successfully."));
        exit;
    } else { 
        $error = "Failed to delete record: " . $stmt->error; 
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 2. HANDLE CREATE / UPDATE ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name      = trim($_POST['first_name']);
    $last_name       = trim($_POST['last_name']);
    $employee_code  = trim($_POST['employee_code']);
    $role            = trim($_POST['role']);
    $phone           = trim($_POST['phone']);
    $employment_type = trim($_POST['employment_type']);
    $daily_rate      = ($_POST['daily_rate'] !== '') ? floatval($_POST['daily_rate']) : null;
    $status          = trim($_POST['status']);
    $date_joined     = !empty($_POST['date_joined']) ? $_POST['date_joined'] : date('Y-m-d');
    $emp_id          = isset($_POST['emp_id']) ? intval($_POST['emp_id']) : null;

    if ($emp_id) {
        // Update Record
        $stmt = $conn->prepare("UPDATE employees SET first_name=?, last_name=?, employee_code=?, role=?, phone=?, employment_type=?, daily_rate=?, status=?, date_joined=? WHERE id=?");
        $stmt->bind_param("ssssssdssi", $first_name, $last_name, $employee_code, $role, $phone, $employment_type, $daily_rate, $status, $date_joined, $emp_id);
        if ($stmt->execute()) { 
            header("Location: employeeReg.php?msg=" . urlencode("Employee profile updated successfully."));
            exit;
        } else { 
            $error = "Update failed: " . $stmt->error; 
        }
        $stmt->close();
    } else {
        // Insert Record & Reset Form via PRG Pattern Redirect
        $stmt = $conn->prepare("INSERT INTO employees (first_name, last_name, employee_code, role, phone, employment_type, daily_rate, status, date_joined) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssdss", $first_name, $last_name, $employee_code, $role, $phone, $employment_type, $daily_rate, $status, $date_joined);
        if ($stmt->execute()) { 
            header("Location: employeeReg.php?msg=" . urlencode("Employee registered successfully."));
            exit;
        } else { 
            $error = "Registration failed: " . $stmt->error; 
        }
        $stmt->close();
    }
}

// Fetch Edit Data if Requested
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { $edit_data = $res->fetch_assoc(); }
    $stmt->close();
}

// ---------------------------------------------------------
// 3. FETCH WORKERS & ANALYTICS
// ---------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_sql = $search !== '' ? "WHERE first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR role LIKE '%$search%' OR employee_code LIKE '%$search%'" : "WHERE 1=1";
$employees_res = $conn->query("SELECT * FROM employees $where_sql ORDER BY id DESC");
$employees = $employees_res ? $employees_res->fetch_all(MYSQLI_ASSOC) : [];

$total_workers = count($employees);
$active_workers = count(array_filter($employees, fn($e) => strtolower($e['status']) === 'active'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Registration & Roles - Lima Digital</title>
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
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { color: var(--text-dark); background-color: var(--light-bg); padding: 25px 4%; font-size: 0.9rem; line-height: 1.5; }

        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .header-nav h1 { font-size: 1.6rem; color: var(--primary-dark); font-weight: 700; letter-spacing: -0.5px; }

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

        .btn-back svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.2s ease-in-out; }
        .btn-back:hover { background-color: var(--primary); color: var(--white); border-color: var(--primary); transform: translateY(-1px); }
        .btn-back:hover svg { transform: translateX(-3px); }

        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }

        .workspace { display: grid; grid-template-columns: 380px 1fr; gap: 25px; align-items: start; }
        .card { background: var(--white); padding: 22px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        h2 { color: var(--primary-dark); margin-bottom: 18px; font-size: 1.15rem; font-weight: 700; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--accent); }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: var(--text-dark); }
        input, select { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem; outline: none; background: #fff; }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.12); }

        .btn { background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; color: #ffffff; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border); }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }

        .badge { padding: 3px 8px; font-size: 0.75rem; font-weight: 700; border-radius: 12px; display: inline-block; }
        .badge-op { background: #dcfce7; color: #15803d; }
        .badge-inact { background: #fee2e2; color: #b91c1c; }

        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; }
        .actions { display: flex; gap: 6px; }

        @media (max-width: 992px) { .workspace { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <div class="header-nav">
        <h1>Farm Worker Registration & Roles</h1>
        <a href="/Farm_Management_System/index.php" class="btn-back">
            Return to Dashboard
        </a>
    </div>

    <!-- Analytics Cards -->
    <div class="analytics-grid">
        <div class="stat-card">
            <small>Total Registered Workers</small>
            <h3><?= $total_workers ?></h3>
        </div>
        <div class="stat-card">
            <small>Active Workforce</small>
            <h3><?= $active_workers ?></h3>
        </div>
    </div>

    <div class="workspace">
        <!-- Entry & Edit Form Card -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Employee Profile' : 'Register New Worker' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form action="employeeReg.php" method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="emp_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" placeholder="First name..." value="<?= $edit_data ? htmlspecialchars($edit_data['first_name']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" placeholder="Last name..." value="<?= $edit_data ? htmlspecialchars($edit_data['last_name']) : '' ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Employee ID Code *</label>
                        <input type="text" name="employee_code" placeholder="e.g. EMP-102" value="<?= $edit_data ? htmlspecialchars($edit_data['employee_code']) : '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Assigned Role *</label>
                        <input type="text" name="role" placeholder="e.g. Harvester" value="<?= $edit_data ? htmlspecialchars($edit_data['role']) : '' ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" placeholder="e.g. +260..." value="<?= $edit_data ? htmlspecialchars($edit_data['phone'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Employment Type *</label>
                        <select name="employment_type" required>
                            <option value="Casual" <?= ($edit_data && $edit_data['employment_type'] === 'Casual') ? 'selected' : '' ?>>Casual</option>
                            <option value="Contract" <?= ($edit_data && $edit_data['employment_type'] === 'Contract') ? 'selected' : '' ?>>Contract</option>
                            <option value="Permanent" <?= ($edit_data && $edit_data['employment_type'] === 'Permanent') ? 'selected' : '' ?>>Permanent</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Daily Rate (ZMW)</label>
                        <input type="number" step="0.01" name="daily_rate" placeholder="0.00" value="<?= $edit_data ? htmlspecialchars($edit_data['daily_rate'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required>
                            <option value="Active" <?= ($edit_data && $edit_data['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                            <option value="On Leave" <?= ($edit_data && $edit_data['status'] === 'On Leave') ? 'selected' : '' ?>>On Leave</option>
                            <option value="Terminated" <?= ($edit_data && $edit_data['status'] === 'Terminated') ? 'selected' : '' ?>>Terminated</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Date Joined</label>
                    <input type="date" name="date_joined" value="<?= $edit_data ? $edit_data['date_joined'] : date('Y-m-d') ?>">
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 5px;"><?= $edit_data ? 'Update Profile' : 'Register Worker' ?></button>
                <?php if($edit_data): ?>
                    <a href="employeeReg.php" class="btn btn-secondary" style="width: 100%; margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Roster Grid Card -->
        <div class="card">
            <h2>Active Workforce Roster</h2>

            <!-- Search Bar -->
            <form method="GET" action="employeeReg.php" class="filter-bar">
                <div class="form-group">
                    <label>Search Worker / Role / Code:</label>
                    <input type="text" name="search" placeholder="Search roster..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="employeeReg.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Worker Name</th>
                            <th>Role</th>
                            <th>Type</th>
                            <th>Daily Rate</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($employees)): ?>
                            <?php foreach($employees as $emp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($emp['employee_code']) ?></strong></td>
                                <td><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></td>
                                <td><?= htmlspecialchars($emp['role']) ?></td>
                                <td><?= htmlspecialchars($emp['employment_type']) ?></td>
                                <td><?= $emp['daily_rate'] !== null ? number_format($emp['daily_rate'], 2) . ' ZMW' : '-' ?></td>
                                <td><span class="badge <?= strtolower($emp['status']) === 'active' ? 'badge-op' : 'badge-inact' ?>"><?= htmlspecialchars($emp['status']) ?></span></td>
                                <td class="actions">
                                    <a href="employeeReg.php?action=edit&id=<?= $emp['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="employeeReg.php?action=delete&id=<?= $emp['id'] ?>" onclick="return confirm('Are you sure you want to delete this record?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-light); padding: 25px;">No farm workers found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>