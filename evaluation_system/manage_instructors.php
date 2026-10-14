<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin', 'dean'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$dept_id_session = $_SESSION['dept_id'] ?? null;

// Handle Impersonation
if (isset($_GET['impersonate']) && in_array($role, ['admin', 'system admin'])) {
    $impersonate_id = (int)$_GET['impersonate'];
    $user_to_impersonate_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
    $user_to_impersonate_stmt->bind_param("i", $impersonate_id);
    $user_to_impersonate_stmt->execute();
    $user_to_impersonate = $user_to_impersonate_stmt->get_result()->fetch_assoc();

    if ($user_to_impersonate) {
        $_SESSION['original_admin_id'] = $_SESSION['id'];
        $_SESSION['original_admin_school'] = $_SESSION['school'] ?? null;
        $_SESSION['id'] = $user_to_impersonate['id'];
        $_SESSION['role'] = $user_to_impersonate['role'];
        $_SESSION['full_name'] = $user_to_impersonate['full_name'];
        $_SESSION['username'] = $user_to_impersonate['username'];
        $_SESSION['dept_id'] = $user_to_impersonate['dept_id'];
        $_SESSION['course_id'] = $user_to_impersonate['course_id'] ?? null;
        $_SESSION['avatar'] = $user_to_impersonate['avatar'] ?? 'uploads/avatars/default.png';

        $faculty_school = !empty($user_to_impersonate['school']) ? strtolower(trim($user_to_impersonate['school'])) : null;
        if (!$faculty_school) {
            $dept_id_val = (int)($user_to_impersonate['dept_id'] ?? 0);
            $faculty_school = ($dept_id_val <= 4 && $dept_id_val > 0) ? 'isap' : 'mcnp';
        }
        $_SESSION['school'] = $faculty_school;

        header("Location: dashboard_faculty.php");
        exit();
    }
}

// Handle Template Download
if (isset($_GET['download_template']) && $role == 'admin') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=faculty_template.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Employee ID', 'Full Name', 'Email', 'Department Alias', 'Program Alias'));
    fputcsv($output, array('EMP-BSIT-04', 'Prof. Juan Dela Cruz', 'juan.cruz@isap.edu.ph', 'CITE', 'BSIT'));
    fclose($output);
    exit();
}

// Handle Bulk Upload
if (isset($_POST['bulk_upload']) && isset($_FILES['csv_file']) && $role == 'admin') {
    $file = $_FILES['csv_file']['tmp_name'];
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK && is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); // Skip header
            $success_count = 0;
            $error_count = 0;

            $depts = [];
            $res = $conn->query("SELECT id, UPPER(dept_alias) as da FROM departments");
            while ($row = $res->fetch_assoc()) {
                $depts[trim($row['da'])] = $row['id'];
            }

            $courses = [];
            $c_res = $conn->query("SELECT id, UPPER(course_alias) as ca, dept_id FROM courses");
            while ($row = $c_res->fetch_assoc()) {
                $courses[trim($row['ca'])] = [
                    'id' => $row['id'],
                    'dept_id' => $row['dept_id']
                ];
            }

            $default_pass = password_hash('123456', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, role, password, dept_id, course_id, school, status) VALUES (?, ?, ?, 'faculty', ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), dept_id=VALUES(dept_id), course_id=VALUES(course_id)");

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 3) continue; // Skip incomplete rows

                $emp_id     = trim($data[0] ?? '');
                $full_name  = trim($data[1] ?? '');
                $email      = trim($data[2] ?? '');
                $dept_alias = strtoupper(trim($data[3] ?? ''));
                $prog_alias = strtoupper(trim($data[4] ?? ''));

                if (empty($emp_id) || empty($full_name) || empty($email)) continue;

                $course_id = $courses[$prog_alias]['id'] ?? null;
                $dept_id   = $courses[$prog_alias]['dept_id'] ?? ($depts[$dept_alias] ?? null);
                $school    = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

                $stmt->bind_param("ssssiis", $emp_id, $full_name, $email, $default_pass, $dept_id, $course_id, $school);
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            fclose($handle);
            header("Location: manage_instructors.php?msg=bulk_success&added=$success_count&errors=$error_count");
            exit();
        }
    }
    header("Location: manage_instructors.php?msg=bulk_error");
    exit();
}

$msg = '';

