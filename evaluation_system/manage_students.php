<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin'])) {
    header("Location: index.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_students.php?msg=deleted");
    exit();
}

// Handle Deactivate
if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_students.php?msg=deactivated");
    exit();
}

// Handle Activate
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_students.php?msg=activated");
    exit();
}

// Handle Reset Password
if (isset($_GET['reset_password'])) {
    $id = (int)$_GET['reset_password'];
    $new_pass = password_hash('123456', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ? AND role = 'student'");
    $stmt->bind_param("si", $new_pass, $id);
    $stmt->execute();
    header("Location: manage_students.php?msg=reset_password");
    exit();
}

// Handle Template Download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_template.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('School ID', 'Full Name', 'Email', 'Department Alias', 'Course Alias'));
    fputcsv($output, array('2024-0001', 'Juan Dela Cruz', 'juan@student.mcnp.edu.ph', 'CICS', 'BSIT'));
    fclose($output);
    exit();
}

// Handle Add Student
if (isset($_POST['add_student'])) {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $dept_id    = !empty($_POST['dept_id']) ? (int)$_POST['dept_id'] : null;
    $course_id  = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $full_name  = trim("$first_name $last_name");
    $password   = password_hash('123456', PASSWORD_DEFAULT);
    $school     = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

    // Check duplicate username or email
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        header("Location: manage_students.php?msg=error_user_exists");
        exit();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO users (username, password, role, first_name, last_name, full_name, email, dept_id, course_id, school, status)
             VALUES (?, ?, 'student', ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param("ssssssiiss", $username, $password, $first_name, $last_name, $full_name, $email, $dept_id, $course_id, $school);
        $stmt->execute();
        header("Location: manage_students.php?msg=added");
        exit();
    }
}

// Handle Edit Student
if (isset($_POST['edit_student'])) {
    $id = (int)$_POST['user_id'];
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $dept_id = !empty($_POST['dept_id']) ? (int)$_POST['dept_id'] : null;
    $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $school = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

    // Check duplicate username or email excluding this user
    $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $check->bind_param("ssi", $username, $email, $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        header("Location: manage_students.php?msg=error_user_exists");
        exit();
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, dept_id = ?, course_id = ?, school = ?, status = ? WHERE id = ? AND role = 'student'");
        $stmt->bind_param("sssiissi", $username, $full_name, $email, $dept_id, $course_id, $school, $status, $id);
        $stmt->execute();
        header("Location: manage_students.php?msg=updated");
        exit();
    }
}

// Handle Bulk Upload
if (isset($_POST['bulk_upload']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK && is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); // Skip header
            $success_count = 0;
            $error_count = 0;
            
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, role, password, dept_id, course_id, school, status) VALUES (?, ?, ?, 'student', ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), dept_id=VALUES(dept_id), course_id=VALUES(course_id), school=VALUES(school)");
            
            $depts = [];
            $res = $conn->query("SELECT id, dept_alias FROM departments");
            while ($row = $res->fetch_assoc()) { $depts[strtoupper(trim($row['dept_alias']))] = $row['id']; }
            
            $courses = [];
            $res = $conn->query("SELECT id, course_alias FROM courses");
            while ($row = $res->fetch_assoc()) { $courses[strtoupper(trim($row['course_alias']))] = $row['id']; }

            $default_pass = password_hash('123456', PASSWORD_DEFAULT);

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 3) continue; // Skip incomplete rows
                
                $username = trim($data[0] ?? '');
                $full_name = trim($data[1] ?? '');
                $email = trim($data[2] ?? '');
                $dept_alias = strtoupper(trim($data[3] ?? ''));
                $course_alias = strtoupper(trim($data[4] ?? ''));
                
                if (empty($username) || empty($full_name) || empty($email)) continue;

                $dept_id = $depts[$dept_alias] ?? null;
                $course_id = $courses[$course_alias] ?? null;
                $school = ($dept_id && $dept_id <= 4) ? 'isap' : 'mcnp';

                $stmt->bind_param("ssssiis", $username, $full_name, $email, $default_pass, $dept_id, $course_id, $school);
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            fclose($handle);
            header("Location: manage_students.php?msg=bulk_success&added=$success_count&errors=$error_count");
            exit();
        }
    }
    header("Location: manage_students.php?msg=bulk_error");
    exit();
}

