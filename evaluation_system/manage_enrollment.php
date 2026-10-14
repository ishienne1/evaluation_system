<?php
session_start();
include 'db_connect.php';
if (isset($_SESSION['role']) && $_SESSION['role'] === 'faculty') {
    $cid = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    header("Location: faculty_enrollment.php" . ($cid > 0 ? "?class_id=$cid" : ""));
    exit();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { 
    header("Location: index.php"); 
    exit(); 
}

if (!isset($_GET['class_id'])) {
    header("Location: manage_classes.php");
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get Class Details
$class_details_stmt = $conn->prepare(
    "SELECT u.full_name, s.subject_code, p.semester, p.school_year
     FROM classes c
     JOIN users u ON c.faculty_id = u.id
     JOIN subjects s ON c.subject_id = s.id
     JOIN academic_periods p ON c.period_id = p.id
     WHERE c.id = ?"
);
$class_details_stmt->bind_param("i", $class_id);
$class_details_stmt->execute();
$class_details = $class_details_stmt->get_result()->fetch_assoc();

if (!$class_details) {
    header("Location: manage_classes.php?error=notfound");
    exit();
}

// Handle Add Enrollment
if (isset($_POST['enroll_student'])) {
    $student_id = $_POST['student_id'];

    // Check if already enrolled
    $check = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?");
    $check->bind_param("ii", $student_id, $class_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO enrollments (student_id, class_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $student_id, $class_id);
        $stmt->execute();
    }
    header("Location: manage_enrollment.php?class_id=$class_id&msg=enrolled");
    exit();
}

// Handle Remove Enrollment
if (isset($_GET['unenroll'])) {
    $enrollment_id = (int)$_GET['unenroll'];
    $stmt = $conn->prepare("DELETE FROM enrollments WHERE id = ? AND class_id = ?");
    $stmt->bind_param("ii", $enrollment_id, $class_id);
    $stmt->execute();
    header("Location: manage_enrollment.php?class_id=$class_id&msg=unenrolled");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Enrollment</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php $active_page = 'classes'; include 'admin_sidebar.php'; ?>

    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <div>
                <h2 style="color:var(--text-main);"><i class="fas fa-user-check" style="color:#0d2158;"></i> Manage Enrollment</h2>
                <p style="color:var(--text-muted); font-size:13px; margin:4px 0 0;">
                    Class: <strong><?php echo htmlspecialchars($class_details['subject_code'] . ' - ' . $class_details['full_name']); ?></strong>
                    (<?php echo htmlspecialchars($class_details['semester'] . ' ' . $class_details['school_year']); ?>)
                </p>
            </div>
            <a href="manage_classes.php" class="nav-link-btn" style="background:var(--text-muted); color:white;">← Back to Classes</a>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'enrolled'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Student enrolled successfully.</div>
            <?php elseif ($_GET['msg'] === 'unenrolled'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Student unenrolled.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-user-plus"></i> Enroll Student</h3>
            <form method="POST" style="display:flex; gap:15px; align-items:flex-end;">
                <div class="input-group" style="flex-grow:1; margin:0;">
                    <div class="input-label">Select Student</div>
                    <select name="student_id" required>
                        <option value="" disabled selected>-- Select a student to enroll --</option>
                        <?php
                        // Get students not yet enrolled in this class
                        $students = $conn->prepare(
                            "SELECT id, full_name, username FROM users 
                             WHERE role='student' AND id NOT IN (SELECT student_id FROM enrollments WHERE class_id = ?)
                             ORDER BY full_name ASC"
                        );
                        $students->bind_param("i", $class_id);
                        $students->execute();
                        $result = $students->get_result();
                        while($s = $result->fetch_assoc()) {
                            echo "<option value='{$s['id']}'>".htmlspecialchars($s['full_name'] . ' (' . $s['username'] . ')')."</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" name="enroll_student" class="login-btn" style="width:auto; padding:12px 30px;">Enroll Student</button>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-list-ul"></i> Enrolled Students List</h3>
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $enrolled = $conn->prepare(
                        "SELECT e.id as enrollment_id, u.full_name, u.username
                         FROM enrollments e
                         JOIN users u ON e.student_id = u.id
                         WHERE e.class_id = ?
                         ORDER BY u.full_name ASC"
                    );
                    $enrolled->bind_param("i", $class_id);
                    $enrolled->execute();
                    $result = $enrolled->get_result();
                    if ($result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                        <td style="text-align:right;">
                            <a href="manage_enrollment.php?class_id=<?php echo $class_id; ?>&unenroll=<?php echo $row['enrollment_id']; ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to unenroll this student?');">
                                <i class="fas fa-user-minus"></i> Unenroll
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="3" style="text-align:center; padding:30px; color:var(--text-muted);">
                            No students are enrolled in this class yet.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
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
</body>
</html>