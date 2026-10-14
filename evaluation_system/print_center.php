<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin', 'dean'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$session_dept = $_SESSION['dept_id'] ?? null;

// Determine active academic period
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = (int)($active_period['id'] ?? 1);
$active_sy = trim($active_period['school_year'] ?? '2026-2027');
if ($active_sy === '2026' || empty($active_sy)) {
    $active_sy = '2026-2027';
}
$active_sem = trim($active_period['semester'] ?? '1st Semester');
$period_label = "{$active_sem} {$active_sy}";

// Fetch Dean's department & school context if applicable
$dean_school = null;
$dean_dept_name = '';
$dean_dept_alias = '';
if ($role === 'dean' && $session_dept) {
    $d_stmt = $conn->query("SELECT id, dept_name, dept_alias FROM departments WHERE id = " . (int)$session_dept);
    if ($d_stmt && $d_row = $d_stmt->fetch_assoc()) {
        $dean_dept_name = $d_row['dept_name'];
        $dean_dept_alias = $d_row['dept_alias'];
        $dean_school = in_array($d_row['dept_alias'], ['CASTE', 'CBEM', 'CITE', 'COCJE']) ? 'isap' : 'mcnp';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Center · Official Evaluation Reports</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --mcnp-primary: #0b5394;
            --mcnp-gradient: linear-gradient(135deg, #0b5394 0%, #1d4ed8 100%);
            --isap-primary: #8B0000;
            --isap-gradient: linear-gradient(135deg, #8B0000 0%, #b91c1c 100%);
            --dept-primary: #6366f1;
            --dept-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --school-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --excel-gradient: linear-gradient(135deg, #107c41 0%, #0e6b37 100%);
            --amber-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        /* Hero Header */
        .pc-page-header {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 26px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            position: relative;
            overflow: hidden;
        }

        .pc-page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(180deg, var(--accent), #3b82f6);
        }

        .pc-header-left {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pc-badge-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pc-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .pc-pill.accent {
            background: rgba(99, 102, 241, 0.12);
            color: var(--accent);
            border: 1px solid rgba(99, 102, 241, 0.25);
        }

        .pc-pill.active {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .pc-dot-pulse {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pcPulse 1.8s infinite;
        }

        @keyframes pcPulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .pc-title {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pc-subtitle {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .pc-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-upload-hub {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--school-gradient);
            color: white;
            font-size: 13px;
            font-weight: 700;
            border-radius: 10px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
            transition: all 0.2s ease;
        }

        .btn-upload-hub:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
            color: white;
        }

        /* Section Headings */
        .pc-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0 0 16px 2px;
        }

        .pc-section-title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Modern Grid Systems */
        .pc-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
            margin-bottom: 26px;
        }

        .pc-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
            margin-bottom: 26px;
        }

        @media (max-width: 960px) {
            .pc-grid-2, .pc-grid-3 {
                grid-template-columns: 1fr !important;
            }
            .pc-page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Card Container */
        .pc-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .pc-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.07);
        }

        .pc-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .pc-card.mcnp-card::before { background: var(--mcnp-gradient); }
        .pc-card.isap-card::before { background: var(--isap-gradient); }
        .pc-card.dept-card::before { background: var(--dept-gradient); }
        .pc-card.school-card::before { background: var(--school-gradient); }
        .pc-card.service-card::before { background: var(--amber-gradient); }
        .pc-card.import-card::before { background: var(--school-gradient); }

        /* Card Header & Badges */
        .pc-card-head {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .pc-card-icon-frame {
            width: 58px;
            height: 58px;
            border-radius: 14px;
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .pc-card-icon-frame img {
            height: 42px;
            width: auto;
            object-fit: contain;
        }

        .pc-card-icon-frame i {
            font-size: 26px;
        }

        .pc-card-meta {
            flex: 1;
            min-width: 0;
        }

        .pc-card-title {
            margin: 0 0 3px 0;
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .pc-card-tag {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .pc-card-desc {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* Form Controls */
        .pc-form-group {
            margin-bottom: 13px;
        }

        .pc-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .pc-label i {
            font-size: 11px;
            color: var(--accent);
        }

        .pc-select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-color);
            color: var(--text-main);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            outline: none;
            box-sizing: border-box;
        }

        .pc-select:focus:not(:disabled) {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        .pc-select:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            background: rgba(0, 0, 0, 0.03);
        }

        /* Button Action Stack */
        .pc-actions-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 18px;
        }

        .btn-pc {
            width: 100%;
            height: 42px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .btn-pc:hover {
            transform: translateY(-2px);
        }

        .btn-pc-mcnp {
            background: var(--mcnp-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(11, 83, 148, 0.25);
        }
        .btn-pc-mcnp:hover {
            box-shadow: 0 6px 18px rgba(11, 83, 148, 0.35);
            color: white;
        }

        .btn-pc-isap {
            background: var(--isap-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 0, 0, 0.25);
        }
        .btn-pc-isap:hover {
            box-shadow: 0 6px 18px rgba(139, 0, 0, 0.35);
            color: white;
        }

        .btn-pc-dept {
            background: var(--dept-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
        }
        .btn-pc-dept:hover {
            box-shadow: 0 6px 18px rgba(99, 102, 241, 0.35);
            color: white;
        }

        .btn-pc-school {
            background: var(--school-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-pc-school:hover {
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.35);
            color: white;
        }

        .btn-pc-service {
            background: var(--amber-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        .btn-pc-service:hover {
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.35);
            color: white;
        }

        .btn-pc-excel {
            background: var(--excel-gradient);
            color: white;
            box-shadow: 0 3px 10px rgba(16, 124, 65, 0.2);
            height: 38px;
            font-size: 12.5px;
        }
        .btn-pc-excel:hover {
            box-shadow: 0 5px 15px rgba(16, 124, 65, 0.3);
            color: white;
        }

        .btn-pc-import {
            background: var(--school-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-pc-import:hover {
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.35);
            color: white;
        }

        /* Template Downloads Row */
        .pc-template-row {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-pc-template {
            flex: 1;
            height: 34px;
            font-size: 11.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-color);
            color: var(--text-main);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-pc-template:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(99, 102, 241, 0.05);
        }

        .pc-notice-pill {
            padding: 9px 12px;
            border-radius: 10px;
            font-size: 12px;
            background: rgba(99, 102, 241, 0.06);
            border: 1px solid rgba(99, 102, 241, 0.15);
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            line-height: 1.35;
        }

        /* Full Width Hub Banner (for Admin) */
        .pc-wide-hub {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 22px 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            margin-top: 10px;
        }

        .pc-wide-hub::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--school-gradient);
        }
    </style>
</head>

<body class="dashboard-body">
    <div class="split-container">
        <?php
        $active_page = 'print_center';
        $active_page_dean = 'print_center';
        if ($role == 'dean') {
            include 'dean_sidebar.php';
        } else {
            include 'admin_sidebar.php';
        }
        ?>

        <main class="main-content">

            <!-- Hero Page Header -->
            <div class="pc-page-header">
                <div class="pc-header-left">
                    <div class="pc-badge-row">
                        <span class="pc-pill accent"><i class="fas fa-print"></i> Official Report Generation</span>
                        <span class="pc-pill active"><span class="pc-dot-pulse"></span> Active: <?php echo htmlspecialchars($period_label); ?></span>
                        <?php if ($role === 'dean' && !empty($dean_dept_alias)): ?>
                            <span class="pc-pill" style="background: rgba(99, 102, 241, 0.1); color: var(--accent); border: 1px solid rgba(99, 102, 241, 0.2);">
                                <i class="fas fa-user-shield"></i> Dean Portal · <?php echo htmlspecialchars($dean_dept_alias); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 class="pc-title"><i class="fas fa-print" style="color:var(--accent);"></i> Print Center</h1>
                    <p class="pc-subtitle">Generate official QAO Form 013 instructor evaluation sheets, departmental summary reports, and export datasets.</p>
                </div>
                <div class="pc-header-actions">
                    <a href="import_evaluations.php" class="btn-upload-hub" title="Upload evaluation Excel or CSV file">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Upload Evaluation File</span>
                    </a>
                </div>
            </div>

            <!-- SECTION 1: FACULTY INDIVIDUAL REPORTS (2 COLUMNS) -->
            <div class="pc-section-header">
                <span class="pc-section-title"><i class="fas fa-chalkboard-teacher" style="color:var(--accent);"></i> Faculty Individual Evaluations (Form 013)</span>
                <span style="font-size:12px; color:var(--text-muted); font-weight:600;">MCNP & ISAP Instructors</span>
            </div>

            <div class="pc-grid-2">

                <!-- 1. MCNP Faculty Report Card -->
                <div class="pc-card mcnp-card">
                    <div>
                        <div class="pc-card-head">
                            <div class="pc-card-icon-frame">
                                <img src="MCNP_Logo.png" alt="MCNP Logo">
                            </div>
                            <div class="pc-card-meta">
                                <div class="pc-card-title">
                                    <span>MCNP Faculty Report</span>
                                    <span class="pc-card-tag" style="background: rgba(11, 83, 148, 0.12); color: #0b5394;">Form 013</span>
                                </div>
                                <p class="pc-card-desc">Medical Colleges of Northern Philippines · Individual Instructor Evaluation</p>
                            </div>
                        </div>

                        <?php if ($role === 'dean' && $dean_school === 'isap'): ?>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 12px; background: rgba(0,0,0,0.03); padding: 7px 11px; border-radius: 8px;">
                                <i class="fas fa-info-circle"></i> Your assigned department is under ISAP (<strong><?php echo htmlspecialchars($dean_dept_alias); ?></strong>).
                            </div>
                        <?php elseif ($role === 'dean' && $dean_school === 'mcnp'): ?>
                            <div style="font-size: 11.5px; color: #0b5394; font-weight: 600; margin-bottom: 12px; background: rgba(11, 83, 148, 0.08); padding: 7px 11px; border-radius: 8px;">
                                <i class="fas fa-check-circle"></i> Department Pre-selected: <strong><?php echo htmlspecialchars($dean_dept_alias); ?></strong>
                            </div>
                        <?php endif; ?>

                        <form action="print_report.php" method="GET" target="_blank" id="mcnpForm">
                            <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">
                            <input type="hidden" name="school" value="mcnp">

                            <!-- Department Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-building"></i> 1. Department</label>
                                <select id="mcnp_dept" onchange="filterPrograms('mcnp')" class="pc-select" required>
                                    <option value="" disabled <?php echo (!$dean_school || $dean_school !== 'mcnp') ? 'selected' : ''; ?>>-- Select MCNP Department --</option>
                                    <?php
                                    $mcnp_depts_sql = "SELECT * FROM departments WHERE dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE') ORDER BY dept_alias";
                                    $mcnp_depts = $conn->query($mcnp_depts_sql);
                                    if ($mcnp_depts) {
                                        while ($d = $mcnp_depts->fetch_assoc()) {
                                            $sel = ($role == 'dean' && $session_dept == $d['id']) ? 'selected' : '';
                                            echo "<option value='{$d['id']}' {$sel}>{$d['dept_alias']} — {$d['dept_name']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Program Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-graduation-cap"></i> 2. Program / Course</label>
                                <select id="mcnp_program" onchange="filterFaculty('mcnp')" class="pc-select" disabled>
                                    <option value="" disabled selected>-- Select Program / Course --</option>
                                    <?php
                                    $mcnp_courses_sql = "SELECT c.id, c.course_alias, c.course_name, c.dept_id FROM courses c JOIN departments d ON c.dept_id = d.id WHERE d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE') ORDER BY c.course_alias";
                                    $mcnp_courses = $conn->query($mcnp_courses_sql);
                                    if ($mcnp_courses) {
                                        while ($c = $mcnp_courses->fetch_assoc()) {
                                            echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}' style='display:none;'>{$c['course_alias']} — {$c['course_name']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Faculty Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-chalkboard-teacher"></i> 3. Faculty Instructor</label>
                                <select name="faculty_id" id="mcnp_faculty" required disabled class="pc-select">
                                    <option value="" disabled selected>-- Select Faculty Member --</option>
                                    <?php
                                    $query = "SELECT u.id, u.full_name, u.dept_id, u.course_id, c.course_alias FROM users u LEFT JOIN departments d ON u.dept_id = d.id LEFT JOIN courses c ON u.course_id = c.id WHERE u.role = 'faculty' AND u.status = 'active'";
                                    $query .= " AND (d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE') OR d.id IS NULL)";
                                    if ($role == 'dean' && $session_dept) {
                                        $query .= " AND u.dept_id = $session_dept";
                                    }
                                    $query .= " ORDER BY u.full_name ASC";
                                    $fac_res = $conn->query($query);
                                    if ($fac_res) {
                                        while ($f = $fac_res->fetch_assoc()) {
                                            $c_alias = !empty($f['course_alias']) ? " [{$f['course_alias']}]" : "";
                                            echo "<option value='{$f['id']}' class='mcnp-opt' data-dept='{$f['dept_id']}' data-course='{$f['course_id']}' style='display:none;'>" . htmlspecialchars($f['full_name'] . $c_alias) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                    </div>

                    <div class="pc-actions-stack">
                        <button type="submit" class="btn-pc btn-pc-mcnp">
                            <i class="fas fa-print"></i> Print MCNP Report (Form 013)
                        </button>
                        <button type="submit" formaction="export_excel.php" class="btn-pc btn-pc-excel">
                            <i class="fas fa-file-excel"></i> Export Excel Summary (.xls)
                        </button>
                    </div>
                    </form>
                </div>

                <!-- 2. ISAP Faculty Report Card -->
                <div class="pc-card isap-card">
                    <div>
                        <div class="pc-card-head">
                            <div class="pc-card-icon-frame">
                                <img src="ISAP_Logo.png" alt="ISAP Logo">
                            </div>
                            <div class="pc-card-meta">
                                <div class="pc-card-title">
                                    <span>ISAP Faculty Report</span>
                                    <span class="pc-card-tag" style="background: rgba(139, 0, 0, 0.12); color: #8B0000;">Form 013</span>
                                </div>
                                <p class="pc-card-desc">International School of Asia and the Pacific · Individual Instructor Evaluation</p>
                            </div>
                        </div>

                        <?php if ($role === 'dean' && $dean_school === 'mcnp'): ?>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 12px; background: rgba(0,0,0,0.03); padding: 7px 11px; border-radius: 8px;">
                                <i class="fas fa-info-circle"></i> Your assigned department is under MCNP (<strong><?php echo htmlspecialchars($dean_dept_alias); ?></strong>).
                            </div>
                        <?php elseif ($role === 'dean' && $dean_school === 'isap'): ?>
                            <div style="font-size: 11.5px; color: #8B0000; font-weight: 600; margin-bottom: 12px; background: rgba(139, 0, 0, 0.08); padding: 7px 11px; border-radius: 8px;">
                                <i class="fas fa-check-circle"></i> Department Pre-selected: <strong><?php echo htmlspecialchars($dean_dept_alias); ?></strong>
                            </div>
                        <?php endif; ?>

                        <form action="print_report.php" method="GET" target="_blank" id="isapForm">
                            <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">
                            <input type="hidden" name="school" value="isap">

                            <!-- Department Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-building"></i> 1. Department</label>
                                <select id="isap_dept" onchange="filterPrograms('isap')" class="pc-select" required>
                                    <option value="" disabled <?php echo (!$dean_school || $dean_school !== 'isap') ? 'selected' : ''; ?>>-- Select ISAP Department --</option>
                                    <?php
                                    $isap_depts_sql = "SELECT * FROM departments WHERE dept_alias IN ('CASTE','CBEM','CITE','COCJE') ORDER BY dept_alias";
                                    $isap_depts = $conn->query($isap_depts_sql);
                                    if ($isap_depts) {
                                        while ($d = $isap_depts->fetch_assoc()) {
                                            $sel = ($role == 'dean' && $session_dept == $d['id']) ? 'selected' : '';
                                            echo "<option value='{$d['id']}' {$sel}>{$d['dept_alias']} — {$d['dept_name']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Program Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-graduation-cap"></i> 2. Program / Course</label>
                                <select id="isap_program" onchange="filterFaculty('isap')" class="pc-select" disabled>
                                    <option value="" disabled selected>-- Select Program / Course --</option>
                                    <?php
                                    $isap_courses_sql = "SELECT c.id, c.course_alias, c.course_name, c.dept_id FROM courses c JOIN departments d ON c.dept_id = d.id WHERE d.dept_alias IN ('CASTE','CBEM','CITE','COCJE') ORDER BY c.course_alias";
                                    $isap_courses = $conn->query($isap_courses_sql);
                                    if ($isap_courses) {
                                        while ($c = $isap_courses->fetch_assoc()) {
                                            echo "<option value='{$c['id']}' data-dept='{$c['dept_id']}' style='display:none;'>{$c['course_alias']} — {$c['course_name']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Faculty Select -->
                            <div class="pc-form-group">
                                <label class="pc-label"><i class="fas fa-chalkboard-teacher"></i> 3. Faculty Instructor</label>
                                <select name="faculty_id" id="isap_faculty" required disabled class="pc-select">
                                    <option value="" disabled selected>-- Select Faculty Member --</option>
                                    <?php
                                    $query = "SELECT u.id, u.full_name, u.dept_id, u.course_id, c.course_alias FROM users u LEFT JOIN departments d ON u.dept_id = d.id LEFT JOIN courses c ON u.course_id = c.id WHERE u.role = 'faculty' AND u.status = 'active'";
                                    $query .= " AND d.dept_alias IN ('CASTE','CBEM','CITE','COCJE')";
                                    if ($role == 'dean' && $session_dept) {
                                        $query .= " AND u.dept_id = $session_dept";
                                    }
                                    $query .= " ORDER BY u.full_name ASC";
                                    $fac_res = $conn->query($query);
                                    if ($fac_res) {
                                        while ($f = $fac_res->fetch_assoc()) {
                                            $c_alias = !empty($f['course_alias']) ? " [{$f['course_alias']}]" : "";
                                            echo "<option value='{$f['id']}' class='isap-opt' data-dept='{$f['dept_id']}' data-course='{$f['course_id']}' style='display:none;'>" . htmlspecialchars($f['full_name'] . $c_alias) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                    </div>

                    <div class="pc-actions-stack">
                        <button type="submit" class="btn-pc btn-pc-isap">
                            <i class="fas fa-print"></i> Print ISAP Report (Form 013)
                        </button>
                        <button type="submit" formaction="export_excel.php" class="btn-pc btn-pc-excel">
                            <i class="fas fa-file-excel"></i> Export Excel Summary (.xls)
                        </button>
                    </div>
                    </form>
                </div>

            </div>

            <!-- SECTION 2: SUMMARIES & DATA MANAGEMENT -->
            <div class="pc-section-header">
                <span class="pc-section-title"><i class="fas fa-chart-pie" style="color:var(--accent);"></i> Performance Summaries & Data Tools</span>
                <span style="font-size:12px; color:var(--text-muted); font-weight:600;">
                    <?php echo ($role === 'dean') ? 'Department Metrics & Templates' : 'Campus-wide Summaries & Templates'; ?>
                </span>
            </div>

            <?php if ($role === 'dean'): ?>
                <!-- DEAN BALANCED 2-COLUMN SECTION -->
                <div class="pc-grid-2">

                    <!-- Department Summary Card -->
                    <div class="pc-card dept-card">
                        <div>
                            <div class="pc-card-head">
                                <div class="pc-card-icon-frame" style="color: #6366f1;">
                                    <i class="fas fa-building-columns"></i>
                                </div>
                                <div class="pc-card-meta">
                                    <div class="pc-card-title">
                                        <span>Department Summary</span>
                                        <span class="pc-card-tag" style="background: rgba(99, 102, 241, 0.12); color: #6366f1;">Form 014</span>
                                    </div>
                                    <p class="pc-card-desc">Consolidated faculty ratings and performance metrics for your department.</p>
                                </div>
                            </div>

                            <form action="print_dept_summary.php" method="GET" target="_blank">
                                <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">

                                <div class="pc-form-group">
                                    <label class="pc-label"><i class="fas fa-building"></i> Target Department</label>
                                    <select name="dept_id" required class="pc-select">
                                        <option value="" disabled <?php echo (!$session_dept) ? 'selected' : ''; ?>>-- Select Department --</option>
                                        <?php
                                        $dept_query = "SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC";
                                        $dept_res = $conn->query($dept_query);
                                        if ($dept_res) {
                                            while ($d = $dept_res->fetch_assoc()) {
                                                if ($session_dept && $d['id'] != $session_dept) continue;
                                                $sel = ($session_dept == $d['id']) ? 'selected' : '';
                                                echo "<option value='{$d['id']}' {$sel}>{$d['dept_alias']} — {$d['dept_name']}</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="pc-notice-pill">
                                    <i class="fas fa-chart-line" style="color: #6366f1;"></i>
                                    <span>Includes mean scores, criteria radar breakdowns, and sentiment analytics.</span>
                                </div>
                        </div>

                        <div class="pc-actions-stack">
                            <button type="submit" class="btn-pc btn-pc-dept">
                                <i class="fas fa-print"></i> Print Department Summary
                            </button>
                        </div>
                        </form>
                    </div>

                    <!-- Upload & Template Management Card -->
                    <div class="pc-card import-card">
                        <div>
                            <div class="pc-card-head">
                                <div class="pc-card-icon-frame" style="color: #10b981;">
                                    <i class="fas fa-file-import"></i>
                                </div>
                                <div class="pc-card-meta">
                                    <div class="pc-card-title">
                                        <span>Evaluation Data & Templates</span>
                                        <span class="pc-card-tag" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">Excel / CSV</span>
                                    </div>
                                    <p class="pc-card-desc">Import external student survey responses or download official QAO templates.</p>
                                </div>
                            </div>

                            <div class="pc-notice-pill" style="background: rgba(16, 185, 129, 0.07); border-color: rgba(16, 185, 129, 0.2);">
                                <i class="fas fa-check-circle" style="color: #10b981;"></i>
                                <span>Batch import student evaluation responses directly into the database.</span>
                            </div>

                            <p style="font-size: 12.5px; color: var(--text-muted); margin: 0 0 14px 0; line-height: 1.45;">
                                Download the official evaluation format templates to organize instructor ratings before uploading.
                            </p>
                        </div>

                        <div class="pc-actions-stack">
                            <a href="import_evaluations.php" class="btn-pc btn-pc-import">
                                <i class="fas fa-cloud-upload-alt"></i> Import Evaluations Now
                            </a>
                            <div class="pc-template-row">
                                <a href="download_template.php?format=excel" class="btn-pc-template" title="Download Excel format template">
                                    <i class="fas fa-file-excel" style="color: #107c41;"></i> Excel Template (.xlsx)
                                </a>
                                <a href="download_template.php?format=csv" class="btn-pc-template" title="Download CSV format template">
                                    <i class="fas fa-file-csv" style="color: #0284c7;"></i> CSV Template (.csv)
                                </a>
                            </div>
                        </div>
                    </div>

                </div>

            <?php else: ?>
                <!-- QA / SYSTEM ADMIN 3-COLUMN SUMMARY SECTION -->
                <div class="pc-grid-3">

                    <!-- Department Summary Card -->
                    <div class="pc-card dept-card">
                        <div>
                            <div class="pc-card-head">
                                <div class="pc-card-icon-frame" style="color: #6366f1;">
                                    <i class="fas fa-building-columns"></i>
                                </div>
                                <div class="pc-card-meta">
                                    <div class="pc-card-title">
                                        <span>Department Summary</span>
                                        <span class="pc-card-tag" style="background: rgba(99, 102, 241, 0.12); color: #6366f1;">Form 014</span>
                                    </div>
                                    <p class="pc-card-desc">Overall department ratings and statistical analytics.</p>
                                </div>
                            </div>

                            <form action="print_dept_summary.php" method="GET" target="_blank">
                                <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">

                                <div class="pc-form-group">
                                    <label class="pc-label"><i class="fas fa-building"></i> Target Department</label>
                                    <select name="dept_id" required class="pc-select">
                                        <option value="" disabled selected>-- Select Department --</option>
                                        <?php
                                        $dept_query = "SELECT id, dept_name, dept_alias FROM departments ORDER BY dept_alias ASC";
                                        $dept_res = $conn->query($dept_query);
                                        if ($dept_res) {
                                            while ($d = $dept_res->fetch_assoc()) {
                                                echo "<option value='{$d['id']}'>{$d['dept_alias']} — {$d['dept_name']}</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                        </div>

                        <div class="pc-actions-stack">
                            <button type="submit" class="btn-pc btn-pc-dept">
                                <i class="fas fa-print"></i> Print Department Summary
                            </button>
                        </div>
                        </form>
                    </div>

                    <!-- Overall School Summary Card -->
                    <div class="pc-card school-card">
                        <div>
                            <div class="pc-card-head">
                                <div class="pc-card-icon-frame" style="color: #10b981;">
                                    <i class="fas fa-school"></i>
                                </div>
                                <div class="pc-card-meta">
                                    <div class="pc-card-title">
                                        <span>School Summary</span>
                                        <span class="pc-card-tag" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">Campus-wide</span>
                                    </div>
                                    <p class="pc-card-desc">Comprehensive institutional results for MCNP & ISAP.</p>
                                </div>
                            </div>

                            <form action="print_school_summary.php" method="GET" target="_blank">
                                <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">

                                <div class="pc-form-group">
                                    <label class="pc-label"><i class="fas fa-university"></i> Target School</label>
                                    <select name="school" required class="pc-select">
                                        <option value="" disabled selected>-- Select School --</option>
                                        <option value="all">All Schools (MCNP + ISAP)</option>
                                        <option value="mcnp">MCNP — Medical Colleges</option>
                                        <option value="isap">ISAP — International School</option>
                                    </select>
                                </div>
                        </div>

                        <div class="pc-actions-stack">
                            <button type="submit" class="btn-pc btn-pc-school">
                                <i class="fas fa-print"></i> Print School Summary
                            </button>
                        </div>
                        </form>
                    </div>

                    <!-- Campus Services Report Card -->
                    <div class="pc-card service-card">
                        <div>
                            <div class="pc-card-head">
                                <div class="pc-card-icon-frame" style="color: #f59e0b;">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="pc-card-meta">
                                    <div class="pc-card-title">
                                        <span>Campus Services</span>
                                        <span class="pc-card-tag" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">Form 016</span>
                                    </div>
                                    <p class="pc-card-desc">Evaluation results for facilities and administrative offices.</p>
                                </div>
                            </div>

                            <form action="print_services_report.php" method="GET" target="_blank">
                                <input type="hidden" name="period_id" value="<?php echo $active_id; ?>">

                                <div class="pc-form-group">
                                    <label class="pc-label"><i class="fas fa-concierge-bell"></i> Target Service</label>
                                    <select name="service" required class="pc-select">
                                        <option value="" disabled selected>-- Select Campus Service --</option>
                                        <option value="all">All Campus Services</option>
                                        <option value="General School">General School</option>
                                        <option value="Canteen Services">Canteen Services</option>
                                        <option value="Science Lab">Science Lab</option>
                                        <option value="Computer Lab">Computer Lab</option>
                                        <option value="School Gym">School Gym</option>
                                        <option value="Registrar Office">Registrar Office</option>
                                        <option value="Finance Office">Finance Office</option>
                                        <option value="School Library">School Library</option>
                                        <option value="Security Unit">Security Unit</option>
                                        <option value="Guidance Office">Guidance Office</option>
                                        <option value="School Clinic">School Clinic</option>
                                        <option value="IT & WiFi Services">IT & WiFi Services</option>
                                        <option value="Classrooms">Classrooms</option>
                                        <option value="Comfort Rooms">Comfort Rooms</option>
                                        <option value="Campus Common Areas">Campus Common Areas</option>
                                    </select>
                                </div>
                        </div>

                        <div class="pc-actions-stack">
                            <button type="submit" class="btn-pc btn-pc-service">
                                <i class="fas fa-print"></i> Print Services Report
                            </button>
                        </div>
                        </form>
                    </div>

                </div>

                <!-- ADMIN DATA MANAGEMENT BANNER -->
                <div class="pc-wide-hub">
                    <div style="display:flex; align-items:center; gap:16px;">
                        <div class="pc-card-icon-frame" style="color: #10b981; width:52px; height:52px;">
                            <i class="fas fa-file-import"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 3px 0; font-size:16px; font-weight:800; color:var(--text-main);">
                                Evaluation Data Import & Template Center
                            </h3>
                            <p style="margin:0; font-size:12.5px; color:var(--text-muted);">
                                Upload external Excel/CSV survey files or download standardized QAO template sheets.
                            </p>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <a href="download_template.php?format=excel" class="btn-pc-template" style="height:38px; padding:0 14px;">
                            <i class="fas fa-file-excel" style="color: #107c41;"></i> Excel Template
                        </a>
                        <a href="download_template.php?format=csv" class="btn-pc-template" style="height:38px; padding:0 14px;">
                            <i class="fas fa-file-csv" style="color: #0284c7;"></i> CSV Template
                        </a>
                        <a href="import_evaluations.php" class="btn-upload-hub" style="height:38px; padding:0 18px; box-sizing:border-box;">
                            <i class="fas fa-cloud-upload-alt"></i> Import Now
                        </a>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        function filterPrograms(school) {
            const deptSelect = document.getElementById(school + '_dept');
            const progSelect = document.getElementById(school + '_program');
            const facSelect = document.getElementById(school + '_faculty');
            if (!deptSelect || !progSelect || !facSelect) return;

            const selectedDept = deptSelect.value;

            // Reset child dropdowns
            progSelect.value = "";
            progSelect.disabled = !selectedDept;
            facSelect.value = "";
            facSelect.disabled = !selectedDept;

            let progCount = 0;
            const progOptions = progSelect.querySelectorAll('option[data-dept]');
            progOptions.forEach(opt => {
                if (opt.getAttribute('data-dept') === selectedDept) {
                    opt.style.display = '';
                    opt.hidden = false;
                    progCount++;
                } else {
                    opt.style.display = 'none';
                    opt.hidden = true;
                }
            });

            // Enable and show faculty in this department immediately
            let facCount = 0;
            const facOptions = facSelect.querySelectorAll('option[data-dept]');
            facOptions.forEach(opt => {
                if (opt.getAttribute('data-dept') === selectedDept) {
                    opt.style.display = '';
                    opt.hidden = false;
                    facCount++;
                } else {
                    opt.style.display = 'none';
                    opt.hidden = true;
                }
            });

            if (facCount === 0) {
                facSelect.disabled = true;
            }
        }

        function filterFaculty(school) {
            const deptSelect = document.getElementById(school + '_dept');
            const progSelect = document.getElementById(school + '_program');
            const facSelect = document.getElementById(school + '_faculty');
            if (!deptSelect || !progSelect || !facSelect) return;

            const selectedDept = deptSelect.value;
            const selectedCourse = progSelect.value;

            facSelect.value = "";
            facSelect.disabled = false;

            let count = 0;
            const facOptions = facSelect.querySelectorAll('option[data-dept]');
            facOptions.forEach(opt => {
                const matchCourse = (!selectedCourse || opt.getAttribute('data-course') === selectedCourse);
                const matchDept = (opt.getAttribute('data-dept') === selectedDept);
                if (matchCourse && matchDept) {
                    opt.style.display = '';
                    opt.hidden = false;
                    count++;
                } else {
                    opt.style.display = 'none';
                    opt.hidden = true;
                }
            });

            if (count === 0) {
                facSelect.disabled = true;
            }
        }

        // Auto-initialize pre-selected department on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($role === 'dean' && $dean_school && $session_dept): ?>
                filterPrograms('<?php echo $dean_school; ?>');
            <?php endif; ?>
        });
    </script>
</body>

</html>