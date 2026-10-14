<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { 
    header("Location: index.php"); 
    exit(); 
}

$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;
$period_label = $active_period ? "{$active_period['semester']} {$active_period['school_year']}" : "No Active Period";

// Handle Add Class
if (isset($_POST['add_class']) && $active_id > 0) {
    $faculty_id = (int)$_POST['faculty_id'];
    $subject_id = (int)$_POST['subject_id'];

    // Check for duplicates
    $check = $conn->prepare("SELECT id FROM classes WHERE faculty_id = ? AND subject_id = ? AND period_id = ?");
    $check->bind_param("iii", $faculty_id, $subject_id, $active_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        header("Location: manage_classes.php?error=exists");
    } else {
        $stmt = $conn->prepare("INSERT INTO classes (faculty_id, subject_id, period_id) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $faculty_id, $subject_id, $active_id);
        if ($stmt->execute()) {
            header("Location: manage_classes.php?msg=added");
        } else {
            header("Location: manage_classes.php?error=failed");
        }
    }
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_classes.php?msg=deleted");
    exit();
}

// Handle Template Download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=classes_template.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Faculty Email', 'Subject Code', 'Subject Description'));
    fputcsv($output, array('juan.cruz@isap.edu.ph', 'IT101', 'Introduction to Computing'));
    fclose($output);
    exit();
}

// Handle Bulk Upload
if (isset($_POST['bulk_upload']) && isset($_FILES['csv_file']) && $active_id > 0) {
    $file = $_FILES['csv_file']['tmp_name'];
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK && is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); // Skip header
            $success_count = 0;
            $error_count = 0;
            
            // Fetch Faculty Map
            $faculty = [];
            $res = $conn->query("SELECT id, email FROM users WHERE role = 'faculty'");
            while ($row = $res->fetch_assoc()) { $faculty[trim(strtolower($row['email']))] = $row['id']; }

            // Fetch Subjects Map
            $subjects = [];
            $res = $conn->query("SELECT id, subject_code FROM subjects");
            while ($row = $res->fetch_assoc()) { $subjects[trim(strtoupper($row['subject_code']))] = $row['id']; }

            $stmt_class = $conn->prepare("INSERT IGNORE INTO classes (faculty_id, subject_id, period_id) VALUES (?, ?, ?)");
            $stmt_subject = $conn->prepare("INSERT IGNORE INTO subjects (subject_code, subject_description) VALUES (?, ?)");

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 2) continue; // Skip incomplete rows
                
                $f_email = trim(strtolower($data[0] ?? ''));
                $s_code = trim(strtoupper($data[1] ?? ''));
                $s_desc = trim($data[2] ?? '');
                
                if (empty($f_email) || empty($s_code)) continue;

                $faculty_id = $faculty[$f_email] ?? null;
                if (!$faculty_id) {
                    $error_count++;
                    continue; // Faculty doesn't exist
                }

                // If subject doesn't exist, create it!
                $subject_id = $subjects[$s_code] ?? null;
                if (!$subject_id) {
                    $stmt_subject->bind_param("ss", $s_code, $s_desc);
                    if ($stmt_subject->execute()) {
                        $subject_id = $conn->insert_id;
                        $subjects[$s_code] = $subject_id; // Add to map
                    } else {
                        $error_count++; 
                        continue;
                    }
                }

                $stmt_class->bind_param("iii", $faculty_id, $subject_id, $active_id);
                if ($stmt_class->execute() && $stmt_class->affected_rows > 0) {
                    $success_count++;
                } else {
                    $error_count++; // Already exists or error
                }
            }
            fclose($handle);
            header("Location: manage_classes.php?msg=bulk_success&added=$success_count&errors=$error_count");
            exit();
        }
    }
    header("Location: manage_classes.php?msg=bulk_error");
    exit();
}

// Filter parameters
$filter_dept_id    = isset($_GET['dept_id']) && $_GET['dept_id'] !== '' ? (int)$_GET['dept_id'] : null;
$filter_course_id  = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
$filter_faculty_id = isset($_GET['faculty_id']) && $_GET['faculty_id'] !== '' ? (int)$_GET['faculty_id'] : null;

// If a course is selected but department is not, auto-detect the course's department
if ($filter_course_id && !$filter_dept_id) {
    $c_lookup = $conn->query("SELECT dept_id FROM courses WHERE id = " . (int)$filter_course_id);
    if ($c_lookup && $cr_row = $c_lookup->fetch_assoc()) {
        $filter_dept_id = (int)$cr_row['dept_id'];
    }
}

