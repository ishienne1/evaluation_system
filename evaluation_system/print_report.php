<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';
include_once 'process_sentiment.php';

// Access control — admin (QA), dean, faculty allowed; ICT blocked
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'dean', 'faculty'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'system admin') {
        header("Location: dashboard_ict.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$role          = $_SESSION['role'];
$session_id    = $_SESSION['id'] ?? 0;
$session_dept  = $_SESSION['dept_id'] ?? null;

// Faculty can only print their own report
$faculty_id = (int)($_GET['faculty_id'] ?? 0);
if ($role === 'faculty') {
    $faculty_id = $session_id;
}

// Strictly lock to the currently active academic period
$active_p = $conn->query("SELECT * FROM academic_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_id = (int)($active_p['id'] ?? 1);
$period_info = $active_p;

// School Year: strictly set to 2026-2027
$school_year_raw = trim($period_info['school_year'] ?? '2026-2027');
if ($school_year_raw === '2026' || empty($school_year_raw)) {
    $school_year_display = '2026-2027';
} else {
    $school_year_display = $school_year_raw;
}

// Active Semester: First or Second
$sem_raw = trim($period_info['semester'] ?? '1st Semester');
if (stripos($sem_raw, '1') !== false || stripos($sem_raw, 'first') !== false) {
    $semester_display = 'First';
    $semester_word    = 'first';
} elseif (stripos($sem_raw, '2') !== false || stripos($sem_raw, 'second') !== false) {
    $semester_display = 'Second';
    $semester_word    = 'second';
} else {
    $semester_display = $sem_raw;
    $semester_word    = strtolower($sem_raw);
}

// Get faculty info + department + school
$faculty = null;
$dept    = null;
$school  = 'mcnp'; // default

if ($faculty_id) {
    $f_stmt = $conn->prepare("
        SELECT u.*, d.dept_alias, d.dept_name, c.course_alias, c.course_name
        FROM users u
        LEFT JOIN departments d ON u.dept_id = d.id
        LEFT JOIN courses c ON u.course_id = c.id
        WHERE u.id = ?
    ");
    $f_stmt->bind_param("i", $faculty_id);
    $f_stmt->execute();
    $faculty = $f_stmt->get_result()->fetch_assoc();

    if ($faculty) {
        $dept = $faculty['dept_alias'] ?? '';
        if (!empty($faculty['school'])) {
            $school = strtolower(trim($faculty['school']));
        } else {
            $isap_depts = ['CASTE','CBEM','CITE','COCJE'];
            $school = in_array($dept, $isap_depts) ? 'isap' : 'mcnp';
        }
    }
}

// Override school if passed in URL
if (isset($_GET['school']) && in_array($_GET['school'], ['mcnp','isap'])) {
    $school = $_GET['school'];
}

// Dean can only see their department
if ($role === 'dean' && $faculty && $faculty['dept_id'] != $session_dept) {
    header("Location: view_results.php"); exit();
}

// Faculty Names Formatting
// 1. Table format: LASTNAME, FIRSTNAME [MIDDLE]
$formatted_faculty_table_name = 'N/A';
$formatted_faculty_sig_name   = 'N/A';

if ($faculty) {
    if (!empty($faculty['last_name']) && !empty($faculty['first_name'])) {
        $lName = strtoupper(trim($faculty['last_name']));
        $fName = strtoupper(trim($faculty['first_name']));
        $mName = !empty($faculty['middle_name']) ? ' ' . strtoupper(trim($faculty['middle_name'])) : '';
        $formatted_faculty_table_name = "{$lName}, {$fName}{$mName}";
        $formatted_faculty_sig_name   = "{$fName}{$mName} {$lName}";
    } else {
        $fullName = trim($faculty['full_name'] ?? '');
        if (strpos($fullName, ',') !== false) {
            $formatted_faculty_table_name = strtoupper($fullName);
            $parts = explode(',', $fullName);
            $formatted_faculty_sig_name = strtoupper(trim($parts[1] ?? '') . ' ' . trim($parts[0] ?? ''));
        } else {
            $parts = explode(' ', $fullName);
            if (count($parts) > 1) {
                $last = array_pop($parts);
                $first = implode(' ', $parts);
                $formatted_faculty_table_name = strtoupper("{$last}, {$first}");
                $formatted_faculty_sig_name   = strtoupper("{$first} {$last}");
            } else {
                $formatted_faculty_table_name = strtoupper($fullName);
                $formatted_faculty_sig_name   = strtoupper($fullName);
            }
        }
    }
}

// College / Department
$college_dept = 'INFORMATION TECHNOLOGY';
if ($faculty) {
    if (!empty($faculty['course_name'])) {
        $cClean = preg_replace('/^Bachelor of (Science in|Secondary Education in|Arts in)?\s*/i', '', $faculty['course_name']);
        $college_dept = strtoupper(trim($cClean));
    } elseif (!empty($faculty['dept_name'])) {
        $college_dept = strtoupper(trim($faculty['dept_name']));
    } elseif (!empty($faculty['dept_alias'])) {
        $college_dept = strtoupper(trim($faculty['dept_alias']));
    }
}

// Employment Status
$employment_status = 'FULL-TIME';

// Subjects Taught
$subjects_list = [];
if ($faculty_id && $period_id) {
    $sub_stmt = $conn->prepare("
        SELECT DISTINCT s.subject_code, s.subject_description 
        FROM subjects s 
        LEFT JOIN classes c ON (c.subject_id = s.id AND c.period_id = ?)
        WHERE s.faculty_id = ? OR c.faculty_id = ?
        ORDER BY s.subject_code ASC
    ");
    $sub_stmt->bind_param("iii", $period_id, $faculty_id, $faculty_id);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    while ($s_row = $sub_res->fetch_assoc()) {
        $code = trim($s_row['subject_code']);
        $desc = trim($s_row['subject_description']);
        $subjects_list[] = !empty($desc) ? "{$code} ({$desc})" : $code;
    }
}
$subjects_taught = !empty($subjects_list) ? implode(', ', $subjects_list) : '';

// Other Assignment/s
$other_assignments = 'None';

// Immediate Supervisor
$supervisor_name = 'ENGR. MYKE ALBERT TEJADA, MSEE-CpE';
if (!empty($faculty['dept_id'])) {
    $d_stmt = $conn->prepare("SELECT full_name FROM users WHERE role='dean' AND dept_id=? AND status='active' LIMIT 1");
    $d_stmt->bind_param("i", $faculty['dept_id']);
    $d_stmt->execute();
    $dean_res = $d_stmt->get_result()->fetch_assoc();
    if ($dean_res && !empty($dean_res['full_name']) && stripos($dean_res['full_name'], 'Dean ') === false) {
        $supervisor_name = strtoupper($dean_res['full_name']);
    }
}

// Evaluation Data
$overall_avg     = 0;
$total_responses = 0;
$category_scores = [];
$all_comments    = [];

if ($faculty_id && $period_id) {
    // Count distinct student evaluations
    $avg_stmt = $conn->prepare("SELECT AVG(rating) as avg_r, COUNT(*) as cnt FROM evaluations WHERE faculty_id=? AND academic_period_id=?");
    $avg_stmt->bind_param("ii", $faculty_id, $period_id);
    $avg_stmt->execute();
    $avg_row = $avg_stmt->get_result()->fetch_assoc();
    $overall_avg     = round((float)($avg_row['avg_r'] ?? 0), 2);
    $total_responses = (int)($avg_row['cnt'] ?? 0);

    // Scores by category
    $cat_stmt = $conn->prepare("
        SELECT q.category, AVG(r.rating) as score, COUNT(DISTINCT e.id) as responses
        FROM evaluation_responses r
        JOIN evaluation_questions q ON r.question_id = q.id
        JOIN evaluations e ON r.evaluation_id = e.id
        WHERE e.faculty_id=? AND e.academic_period_id=?
        GROUP BY q.category ORDER BY MIN(q.id) ASC
    ");
    $cat_stmt->bind_param("ii", $faculty_id, $period_id);
    $cat_stmt->execute();
    $category_scores = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Comments / Qualitative Feedback
    $com_stmt = $conn->prepare("
        SELECT e.feedback, e.rating, e.created_at, u.username as student_identifier
        FROM evaluations e
        LEFT JOIN users u ON e.student_id = u.id
        WHERE e.faculty_id=? AND e.academic_period_id=? AND e.feedback IS NOT NULL AND TRIM(e.feedback) != ''
        ORDER BY e.created_at DESC
    ");
    $com_stmt->bind_param("ii", $faculty_id, $period_id);
    $com_stmt->execute();
    $all_comments = $com_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Rank Interpretation Function (Exact scale from official document)
if (!function_exists('getRankInterpretation')) {
    function getRankInterpretation($score) {
        if ($score >= 4.20) return 'Excellent';
        if ($score >= 3.40) return 'Very Good';
        if ($score >= 2.60) return 'Good';
        if ($score >= 1.80) return 'Fair';
        if ($score >= 1.00) return 'Poor';
        return 'N/A';
    }
}

if (!function_exists('toRoman')) {
    function toRoman($num) {
        $romans = [
            10 => 'X', 9 => 'IX', 8 => 'VIII', 7 => 'VII', 6 => 'VI',
            5 => 'V', 4 => 'IV', 3 => 'III', 2 => 'II', 1 => 'I'
        ];
        foreach ($romans as $val => $rom) {
            if ($num >= $val) return $rom;
        }
        return (string)$num;
    }
}

// Prepare Criteria Rows
$criteria_rows = [];
if (!empty($category_scores)) {
    $idx = 1;
    foreach ($category_scores as $cs) {
        $cName = trim($cs['category']);
        // Add Roman numeral if not already present
        if (!preg_match('/^[IVXLCDM]+\.\s*/i', $cName)) {
            $cName = toRoman($idx) . '. ' . $cName;
        }
        $criteria_rows[] = [
            'criteria'  => $cName,
            'responses' => $cs['responses'] ?: $total_responses,
            'mean'      => number_format((float)$cs['score'], 2)
        ];
        $idx++;
    }
} else {
    // Standard default criteria matching official QMS-DCO-Form No: 013
    $criteria_rows = [
        ['criteria' => 'I. Conduct of Online Class',   'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
        ['criteria' => 'II. Conduct of In-person Class', 'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
        ['criteria' => 'III. Feedbacking Mechanism',     'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
    ];
}

$descriptive_interpretation = ($overall_avg > 0) ? getRankInterpretation($overall_avg) : 'N/A';

// School config
$schools = [
    'mcnp' => [
        'name'    => 'MEDICAL COLLEGES OF NORTHERN PHILIPPINES',
        'address' => 'Alimannao Hills, Peñablanca, Cagayan 3502 Philippines',
        'phone'   => 'Telefax No: (+63) (078) 304-1010 Website: www.mcnp.edu.ph',
        'email'   => 'E-Mail Address: qaoffice@mcnp.edu.ph/dco@mcnp.edu.ph',
        'logo'    => 'MCNP_Logo.png',
        'short'   => 'MCNP',
    ],
    'isap' => [
        'name'    => 'INTERNATIONAL SCHOOL OF ASIA AND THE PACIFIC',
        'address' => 'Alimannao Hills, Peñablanca, Cagayan 3502 Philippines',
        'phone'   => 'Telefax No: (+63) (078) 304-1010 Website: www.isap.edu.ph',
        'email'   => 'E-Mail Address: qaoffice@isap.edu.ph/dco@isap.edu.ph',
        'logo'    => 'ISAP_Logo.png',
        'short'   => 'ISAP',
    ],
];
$sc = $schools[$school] ?? $schools['mcnp'];

// Base64 encode logo
$base64Logo = '';
if (file_exists($sc['logo'])) {
    $type = pathinfo($sc['logo'], PATHINFO_EXTENSION);
    $data = file_get_contents($sc['logo']);
    $base64Logo = 'data:image/' . $type . ';base64,' . base64_encode($data);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QMS-DCO-Form No: 013 — SUMMARY OF EVALUATION (<?php echo htmlspecialchars($formatted_faculty_table_name); ?>)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
    /* ── RESET & BASE ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #525659;
        color: #000;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* ── ACTION BAR ── */
    .action-bar {
        background: #1e293b;
        color: white;
        padding: 10px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 9999;
        box-shadow: 0 4px 15px rgba(0,0,0,0.35);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .action-bar-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .back-btn {
        color: #94a3b8;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: color 0.2s;
    }
    .back-btn:hover { color: white; }
    .action-bar-title {
        font-size: 14px;
        font-weight: 600;
        color: #f8fafc;
    }
    .action-bar-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .btn-act {
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        transition: transform 0.15s, opacity 0.15s;
    }
    .btn-act:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-print { background: #3b82f6; color: white; }
    .btn-pdf { background: #ef4444; color: white; }
    .btn-word { background: #2563eb; color: white; }

    /* ── PAGE CONTAINER (PREVIEW MODE) ── */
    .pages-container {
        padding: 30px 10px 60px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 30px;
    }

    .sheet {
        background: #fff;
        width: 210mm;
        min-height: 297mm;
        padding: 10mm 15mm 10mm 15mm;
        box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    /* ── LETTERHEAD & TOP HEADER ROW (Box on top right) ── */
    .top-header-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        margin-bottom: 6px;
        width: 100%;
        position: relative;
    }
    .top-logo-container {
        width: 180px;
        flex-shrink: 0;
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
        padding-left: 5px;
    }
    .letterhead-logo {
        width: 68px;
        height: 68px;
        object-fit: contain;
    }
    .letterhead-content {
        flex: 1;
        text-align: center;
        padding: 0 5px;
    }
    .school-name {
        font-size: 13pt;
        font-weight: bold;
        letter-spacing: 0.2px;
        color: #000;
        margin-bottom: 2px;
        line-height: 1.15;
    }
    .school-sub {
        font-size: 8pt;
        line-height: 1.35;
        color: #111;
    }
    .top-doc-box-container {
        width: 180px;
        flex-shrink: 0;
        display: flex;
        justify-content: flex-end;
    }
    .doc-control-box {
        width: 180px;
        border-collapse: collapse;
        border: 1px solid #000;
        font-size: 8pt;
        line-height: 1.15;
    }
    .doc-control-box td {
        border: 1px solid #000;
        padding: 2.5px 5px;
        color: #000;
        text-align: left;
    }

    /* ── TITLES BLOCK ── */
    .titles-block {
        text-align: center;
        margin-top: 4px;
        margin-bottom: 8px;
        width: 100%;
    }
    .titles-block .office-title {
        font-size: 10pt;
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    .titles-block .main-title {
        font-size: 11pt;
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    .titles-block .sub-title {
        font-size: 10pt;
        font-weight: bold;
        letter-spacing: 0.5px;
    }

    /* ── PERIOD BAR ── */
    .period-bar {
        display: flex;
        justify-content: space-between;
        font-size: 9.5pt;
        font-weight: bold;
        margin-bottom: 4px;
        padding: 0 1px;
    }
    .period-bar span.val {
        font-weight: bold;
        font-style: italic;
    }

    /* ── FACULTY INFO TABLE ── */
    .info-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #000;
        font-size: 9pt;
        margin-bottom: 6px;
    }
    .info-table td {
        border: 1px solid #000;
        padding: 3.5px 6px;
        vertical-align: middle;
    }
    .info-table td.lbl {
        font-weight: bold;
    }

    /* ── RANK EQUIVALENT BOX ── */
    .rank-box {
        border: 1px solid #000;
        padding: 4px 10px;
        font-size: 8.5pt;
        margin-bottom: 6px;
        text-align: center;
    }
    .rank-box-title {
        font-weight: bold;
        font-size: 9pt;
        margin-bottom: 3px;
        letter-spacing: 0.5px;
    }
    .rank-grid {
        display: flex;
        justify-content: space-around;
        text-align: left;
        line-height: 1.35;
        padding: 0 20px;
    }
    .rank-col {
        display: flex;
        flex-direction: column;
    }
    .rank-center-bottom {
        text-align: center;
        margin-top: 1px;
        line-height: 1.35;
    }

    /* ── RESULT/S TABLE ── */
    .results-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #000;
        font-size: 9pt;
        margin-bottom: 6px;
    }
    .results-table th, .results-table td {
        border: 1px solid #000;
        padding: 4px 6px;
    }
    .results-table th {
        font-weight: bold;
        text-align: center;
        background: #fff;
    }
    .results-banner {
        text-align: center;
        font-weight: bold;
        letter-spacing: 3px;
        font-size: 9pt;
        padding: 3px !important;
    }
    .col-criteria { width: 34%; text-align: left; }
    .col-responses { width: 14%; text-align: center; }
    .col-mean { width: 14%; text-align: center; }
    .col-comments { width: 38%; text-align: center; font-weight: bold; vertical-align: middle; }

    .row-summary {
        font-weight: bold;
    }
    .row-summary td.lbl {
        text-align: right;
        padding-right: 15px;
    }

    /* ── SIGNATORIES SECTION ── */
    .signatories-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #000;
        font-size: 8.5pt;
        margin-bottom: 5px;
    }
    .signatories-table tr {
        border: none;
    }
    .signatories-table td {
        width: 50%;
        padding: 5px 12px;
        border: none;
        vertical-align: bottom;
    }
    .sig-col-left {
        border-right: 1px solid #000 !important;
    }
    .sig-label {
        font-weight: bold;
        font-style: italic;
        margin-bottom: 10px;
    }
    .sig-person {
        text-align: center;
    }
    .sig-name {
        font-weight: bold;
        text-decoration: underline;
        font-size: 9pt;
        margin-bottom: 2px;
    }
    .sig-role {
        font-size: 8pt;
        font-style: italic;
    }
    .conforme-text {
        font-size: 7.8pt;
        font-style: italic;
        line-height: 1.25;
        margin-bottom: 10px;
    }

    /* ── FOOTER TABLE (100% PANTAY & NEVER WRAPS) ── */
    .sheet-footer-table {
        width: 100%;
        border-collapse: collapse;
        border: none !important;
        font-size: 6.8pt;
        line-height: 1.15;
        margin-top: 4px;
        white-space: nowrap;
    }
    .sheet-footer-table td {
        border: none !important;
        padding: 0 !important;
        vertical-align: baseline !important;
        white-space: nowrap !important;
    }
    .sheet-footer-table td.footer-left {
        font-style: italic;
        text-align: left;
        color: #000;
    }
    .sheet-footer-table td.footer-date {
        text-align: right;
        padding-left: 20px !important;
        width: 1%;
        white-space: nowrap;
    }
    .sheet-footer-table td.footer-page {
        text-align: right;
        padding-left: 20px !important;
        width: 1%;
        white-space: nowrap;
    }

    /* ── PAGE 2 COMMENTS TABLE ── */
    .comments-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #000;
        font-size: 9pt;
        margin-top: 10px;
        margin-bottom: auto;
    }
    .comments-table th, .comments-table td {
        border: 1px solid #000;
        padding: 6px 8px;
        vertical-align: top;
    }
    .comments-table th {
        background: #f8f9fa;
        font-weight: bold;
        text-align: center;
    }

    /* ── PRINT RULES ── */
    @media print {
        @page {
            size: A4 portrait;
            margin: 6mm 10mm 6mm 10mm;
        }
        body {
            background: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .action-bar { display: none !important; }
        .pages-container {
            padding: 0 !important;
            gap: 0 !important;
        }
        .sheet {
            width: 100% !important;
            min-height: auto !important;
            height: auto !important;
            box-shadow: none !important;
            padding: 4mm 6mm 4mm 6mm !important;
            margin: 0 !important;
            page-break-after: always !important;
            break-after: page !important;
        }
        .sheet:last-child {
            page-break-after: auto !important;
            break-after: auto !important;
        }
        .page-break {
            page-break-before: always !important;
            break-before: page !important;
        }
    }
    </style>
</head>
<body>

<!-- Action Bar (hidden on paper / print) -->
<div class="action-bar">
    <div class="action-bar-left">
        <a href="javascript:history.back()" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Print Center
        </a>
        <div class="action-bar-title">
            <i class="fas fa-file-invoice"></i> Evaluation Report — <?php echo htmlspecialchars($formatted_faculty_table_name); ?>
        </div>
    </div>
    <div class="action-bar-right">
        <button class="btn-act btn-print" onclick="window.print()" title="Open browser print dialog">
            <i class="fas fa-print"></i> Print Document
        </button>
        <button class="btn-act btn-pdf" onclick="downloadPDF('ratings')" title="Download official Form 013 Ratings Summary PDF (Page 1)">
            <i class="fas fa-file-pdf"></i> Ratings PDF (Form 013)
        </button>
        <button class="btn-act btn-pdf" style="background:#334155;" onclick="downloadPDF('full')" title="Download complete 2-page report (Ratings + Comments)">
            <i class="fas fa-file-alt"></i> Full PDF (Ratings + Comments)
        </button>
        <a class="btn-act btn-word" href="export_word.php?faculty_id=<?php echo $faculty_id; ?>&period_id=<?php echo $period_id; ?>&school=<?php echo $school; ?>" title="Export report to Word (.doc)">
            <i class="fas fa-file-word"></i> Download Word
        </a>
        <a class="btn-act btn-excel" href="export_excel.php?faculty_id=<?php echo $faculty_id; ?>&period_id=<?php echo $period_id; ?>&school=<?php echo $school; ?>" style="background:#107c41; color:white;" title="Export multi-sheet Excel (.xls) with Ratings & Comments">
            <i class="fas fa-file-excel"></i> Ratings &amp; Comments (Excel)
        </a>
    </div>
</div>

<div class="pages-container" id="printable-document">

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- 📄 PAGE 1: OFFICIAL STUDENT-FACULTY EVALUATION SUMMARY      -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="sheet" id="page-1">
        <div>
            <!-- Top Header Row: Logo (left), School Info (center), Document Control Box (flush top-right) -->
            <div class="top-header-row">
                <div class="top-logo-container">
                    <?php if (!empty($base64Logo)): ?>
                        <img src="<?php echo $base64Logo; ?>" alt="School Logo" class="letterhead-logo">
                    <?php endif; ?>
                </div>
                <div class="letterhead-content">
                    <div class="school-name"><?php echo htmlspecialchars($sc['name']); ?></div>
                    <div class="school-sub">
                        <?php echo htmlspecialchars($sc['address']); ?><br>
                        <?php echo htmlspecialchars($sc['phone']); ?><br>
                        <?php echo htmlspecialchars($sc['email']); ?>
                    </div>
                </div>
                <div class="top-doc-box-container">
                    <table class="doc-control-box">
                        <tr>
                            <td>
                                Document No.<br>
                                <strong>QMS-DCO-Form No: 013</strong>
                            </td>
                        </tr>
                        <tr>
                            <td>Effective Date: <strong>July 2019</strong></td>
                        </tr>
                        <tr>
                            <td>Revision No. <strong>00</strong></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Titles Block: Cleanly centered across full page width -->
            <div class="titles-block">
                <div class="office-title">QUALITY ASSURANCE OFFICE</div>
                <div class="main-title">SUMMARY OF EVALUATION</div>
                <div class="sub-title">STUDENT-FACULTY EVALUATION</div>
            </div>

            <!-- School Year and Semester Line -->
            <div class="period-bar">
                <div>SCHOOL YEAR: <span class="val"><?php echo htmlspecialchars($school_year_display); ?></span></div>
                <div>SEMESTER: <span class="val"><?php echo htmlspecialchars($semester_display); ?></span></div>
            </div>

            <!-- Faculty Information Table -->
            <table class="info-table">
                <tr>
                    <td class="lbl" style="width: 22%;">Name of Faculty</td>
                    <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($formatted_faculty_table_name); ?></td>
                    <td class="lbl" style="width: 19%;">Employment Status:</td>
                    <td style="width: 15%; font-weight: bold;"><?php echo htmlspecialchars($employment_status); ?></td>
                </tr>
                <tr>
                    <td class="lbl">Subject/s Taught</td>
                    <td colspan="3"><?php echo htmlspecialchars($subjects_taught); ?></td>
                </tr>
                <tr>
                    <td class="lbl">Other Assignment/s</td>
                    <td colspan="3"><?php echo htmlspecialchars($other_assignments); ?></td>
                </tr>
                <tr>
                    <td class="lbl">College/Department</td>
                    <td colspan="3" style="font-weight: bold;"><?php echo htmlspecialchars($college_dept); ?></td>
                </tr>
            </table>

            <!-- Rank Equivalent Box -->
            <div class="rank-box">
                <div class="rank-box-title">RANK EQUIVALENT</div>
                <div class="rank-grid">
                    <div class="rank-col">
                        <div>4.20 &ndash; 5.0 &nbsp;&nbsp;&nbsp;=&nbsp;&nbsp; Excellent &nbsp;&nbsp;(95 &ndash; 100)</div>
                        <div>3.40 &ndash; 4.19 &nbsp;&nbsp;=&nbsp;&nbsp; Very Good &nbsp;(90 &ndash; 94)</div>
                    </div>
                    <div class="rank-col">
                        <div>2.60 &ndash; 3.39 &nbsp;&nbsp;=&nbsp;&nbsp; Good &nbsp;&nbsp;(86 &ndash; 89)</div>
                        <div>1.80 &ndash; 2.59 &nbsp;&nbsp;=&nbsp;&nbsp; Fair &nbsp;&nbsp;&nbsp;(80 &ndash; 85)</div>
                    </div>
                </div>
                <div class="rank-center-bottom">
                    1.0 &ndash; 1.79 &nbsp;&nbsp;=&nbsp;&nbsp; Poor &nbsp;&nbsp;&nbsp;(75 &ndash; 79)
                </div>
            </div>

            <!-- Results Table -->
            <table class="results-table">
                <tr>
                    <td colspan="4" class="results-banner">R E S U L T / S</td>
                </tr>
                <tr>
                    <th class="col-criteria">CRITERIA</th>
                    <th class="col-responses">No. of<br>Responses</th>
                    <th class="col-mean">Mean</th>
                    <th class="col-comments">COMMENTS AND SUGGESTIONS FOR<br>IMPROVEMENT</th>
                </tr>
                <?php 
                $rowCount = count($criteria_rows);
                foreach ($criteria_rows as $i => $row): 
                ?>
                <tr>
                    <td class="col-criteria"><?php echo htmlspecialchars($row['criteria']); ?></td>
                    <td class="col-responses"><?php echo htmlspecialchars($row['responses']); ?></td>
                    <td class="col-mean"><?php echo htmlspecialchars($row['mean']); ?></td>
                    <?php if ($i === 0): ?>
                        <td rowspan="<?php echo $rowCount; ?>" class="col-comments">SEE ATTACHED</td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <tr class="row-summary">
                    <td colspan="2" class="lbl">OVERALL RATING</td>
                    <td class="col-mean"><?php echo ($overall_avg > 0) ? number_format($overall_avg, 2) : '-'; ?></td>
                    <td></td>
                </tr>
                <tr class="row-summary">
                    <td colspan="2" class="lbl">DESCRIPTIVE INTERPRETATION</td>
                    <td colspan="2" style="text-align: center;"><?php echo htmlspecialchars($descriptive_interpretation); ?></td>
                </tr>
            </table>

            <!-- Signatories Box -->
            <table class="signatories-table">
                <!-- ROW 1: PREPARED BY -->
                <tr>
                    <td class="sig-col-left">
                        <div class="sig-label">Prepared by:</div>
                        <div class="sig-person">
                            <div class="sig-name">DOMINGA M. QUINAGORAN, LPT, MBA</div>
                            <div class="sig-role">Quality Assurance Moderator for Administration</div>
                        </div>
                    </td>
                    <td>
                        <div class="sig-person">
                            <div class="sig-name">CINDY T. DULDULAO, PTRP, MPH</div>
                            <div class="sig-role">Quality Assurance Moderator for Academics</div>
                        </div>
                    </td>
                </tr>

                <!-- ROW 2: ATTESTED BY -->
                <tr>
                    <td class="sig-col-left">
                        <div class="sig-label">Attested by:</div>
                        <div class="sig-person">
                            <div class="sig-name"><?php echo htmlspecialchars($supervisor_name); ?></div>
                            <div class="sig-role">Immediate Supervisor Name and Signature</div>
                        </div>
                    </td>
                    <td>
                        <div class="sig-person">
                            <div class="sig-name">CB RONIE E. SUGAROL, MPBM</div>
                            <div class="sig-role">Quality Assurance Director / Administrative Officer</div>
                        </div>
                    </td>
                </tr>

                <!-- ROW 3: CONFORME & VPAA -->
                <tr>
                    <td class="sig-col-left" style="padding-bottom: 4px;">
                        <div class="sig-label" style="margin-bottom: 2px;">Conforme:</div>
                        <div class="conforme-text">
                            I have carefully reviewed and accepted my students' evaluation results during the <u><?php echo $semester_word; ?> semester</u> of the <u><?php echo htmlspecialchars($school_year_display); ?></u> school year.
                        </div>
                        <div class="sig-person">
                            <div class="sig-name"><?php echo htmlspecialchars($formatted_faculty_sig_name); ?></div>
                            <div class="sig-role">Faculty Member Name and Signature</div>
                        </div>
                    </td>
                    <td style="padding-bottom: 4px;">
                        <div class="sig-person">
                            <div class="sig-name">WINNIE T. CANCEJO, Ph.D</div>
                            <div class="sig-role">Vice President for Academic Affairs</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Footer Page 1 (100% PANTAY SA ISANG LINYA) -->
        <table class="sheet-footer-table">
            <tr>
                <td class="footer-left">
                    This printed document is for reference use only unless stamped as a <strong>CONTROLLED</strong> copy by the Quality Assurance Office (QAO).
                </td>
                <td class="footer-date"><?php echo date('d/m/Y'); ?></td>
                <td class="footer-page">Page 1 of 2</td>
            </tr>
        </table>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- 📄 PAGE 2: ATTACHMENT — COMMENTS AND SUGGESTIONS            -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="sheet page-break" id="page-2">
        <div>
            <!-- Letterhead -->
            <div class="letterhead">
                <?php if (!empty($base64Logo)): ?>
                    <img src="<?php echo $base64Logo; ?>" alt="School Logo" class="letterhead-logo">
                <?php endif; ?>
                <div class="letterhead-content">
                    <div class="school-name"><?php echo htmlspecialchars($sc['name']); ?></div>
                    <div class="school-sub">
                        <?php echo htmlspecialchars($sc['address']); ?><br>
                        <?php echo htmlspecialchars($sc['phone']); ?><br>
                        <?php echo htmlspecialchars($sc['email']); ?>
                    </div>
                </div>
            </div>

            <!-- Title -->
            <div style="text-align: center; margin: 12px 0 10px;">
                <div style="font-size: 10pt; font-weight: bold; letter-spacing: 0.5px;">QUALITY ASSURANCE OFFICE</div>
                <div style="font-size: 10.5pt; font-weight: bold; letter-spacing: 0.5px;">SUMMARY OF EVALUATION</div>
                <div style="font-size: 10pt; font-weight: bold; letter-spacing: 0.5px;">STUDENT-FACULTY EVALUATION</div>
                <div style="font-size: 10pt; font-weight: bold; margin-top: 6px; text-decoration: underline;">
                    ATTACHMENT: COMMENTS AND SUGGESTIONS FOR IMPROVEMENT
                </div>
            </div>

            <!-- Meta Table -->
            <table class="info-table" style="margin-bottom: 12px;">
                <tr>
                    <td class="lbl" style="width: 22%;">Name of Faculty</td>
                    <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($formatted_faculty_table_name); ?></td>
                    <td class="lbl" style="width: 19%;">School Year:</td>
                    <td style="width: 15%; font-weight: bold;"><?php echo htmlspecialchars($school_year_display); ?></td>
                </tr>
                <tr>
                    <td class="lbl">College/Department</td>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($college_dept); ?></td>
                    <td class="lbl">Semester:</td>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($semester_display); ?></td>
                </tr>
            </table>

            <!-- Comments Table -->
            <table class="comments-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">No.</th>
                        <th style="width: 72%;">Comments / Suggestions Submitted by Students</th>
                        <th style="width: 20%;">Sentiment / Classification</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_comments)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #666; padding: 25px;">
                                <em>No written comments or suggestions submitted for this evaluation period.</em>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_comments as $idx => $com): 
                            $feedbackText = trim($com['feedback']);
                            $sentiment = function_exists('analyzeSentiment') ? analyzeSentiment($feedbackText, $com['rating'] ?? 5) : 'Positive';
                            $badgeColor = ($sentiment === 'Positive') ? '#15803d' : (($sentiment === 'Negative') ? '#b91c1c' : '#b45309');
                        ?>
                        <tr>
                            <td style="text-align: center; font-weight: bold;"><?php echo ($idx + 1); ?></td>
                            <td>&ldquo;<?php echo htmlspecialchars($feedbackText); ?>&rdquo;</td>
                            <td style="text-align: center; font-weight: bold; color: <?php echo $badgeColor; ?>;">
                                <?php echo htmlspecialchars($sentiment); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer Page 2 (100% PANTAY SA ISANG LINYA) -->
        <table class="sheet-footer-table">
            <tr>
                <td class="footer-left">
                    This printed document is for reference use only unless stamped as a <strong>CONTROLLED</strong> copy by the Quality Assurance Office (QAO).
                </td>
                <td class="footer-date"><?php echo date('d/m/Y'); ?></td>
                <td class="footer-page">Page 2 of 2</td>
            </tr>
        </table>
    </div>

</div>

<script>
function downloadPDF(mode = 'ratings') {
    let element;
    let filename;
    const facultyName = <?php echo json_encode($formatted_faculty_sig_name); ?>;
    const period = <?php echo json_encode($school_year_display . '_' . $semester_display); ?>;
    const cleanFaculty = facultyName.replace(/[^a-zA-Z0-9]/g, '_');

    if (mode === 'ratings') {
        element = document.getElementById('page-1');
        filename = 'RATINGS_QMS_Form_013_' + cleanFaculty + '_' + period + '.pdf';
    } else {
        element = document.getElementById('printable-document');
        filename = 'COMPLETE_REPORT_QMS_Form_013_' + cleanFaculty + '_' + period + '.pdf';
    }

    const opt = {
        margin:       0,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    const btn = (typeof event !== 'undefined' && event && event.target) ? event.target.closest('button') : null;
    let origHtml = '';
    if (btn) {
        origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        btn.disabled = true;
    }

    html2pdf().set(opt).from(element).save().then(() => {
        if (btn) {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    }).catch(err => {
        console.error(err);
        if (btn) {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
        window.print();
    });
}

// Suppress title in print headers
let origDocTitle = document.title;
window.addEventListener('beforeprint', () => {
    origDocTitle = document.title;
    document.title = '';
});
window.addEventListener('afterprint', () => {
    document.title = origDocTitle;
});
</script>
</body>
</html>