// Handle Impersonation
if (isset($_GET['impersonate']) && $role == 'admin') {
    $impersonate_id = (int)$_GET['impersonate'];
    $user_to_impersonate_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
    $user_to_impersonate_stmt->bind_param("i", $impersonate_id);
    $user_to_impersonate_stmt->execute();
    $user_to_impersonate = $user_to_impersonate_stmt->get_result()->fetch_assoc();

    if ($user_to_impersonate) {
        $_SESSION['original_admin_id'] = $_SESSION['id'];
        $_SESSION['original_admin_school'] = $_SESSION['school'] ?? null;
        $_SESSION['id'] = $user_to_impersonate['id'];
        $_SESSION['role'] = $user_to_impersonate['role'];
        $_SESSION['full_name'] = $user_to_impersonate['full_name'];
        $_SESSION['username'] = $user_to_impersonate['username'];
        $_SESSION['dept_id'] = $user_to_impersonate['dept_id'];
        $_SESSION['course_id'] = $user_to_impersonate['course_id'] ?? null;
        $_SESSION['avatar'] = $user_to_impersonate['avatar'] ?? 'uploads/avatars/default.png';
        $_SESSION['school'] = $user_to_impersonate['school'] ?? (($user_to_impersonate['dept_id'] <= 4) ? 'isap' : 'mcnp');

        header("Location: dashboard_faculty.php");
        exit();
    }
}

