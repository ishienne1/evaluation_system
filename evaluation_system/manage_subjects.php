<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin', 'dean'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$dept_id = $_SESSION['dept_id'] ?? null;

if (isset($_POST['save_subject'])) {
    $id = (int)$_POST['id'];
    $code = trim($_POST['subject_code']);
    $desc = trim($_POST['subject_description']);
    $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
    $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $year_level = trim($_POST['year_level']);
    $section = trim($_POST['section']);
    
    if ($role === 'admin' || $role === 'system admin') {
        $assign_dept_id = !empty($_POST['dept_id']) ? (int)$_POST['dept_id'] : null;
        if ($course_id && !$assign_dept_id) {
            $c_res = $conn->query("SELECT dept_id FROM courses WHERE id = $course_id LIMIT 1");
            if ($c_res && $c_row = $c_res->fetch_assoc()) {
                $assign_dept_id = (int)$c_row['dept_id'];
            }
        }
    } else {
        $assign_dept_id = (int)($dept_id ?? 0);
    }

    if ($id > 0) { // Update
        $check_stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ? AND id != ?");
        $check_stmt->bind_param("si", $code, $id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            header("Location: manage_subjects.php?error=exists&edit=$id"); exit();
        }
        $stmt = $conn->prepare("UPDATE subjects SET subject_code = ?, subject_description = ?, dept_id = ?, course_id = ?, faculty_id = ?, year_level = ?, section = ? WHERE id = ?");
        $stmt->bind_param("ssiiissi", $code, $desc, $assign_dept_id, $course_id, $faculty_id, $year_level, $section, $id);
        $msg = 'updated';
    } else { // Insert
        $check_stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ?");
        $check_stmt->bind_param("s", $code);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            header("Location: manage_subjects.php?error=exists"); exit();
        }
        $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_description, dept_id, course_id, faculty_id, year_level, section) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiiiss", $code, $desc, $assign_dept_id, $course_id, $faculty_id, $year_level, $section);
        $msg = 'added';
    }
    
    if ($stmt->execute()) {
        $subject_id = ($id > 0) ? $id : $conn->insert_id;
        // Sync class assignment for active period if faculty assigned
        if ($faculty_id) {
            $active_period = $conn->query("SELECT id FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
            $period_id = $active_period['id'] ?? 0;
            if ($period_id > 0) {
                $conn->query("INSERT INTO classes (faculty_id, subject_id, period_id) VALUES ($faculty_id, $subject_id, $period_id) ON DUPLICATE KEY UPDATE faculty_id = $faculty_id");
            }
        }
        header("Location: manage_subjects.php?msg=$msg");
    } else {
        header("Location: manage_subjects.php?error=failed");
    }
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($role == 'admin' || $role == 'system admin') {
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ? AND dept_id = ?");
        $stmt->bind_param("ii", $id, $dept_id);
    }
    $stmt->execute();
    header("Location: manage_subjects.php?msg=deleted");
    exit();
}

