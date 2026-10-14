<?php
session_start();
include 'db_connect.php';

// Authentication: Only faculty allowed
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: index.php");
    exit();
}

$faculty_id = (int)$_SESSION['id'];
$faculty_name = $_SESSION['full_name'] ?? 'Faculty Member';
$faculty_dept_id = (int)($_SESSION['dept_id'] ?? 0);

// Get active academic period
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc() ?: [];
$active_id = (int)($active_period['id'] ?? 0);
$period_label = isset($active_period['semester']) ? "{$active_period['semester']} {$active_period['school_year']}" : "No Active Period";

// Auto-sync: Ensure any subjects assigned to this faculty in `subjects` table exist in `classes` table for active period
if ($active_id > 0) {
    $assigned_subs = $conn->query("SELECT id FROM subjects WHERE faculty_id = $faculty_id");
    if ($assigned_subs && $assigned_subs->num_rows > 0) {
        while ($sub_row = $assigned_subs->fetch_assoc()) {
            $s_id = (int)$sub_row['id'];
            $chk_c = $conn->query("SELECT id FROM classes WHERE faculty_id = $faculty_id AND subject_id = $s_id AND period_id = $active_id LIMIT 1");
            if ($chk_c && $chk_c->num_rows === 0) {
                $conn->query("INSERT IGNORE INTO classes (faculty_id, subject_id, period_id) VALUES ($faculty_id, $s_id, $active_id)");
            }
        }
    }
}

// Fetch all classes taught by this faculty for the active period
$classes_stmt = $conn->prepare("
    SELECT c.id as class_id, s.id as subject_id, s.subject_code, s.subject_description, 
           s.year_level, s.section,
           (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id) as enrolled_count
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.faculty_id = ? AND c.period_id = ?
    ORDER BY s.subject_code ASC
");
$classes_stmt->bind_param("ii", $faculty_id, $active_id);
$classes_stmt->execute();
$my_classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Selected Class resolution
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$current_class = null;

if (!empty($my_classes)) {
    if ($selected_class_id > 0) {
        foreach ($my_classes as $cls) {
            if ($cls['class_id'] === $selected_class_id) {
                $current_class = $cls;
                break;
            }
        }
    }
    // Default to first class if not selected or invalid
    if (!$current_class) {
        $current_class = $my_classes[0];
        $selected_class_id = (int)$current_class['class_id'];
    }
}

// Handle Single Student Enrollment
if (isset($_POST['enroll_single']) && $selected_class_id > 0) {
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    // Check if student exists and has role student
    $st_chk = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'student'");
    $st_chk->bind_param("i", $student_id);
    $st_chk->execute();
    $st_row = $st_chk->get_result()->fetch_assoc();

    if ($st_row) {
        // Check duplicate
        $dup_chk = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?");
        $dup_chk->bind_param("ii", $student_id, $selected_class_id);
        $dup_chk->execute();
        if ($dup_chk->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO enrollments (student_id, class_id) VALUES (?, ?)");
            $ins->bind_param("ii", $student_id, $selected_class_id);
            $ins->execute();
            header("Location: faculty_enrollment.php?class_id=$selected_class_id&msg=enrolled");
            exit();
        } else {
            header("Location: faculty_enrollment.php?class_id=$selected_class_id&msg=already_enrolled");
            exit();
        }
    } else {
        header("Location: faculty_enrollment.php?class_id=$selected_class_id&msg=not_found");
        exit();
    }
}

// Handle Batch Enrollment
if (isset($_POST['enroll_batch']) && $selected_class_id > 0) {
    $student_ids = $_POST['batch_student_ids'] ?? [];
    $enrolled_count = 0;

    if (!empty($student_ids) && is_array($student_ids)) {
        $ins = $conn->prepare("INSERT IGNORE INTO enrollments (student_id, class_id) VALUES (?, ?)");
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                // Check duplicate
                $dup = $conn->query("SELECT id FROM enrollments WHERE student_id = $sid AND class_id = $selected_class_id LIMIT 1");
                if ($dup && $dup->num_rows === 0) {
                    $ins->bind_param("ii", $sid, $selected_class_id);
                    $ins->execute();
                    $enrolled_count++;
                }
            }
        }
    }

    header("Location: faculty_enrollment.php?class_id=$selected_class_id&msg=batch_enrolled&count=$enrolled_count");
    exit();
}