// Handle Deactivation
if (isset($_GET['deactivate']) && in_array($role, ['admin', 'system admin'])) {
    $id = (int)$_GET['deactivate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'faculty'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_instructors.php?msg=deactivated");
    exit();
}

// Handle Activation
if (isset($_GET['activate']) && in_array($role, ['admin', 'system admin'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'faculty'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_instructors.php?msg=activated");
    exit();
}

// Handle Edit Faculty
if (isset($_POST['edit_faculty']) && in_array($role, ['admin', 'system admin'])) {
    $edit_id   = (int)$_POST['edit_id'];
    $emp_id    = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $dept_id   = !empty($_POST['dept_id']) ? (int)$_POST['dept_id'] : null;
    $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $status    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $school    = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

    $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, dept_id = ?, course_id = ?, school = ?, status = ? WHERE id = ? AND role = 'faculty'");
    $stmt->bind_param("sssiissi", $emp_id, $full_name, $email, $dept_id, $course_id, $school, $status, $edit_id);
    if ($stmt->execute()) {
        header("Location: manage_instructors.php?msg=updated");
        exit();
    }
}

// Handle Reset Password
if (isset($_GET['reset_password']) && in_array($role, ['admin', 'system admin'])) {
    $id = (int)$_GET['reset_password'];
    $new_pass = password_hash('123456', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ? AND role = 'faculty'");
    $stmt->bind_param("si", $new_pass, $id);
    $stmt->execute();
    header("Location: manage_instructors.php?msg=reset_password");
    exit();
}

// Handle Delete
if (isset($_GET['delete']) && in_array($role, ['admin', 'system admin'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'faculty'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_instructors.php?msg=deleted");
    exit();
}

// Handle Add Instructor
if (isset($_POST['add_instructor']) && $role == 'admin') {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $dept_id    = !empty($_POST['dept_id']) ? (int)$_POST['dept_id'] : null;
    $course_id  = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $full_name  = "$first_name $last_name";
    $password   = password_hash('123456', PASSWORD_DEFAULT);
    $school     = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

    // Check duplicate username or email
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $msg = 'error_user_exists';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO users (username, password, role, first_name, last_name, full_name, email, dept_id, course_id, school, status)
             VALUES (?, ?, 'faculty', ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param("ssssssiiss", $username, $password, $first_name, $last_name, $full_name, $email, $dept_id, $course_id, $school);
        $stmt->execute();
        header("Location: manage_instructors.php?msg=added");
        exit();
    }
}

// Filter parameters
$filter_dept_id   = isset($_GET['dept_id']) && $_GET['dept_id'] !== '' ? (int)$_GET['dept_id'] : null;
$filter_course_id = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
$filter_status    = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;

// Build Query
$where = ["u.role = 'faculty'", "u.status != 'pending'"];
if ($filter_dept_id) {
    $where[] = "u.dept_id = " . (int)$filter_dept_id;
}
if ($filter_course_id) {
    $where[] = "u.course_id = " . (int)$filter_course_id;
}
if ($filter_status) {
    $where[] = "u.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if ($role == 'dean' && $dept_id_session) {
    $where[] = "u.dept_id = " . (int)$dept_id_session;
}

$count_sql = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where);
$total_count = $conn->query($count_sql)->fetch_row()[0];

$sql = "SELECT u.*, d.dept_alias, d.dept_name, c.course_alias, c.course_name
        FROM users u
        LEFT JOIN departments d ON u.dept_id = d.id
        LEFT JOIN courses c ON u.course_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.course_alias ASC, u.full_name ASC";
$instructors = $conn->query($sql);
?>
<!DOCTYPE html>
<html>

<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php
        if ($role == 'admin' || $role == 'system admin') {
            $active_page = 'faculties';
            include 'admin_sidebar.php';
        } else {
            $active_page_dean = 'instructors';
            include 'dean_sidebar.php';
        }
        ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap: wrap; gap: 10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-chalkboard-teacher" style="color:var(--accent);"></i> Manage Instructors
                </h2>
                <div style="display:flex; gap: 10px;">
                    <?php if ($role == 'admin'): ?>
                        <button onclick="document.getElementById('addFacultyModal').style.display='flex'" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-user-plus"></i> Add Faculty</button>
                        <button onclick="document.getElementById('bulkUploadModal').style.display='flex'" class="btn-action" style="background:var(--accent); color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-file-csv"></i> Bulk Upload</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bulk Upload Modal -->
            <?php if ($role == 'admin'): ?>
                <div id="bulkUploadModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                    <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:420px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                            <h3 class="modal-title" style="margin:0;"><i class="fas fa-upload"></i> Bulk Upload Faculty</h3>
                            <button type="button" class="modal-close-btn" onclick="document.getElementById('bulkUploadModal').style.display='none'" aria-label="Close modal">&times;</button>
                        </div>
                        <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Upload a CSV file containing faculty records. <br><a href="manage_instructors.php?download_template=1" style="color:var(--accent); font-weight:bold;">Download CSV Template</a></p>
                        <form method="POST" enctype="multipart/form-data">
                            <div style="margin-bottom:20px;">
                                <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:10px; border:1px dashed var(--border-color); border-radius:8px; background:var(--bg-color); color:var(--text-main);">
                            </div>
                            <button type="submit" name="bulk_upload" style="width:100%; background:var(--accent); color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-cloud-upload-alt"></i> Upload CSV</button>
                        </form>
                    </div>
                </div>

                <!-- Add Faculty Modal -->
                <div id="addFacultyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                    <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                            <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-plus"></i> Add Faculty Member</h3>
                            <button type="button" class="modal-close-btn" onclick="document.getElementById('addFacultyModal').style.display='none'" aria-label="Close modal">&times;</button>
                        </div>
                        <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                            <div class="input-group" style="grid-column:1 / -1; margin:0;">
                                <div class="input-label">Employee / School ID</div>
                                <input type="text" name="username" placeholder="e.g. EMP-BSIT-04" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">First Name</div>
                                <input type="text" name="first_name" placeholder="First Name" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Last Name</div>
                                <input type="text" name="last_name" placeholder="Last Name" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="grid-column:1 / -1; margin:0;">
                                <div class="input-label">Email Address</div>
                                <input type="email" name="email" placeholder="faculty@school.edu.ph" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Department</div>
                                <select name="dept_id" id="modal_dept_id" required onchange="filterModalCourses()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                    <option value="">-- Select Dept --</option>
                                    <?php
                                    $all_depts = $conn->query("SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC");
                                    while ($d = $all_depts->fetch_assoc()) {
                                        echo "<option value='{$d['id']}'>{$d['dept_alias']} - {$d['dept_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Program / Course</div>
                                <select name="course_id" id="modal_course_id" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                    <option value="">-- Select Program --</option>
                                    <?php
                                    $all_courses = $conn->query("SELECT id, course_name, course_alias, dept_id FROM courses ORDER BY course_alias ASC");
                                    while ($c = $all_courses->fetch_assoc()) {
                                        echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}'>{$c['course_alias']} - {$c['course_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div style="grid-column:1 / -1; font-size:12px; color:var(--text-muted);">
                                <i class="fas fa-info-circle" style="color:var(--accent);"></i> Default password will automatically be set to <strong>123456</strong>.
                            </div>
                            <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                                <button type="button" onclick="document.getElementById('addFacultyModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                                <button type="submit" name="add_instructor" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-check"></i> Save Faculty</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Edit Faculty Modal -->
            <?php if (in_array($role, ['admin', 'system admin'])): ?>
                <div id="editFacultyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                    <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:520px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                            <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-edit"></i> Edit Faculty Details & Status</h3>
                            <button type="button" class="modal-close-btn" onclick="closeEditFacultyModal()" aria-label="Close modal">&times;</button>
                        </div>
                        <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                            <input type="hidden" name="edit_id" id="edit_fac_id">
                            <div class="input-group" style="grid-column:1 / -1; margin:0;">
                                <div class="input-label">Full Name</div>
                                <input type="text" name="full_name" id="edit_fac_name" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Employee / School ID</div>
                                <input type="text" name="username" id="edit_fac_username" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Email Address</div>
                                <input type="email" name="email" id="edit_fac_email" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Department</div>
                                <select name="dept_id" id="edit_fac_dept" required onchange="filterEditModalCourses()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                    <option value="">-- Select Dept --</option>
                                    <?php
                                    $all_depts_q = $conn->query("SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC");
                                    while ($d = $all_depts_q->fetch_assoc()) {
                                        echo "<option value='{$d['id']}'>{$d['dept_alias']} - {$d['dept_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="input-group" style="margin:0;">
                                <div class="input-label">Program / Course</div>
                                <select name="course_id" id="edit_fac_course" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                    <option value="">-- Select Program --</option>
                                    <?php
                                    $all_courses_q = $conn->query("SELECT id, course_name, course_alias, dept_id FROM courses ORDER BY course_alias ASC");
                                    while ($c = $all_courses_q->fetch_assoc()) {
                                        echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}'>{$c['course_alias']} - {$c['course_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="input-group" style="grid-column:1 / -1; margin:0;">
                                <div class="input-label">Account Status</div>
                                <select name="status" id="edit_fac_status" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main); font-weight:700;">
                                    <option value="active" style="color:#059669; font-weight:700;">Active (Can Login, Teach, & Be Evaluated)</option>
                                    <option value="inactive" style="color:#dc2626; font-weight:700;">Inactive (Deactivated / Blocked from Login)</option>
                                </select>
                            </div>
                            <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                                <button type="button" onclick="closeEditFacultyModal()" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                                <button type="submit" name="edit_faculty" style="background:#3b82f6; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-save"></i> Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Flash Messages -->
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Faculty account created successfully! Default password is <strong>123456</strong>.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Faculty account deleted.</div>
                <?php elseif ($_GET['msg'] === 'activated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Faculty account activated.</div>
                <?php elseif ($_GET['msg'] === 'deactivated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Faculty account deactivated.</div>
                <?php elseif ($_GET['msg'] === 'reset_password'): ?>
                    <div class="flash-success"><i class="fas fa-key"></i> Password has been reset to default (<strong>123456</strong>).</div>
                <?php elseif ($_GET['msg'] === 'bulk_success'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Bulk upload complete! Successfully processed <strong><?php echo (int)($_GET['added'] ?? 0); ?></strong> records (Errors: <?php echo (int)($_GET['errors'] ?? 0); ?>).</div>
                <?php elseif ($_GET['msg'] === 'bulk_error'): ?>
                    <div class="flash-success" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;"><i class="fas fa-exclamation-circle"></i> Error uploading CSV file. Please check the file format.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($msg === 'error_user_exists'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> An instructor with this Employee ID or Email already exists. Please check and try again.</div>
            <?php endif; ?>

            <!-- Department & Program Filter Card -->
            <div class="card">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Faculty</h3>
                <form method="GET" id="instructorFilterForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end;">
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Department</div>
                        <select name="dept_id" id="filter_dept" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Departments</option>
                            <?php
                            $dept_sql = "SELECT id, dept_name, dept_alias FROM departments";
                            if ($role == 'dean' && $dept_id_session) {
                                $dept_sql .= " WHERE id = " . (int)$dept_id_session;
                            }
                            $dept_sql .= " ORDER BY dept_alias ASC";
                            $depts = $conn->query($dept_sql);
                            while ($d = $depts->fetch_assoc()) {
                                $sel = ($filter_dept_id === (int)$d['id']) ? 'selected' : '';
                                echo "<option value='{$d['id']}' {$sel}>{$d['dept_alias']} - {$d['dept_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Program / Course</div>
                        <select name="course_id" id="filter_course" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Programs / Courses</option>
                            <?php
                            $c_sql = "SELECT id, course_name, course_alias FROM courses";
                            if ($role == 'dean' && $dept_id_session) {
                                $c_sql .= " WHERE dept_id = " . (int)$dept_id_session;
                            } elseif ($filter_dept_id) {
                                $c_sql .= " WHERE dept_id = " . (int)$filter_dept_id;
                            }
                            $c_sql .= " ORDER BY course_alias ASC";
                            $courses_res = $conn->query($c_sql);
                            while ($c = $courses_res->fetch_assoc()) {
                                $sel = ($filter_course_id === (int)$c['id']) ? 'selected' : '';
                                echo "<option value='{$c['id']}' {$sel}>{$c['course_alias']} - {$c['course_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Status</div>
                        <select name="status" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <?php if ($filter_dept_id || $filter_course_id || $filter_status): ?>
                            <a href="manage_instructors.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; display:inline-block; border-radius:8px;">Reset Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Instructor List Card -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-chalkboard-teacher"></i> Instructor List</h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php echo $total_count; ?> total instructors
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Employee ID</th>
                                <th>Name</th>
                                <th style="text-align:center; width: 75px;">School</th>
                                <th style="text-align:center; width: 85px;">Department</th>
                                <th style="text-align:center; width: 85px;">Course</th>
                                <th style="width: 170px;">Email</th>
                                <th style="text-align:center; width: 80px;">Status</th>
                                <th style="text-align:right; width: 145px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($instructors && $instructors->num_rows > 0):
                                while ($row = $instructors->fetch_assoc()):
                                    $row_school = !empty($row['school']) ? strtoupper($row['school']) : (((int)$row['dept_id'] <= 4 && (int)$row['dept_id'] > 0) ? 'ISAP' : 'MCNP');
                                    $badge_bg = ($row_school === 'ISAP') ? 'rgba(122, 0, 0, 0.1)' : 'rgba(26, 47, 122, 0.1)';
                                    $badge_color = ($row_school === 'ISAP') ? '#7a0000' : '#1a2f7a';
                            ?>
                                    <tr>
                                        <td style="white-space:nowrap;"><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td style="white-space:nowrap;"><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                                        <td style="text-align:center; white-space:nowrap;"><span class="category-badge" style="background:<?php echo $badge_bg; ?>; color:<?php echo $badge_color; ?>; font-weight:800;"><?php echo $row_school; ?></span></td>
                                        <td style="text-align:center; white-space:nowrap;"><span class="category-badge" style="background:#eef2ff; color:#4338ca;"><?php echo htmlspecialchars($row['dept_alias'] ?? 'N/A'); ?></span></td>
                                        <td style="text-align:center; white-space:nowrap;"><span class="category-badge"><?php echo htmlspecialchars($row['course_alias'] ?? 'N/A'); ?></span></td>
                                        <td class="cell-email" title="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                        <td style="text-align:center; white-space:nowrap;">
                                            <?php if (in_array($role, ['admin', 'system admin'])): ?>
                                                <?php if ($row['status'] == 'active'): ?>
                                                    <a href="manage_instructors.php?deactivate=<?php echo $row['id']; ?>"
                                                        onclick="return confirm('Deactivate faculty account for <?php echo addslashes($row['full_name']); ?>? They will be locked out from logging in.')"
                                                        style="text-decoration:none;"
                                                        title="Status: Active — Click to Deactivate">
                                                        <span class="category-badge" style="background:#d1fae5; color:#065f46; cursor:pointer; font-weight:700; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:20px;">
                                                            <i class="fas fa-check-circle"></i> Active
                                                        </span>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="manage_instructors.php?activate=<?php echo $row['id']; ?>"
                                                        onclick="return confirm('Activate faculty account for <?php echo addslashes($row['full_name']); ?>? They will be allowed to log in.')"
                                                        style="text-decoration:none;"
                                                        title="Status: Inactive — Click to Activate">
                                                        <span class="category-badge" style="background:#fee2e2; color:#991b1b; cursor:pointer; font-weight:700; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:20px;">
                                                            <i class="fas fa-ban"></i> Inactive
                                                        </span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($row['status'] == 'active'): ?>
                                                    <span class="category-badge" style="background:#d1fae5; color:#065f46; font-weight:700;">Active</span>
                                                <?php else: ?>
                                                    <span class="category-badge" style="background:#fee2e2; color:#991b1b; font-weight:700;">Inactive</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <?php if (in_array($role, ['admin', 'system admin'])): ?>
                                                <button type="button" class="btn-action" style="background:#3b82f6; color:white; border:none; cursor:pointer;" onclick="openEditFacultyModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Edit Faculty Details & Status"><i class="fas fa-edit"></i></button>
                                                <?php if ($row['status'] == 'active'): ?>
                                                    <a href="manage_instructors.php?deactivate=<?php echo $row['id']; ?>" class="btn-action" style="background:#f59e0b; color:white;" title="Deactivate Account" onclick="return confirm('Deactivate instructor account?')"><i class="fas fa-user-slash"></i></a>
                                                <?php else: ?>
                                                    <a href="manage_instructors.php?activate=<?php echo $row['id']; ?>" class="btn-action" style="background:#10b981; color:white;" title="Activate Account" onclick="return confirm('Activate instructor account?')"><i class="fas fa-user-check"></i></a>
                                                <?php endif; ?>
                                                <a href="manage_instructors.php?reset_password=<?php echo $row['id']; ?>" class="btn-action" style="background:#6366f1; color:white;" title="Reset Password to 123456" onclick="return confirm('Are you sure you want to reset the password for <?php echo htmlspecialchars($row['full_name']); ?> to 123456?')"><i class="fas fa-key"></i></a>
                                                <a href="manage_instructors.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete instructor account?')"><i class="fas fa-trash-alt"></i></a>
                                                <a href="manage_instructors.php?impersonate=<?php echo $row['id']; ?>" class="btn-action" style="background:#8b5cf6; color:white;" title="Log in as this user">
                                                    <i class="fas fa-user-secret"></i>
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:12px; color:var(--text-muted);">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding:30px; color:var(--text-muted);">
                                        <i class="fas fa-inbox" style="font-size:30px; display:block; margin-bottom:8px; opacity:0.4;"></i>
                                        No instructors found for the selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function filterModalCourses() {
            const deptId = document.getElementById('modal_dept_id').value;
            const courseSelect = document.getElementById('modal_course_id');
            if (!courseSelect) return;
            const options = courseSelect.querySelectorAll('option');

            options.forEach(opt => {
                if (!opt.value) {
                    opt.style.display = '';
                    return;
                }
                const optDept = opt.getAttribute('data-dept');
                if (!deptId || optDept === deptId) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });

            const currentOpt = courseSelect.options[courseSelect.selectedIndex];
            if (currentOpt && currentOpt.style.display === 'none') {
                courseSelect.value = '';
            }

            function openEditFacultyModal(fac) {
                document.getElementById('edit_fac_id').value = fac.id;
                document.getElementById('edit_fac_name').value = fac.full_name;
                document.getElementById('edit_fac_username').value = fac.username;
                document.getElementById('edit_fac_email').value = fac.email;
                document.getElementById('edit_fac_dept').value = fac.dept_id || '';
                document.getElementById('edit_fac_status').value = fac.status || 'active';
                filterEditModalCourses();
                document.getElementById('edit_fac_course').value = fac.course_id || '';
                document.getElementById('editFacultyModal').style.display = 'flex';
            }

            function closeEditFacultyModal() {
                document.getElementById('editFacultyModal').style.display = 'none';
            }

            function filterEditModalCourses() {
                const deptId = document.getElementById('edit_fac_dept').value;
                const courseSelect = document.getElementById('edit_fac_course');
                if (!courseSelect) return;
                const options = courseSelect.querySelectorAll('option');

                options.forEach(opt => {
                    if (!opt.value) {
                        opt.style.display = '';
                        return;
                    }
                    const optDept = opt.getAttribute('data-dept');
                    if (!deptId || optDept === deptId) {
                        opt.style.display = '';
                    } else {
                        opt.style.display = 'none';
                    }
                });

                const currentOpt = courseSelect.options[courseSelect.selectedIndex];
                if (currentOpt && currentOpt.style.display === 'none') {
                    courseSelect.value = '';
                }
            }

            function toggleTheme() {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', targetTheme);
                localStorage.setItem('theme', targetTheme);
                updateThemeIcon(targetTheme);
            }

            function updateThemeIcon(theme) {
                const icon = document.querySelector('#themeBtn i');
                if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);
        }
    </script>
</body>

</html>