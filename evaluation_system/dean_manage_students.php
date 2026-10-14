<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'dean') {
    header("Location: index.php");
    exit();
}

$dept_id = $_SESSION['dept_id'];

// Handle Deactivate
if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'student' AND dept_id = ?");
    $stmt->bind_param("ii", $id, $dept_id);
    $stmt->execute();
    header("Location: dean_manage_students.php?msg=deactivated");
    exit();
}

// Handle Activate
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'student' AND dept_id = ?");
    $stmt->bind_param("ii", $id, $dept_id);
    $stmt->execute();
    header("Location: dean_manage_students.php?msg=activated");
    exit();
}

// Handle Reset Password
if (isset($_GET['reset_password'])) {
    $id = (int)$_GET['reset_password'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'student' AND dept_id = ?");
    $stmt->bind_param("ii", $id, $dept_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $student = $res->fetch_assoc();
        $default_password = password_hash('123456', PASSWORD_DEFAULT);
        
        $upd = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ? AND role = 'student' AND dept_id = ?");
        $upd->bind_param("sii", $default_password, $id, $dept_id);
        $upd->execute();
        header("Location: dean_manage_students.php?msg=reset_password");
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$filter_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// Build Query
$sql = "
    SELECT u.id, u.username, u.full_name, u.email, u.status, d.dept_alias, c.course_alias
    FROM users u
    LEFT JOIN departments d ON u.dept_id = d.id
    LEFT JOIN courses c ON u.course_id = c.id
    WHERE u.role = 'student' AND u.status != 'pending' AND u.dept_id = $dept_id
";

if ($filter_course_id) {
    $sql .= " AND u.course_id = " . $filter_course_id;
}
if ($filter_status) {
    $sql .= " AND u.status = '" . $conn->real_escape_string($filter_status) . "'";
}

$sql .= " ORDER BY u.full_name ASC LIMIT $limit OFFSET $offset";
$students = $conn->query($sql);

$courses = $conn->query("SELECT * FROM courses WHERE dept_id = $dept_id ORDER BY course_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Dean Portal</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php $active_page_dean = 'dean_manage_students'; include 'dean_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-users" style="color:#0d2158;"></i> Manage Department Students
                </h2>
            </div>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'activated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account activated.</div>
                <?php elseif ($_GET['msg'] === 'deactivated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account deactivated.</div>
                <?php elseif ($_GET['msg'] === 'reset_password'): ?>
                    <div class="flash-success"><i class="fas fa-key"></i> Password has been reset to default (<strong>123456</strong>).</div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card" style="margin-bottom: 25px;">
                <h4 style="margin-top:0; margin-bottom:15px; color:var(--text-main);">Filter Students</h4>
                <form method="GET" action="" style="display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end;">
                    <div style="flex:1; min-width:200px;">
                        <label style="font-size:12px; font-weight:600; color:var(--text-muted); display:block; margin-bottom:5px;">Course</label>
                        <select name="course_id" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Courses</option>
                            <?php while($c = $courses->fetch_assoc()): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($filter_course_id == $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['course_alias']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label style="font-size:12px; font-weight:600; color:var(--text-muted); display:block; margin-bottom:5px;">Status</label>
                        <select name="status" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Status</option>
                            <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="nav-link-btn" style="background:var(--accent); color:white; padding:12px 20px; border:none; cursor:pointer;"><i class="fas fa-filter"></i> Apply Filters</button>
                        <?php if ($filter_course_id || $filter_status): ?>
                            <a href="dean_manage_students.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; display:inline-block; border-radius:8px;">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-user-graduate"></i> Student List</h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php 
                        $count_sql = "SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status != 'pending' AND u.dept_id = $dept_id";
                        if ($filter_course_id) $count_sql .= " AND u.course_id = " . $filter_course_id;
                        if ($filter_status) $count_sql .= " AND u.status = '" . $conn->real_escape_string($filter_status) . "'";
                        echo $conn->query($count_sql)->fetch_row()[0]; 
                        ?> total students
                    </span>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($students->num_rows > 0): ?>
                            <?php while ($row = $students->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td>
                                        <div style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                                    </td>
                                    <td><span class="category-badge"><?php echo htmlspecialchars($row['course_alias'] ?? 'None'); ?></span></td>
                                    <td>
                                        <?php if ($row['status'] == 'active'): ?>
                                            <a href="?deactivate=<?php echo $row['id']; ?>" 
                                               onclick="return confirm('Deactivate student account for <?php echo addslashes($row['full_name']); ?>?')" 
                                               style="text-decoration:none;" 
                                               title="Click to Deactivate">
                                                <span style="color:#10b981; font-weight:700; font-size:12px; background:#d1fae5; padding:4px 10px; border-radius:12px; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                                    <i class="fas fa-check-circle" style="font-size:10px;"></i>Active
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <a href="?activate=<?php echo $row['id']; ?>" 
                                               onclick="return confirm('Activate student account for <?php echo addslashes($row['full_name']); ?>?')" 
                                               style="text-decoration:none;" 
                                               title="Click to Activate">
                                                <span style="color:#ef4444; font-weight:700; font-size:12px; background:#fee2e2; padding:4px 10px; border-radius:12px; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                                    <i class="fas fa-ban" style="font-size:10px;"></i>Inactive
                                                </span>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($row['status'] == 'active'): ?>
                                            <a href="?deactivate=<?php echo $row['id']; ?>" class="btn-action" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5;" title="Deactivate"><i class="fas fa-ban"></i></a>
                                        <?php else: ?>
                                            <a href="?activate=<?php echo $row['id']; ?>" class="btn-action" style="background:#f0fdf4; color:#16a34a; border:1px solid #dcfce7;" title="Activate"><i class="fas fa-check"></i></a>
                                        <?php endif; ?>
                                        <a href="?reset_password=<?php echo $row['id']; ?>" class="btn-action" style="background:#3b82f6; color:white;" title="Reset Password to 123456" onclick="return confirm('Are you sure you want to reset password for <?php echo htmlspecialchars($row['full_name']); ?> to 123456?');"><i class="fas fa-key"></i></a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No students found in this department.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