// Handle Unenroll Student
if (isset($_GET['unenroll']) && $selected_class_id > 0) {
    $enrollment_id = (int)$_GET['unenroll'];
    $del = $conn->prepare("DELETE FROM enrollments WHERE id = ? AND class_id = ?");
    $del->bind_param("ii", $enrollment_id, $selected_class_id);
    $del->execute();
    header("Location: faculty_enrollment.php?class_id=$selected_class_id&msg=unenrolled");
    exit();
}

// Fetch enrolled students for the currently selected class
$enrolled_students = [];
if ($selected_class_id > 0 && $current_class) {
    $cur_subject_id = (int)$current_class['subject_id'];
    $enr_q = $conn->prepare("
        SELECT e.id as enrollment_id, u.id as student_id, u.username as student_no, u.full_name, u.avatar,
               c.course_alias, c.course_name, d.dept_alias,
               (SELECT COUNT(*) FROM evaluations WHERE student_id = u.id AND faculty_id = ? AND subject_id = ? AND academic_period_id = ?) as has_evaluated,
               (SELECT rating FROM evaluations WHERE student_id = u.id AND faculty_id = ? AND subject_id = ? AND academic_period_id = ? LIMIT 1) as evaluation_rating
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        LEFT JOIN courses c ON u.course_id = c.id
        LEFT JOIN departments d ON u.dept_id = d.id
        WHERE e.class_id = ?
        ORDER BY u.full_name ASC
    ");
    $enr_q->bind_param("iiiiiii", $faculty_id, $cur_subject_id, $active_id, $faculty_id, $cur_subject_id, $active_id, $selected_class_id);
    $enr_q->execute();
    $enrolled_students = $enr_q->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch available students NOT yet enrolled in this class
$available_students = [];
if ($selected_class_id > 0) {
    $avail_q = $conn->prepare("
        SELECT u.id, u.username as student_no, u.full_name, c.course_alias, c.course_name, d.dept_alias
        FROM users u
        LEFT JOIN courses c ON u.course_id = c.id
        LEFT JOIN departments d ON u.dept_id = d.id
        WHERE u.role = 'student' AND u.status = 'active'
          AND u.id NOT IN (SELECT student_id FROM enrollments WHERE class_id = ?)
        ORDER BY c.course_alias ASC, u.full_name ASC
    ");
    $avail_q->bind_param("i", $selected_class_id);
    $avail_q->execute();
    $available_students = $avail_q->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Total counts across all faculty classes
$total_all_enrolled = $conn->query("
    SELECT COUNT(DISTINCT e.student_id) 
    FROM enrollments e 
    JOIN classes c ON e.class_id = c.id 
    WHERE c.faculty_id = $faculty_id AND c.period_id = $active_id
")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Enrollment — Faculty Portal</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .class-tab-card {
            background: var(--card-bg);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        .class-tab-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }
        .class-tab-card.active {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(132, 90, 223, 0.08), rgba(37, 99, 235, 0.04));
            box-shadow: 0 6px 18px rgba(132, 90, 223, 0.15);
        }
        .badge-count {
            background: var(--purple-glow);
            color: var(--accent);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 800;
        }
        .class-tab-card.active .badge-count {
            background: var(--accent);
            color: white;
        }
        .tab-btn {
            background: transparent;
            border: none;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }
        .student-check-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            transition: background 0.15s;
            cursor: pointer;
        }
        .student-check-item:hover {
            background: var(--card-bg);
            border-color: var(--accent);
        }
        @media print {
            .sidebar, .header-bar-modern, .enroll-action-panel, .btn-action, .theme-toggle, footer, .mobile-hamburger-btn {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .card-modern {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 25px;
            }
        }
        .print-header { display: none; }
    </style>
</head>
<body class="dashboard-body">

    <div style="display: flex; min-height: 100vh; position: relative;">
        <!-- Sidebar -->
        <?php $active_page_faculty = 'enrollment'; $mobile_page_title = 'Subject Enrollment'; include 'faculty_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            
            <!-- Header Bar -->
            <div class="header-bar-modern">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 2px;">Subject & Student Enrollment</h1>
                        <p style="font-size: 13px; color: var(--text-muted); font-weight: 500; margin: 0;">Enroll students into your assigned subjects for <strong><?php echo htmlspecialchars($period_label); ?></strong></p>
                    </div>
                </div>
                
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="dashboard_faculty.php" class="btn-action btn-action-text" style="background: rgba(0,0,0,0.05); color: var(--text-main); border: 1px solid var(--border-color); text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <?php if ($current_class): ?>
                    <button type="button" onclick="window.print()" class="btn-action" style="background: #eef2ff; color: #4338ca; border: 1px solid rgba(67, 56, 202, 0.2); padding: 8px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-print"></i> Print Roster
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Print Header Banner (visible on print only) -->
            <div class="print-header">
                <h2 style="margin: 0; font-size: 20px;">Medical Colleges of Northern Philippines / International School of Asia and the Pacific</h2>
                <h3 style="margin: 4px 0 10px; font-size: 16px; color: #4b5563;">Official Class Roster & Student Enrollment</h3>
                <p style="margin: 0; font-size: 13px;">
                    <strong>Instructor:</strong> <?php echo htmlspecialchars($faculty_name); ?> | 
                    <strong>Subject:</strong> <?php echo htmlspecialchars(($current_class['subject_code'] ?? '') . ' - ' . ($current_class['subject_description'] ?? '')); ?> | 
                    <strong>Period:</strong> <?php echo htmlspecialchars($period_label); ?>
                </p>
                <hr style="margin: 15px 0;">
            </div>

            <?php if (empty($my_classes)): ?>
            <!-- No Classes Notice -->
            <div class="card-modern" style="text-align: center; padding: 60px 20px;">
                <div style="width: 70px; height: 70px; border-radius: 50%; background: rgba(132, 90, 223, 0.1); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px;">
                    <i class="fas fa-book-open"></i>
                </div>
                <h3 style="font-size: 20px; font-weight: 800; color: var(--text-main); margin-bottom: 8px;">No Assigned Subjects Found</h3>
                <p style="color: var(--text-muted); font-size: 14px; max-width: 500px; margin: 0 auto 24px; line-height: 1.6;">
                    You do not have any teaching subjects assigned for <strong><?php echo htmlspecialchars($period_label); ?></strong>. Please contact your Dean or Academic Head to assign your teaching load.
                </p>
                <a href="dashboard_faculty.php" class="login-btn" style="width: auto; padding: 10px 24px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-arrow-left"></i> Return to Dashboard
                </a>
            </div>
            <?php else: ?>

            <!-- STAT SUMMARY ROW -->
            <div class="modern-stat-grid" style="margin-bottom: 24px;">
                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Current Subject</p>
                        <h3 style="font-size: 20px; color: var(--accent);"><?php echo htmlspecialchars($current_class['subject_code']); ?></h3>
                        <small style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($current_class['year_level'] ?? 'All Years'); ?> - <?php echo htmlspecialchars($current_class['section'] ?? 'Sec A'); ?></small>
                    </div>
                    <div class="stat-icon blue" style="background: var(--purple-glow); color: var(--accent);"><i class="fas fa-book"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Enrolled in this Subject</p>
                        <h3 style="color: #10b981;"><?php echo count($enrolled_students); ?> Students</h3>
                        <small style="color: var(--text-muted); font-size: 12px;">Active in this class roster</small>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Available to Enroll</p>
                        <h3 style="color: #f59e0b;"><?php echo count($available_students); ?> Students</h3>
                        <small style="color: var(--text-muted); font-size: 12px;">Not yet enrolled in this class</small>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-user-plus"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Total Enrolled (All Classes)</p>
                        <h3 style="color: var(--accent);"><?php echo $total_all_enrolled; ?> Students</h3>
                        <small style="color: var(--text-muted); font-size: 12px;">Across <?php echo count($my_classes); ?> teaching subjects</small>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                </div>
            </div>

            <!-- CLASS SELECTOR TABS -->
            <div style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 style="font-size: 15px; font-weight: 800; color: var(--text-main); margin: 0;">
                        <i class="fas fa-layer-group" style="color: var(--accent); margin-right: 6px;"></i> Select Subject to Manage:
                    </h3>
                    <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo count($my_classes); ?> Assigned Subjects</span>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px;">
                    <?php foreach ($my_classes as $cls_item): 
                        $is_active_tab = ($cls_item['class_id'] == $selected_class_id);
                    ?>
                    <a href="faculty_enrollment.php?class_id=<?php echo $cls_item['class_id']; ?>" class="class-tab-card <?php echo $is_active_tab ? 'active' : ''; ?>">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <strong style="font-size: 16px; color: <?php echo $is_active_tab ? 'var(--accent)' : 'var(--text-main)'; ?>;"><?php echo htmlspecialchars($cls_item['subject_code']); ?></strong>
                                <span style="font-size: 11px; background: rgba(0,0,0,0.06); padding: 2px 6px; border-radius: 4px; font-weight: 700; color: var(--text-muted);">
                                    <?php echo htmlspecialchars($cls_item['section'] ?? 'Sec A'); ?>
                                </span>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); line-height: 1.3; max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo htmlspecialchars($cls_item['subject_description']); ?>
                            </div>
                        </div>
                        <span class="badge-count">
                            <i class="fas fa-users" style="font-size: 10px;"></i> <?php echo $cls_item['enrolled_count']; ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ENROLLMENT WORKSPACE -->
            <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 24px; align-items: start;">
                
                <!-- LEFT COLUMN: ENROLLMENT FORMS -->
                <div class="enroll-action-panel">
                    <div class="card-modern" style="padding: 24px;">
                        
                        <!-- Mode Tabs -->
                        <div style="display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 20px;">
                            <button type="button" class="tab-btn active" id="btnSingleMode" onclick="switchEnrollMode('single')">
                                <i class="fas fa-user-plus"></i> Single Enroll
                            </button>
                            <button type="button" class="tab-btn" id="btnBatchMode" onclick="switchEnrollMode('batch')">
                                <i class="fas fa-users"></i> Batch Enroll (<?php echo count($available_students); ?>)
                            </button>
                        </div>

                        <!-- MODE 1: SINGLE ENROLL -->
                        <div id="singleEnrollView">
                            <h4 style="margin: 0 0 10px; font-size: 15px; font-weight: 800; color: var(--text-main);">
                                Enroll Single Student
                            </h4>
                            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 18px; line-height: 1.45;">
                                Select a student from the dropdown list to add them into <strong><?php echo htmlspecialchars($current_class['subject_code']); ?></strong>.
                            </p>

                            <form method="POST">
                                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                                
                                <div class="input-group" style="margin-bottom: 16px;">
                                    <label class="input-label" style="font-weight: 700; font-size: 13px; color: var(--text-main); margin-bottom: 6px; display: block;">
                                        Select Student
                                    </label>
                                    <select name="student_id" required style="width: 100%; padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--input-bg); color: var(--text-main); font-size: 14px;">
                                        <option value="" disabled selected>-- Choose student to enroll --</option>
                                        <?php if (!empty($available_students)): ?>
                                            <?php foreach ($available_students as $as): ?>
                                            <option value="<?php echo $as['id']; ?>">
                                                <?php echo htmlspecialchars($as['full_name'] . ' (' . $as['student_no'] . ') — ' . ($as['course_alias'] ?? 'Student')); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>All eligible active students are already enrolled.</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <button type="submit" name="enroll_single" class="login-btn" style="width: 100%; padding: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px;" <?php echo empty($available_students) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                                    <i class="fas fa-user-plus"></i> Enroll Student
                                </button>
                            </form>
                        </div>

                        <!-- MODE 2: BATCH ENROLL -->
                        <div id="batchEnrollView" style="display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: var(--text-main);">
                                    Batch / Multi-Student Enroll
                                </h4>
                                <label style="font-size: 12px; font-weight: 700; color: var(--accent); cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)"> Select All
                                </label>
                            </div>
                            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 14px;">
                                Check the students you want to enroll at once into <strong><?php echo htmlspecialchars($current_class['subject_code']); ?></strong>.
                            </p>

                            <!-- Course Quick Filter -->
                            <div style="margin-bottom: 12px;">
                                <input type="text" id="batchSearchInput" placeholder="Quick search by name or ID..." onkeyup="filterBatchList()" style="width: 100%; padding: 8px 12px; font-size: 13px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main);">
                            </div>

                            <form method="POST" id="batchEnrollForm">
                                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">

                                <div id="batchStudentsList" style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; padding-right: 4px;">
                                    <?php if (!empty($available_students)): ?>
                                        <?php foreach ($available_students as $as): ?>
                                        <label class="student-check-item batch-student-row" data-name="<?php echo strtolower(htmlspecialchars($as['full_name'] . ' ' . $as['student_no'])); ?>">
                                            <input type="checkbox" name="batch_student_ids[]" value="<?php echo $as['id']; ?>" class="batch-checkbox">
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="font-size: 13.5px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars($as['full_name']); ?>
                                                </div>
                                                <div style="font-size: 11.5px; color: var(--text-muted); display: flex; gap: 8px;">
                                                    <span>ID: <?php echo htmlspecialchars($as['student_no']); ?></span>
                                                    <span>•</span>
                                                    <span style="color: var(--accent); font-weight: 600;"><?php echo htmlspecialchars($as['course_alias'] ?? 'Program'); ?></span>
                                                </div>
                                            </div>
                                        </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                                            No available students to enroll.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" name="enroll_batch" class="login-btn" style="width: 100%; padding: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px;" <?php echo empty($available_students) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                                    <i class="fas fa-users"></i> Enroll Selected Students
                                </button>
                            </form>
                        </div>

                    </div>
                </div>

                <!-- RIGHT COLUMN: ENROLLED STUDENTS ROSTER -->
                <div class="card-modern" style="padding: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px;">
                        <div>
                            <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-list-ul" style="color: var(--accent);"></i> Enrolled Students Roster
                            </h3>
                            <p style="margin: 4px 0 0; font-size: 13px; color: var(--text-muted);">
                                <strong><?php echo htmlspecialchars($current_class['subject_code'] . ' - ' . $current_class['subject_description']); ?></strong>
                            </p>
                        </div>
                        <div style="position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px;"></i>
                            <input type="text" id="rosterSearch" placeholder="Search roster..." onkeyup="filterRoster()" style="padding: 8px 12px 8px 30px; font-size: 13px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); width: 180px;">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table-modern" id="rosterTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Course</th>
                                    <th>Evaluation Status</th>
                                    <th style="text-align: right; width: 110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($enrolled_students)): 
                                    $num = 1;
                                    foreach ($enrolled_students as $st): 
                                ?>
                                <tr class="roster-row">
                                    <td style="color: var(--text-muted); font-weight: 600;"><?php echo $num++; ?></td>
                                    <td>
                                        <span class="category-badge" style="font-family: monospace; font-size: 12px;">
                                            <?php echo htmlspecialchars($st['student_no']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars($st['full_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span style="font-size: 12px; font-weight: 600; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($st['course_alias'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($st['has_evaluated']): ?>
                                            <span style="background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 700; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-check-circle"></i> Evaluated (<?php echo number_format((float)$st['evaluation_rating'], 2); ?> ★)
                                            </span>
                                        <?php else: ?>
                                            <span style="background: rgba(245, 158, 11, 0.12); color: #d97706; font-weight: 700; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-clock"></i> Pending Evaluation
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-action btn-delete" style="padding: 6px 12px; font-size: 12px; border-radius: 6px;" onclick="confirmUnenroll(<?php echo $st['enrollment_id']; ?>, '<?php echo addslashes($st['full_name']); ?>', <?php echo $selected_class_id; ?>)">
                                            <i class="fas fa-user-minus"></i> Remove
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                                        <i class="fas fa-user-graduate" style="font-size: 32px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                                        No students have been enrolled in this subject yet.<br>
                                        <small style="margin-top: 4px; display: block;">Use the form on the left to add students to your class.</small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <?php endif; ?>

            <footer style="margin-top: 40px;">
                <i class="fas fa-university"></i> Faculty Portal · MCNP-ISAP Evaluation System · ID: <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>
            </footer>
        </main>
    </div>

    <!-- SweetAlert Messages -->
    <script>
        function switchEnrollMode(mode) {
            const singleView = document.getElementById('singleEnrollView');
            const batchView = document.getElementById('batchEnrollView');
            const btnSingle = document.getElementById('btnSingleMode');
            const btnBatch = document.getElementById('btnBatchMode');

            if (mode === 'single') {
                singleView.style.display = 'block';
                batchView.style.display = 'none';
                btnSingle.classList.add('active');
                btnBatch.classList.remove('active');
            } else {
                singleView.style.display = 'none';
                batchView.style.display = 'block';
                btnSingle.classList.remove('active');
                btnBatch.classList.add('active');
            }
        }

        function toggleSelectAll(master) {
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('.batch-student-row').style.display !== 'none') {
                    cb.checked = master.checked;
                }
            });
        }

        function filterBatchList() {
            const query = document.getElementById('batchSearchInput').value.toLowerCase().trim();
            const rows = document.querySelectorAll('.batch-student-row');
            rows.forEach(r => {
                const name = r.getAttribute('data-name');
                if (!query || name.includes(query)) {
                    r.style.display = 'flex';
                } else {
                    r.style.display = 'none';
                }
            });
        }

        function filterRoster() {
            const query = document.getElementById('rosterSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#rosterTable tbody tr.roster-row');
            rows.forEach(r => {
                const text = r.innerText.toLowerCase();
                r.style.display = (!query || text.includes(query)) ? '' : 'none';
            });
        }

        function confirmUnenroll(enrollmentId, studentName, classId) {
            Swal.fire({
                title: 'Remove Student from Class?',
                html: `Are you sure you want to remove <strong>${studentName}</strong> from this subject?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: '<i class="fas fa-user-minus"></i> Yes, Remove',
                cancelButtonText: 'Cancel'
            }).then((res) => {
                if (res.isConfirmed) {
                    window.location.href = `faculty_enrollment.php?class_id=${classId}&unenroll=${enrollmentId}`;
                }
            });
        }

        // Flash message handling
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const msg = urlParams.get('msg');
            const count = urlParams.get('count');

            if (msg === 'enrolled') {
                Swal.fire({
                    icon: 'success',
                    title: 'Student Enrolled!',
                    text: 'The student has been successfully enrolled into this subject.',
                    timer: 3000,
                    showConfirmButton: false
                });
            } else if (msg === 'batch_enrolled') {
                Swal.fire({
                    icon: 'success',
                    title: 'Batch Enrollment Successful!',
                    text: `${count || 'Selected'} student(s) have been enrolled into this subject.`,
                    timer: 3500,
                    showConfirmButton: false
                });
            } else if (msg === 'unenrolled') {
                Swal.fire({
                    icon: 'success',
                    title: 'Student Removed',
                    text: 'The student has been removed from this subject roster.',
                    timer: 3000,
                    showConfirmButton: false
                });
            } else if (msg === 'already_enrolled') {
                Swal.fire({
                    icon: 'info',
                    title: 'Already Enrolled',
                    text: 'This student is already in this class roster.'
                });
            } else if (msg === 'not_found') {
                Swal.fire({
                    icon: 'error',
                    title: 'Student Not Found',
                    text: 'The selected student account could not be found or is inactive.'
                });
            }
        });
    </script>
</body>
</html>