// If faculty is selected, verify they match the course / dept filter; if not, reset filter_faculty_id
if ($filter_faculty_id) {
    if ($filter_course_id) {
        $fac_chk = $conn->query("SELECT id FROM users WHERE id = $filter_faculty_id AND (course_id = $filter_course_id OR id IN (SELECT c2.faculty_id FROM classes c2 JOIN subjects s2 ON c2.subject_id = s2.id WHERE s2.course_id = $filter_course_id AND c2.period_id = " . (int)$active_id . "))");
        if (!$fac_chk || $fac_chk->num_rows == 0) {
            $filter_faculty_id = null;
        }
    } elseif ($filter_dept_id) {
        $fac_chk = $conn->query("SELECT u.id FROM users u LEFT JOIN courses c ON u.course_id = c.id WHERE u.id = $filter_faculty_id AND (u.dept_id = $filter_dept_id OR c.dept_id = $filter_dept_id)");
        if (!$fac_chk || $fac_chk->num_rows == 0) {
            $filter_faculty_id = null;
        }
    }
}

$where = ["c.period_id = " . (int)$active_id];
if ($filter_course_id) {
    $where[] = "(s.course_id = $filter_course_id OR u.course_id = $filter_course_id)";
} elseif ($filter_dept_id) {
    $where[] = "(u.dept_id = $filter_dept_id OR cs.dept_id = $filter_dept_id)";
}
if ($filter_faculty_id) {
    $where[] = "c.faculty_id = $filter_faculty_id";
}

$classes_sql = "SELECT c.id, u.full_name, u.username as emp_id, u.school as faculty_school, u.dept_id as f_dept_id,
                       s.subject_code, s.subject_description, 
                       COALESCE(cs.course_alias, cf.course_alias, 'General') as program_name,
                       COALESCE(ds.dept_alias, df.dept_alias, 'N/A') as dept_alias,
                       (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id) as student_count
                FROM classes c
                JOIN users u ON c.faculty_id = u.id
                JOIN subjects s ON c.subject_id = s.id
                LEFT JOIN courses cs ON s.course_id = cs.id
                LEFT JOIN departments ds ON cs.dept_id = ds.id
                LEFT JOIN courses cf ON u.course_id = cf.id
                LEFT JOIN departments df ON cf.dept_id = df.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY program_name ASC, u.full_name ASC, s.subject_code ASC";
