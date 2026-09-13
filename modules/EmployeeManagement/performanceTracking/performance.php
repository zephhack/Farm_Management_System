<?php
$db_path = __DIR__ . '/../../db_config.php';
if (!file_exists($db_path)) { $db_path = $_SERVER['DOCUMENT_ROOT'] . '/Farm_Management_System/db_config.php'; }
require_once $db_path;
if (!isset($conn) && isset($pdo)) { $conn = $pdo; }

$message = ""; $error = "";

// Auto-create table if not exists (matches ifms_db.sql schema)
$conn->query("CREATE TABLE IF NOT EXISTS performance_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    eval_date DATE NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    efficiency_percentage DECIMAL(5,2) DEFAULT 0.00,
    reviewer_comments TEXT DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

// ---------------------------------------------------------
// HANDLE DELETE
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM performance_evaluations WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $message = "Evaluation record deleted successfully.";
    } else {
        $error = "Failed to delete evaluation: " . $stmt->error;
    }
    $stmt->close();
}

// ---------------------------------------------------------
// HANDLE CREATE / UPDATE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id           = intval($_POST['employee_id']);
    $eval_date             = $_POST['eval_date'];
    $rating                = intval($_POST['rating']);
    $efficiency_percentage = floatval($_POST['efficiency_percentage']);
    $reviewer_comments     = trim($_POST['reviewer_comments']);
    $eval_id               = isset($_POST['eval_id']) ? intval($_POST['eval_id']) : null;

    // Basic validation
    if ($rating < 1 || $rating > 5) {
        $error = "Rating must be between 1 and 5.";
    } elseif ($efficiency_percentage < 0 || $efficiency_percentage > 100) {
        $error = "Efficiency percentage must be between 0 and 100.";
    } else {
        if ($eval_id) {
            // Update existing evaluation
            $stmt = $conn->prepare("UPDATE performance_evaluations SET employee_id = ?, eval_date = ?, rating = ?, efficiency_percentage = ?, reviewer_comments = ? WHERE id = ?");
            $stmt->bind_param("isidsi", $employee_id, $eval_date, $rating, $efficiency_percentage, $reviewer_comments, $eval_id);
            if ($stmt->execute()) {
                $message = "Evaluation record updated successfully.";
            } else {
                $error = "Failed to update evaluation: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Insert new evaluation
            $stmt = $conn->prepare("INSERT INTO performance_evaluations (employee_id, eval_date, rating, efficiency_percentage, reviewer_comments) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isids", $employee_id, $eval_date, $rating, $efficiency_percentage, $reviewer_comments);
            if ($stmt->execute()) {
                $message = "Evaluation record saved successfully.";
                // Reset form values
                $_POST = [];
            } else {
                $error = "Failed to save evaluation: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// ---------------------------------------------------------
// HANDLE EDIT MODE
// ---------------------------------------------------------
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM performance_evaluations WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $edit_data = $res->fetch_assoc();
    }
    $stmt->close();
}

// ---------------------------------------------------------
// FETCH DATA & ANALYTICS
// ---------------------------------------------------------

// Search & Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_employee = isset($_GET['filter_employee']) ? intval($_GET['filter_employee']) : 0;
$filter_rating = isset($_GET['filter_rating']) ? intval($_GET['filter_rating']) : 0;

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where_clauses[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR p.reviewer_comments LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}
if ($filter_employee > 0) {
    $where_clauses[] = "p.employee_id = ?";
    $params[] = $filter_employee;
    $types .= "i";
}
if ($filter_rating > 0) {
    $where_clauses[] = "p.rating = ?";
    $params[] = $filter_rating;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Workers dropdown (Active only)
$workers = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, role FROM employees WHERE status = 'Active' ORDER BY first_name ASC")->fetch_all(MYSQLI_ASSOC);

// Fetch Evaluations with filters
$sql = "SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) as worker_name, e.role 
        FROM performance_evaluations p 
        JOIN employees e ON p.employee_id = e.id 
        WHERE $where_sql 
        ORDER BY p.eval_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$evaluations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Analytics (based on filtered set)
$avg_rating = 0; $avg_efficiency = 0; $count = count($evaluations);
if ($count > 0) {
    $sum_r = 0; $sum_e = 0;
    foreach ($evaluations as $ev) {
        $sum_r += $ev['rating'];
        $sum_e += $ev['efficiency_percentage'];
    }
    $avg_rating = $sum_r / $count;
    $avg_efficiency = $sum_e / $count;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=performance_evaluations_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Employee', 'Role', 'Rating (1-5)', 'Efficiency (%)', 'Reviewer Comments']);
    foreach ($evaluations as $row) {
        fputcsv($output, [
            $row['id'],
            $row['eval_date'],
            $row['worker_name'],
            $row['role'],
            $row['rating'],
            $row['efficiency_percentage'],
            $row['reviewer_comments'] ?? ''
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
    <title>Performance & Productivity Monitoring - Lima Digital</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #1b4d3e; --primary-dark: #123529; --accent: #2e8b57; --light-bg: #f4f7f5; --text-dark: #1e293b; --text-light: #64748b; --white: #ffffff; --border: #cbd5e1; --danger: #ef4444; --warning: #f59e0b; }
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
        
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
        .stat-card small { color: var(--text-light); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card h3 { color: var(--primary-dark); font-size: 1.5rem; margin-top: 4px; font-weight: 700; }
        
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
        textarea { resize: vertical; min-height: 70px; }
        
        .btn { background-color: var(--primary); color: var(--white); border: none; padding: 9px 15px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer; width: 100%; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 5px 10px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; display: inline-block; width: auto; }
        
        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 18px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid var(--border); }
        .filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 140px; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        th { background: #f8fafc; color: var(--primary-dark); font-weight: 700; }
        tr:hover { background: #f1f5f9; }
        
        .stars { color: #f59e0b; font-weight: bold; letter-spacing: 1px; }
        .rating-cell { white-space: nowrap; }
        .efficiency-bar { display: flex; align-items: center; gap: 8px; }
        .efficiency-track { flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; min-width: 60px; }
        .efficiency-fill { height: 100%; background: var(--accent); border-radius: 3px; }
        .efficiency-fill.high { background: #22c55e; }
        .efficiency-fill.medium { background: #f59e0b; }
        .efficiency-fill.low { background: #ef4444; }
        
        .actions { display: flex; gap: 6px; }
        
        @media (max-width: 992px) { .workspace { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header-nav">
        <h1>Performance & Productivity Monitoring</h1>
        <div class="header-actions">
            <a href="performance.php?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn-back">Export CSV</a>
            <a href="/Farm_Management_System/index.php" class="btn-back">Return to Dashboard</a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <button class="tab-item active">Evaluation Records</button>
        <button class="tab-item">Efficiency Trends</button>
        <button class="tab-item">Team Leaderboard</button>
    </div>

    <!-- Analytics -->
    <div class="analytics-grid">
        <div class="stat-card"><small>Average Workforce Rating</small><h3><?= number_format($avg_rating, 1) ?> / 5</h3></div>
        <div class="stat-card"><small>Average Efficiency Score</small><h3><?= number_format($avg_efficiency, 1) ?>%</h3></div>
        <div class="stat-card"><small>Total Evaluations</small><h3><?= $count ?> <span style="font-size:0.85rem; font-weight:normal; color:var(--text-light);">Records</span></h3></div>
    </div>

    <div class="workspace">
        <!-- Entry / Edit Form Sidebar -->
        <div class="card">
            <h2><?= $edit_data ? 'Edit Evaluation' : 'Log Evaluation' ?></h2>
            
            <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

            <form method="POST" id="evalForm" autocomplete="off">
                <?php if($edit_data): ?>
                    <input type="hidden" name="eval_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Worker *</label>
                    <select name="employee_id" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= ($edit_data && $edit_data['employee_id'] == $w['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars($w['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Evaluation Date *</label>
                    <input type="date" name="eval_date" value="<?= $edit_data ? $edit_data['eval_date'] : date('Y-m-d') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Rating (1-5) *</label>
                        <select name="rating" required>
                            <?php for($i=5; $i>=1; $i--): ?>
                                <option value="<?= $i ?>" <?= ($edit_data && $edit_data['rating'] == $i) ? 'selected' : '' ?>>
                                    <?= $i ?> - <?= ['','Poor','Needs Improvement','Satisfactory','Good','Excellent'][$i] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Efficiency (%) *</label>
                        <input type="number" step="0.1" min="0" max="100" name="efficiency_percentage" placeholder="e.g. 88.5" 
                               value="<?= $edit_data ? $edit_data['efficiency_percentage'] : '' ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Supervisor Comments</label>
                    <textarea name="reviewer_comments" rows="3" placeholder="Optional remarks..."><?= $edit_data ? htmlspecialchars($edit_data['reviewer_comments']) : '' ?></textarea>
                </div>

                <button type="submit" class="btn"><?= $edit_data ? 'Update Evaluation' : 'Save Evaluation' ?></button>
                <?php if($edit_data): ?>
                    <a href="performance.php" class="btn btn-secondary" style="margin-top: 8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Evaluation Records Table -->
        <div class="card">
            <h2>Evaluation Records</h2>

            <!-- Filters -->
            <form method="GET" action="performance.php" class="filter-bar">
                <div class="form-group">
                    <label>Search:</label>
                    <input type="text" name="search" placeholder="Name or comments..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>Employee:</label>
                    <select name="filter_employee">
                        <option value="0">All Employees</option>
                        <?php foreach($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= ($filter_employee == $w['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Rating:</label>
                    <select name="filter_rating">
                        <option value="0">All Ratings</option>
                        <?php for($i=5; $i>=1; $i--): ?>
                            <option value="<?= $i ?>" <?= ($filter_rating == $i) ? 'selected' : '' ?>><?= $i ?> Star<?= $i>1?'s':'' ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-sm" style="padding: 8px 14px;">Filter</button>
                    <a href="performance.php" class="btn btn-secondary btn-sm" style="padding: 8px 14px;">Reset</a>
                </div>
            </form>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th>Date</th>
                            <th>Rating</th>
                            <th>Efficiency</th>
                            <th>Comments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($evaluations)): ?>
                            <?php foreach($evaluations as $e): 
                                $eff = floatval($e['efficiency_percentage']);
                                $effClass = $eff >= 85 ? 'high' : ($eff >= 65 ? 'medium' : 'low');
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($e['worker_name']) ?></strong><br>
                                    <small style="color: var(--text-light);"><?= htmlspecialchars($e['role']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($e['eval_date']) ?></td>
                                <td class="rating-cell">
                                    <span class="stars"><?= str_repeat('★', $e['rating']) . str_repeat('☆', 5 - $e['rating']) ?></span>
                                    <small style="color: var(--text-light);">(<?= $e['rating'] ?>/5)</small>
                                </td>
                                <td>
                                    <div class="efficiency-bar">
                                        <div class="efficiency-track">
                                            <div class="efficiency-fill <?= $effClass ?>" style="width: <?= min($eff, 100) ?>%;"></div>
                                        </div>
                                        <strong style="min-width: 45px; text-align: right;"><?= number_format($eff, 1) ?>%</strong>
                                    </div>
                                </td>
                                <td><?= $e['reviewer_comments'] ? htmlspecialchars(mb_strimwidth($e['reviewer_comments'], 0, 50, '…')) : '—' ?></td>
                                <td class="actions">
                                    <a href="performance.php?action=edit&id=<?= $e['id'] ?>" class="btn-sm btn-secondary">Edit</a>
                                    <a href="performance.php?action=delete&id=<?= $e['id'] ?>" onclick="return confirm('Delete this evaluation record?');" class="btn-sm btn-danger">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-light); padding: 25px;">No evaluation records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Auto-reset form after successful save (only if no edit mode and no error)
        <?php if ($message && !$edit_data && !$error): ?>
            document.getElementById('evalForm').reset();
            // Re-set the date field to today after reset
            document.querySelector('input[name="eval_date"]').value = '<?= date('Y-m-d') ?>';
            // Optionally clear any URL params to prevent stale state
            if (window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        <?php endif; ?>

        // Confirm before deleting
        document.querySelectorAll('a[href*="action=delete"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this evaluation record? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>