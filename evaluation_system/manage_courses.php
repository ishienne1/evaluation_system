<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'system admin')) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'dean') {
        header("Location: dashboard_dean.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$role = $_SESSION['role'];
$msg = '';

// Handle Add/Edit Course
if (isset($_POST['save_course'])) {
    $id = (int)$_POST['id'];
    $course_name = trim($_POST['course_name']);
    $course_alias = trim($_POST['course_alias']);
    $dept_id = (int)$_POST['dept_id'];

    if ($id > 0) { // Update
        $stmt = $conn->prepare("UPDATE courses SET course_name = ?, course_alias = ?, dept_id = ? WHERE id = ?");
        $stmt->bind_param("ssii", $course_name, $course_alias, $dept_id, $id);
        $msg = 'updated';
    } else {
        // Check for duplicates on insert
        $check_stmt = $conn->prepare("SELECT id FROM courses WHERE course_alias = ?");
        $check_stmt->bind_param("s", $course_alias);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
            $msg = 'error_exists';
        } else {
            $stmt = $conn->prepare("INSERT INTO courses (course_name, course_alias, dept_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $course_name, $course_alias, $dept_id);
            $msg = 'added';
        }
    }
    if (isset($stmt) && $stmt->execute()) {
        header("Location: manage_courses.php?msg=$msg");
        exit();
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_courses.php?msg=deleted");
    exit();
}

// Fetch for editing
$edit_course = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_course = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">

    <?php 
    $active_page = 'courses'; 
    $mobile_page_title = 'Manage Courses';
    include 'admin_sidebar.php';
    ?>

    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 style="color:var(--text-main);"><i class="fas fa-graduation-cap" style="color:var(--accent);"></i> Manage Courses</h2>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'added'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Course added successfully.</div>
            <?php elseif ($_GET['msg'] === 'updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Course updated successfully.</div>
            <?php elseif ($_GET['msg'] === 'deleted'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Course deleted.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($msg === 'error_exists'): ?>
            <div class="flash-error"><i class="fas fa-exclamation-circle"></i> Course Alias already exists.</div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-plus-circle"></i> <?php echo $edit_course ? 'Edit' : 'Add New'; ?> Course</h3>
            <form method="POST" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                <input type="hidden" name="id" value="<?php echo $edit_course['id'] ?? 0; ?>">
                <div class="input-group">
                    <div class="input-label">Course Name</div>
                    <input type="text" name="course_name" value="<?php echo htmlspecialchars($edit_course['course_name'] ?? ''); ?>" placeholder="e.g. Bachelor of Science in Information Technology" required>
                </div>
                <div class="input-group">
                    <div class="input-label">Course Alias</div>
                    <input type="text" name="course_alias" value="<?php echo htmlspecialchars($edit_course['course_alias'] ?? ''); ?>" placeholder="e.g. BSIT" required>
                </div>
                <div class="input-group">
                    <div class="input-label">Department</div>
                    <select name="dept_id" required style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        <option value="" disabled selected>Select Department</option>
                        <?php
                        $depts = $conn->query("SELECT * FROM departments ORDER BY dept_alias ASC");
                        while ($d = $depts->fetch_assoc()):
                            $selected = (isset($edit_course) && $edit_course['dept_id'] == $d['id']) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($d['dept_alias']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" name="save_course" class="login-btn" style="grid-column: 1 / -1;"><?php echo $edit_course ? 'Update' : 'Add'; ?> Course</button>
                <?php if ($edit_course): ?>
                    <a href="manage_courses.php" class="nav-link-btn" style="grid-column: 1 / -1; text-align:center; background:var(--text-muted); color:white;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-list"></i> Course List</h3>
            <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Alias</th>
                        <th>Department</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $courses = $conn->query("SELECT c.*, d.dept_alias FROM courses c JOIN departments d ON c.dept_id = d.id ORDER BY c.course_name ASC");
                    while ($row = $courses->fetch_assoc()):
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['course_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['course_alias']); ?></td>
                        <td><span class="category-badge"><?php echo htmlspecialchars($row['dept_alias']); ?></span></td>
                        <td style="text-align:right;">
                            <a href="manage_courses.php?edit=<?php echo $row['id']; ?>" class="btn-action" style="background:#eef2ff; color:#4338ca;"><i class="fas fa-edit"></i></a>
                            <a href="manage_courses.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this course?')"><i class="fas fa-trash-alt"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>
</div>
</body>
<script>
    function toggleTheme() {
        const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('theme', t);
        updateThemeIcon(t);
    }
    function updateThemeIcon(t) {
        const icon = document.querySelector('#themeBtn i');
        if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeIcon(saved);
</script>
</html>