// Fetch for editing
$edit_subject = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_subject = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php 
        if ($role == 'admin' || $role == 'system admin') {
            $active_page = 'classes'; 
            include 'admin_sidebar.php'; 
        } else {
            $active_page_dean = 'subjects'; 
            include 'dean_sidebar.php'; 
        }
        ?>

        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-book-open" style="color:var(--accent);"></i> Manage Subjects
                </h2>
                <span style="color:var(--text-muted); font-size:14px;">
                    <i class="fas fa-clock"></i> <?php echo date('l, F j, Y'); ?>
                </span>
            </div>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Subject added successfully.</div>
                <?php elseif ($_GET['msg'] === 'updated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Subject updated successfully.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Subject deleted.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'exists'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> A subject with this code already exists.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'failed'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> Failed to save subject. Please try again.</div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-bottom:20px;"><i class="fas fa-plus-circle"></i> <?php echo $edit_subject ? 'Edit' : 'Add New'; ?> Subject</h3>
                <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <input type="hidden" name="id" value="<?php echo $edit_subject['id'] ?? 0; ?>">
                    <div class="input-group">
                        <div class="input-label">Subject Code</div>
                        <input type="text" name="subject_code" value="<?php echo htmlspecialchars($edit_subject['subject_code'] ?? ''); ?>" placeholder="e.g. IT101" required>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Subject Description</div>
                        <input type="text" name="subject_description" value="<?php echo htmlspecialchars($edit_subject['subject_description'] ?? ''); ?>" placeholder="e.g. Intro to Computing" required>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Program / Course</div>
                        <select name="course_id" id="subject_course_id" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="">-- Select Program / Course --</option>
                            <?php
                            $c_sql = "SELECT c.id, c.course_alias, c.course_name, c.dept_id FROM courses c";
                            if ($role == 'dean' && $dept_id) {
                                $c_sql .= " WHERE c.dept_id = " . (int)$dept_id;
                            }
                            $c_sql .= " ORDER BY c.course_alias ASC";
                            $courses_res = $conn->query($c_sql);
                            while($c = $courses_res->fetch_assoc()) {
                                $selected = (isset($edit_subject) && $edit_subject['course_id'] == $c['id']) ? 'selected' : '';
                                echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}' {$selected}>{$c['course_alias']} - {$c['course_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Assigned Teacher (Faculty)</div>
                        <select name="faculty_id" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="">-- Select Instructor --</option>
                            <?php
                            $f_sql = "SELECT u.id, u.full_name, c.course_alias FROM users u LEFT JOIN courses c ON u.course_id = c.id WHERE u.role='faculty'";
                            if ($role == 'dean' && $dept_id) {
                                $f_sql .= " AND u.dept_id = " . (int)$dept_id;
                            }
                            $f_sql .= " ORDER BY u.full_name ASC";
                            $facs = $conn->query($f_sql);
                            while($f = $facs->fetch_assoc()) {
                                $selected = (isset($edit_subject) && $edit_subject['faculty_id'] == $f['id']) ? 'selected' : '';
                                $prog = !empty($f['course_alias']) ? " ({$f['course_alias']})" : "";
                                echo "<option value='{$f['id']}' $selected>" . htmlspecialchars($f['full_name'] . $prog) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Year Level</div>
                        <select name="year_level" required style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="1st Year" <?php echo (isset($edit_subject) && $edit_subject['year_level'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2nd Year" <?php echo (isset($edit_subject) && $edit_subject['year_level'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3rd Year" <?php echo (isset($edit_subject) && ($edit_subject['year_level'] == '3rd Year' || empty($edit_subject['year_level']))) ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4th Year" <?php echo (isset($edit_subject) && $edit_subject['year_level'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Section of Students</div>
                        <input type="text" name="section" value="<?php echo htmlspecialchars($edit_subject['section'] ?? '1-A'); ?>" placeholder="e.g. 1-A, 2-B, Section A" required>
                    </div>
                    <?php if($role == 'admin' || $role == 'system admin'): ?>
                    <div class="input-group" style="grid-column: 1 / -1;">
                        <div class="input-label">Department</div>
                        <select name="dept_id" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                            <option value="">-- Auto-detect from Program --</option>
                            <?php
                            $depts = $conn->query("SELECT * FROM departments ORDER BY dept_name ASC");
                            while($d = $depts->fetch_assoc()) {
                                $selected = (isset($edit_subject) && $edit_subject['dept_id'] == $d['id']) ? 'selected' : '';
                                echo "<option value='{$d['id']}' $selected>{$d['dept_alias']} - {$d['dept_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div style="grid-column: 1 / -1; display:flex; gap:10px;">
                        <button type="submit" name="save_subject" class="login-btn"><?php echo $edit_subject ? 'Update Subject' : 'Add Subject'; ?></button>
                        <?php if ($edit_subject): ?>
                            <a href="manage_subjects.php" class="nav-link-btn" style="background:var(--text-muted); color:white;">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Subject List -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                    <h3 style="margin:0;"><i class="fas fa-list-ul"></i> Subject List</h3>
                    <span class="category-badge" style="font-size:13px;">Curriculum Overview</span>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Description</th>
                            <th>Program / Course</th>
                            <th>Teacher / Instructor</th>
                            <th>Year Level</th>
                            <th>Section</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query_sql = "SELECT s.*, u.full_name as teacher_name, d.dept_alias, c.course_alias 
                                      FROM subjects s 
                                      LEFT JOIN users u ON s.faculty_id = u.id 
                                      LEFT JOIN departments d ON s.dept_id = d.id
                                      LEFT JOIN courses c ON s.course_id = c.id";
                        if ($role == 'dean' && $dept_id) {
                            $query_sql .= " WHERE s.dept_id = " . (int)$dept_id;
                        }
                        $query_sql .= " ORDER BY c.course_alias ASC, s.subject_code ASC";
                        
                        $res = $conn->query($query_sql);
                        if ($res && $res->num_rows > 0) {
                            while($row = $res->fetch_assoc()) {
                                $teacher = !empty($row['teacher_name']) ? $row['teacher_name'] : 'Unassigned';
                                $prog = !empty($row['course_alias']) ? $row['course_alias'] : 'General';
                                $yl = !empty($row['year_level']) ? $row['year_level'] : 'N/A';
                                $sec = !empty($row['section']) ? $row['section'] : 'N/A';
                                echo "<tr>";
                                echo "<td><strong>" . htmlspecialchars($row['subject_code']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['subject_description']) . "</td>";
                                echo "<td><span class='category-badge' style='background:rgba(99,102,241,0.1); color:#4f46e5; font-weight:700;'><i class='fas fa-graduation-cap'></i> " . htmlspecialchars($prog) . "</span></td>";
                                echo "<td><strong>" . htmlspecialchars($teacher) . "</strong></td>";
                                echo "<td><span class='category-badge'>" . htmlspecialchars($yl) . "</span></td>";
                                echo "<td><span class='category-badge' style='background:#eef2ff; color:#4338ca;'>" . htmlspecialchars($sec) . "</span></td>";
                                echo "<td style='text-align:right;'>
                                        <a href='manage_subjects.php?edit={$row['id']}' class='btn-action' style='background:#eef2ff; color:#4338ca;' title='Edit Subject'><i class='fas fa-edit'></i></a>
                                        <a href='manage_subjects.php?delete={$row['id']}' class='btn-action btn-delete' onclick='return confirm(\"Delete subject?\");' title='Delete Subject'><i class='fas fa-trash-alt'></i></a>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' style='text-align:center; padding:30px; color:var(--text-muted);'>No subjects found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            <footer>
                <?php if ($role == 'admin' || $role == 'system admin'): ?>
                    <i class="fas fa-shield-alt"></i> Admin Panel &middot; <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                <?php else: ?>
                    <i class="fas fa-university"></i> Dean Portal &middot; <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                <?php endif; ?>
            </footer>
        </main>
    </div>
    <script>
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
            updateThemeIcon(targetTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeBtn i');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>
</body>
</html>