// Handle Impersonation
if (isset($_GET['impersonate'])) {
    $impersonate_id = (int)$_GET['impersonate'];
    $user_to_impersonate_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
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
        
        // Correctly set student school (isap / mcnp)
        $student_school = !empty($user_to_impersonate['school']) ? strtolower(trim($user_to_impersonate['school'])) : null;
        if (!$student_school) {
            $dept_id_val = (int)($user_to_impersonate['dept_id'] ?? 0);
            $student_school = ($dept_id_val <= 4 && $dept_id_val > 0) ? 'isap' : 'mcnp';
        }
        $_SESSION['school'] = $student_school;

        header("Location: dashboard_student.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php $active_page = 'students'; include 'admin_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap: wrap; gap: 10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-users" style="color:var(--accent);"></i> Manage Students
                </h2>
                <div style="display:flex; gap: 10px;">
                    <button onclick="document.getElementById('addStudentModal').style.display='flex'" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-user-plus"></i> Add Student</button>
                    <button onclick="document.getElementById('bulkUploadModal').style.display='flex'" class="btn-action" style="background:var(--accent); color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-file-csv"></i> Bulk Upload</button>
                </div>
            </div>

            <!-- Bulk Upload Modal -->
            <div id="bulkUploadModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-upload"></i> Bulk Upload Students</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('bulkUploadModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Upload a CSV file containing student records. <br><a href="manage_students.php?download_template=1" style="color:var(--accent); font-weight:bold;">Download CSV Template</a></p>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="margin-bottom:20px;">
                            <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:10px; border:1px dashed var(--border-color); border-radius:8px; background:var(--bg-color);">
                        </div>
                        <button type="submit" name="bulk_upload" style="width:100%; background:var(--accent); color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-cloud-upload-alt"></i> Upload CSV</button>
                    </form>
                </div>
            </div>

            <!-- Add Student Modal -->
            <div id="addStudentModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-plus"></i> Add New Student</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('addStudentModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Student ID Number</div>
                            <input type="text" name="username" placeholder="e.g. 2024-0001" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
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
                            <input type="email" name="email" placeholder="student@mcnp.edu.ph" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Department</div>
                            <select name="dept_id" id="modal_dept_id" required onchange="filterModalCourses()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">-- Select Dept --</option>
                                <?php
                                $all_depts = $conn->query("SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC");
                                while($d = $all_depts->fetch_assoc()) {
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
                                while($c = $all_courses->fetch_assoc()) {
                                    echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}'>{$c['course_alias']} - {$c['course_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1; font-size:12px; color:var(--text-muted);">
                            <i class="fas fa-info-circle" style="color:var(--accent);"></i> Default password will automatically be set to <strong>123456</strong>.
                        </div>
                        <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" onclick="document.getElementById('addStudentModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="add_student" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-check"></i> Save Student</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Student Modal -->
            <div id="editStudentModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-edit" style="color:var(--accent);"></i> Edit Student Account</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('editStudentModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <input type="hidden" name="user_id" id="edit_student_id">
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Student ID Number</div>
                            <input type="text" name="username" id="edit_username" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Full Name</div>
                            <input type="text" name="full_name" id="edit_full_name" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Email Address</div>
                            <input type="email" name="email" id="edit_email" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Department</div>
                            <select name="dept_id" id="edit_dept_id" required onchange="filterEditModalCourses()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">-- Select Dept --</option>
                                <?php
                                $depts_list = $conn->query("SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC");
                                while($d = $depts_list->fetch_assoc()) {
                                    echo "<option value='{$d['id']}'>{$d['dept_alias']} - {$d['dept_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Program / Course</div>
                            <select name="course_id" id="edit_course_id" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">-- Select Program --</option>
                                <?php
                                $courses_list = $conn->query("SELECT id, course_name, course_alias, dept_id FROM courses ORDER BY course_alias ASC");
                                while($c = $courses_list->fetch_assoc()) {
                                    echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}'>{$c['course_alias']} - {$c['course_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Account Status</div>
                            <select name="status" id="edit_status" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main); font-weight:600;">
                                <option value="active" style="color:#065f46; font-weight:bold;">Active (Can Login & Evaluate)</option>
                                <option value="inactive" style="color:#991b1b; font-weight:bold;">Inactive (Account Locked / Disabled)</option>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" onclick="document.getElementById('editStudentModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="edit_student" style="background:var(--accent); color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-save"></i> Update Student</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account created successfully! Default password is <strong>123456</strong>.</div>
                <?php elseif ($_GET['msg'] === 'updated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account updated successfully!</div>
                <?php elseif ($_GET['msg'] === 'error_user_exists'): ?>
                    <div class="flash-error" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;"><i class="fas fa-exclamation-circle"></i> Student ID or email already exists!</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account deleted.</div>
                <?php elseif ($_GET['msg'] === 'activated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account activated.</div>
                <?php elseif ($_GET['msg'] === 'deactivated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Student account deactivated.</div>
                <?php elseif ($_GET['msg'] === 'reset_password'): ?>
                    <div class="flash-success"><i class="fas fa-key"></i> Password has been reset to default (<strong>123456</strong>).</div>
                <?php elseif ($_GET['msg'] === 'bulk_success'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Bulk upload complete! Added/Updated: <strong><?php echo (int)($_GET['added'] ?? 0); ?></strong>, Errors: <strong><?php echo (int)($_GET['errors'] ?? 0); ?></strong>.</div>
                <?php elseif ($_GET['msg'] === 'bulk_error'): ?>
                    <div class="flash-success" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;"><i class="fas fa-exclamation-circle"></i> Error uploading CSV file. Please check the file format.</div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Department & Program Filter Card -->
            <div class="card">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Students</h3>
                <?php
                $filter_dept_id = isset($_GET['dept_id']) && $_GET['dept_id'] !== '' ? (int)$_GET['dept_id'] : null;
                $filter_course_id = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
                $filter_status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
                ?>
                <form method="GET" id="studentFilterForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end;">
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Department</div>
                        <select name="dept_id" id="filter_dept" onchange="updateCourses(this.value); this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Departments</option>
                            <?php
                            $depts = $conn->query("SELECT * FROM departments ORDER BY dept_alias ASC");
                            while($d = $depts->fetch_assoc()) {
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
                            if ($filter_dept_id) {
                                $c_sql .= " WHERE dept_id = " . $filter_dept_id;
                            }
                            $c_sql .= " ORDER BY course_alias ASC";
                            $courses_res = $conn->query($c_sql);
                            while($c = $courses_res->fetch_assoc()) {
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
                            <a href="manage_students.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; display:inline-block; border-radius:8px;">Reset Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-user-graduate"></i> Student List</h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php 
                        $count_sql = "SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status != 'pending'";
                        if ($filter_dept_id) $count_sql .= " AND u.dept_id = " . $filter_dept_id;
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
                            <th style="width: 110px;">Student ID</th>
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
                        $students_sql = "SELECT u.*, c.course_alias, d.dept_alias FROM users u 
                                         LEFT JOIN courses c ON u.course_id = c.id 
                                         LEFT JOIN departments d ON u.dept_id = d.id 
                                         WHERE u.role = 'student' AND u.status != 'pending'";
                        if ($filter_dept_id) {
                            $students_sql .= " AND u.dept_id = " . $filter_dept_id;
                        }
                        if ($filter_course_id) {
                            $students_sql .= " AND u.course_id = " . $filter_course_id;
                        }
                        if ($filter_status) {
                            $students_sql .= " AND u.status = '" . $conn->real_escape_string($filter_status) . "'";
                        }
                        $students_sql .= " ORDER BY u.username DESC";
                        
                        $students = $conn->query($students_sql);
                        if ($students && $students->num_rows > 0):
                            while($row = $students->fetch_assoc()):
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
                                <?php if ($row['status'] == 'active'): ?>
                                    <a href="manage_students.php?deactivate=<?php echo $row['id']; ?>" 
                                       onclick="return confirm('Deactivate student account for <?php echo addslashes($row['full_name']); ?>? The student will not be able to log in.')"
                                       style="text-decoration:none;" 
                                       title="Click to Deactivate">
                                        <span class="category-badge" style="background:#d1fae5; color:#065f46; cursor:pointer; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fas fa-check-circle" style="font-size:10px; margin-right:3px;"></i>Active
                                        </span>
                                    </a>
                                <?php else: ?>
                                    <a href="manage_students.php?activate=<?php echo $row['id']; ?>" 
                                       onclick="return confirm('Activate student account for <?php echo addslashes($row['full_name']); ?>? The student will be able to log in.')"
                                       style="text-decoration:none;" 
                                       title="Click to Activate">
                                        <span class="category-badge" style="background:#fee2e2; color:#991b1b; cursor:pointer; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fas fa-ban" style="font-size:10px; margin-right:3px;"></i>Inactive
                                        </span>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" 
                                        onclick="openEditStudentModal(<?php echo htmlspecialchars(json_encode([
                                            'id' => $row['id'],
                                            'username' => $row['username'],
                                            'full_name' => $row['full_name'],
                                            'email' => $row['email'],
                                            'dept_id' => $row['dept_id'],
                                            'course_id' => $row['course_id'],
                                            'status' => $row['status']
                                        ])); ?>)" 
                                        class="btn-action" 
                                        style="background:var(--accent); color:white;" 
                                        title="Edit Student Details & Status">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($row['status'] == 'active'): ?>
                                    <a href="manage_students.php?deactivate=<?php echo $row['id']; ?>" class="btn-action" style="background:#f59e0b; color:white;" title="Deactivate Account" onclick="return confirm('Deactivate student account for <?php echo addslashes($row['full_name']); ?>?')"><i class="fas fa-user-slash"></i></a>
                                <?php else: ?>
                                    <a href="manage_students.php?activate=<?php echo $row['id']; ?>" class="btn-action" style="background:#10b981; color:white;" title="Activate Account" onclick="return confirm('Activate student account for <?php echo addslashes($row['full_name']); ?>?')"><i class="fas fa-user-check"></i></a>
                                <?php endif; ?>
                                <a href="manage_students.php?reset_password=<?php echo $row['id']; ?>" class="btn-action" style="background:#3b82f6; color:white;" title="Reset Password to 123456" onclick="return confirm('Are you sure you want to reset the password for <?php echo htmlspecialchars($row['full_name']); ?> to 123456?')"><i class="fas fa-key"></i></a>
                                <a href="manage_students.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete student account for <?php echo addslashes($row['full_name']); ?>?')"><i class="fas fa-trash-alt"></i></a>
                                <a href="manage_students.php?impersonate=<?php echo $row['id']; ?>" class="btn-action" style="background:#8b5cf6; color:white;" title="Log in as this user">
                                    <i class="fas fa-user-secret"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:30px; color:var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size:30px; display:block; margin-bottom:8px; opacity:0.4;"></i>
                                No students found for the selected filters.
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
        function openEditStudentModal(data) {
            document.getElementById('edit_student_id').value = data.id;
            document.getElementById('edit_username').value = data.username;
            document.getElementById('edit_full_name').value = data.full_name;
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_dept_id').value = data.dept_id || '';
            filterEditModalCourses();
            document.getElementById('edit_course_id').value = data.course_id || '';
            document.getElementById('edit_status').value = data.status || 'active';
            document.getElementById('editStudentModal').style.display = 'flex';
        }

        function filterEditModalCourses() {
            const deptId = document.getElementById('edit_dept_id').value;
            const courseSelect = document.getElementById('edit_course_id');
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
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>
</body>
</html>