$classes = $conn->query($classes_sql);
$total_classes = $classes ? $classes->num_rows : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Management</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php $active_page = 'classes'; include 'admin_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="color:var(--text-main); margin:0;">
                        <i class="fas fa-chalkboard-teacher" style="color:var(--accent);"></i> Class Management
                    </h2>
                    <p style="color:var(--text-muted); font-size:13px; margin:4px 0 0;">
                        Active Period: <strong><?php echo htmlspecialchars($period_label); ?></strong>
                    </p>
                </div>
                <div style="display:flex; gap: 10px;">
                    <?php if ($active_id > 0): ?>
                    <button onclick="document.getElementById('addClassModal').style.display='flex'" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-plus"></i> Create Class</button>
                    <button onclick="document.getElementById('bulkUploadModal').style.display='flex'" class="btn-action" style="background:var(--accent); color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-file-csv"></i> Bulk Upload</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bulk Upload Modal -->
            <div id="bulkUploadModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:420px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-upload"></i> Bulk Upload Classes</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('bulkUploadModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Upload a CSV file to link Faculty with Subjects for <strong><?php echo htmlspecialchars($period_label); ?></strong>. <br><a href="manage_classes.php?download_template=1" style="color:var(--accent); font-weight:bold;">Download CSV Template</a></p>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="margin-bottom:20px;">
                            <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:10px; border:1px dashed var(--border-color); border-radius:8px; background:var(--bg-color); color:var(--text-main);">
                        </div>
                        <button type="submit" name="bulk_upload" style="width:100%; background:var(--accent); color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-cloud-upload-alt"></i> Upload CSV</button>
                    </form>
                </div>
            </div>

            <!-- Create Class Modal -->
            <?php if ($active_id > 0): ?>
            <div id="addClassModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:540px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-plus-circle"></i> Create New Class</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('addClassModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST" style="display:grid; grid-template-columns:1fr; gap:15px;">
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Program / Course Filter (Optional)</div>
                            <select id="modalCourseFilter" onchange="filterModalClassOptions(this.value)" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">-- All Programs / Courses --</option>
                                <?php
                                $cr_q = $conn->query("SELECT c.id, c.course_alias, c.course_name FROM courses c ORDER BY c.course_alias ASC");
                                while($cr = $cr_q->fetch_assoc()) {
                                    echo "<option value='{$cr['id']}'>{$cr['course_alias']} - {$cr['course_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Faculty / Instructor</div>
                            <select name="faculty_id" id="modalFacultySelect" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="" disabled selected>-- Select Faculty --</option>
                                <?php
                                $faculties = $conn->query("SELECT u.id, u.full_name, u.course_id, c.course_alias FROM users u LEFT JOIN courses c ON u.course_id = c.id WHERE u.role='faculty' AND u.status='active' ORDER BY u.full_name ASC");
                                while($f = $faculties->fetch_assoc()) {
                                    $c_tag = $f['course_alias'] ? " [{$f['course_alias']}]" : "";
                                    echo "<option value='{$f['id']}' data-course='{$f['course_id']}'>" . htmlspecialchars($f['full_name'] . $c_tag) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Subject</div>
                            <select name="subject_id" id="modalSubjectSelect" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="" disabled selected>-- Select Subject --</option>
                                <?php
                                $subjects = $conn->query("SELECT s.id, s.subject_code, s.subject_description, s.course_id, c.course_alias FROM subjects s LEFT JOIN courses c ON s.course_id = c.id ORDER BY s.subject_code ASC");
                                while($s = $subjects->fetch_assoc()) {
                                    $c_tag = $s['course_alias'] ? " [{$s['course_alias']}]" : "";
                                    echo "<option value='{$s['id']}' data-course='{$s['course_id']}'>" . htmlspecialchars($s['subject_code'] . ' - ' . $s['subject_description'] . $c_tag) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div style="font-size:12px; color:var(--text-muted);">
                            <i class="fas fa-info-circle" style="color:var(--accent);"></i> Class will be created under <strong><?php echo htmlspecialchars($period_label); ?></strong>.
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" onclick="document.getElementById('addClassModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="add_class" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-check"></i> Create Class</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Flash Messages -->
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Class assignment created successfully.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Class assignment removed.</div>
                <?php elseif ($_GET['msg'] === 'bulk_success'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Bulk upload complete! Added/Updated: <strong><?php echo (int)($_GET['added'] ?? 0); ?></strong>, Errors: <strong><?php echo (int)($_GET['errors'] ?? 0); ?></strong>.</div>
                <?php elseif ($_GET['msg'] === 'bulk_error'): ?>
                    <div class="flash-success" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;"><i class="fas fa-exclamation-circle"></i> Error uploading CSV file. Please check the file format.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'exists'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> This faculty is already assigned to this subject in the active period.</div>
            <?php endif; ?>

            <?php if ($active_id > 0): ?>
            <!-- Filter Classes Card -->
            <div class="card">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Classes</h3>
                <form method="GET" id="classFilterForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end;">
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Department</div>
                        <select name="dept_id" id="pageFilterDept" onchange="onPageFilterDeptChange(this)" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
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
                        <select name="course_id" id="pageFilterCourse" onchange="onPageFilterCourseChange(this)" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Programs / Courses</option>
                            <?php
                            $c_sql = "SELECT id, course_name, course_alias FROM courses";
                            if ($filter_dept_id) {
                                $c_sql .= " WHERE dept_id = " . $filter_dept_id;
                            }
                            $c_sql .= " ORDER BY course_alias ASC";
                            $courses_res = $conn->query($c_sql);
                            $selected_course_alias = '';
                            while($c = $courses_res->fetch_assoc()) {
                                $is_sel = ($filter_course_id === (int)$c['id']);
                                if ($is_sel) $selected_course_alias = $c['course_alias'];
                                $sel = $is_sel ? 'selected' : '';
                                echo "<option value='{$c['id']}' {$sel}>{$c['course_alias']} - {$c['course_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Faculty</div>
                        <select name="faculty_id" id="pageFilterFaculty" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">
                                <?php 
                                if (!empty($selected_course_alias)) {
                                    echo "All Faculty (" . htmlspecialchars($selected_course_alias) . ")";
                                } else {
                                    echo "All Faculty";
                                }
                                ?>
                            </option>
                            <?php
                            $fac_sql = "SELECT DISTINCT u.id, u.full_name, c.course_alias 
                                        FROM users u 
                                        LEFT JOIN courses c ON u.course_id = c.id 
                                        WHERE u.role='faculty' AND u.status='active'";
                            if ($filter_course_id) {
                                $fac_sql .= " AND (u.course_id = $filter_course_id OR u.id IN (
                                    SELECT c2.faculty_id FROM classes c2 
                                    JOIN subjects s2 ON c2.subject_id = s2.id 
                                    WHERE s2.course_id = $filter_course_id AND c2.period_id = " . (int)$active_id . "
                                ))";
                            } elseif ($filter_dept_id) {
                                $fac_sql .= " AND (u.dept_id = $filter_dept_id OR c.dept_id = $filter_dept_id)";
                            }
                            $fac_sql .= " ORDER BY u.full_name ASC";
                            $fac_res = $conn->query($fac_sql);
                            while($f = $fac_res->fetch_assoc()) {
                                $sel = ($filter_faculty_id === (int)$f['id']) ? 'selected' : '';
                                $c_info = $f['course_alias'] ? " [{$f['course_alias']}]" : "";
                                echo "<option value='{$f['id']}' {$sel}>".htmlspecialchars($f['full_name'] . $c_info)."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <?php if ($filter_dept_id || $filter_course_id || $filter_faculty_id): ?>
                            <a href="manage_classes.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; display:inline-block; border-radius:8px;">Reset Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Class List Card -->
            <div class="card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-chalkboard-teacher"></i> Class List for <?php echo htmlspecialchars($period_label); ?></h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php echo $total_classes; ?> total classes
                    </span>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Faculty</th>
                            <th>School</th>
                            <th>Department</th>
                            <th>Program / Course</th>
                            <th>Subject</th>
                            <th>Students</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($classes && $total_classes > 0):
                            while($row = $classes->fetch_assoc()):
                                $row_school = !empty($row['faculty_school']) ? strtoupper($row['faculty_school']) : (((int)$row['f_dept_id'] <= 4 && (int)$row['f_dept_id'] > 0) ? 'ISAP' : 'MCNP');
                                $badge_bg = ($row_school === 'ISAP') ? 'rgba(122, 0, 0, 0.1)' : 'rgba(26, 47, 122, 0.1)';
                                $badge_color = ($row_school === 'ISAP') ? '#7a0000' : '#1a2f7a';
                        ?>
                        <tr>
                            <td style="white-space:nowrap;"><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                            <td><span class="category-badge" style="background:<?php echo $badge_bg; ?>; color:<?php echo $badge_color; ?>; font-weight:800;"><?php echo $row_school; ?></span></td>
                            <td><span class="category-badge" style="background:#eef2ff; color:#4338ca;"><?php echo htmlspecialchars($row['dept_alias']); ?></span></td>
                            <td><span class="category-badge"><?php echo htmlspecialchars($row['program_name']); ?></span></td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['subject_code']); ?></strong>
                                <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['subject_description']); ?></div>
                            </td>
                            <td>
                                <span class="category-badge" style="background:#dbeafe; color:#1e40af; font-weight:700;">
                                    <i class="fas fa-users" style="font-size:11px;"></i> <?php echo $row['student_count']; ?>
                                </span>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <a href="manage_enrollment.php?class_id=<?php echo $row['id']; ?>" class="btn-action" style="background:#10b981; color:white;" title="Manage Enrolled Students (<?php echo $row['student_count']; ?> enrolled)">
                                    <i class="fas fa-user-graduate"></i>
                                </a>
                                <a href="manage_classes.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this class? All enrollment data for it will also be lost.');" title="Delete Class">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:35px; color:var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size:32px; display:block; margin-bottom:10px; opacity:0.4;"></i>
                                No classes found for the selected filters in this academic period.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card" style="text-align:center; padding:45px 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size:45px; color:#f59e0b; margin-bottom:15px;"></i>
                <h3 style="color:var(--text-main); margin-bottom:8px;">No Active Academic Period</h3>
                <p style="color:var(--text-muted); margin-bottom:20px;">Please go to <a href="manage_periods.php" style="color:var(--accent); font-weight:700;">Academic Periods</a> and set an active period before managing classes.</p>
                <a href="manage_periods.php" class="btn-action" style="background:var(--accent); color:white; padding:10px 20px;"><i class="fas fa-calendar-check"></i> Set Active Period</a>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function filterModalClassOptions(courseId) {
            const fSel = document.getElementById('modalFacultySelect');
            const sSel = document.getElementById('modalSubjectSelect');
            if (!fSel || !sSel) return;
            
            Array.from(fSel.options).forEach(opt => {
                if (!opt.value) return;
                const c = opt.getAttribute('data-course');
                opt.style.display = (!courseId || c === courseId) ? '' : 'none';
            });
            
            Array.from(sSel.options).forEach(opt => {
                if (!opt.value) return;
                const c = opt.getAttribute('data-course');
                opt.style.display = (!courseId || c === courseId) ? '' : 'none';
            });

            if (fSel.selectedOptions[0] && fSel.selectedOptions[0].style.display === 'none') {
                fSel.value = '';
            }
            if (sSel.selectedOptions[0] && sSel.selectedOptions[0].style.display === 'none') {
                sSel.value = '';
            }
        }

        function onPageFilterDeptChange(sel) {
            const form = sel.form;
            if (form.course_id) form.course_id.value = '';
            if (form.faculty_id) form.faculty_id.value = '';
            form.submit();
        }

        function onPageFilterCourseChange(sel) {
            const form = sel.form;
            if (form.faculty_id) form.faculty_id.value = '';
            form.submit();
        }

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