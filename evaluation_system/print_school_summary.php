<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';
include_once 'process_sentiment.php';

// Access control — admin (QA) allowed; dean and ICT blocked
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'system admin') {
        header("Location: dashboard_ict.php");
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'dean') {
        header("Location: print_center.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$period_id = (int)($_GET['period_id'] ?? 0);
$school_filter = $_GET['school'] ?? 'all';

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
} elseif (stripos($sem_raw, '2') !== false || stripos($sem_raw, 'second') !== false) {
    $semester_display = 'Second';
} else {
    $semester_display = $sem_raw;
}

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

$is_all_schools = ($school_filter === 'all');

// Safe Base64 logo encoder
$base64LogoMCNP = '';
if (file_exists('MCNP_Logo.png')) {
    $type = pathinfo('MCNP_Logo.png', PATHINFO_EXTENSION);
    $data = @file_get_contents('MCNP_Logo.png');
    if ($data !== false) {
        $base64LogoMCNP = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
}

$base64LogoISAP = '';
if (file_exists('ISAP_Logo.png')) {
    $type = pathinfo('ISAP_Logo.png', PATHINFO_EXTENSION);
    $data = @file_get_contents('ISAP_Logo.png');
    if ($data !== false) {
        $base64LogoISAP = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
}

if ($school_filter === 'all') {
    $sc = $schools['mcnp'];
    $report_title = 'MCNP-ISAP JOINT INSTITUTIONS';
    $dept_filter_sql = "";
    $leftLogo  = $base64LogoMCNP;
    $rightLogo = $base64LogoISAP;
} elseif ($school_filter === 'isap') {
    $sc = $schools['isap'];
    $report_title = 'INTERNATIONAL SCHOOL OF ASIA AND THE PACIFIC';
    $dept_filter_sql = " AND d.dept_alias IN ('CASTE','CBEM','CITE','COCJE')";
    $leftLogo  = $base64LogoISAP;
    $rightLogo = '';
} else {
    $sc = $schools['mcnp'];
    $report_title = 'MEDICAL COLLEGES OF NORTHERN PHILIPPINES';
    $dept_filter_sql = " AND d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')";
    $leftLogo  = $base64LogoMCNP;
    $rightLogo = '';
}

// Preserve $base64Logo for backward compatibility
$base64Logo = $leftLogo;

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

// 1. Campus-Wide Overall Rating
$overall_sql = "
    SELECT AVG(e.rating) as grand_avg, COUNT(e.id) as total_evals, COUNT(DISTINCT e.student_id) as unique_students, COUNT(DISTINCT e.faculty_id) as unique_faculty
    FROM evaluations e 
    JOIN users u ON e.faculty_id = u.id
    JOIN departments d ON u.dept_id = d.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NOT NULL $dept_filter_sql
";
$overall_stmt = $conn->prepare($overall_sql);
$overall_stmt->bind_param("i", $period_id);
$overall_stmt->execute();
$overall_stats = $overall_stmt->get_result()->fetch_assoc();
$grand_avg = round((float)($overall_stats['grand_avg'] ?? 0), 2);
$total_evals = (int)($overall_stats['total_evals'] ?? 0);
$unique_students = (int)($overall_stats['unique_students'] ?? 0);
$unique_faculty = (int)($overall_stats['unique_faculty'] ?? 0);

// Total students & faculty
$totals_sql = "
    SELECT 
        (SELECT COUNT(*) FROM users u JOIN departments d ON u.dept_id = d.id WHERE u.role='student' AND u.status='active' $dept_filter_sql) as total_students,
        (SELECT COUNT(*) FROM users u JOIN departments d ON u.dept_id = d.id WHERE u.role='faculty' AND u.status='active' $dept_filter_sql) as total_faculty
";
$totals = $conn->query($totals_sql)->fetch_assoc();
$total_students = (int)($totals['total_students'] ?? 0);
$total_faculty = (int)($totals['total_faculty'] ?? 0);
$participation_rate = $total_students > 0 ? min(100, round(($unique_students / $total_students) * 100, 1)) : 0;

// 2. Department Comparison Table
$dept_comp_sql = "
    SELECT d.dept_alias, d.dept_name,
        COUNT(DISTINCT u2.id) as faculty_count,
        AVG(e.rating) as dept_avg,
        COUNT(e.id) as eval_count
    FROM departments d
    LEFT JOIN users u2 ON d.id = u2.dept_id AND u2.role = 'faculty'
    LEFT JOIN evaluations e ON u2.id = e.faculty_id AND e.academic_period_id = ?
    WHERE 1=1 $dept_filter_sql
    GROUP BY d.id, d.dept_alias, d.dept_name
    ORDER BY dept_avg DESC
";
$dept_comp_stmt = $conn->prepare($dept_comp_sql);
$dept_comp_stmt->bind_param("i", $period_id);
$dept_comp_stmt->execute();
$dept_comparisons = $dept_comp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Category Performance Breakdown
$cat_sql = "
    SELECT q.category, AVG(r.rating) as score, COUNT(DISTINCT e.id) as responses
    FROM evaluation_responses r
    JOIN evaluation_questions q ON r.question_id = q.id
    JOIN evaluations e ON r.evaluation_id = e.id
    JOIN users u ON e.faculty_id = u.id
    JOIN departments d ON u.dept_id = d.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NOT NULL
      AND q.category IN ('Teaching', 'Professionalism', 'Assessment', 'Support')
      $dept_filter_sql
    GROUP BY q.category ORDER BY score DESC
";
$cat_stmt = $conn->prepare($cat_sql);
$cat_stmt->bind_param("i", $period_id);
$cat_stmt->execute();
$category_scores = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Top 5 Faculty
$top_sql = "
    SELECT u.full_name, d.dept_alias, AVG(e.rating) as avg_r, COUNT(e.id) as eval_count
    FROM users u
    JOIN departments d ON u.dept_id = d.id
    JOIN evaluations e ON u.id = e.faculty_id
    WHERE e.academic_period_id = ? $dept_filter_sql
    GROUP BY u.id, u.full_name, d.dept_alias
    ORDER BY avg_r DESC
    LIMIT 5
";
$top_stmt = $conn->prepare($top_sql);
$top_stmt->bind_param("i", $period_id);
$top_stmt->execute();
$top_faculty = $top_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QMS-DCO-Form No: 015 — OVERALL SCHOOL SUMMARY (<?php echo htmlspecialchars($report_title); ?>)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
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
    .action-bar-left { display: flex; align-items: center; gap: 14px; }
    .back-btn {
        color: #94a3b8; text-decoration: none; font-size: 13px; font-weight: 600;
        display: inline-flex; align-items: center; gap: 6px; transition: color 0.2s;
    }
    .back-btn:hover { color: white; }
    .action-bar-title { font-size: 14px; font-weight: 600; color: #f8fafc; }
    .action-bar-right { display: flex; align-items: center; gap: 10px; }
    .btn-act {
        border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px;
        font-weight: 700; cursor: pointer; display: inline-flex; align-items: center;
        gap: 7px; text-decoration: none; transition: transform 0.15s, opacity 0.15s;
    }
    .btn-act:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-print { background: #3b82f6; color: white; }
    .btn-pdf { background: #ef4444; color: white; }

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

    .letterhead {
        display: flex; align-items: center; justify-content: center;
        gap: 15px; margin-bottom: 6px; position: relative;
        min-height: 70px;
    }
    .letterhead-logo {
        width: 70px; height: 70px; object-fit: contain; position: absolute; left: 15px; top: 0;
    }
    .letterhead-logo-right {
        width: 70px; height: 70px; object-fit: contain; position: absolute; right: 15px; top: 0;
    }
    .letterhead-content { text-align: center; width: 100%; padding: 0 95px; }
    .school-name { font-size: 13pt; font-weight: bold; letter-spacing: 0.2px; color: #000; margin-bottom: 2px; }
    .school-name-joint { font-size: 10.5pt; font-weight: bold; letter-spacing: 0.2px; color: #000; line-height: 1.25; margin-bottom: 1px; }
    .school-sub { font-size: 8pt; line-height: 1.35; color: #111; }

    .title-control-row {
        display: flex; align-items: center; justify-content: space-between;
        margin-top: 6px; margin-bottom: 6px; width: 100%;
    }
    .title-spacer { width: 180px; flex-shrink: 0; }
    .title-center { flex: 1; text-align: center; }
    .title-center .office-title { font-size: 10pt; font-weight: bold; }
    .title-center .main-title { font-size: 10.5pt; font-weight: bold; }
    .title-center .sub-title { font-size: 10pt; font-weight: bold; }

    .doc-control-box {
        width: 180px; flex-shrink: 0; margin-left: auto;
        border-collapse: collapse; border: 1px solid #000;
        font-size: 8pt; line-height: 1.15;
    }
    .doc-control-box td { border: 1px solid #000; padding: 2.5px 5px; color: #000; text-align: left; }

    .period-bar {
        display: flex; justify-content: space-between; font-size: 9.5pt;
        font-weight: bold; margin-bottom: 4px; padding: 0 1px;
    }
    .period-bar span.val { font-weight: bold; font-style: italic; }

    .info-table {
        width: 100%; border-collapse: collapse; border: 1px solid #000;
        font-size: 9pt; margin-bottom: 6px;
    }
    .info-table td { border: 1px solid #000; padding: 3.5px 6px; vertical-align: middle; }
    .info-table td.lbl { font-weight: bold; }

    .rank-box {
        border: 1px solid #000; padding: 4px 10px; font-size: 8.5pt;
        margin-bottom: 6px; text-align: center;
    }
    .rank-box-title { font-weight: bold; font-size: 9pt; margin-bottom: 3px; }
    .rank-grid {
        display: flex; justify-content: space-around; text-align: left;
        line-height: 1.35; padding: 0 20px;
    }
    .rank-col { display: flex; flex-direction: column; }
    .rank-center-bottom { text-align: center; margin-top: 1px; line-height: 1.35; }

    .results-table {
        width: 100%; border-collapse: collapse; border: 1px solid #000;
        font-size: 9pt; margin-bottom: 6px;
    }
    .results-table th, .results-table td { border: 1px solid #000; padding: 4px 6px; }
    .results-table th { font-weight: bold; text-align: center; background: #fff; }
    .results-banner {
        text-align: center; font-weight: bold; letter-spacing: 2px;
        font-size: 9pt; padding: 3px !important;
    }

    /* ── SIGNATORIES SECTION (100% PANTAY) ── */
    .signatories-table {
        width: 100%; border-collapse: collapse; border: 1px solid #000;
        font-size: 8.5pt; margin-bottom: 4px;
    }
    .signatories-table tr { border: none; }
    .signatories-table td {
        width: 50%; padding: 5px 12px; border: none; vertical-align: bottom;
    }
    .sig-col-left { border-right: 1px solid #000 !important; }
    .sig-label { font-weight: bold; font-style: italic; margin-bottom: 10px; }
    .sig-person { text-align: center; }
    .sig-name {
        font-weight: bold; text-decoration: underline; font-size: 9pt; margin-bottom: 2px;
    }
    .sig-role { font-size: 8pt; font-style: italic; }

    /* ── FOOTER TABLE (100% PANTAY & NEVER WRAPS) ── */
    .sheet-footer-table {
        width: 100%; border-collapse: collapse; border: none !important;
        font-size: 6.8pt; line-height: 1.15; margin-top: 4px; white-space: nowrap;
    }
    .sheet-footer-table td {
        border: none !important; padding: 0 !important;
        vertical-align: baseline !important; white-space: nowrap !important;
    }
    .sheet-footer-table td.footer-left { font-style: italic; text-align: left; color: #000; }
    .sheet-footer-table td.footer-date {
        text-align: right; padding-left: 20px !important; width: 1%; white-space: nowrap;
    }
    .sheet-footer-table td.footer-page {
        text-align: right; padding-left: 20px !important; width: 1%; white-space: nowrap;
    }

    @media print {
        @page { size: A4 portrait; margin: 6mm 10mm 6mm 10mm; }
        body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
        .action-bar { display: none !important; }
        .pages-container { padding: 0 !important; gap: 0 !important; }
        .sheet {
            width: 100% !important; min-height: auto !important; height: auto !important;
            box-shadow: none !important; padding: 4mm 6mm 4mm 6mm !important; margin: 0 !important;
            page-break-after: always !important; break-after: page !important;
        }
    }
    </style>
</head>
<body>

<div class="action-bar">
    <div class="action-bar-left">
        <a href="javascript:history.back()" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Print Center
        </a>
        <div class="action-bar-title">
            <i class="fas fa-school"></i> School Summary — <?php echo htmlspecialchars($report_title); ?>
        </div>
    </div>
    <div class="action-bar-right">
        <button class="btn-act btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Document
        </button>
        <button class="btn-act btn-pdf" onclick="downloadPDF()">
            <i class="fas fa-file-pdf"></i> Download PDF
        </button>
    </div>
</div>

<div class="pages-container" id="printable-document">

    <div class="sheet" id="page-1">
        <div>
            <!-- Letterhead -->
            <div class="letterhead">
                <?php if (!empty($leftLogo)): ?>
                    <img src="<?php echo $leftLogo; ?>" alt="School Logo" class="letterhead-logo">
                <?php endif; ?>
                <div class="letterhead-content">
                    <?php if ($is_all_schools): ?>
                        <div class="school-name-joint">MEDICAL COLLEGES OF NORTHERN PHILIPPINES</div>
                        <div class="school-name-joint">INTERNATIONAL SCHOOL OF ASIA AND THE PACIFIC</div>
                        <div class="school-sub">
                            Alimannao Hills, Peñablanca, Cagayan 3502 Philippines<br>
                            Telefax No: (+63) (078) 304-1010 &bull; Website: www.mcnp.edu.ph &bull; www.isap.edu.ph<br>
                            E-Mail Address: qaoffice@mcnp.edu.ph / dco@mcnp.edu.ph
                        </div>
                    <?php else: ?>
                        <div class="school-name"><?php echo htmlspecialchars($sc['name']); ?></div>
                        <div class="school-sub">
                            <?php echo htmlspecialchars($sc['address']); ?><br>
                            <?php echo htmlspecialchars($sc['phone']); ?><br>
                            <?php echo htmlspecialchars($sc['email']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($rightLogo)): ?>
                    <img src="<?php echo $rightLogo; ?>" alt="ISAP Logo" class="letterhead-logo-right">
                <?php endif; ?>
            </div>

            <!-- Title & Control Block -->
            <div class="title-control-row">
                <div class="title-spacer"></div>
                <div class="title-center">
                    <div class="office-title">QUALITY ASSURANCE OFFICE</div>
                    <div class="main-title">SUMMARY OF EVALUATION</div>
                    <div class="sub-title">CAMPUS-WIDE INSTITUTIONAL EVALUATION REPORT</div>
                </div>
                <table class="doc-control-box">
                    <tr><td>Document No.<br><strong>QMS-DCO-Form No: 015</strong></td></tr>
                    <tr><td>Effective Date: <strong>July 2019</strong></td></tr>
                    <tr><td>Revision No. <strong>00</strong></td></tr>
                </table>
            </div>

            <!-- Period Bar -->
            <div class="period-bar">
                <div>SCHOOL YEAR: <span class="val"><?php echo htmlspecialchars($school_year_display); ?></span></div>
                <div>SEMESTER: <span class="val"><?php echo htmlspecialchars($semester_display); ?></span></div>
            </div>

            <!-- Overview Table -->
            <table class="info-table">
                <tr>
                    <td class="lbl" style="width: 22%;">Institution / Scope</td>
                    <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($report_title); ?></td>
                    <td class="lbl" style="width: 19%;">Campus Rating:</td>
                    <td style="width: 15%; font-weight: bold;"><?php echo number_format($grand_avg, 2); ?> (<?php echo getRankInterpretation($grand_avg); ?>)</td>
                </tr>
                <tr>
                    <td class="lbl">Total Evaluated Faculty</td>
                    <td><?php echo $unique_faculty; ?> / <?php echo $total_faculty; ?> instructors</td>
                    <td class="lbl">Total Evaluations:</td>
                    <td><?php echo $total_evals; ?> responses</td>
                </tr>
                <tr>
                    <td class="lbl">Student Evaluators</td>
                    <td><?php echo $unique_students; ?> / <?php echo $total_students; ?> students</td>
                    <td class="lbl">Participation Rate:</td>
                    <td><?php echo $participation_rate; ?>%</td>
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

            <!-- Department Comparison Table -->
            <table class="results-table">
                <tr>
                    <td colspan="5" class="results-banner">DEPARTMENT PERFORMANCE COMPARISON</td>
                </tr>
                <tr>
                    <th style="width: 8%;">Rank</th>
                    <th>College / Department</th>
                    <th style="width: 16%;">Faculty Count</th>
                    <th style="width: 16%;">Average Score</th>
                    <th style="width: 24%;">Descriptive Interpretation</th>
                </tr>
                <?php if (!empty($dept_comparisons)): 
                    $rank = 1;
                    foreach ($dept_comparisons as $dc):
                        $score = round((float)($dc['dept_avg'] ?? 0), 2);
                ?>
                <tr>
                    <td style="text-align: center; font-weight: bold;"><?php echo $rank++; ?></td>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($dc['dept_alias'] . ' — ' . $dc['dept_name']); ?></td>
                    <td style="text-align: center;"><?php echo (int)$dc['faculty_count']; ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($score, 2); ?></td>
                    <td style="text-align: center;"><?php echo getRankInterpretation($score); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5" style="text-align:center; color:#777; padding:15px;">No department evaluation data recorded.</td></tr>
                <?php endif; ?>
            </table>

            <!-- Teaching Performance by Category -->
            <?php if (!empty($category_scores)): ?>
            <table class="results-table" style="margin-top: 6px;">
                <tr>
                    <td colspan="4" class="results-banner">INSTITUTIONAL CRITERIA BREAKDOWN</td>
                </tr>
                <tr>
                    <th>Criteria Category</th>
                    <th style="width: 20%;">Average Score</th>
                    <th style="width: 18%;">Evaluations</th>
                    <th style="width: 26%;">Descriptive Interpretation</th>
                </tr>
                <?php foreach ($category_scores as $cs): 
                    $cScore = round((float)$cs['score'], 2);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($cs['category']); ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($cScore, 2); ?></td>
                    <td style="text-align: center;"><?php echo (int)$cs['responses']; ?></td>
                    <td style="text-align: center;"><?php echo getRankInterpretation($cScore); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <!-- Signatories Box (100% PANTAY) -->
            <table class="signatories-table" style="margin-top: 8px;">
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
                <tr>
                    <td class="sig-col-left">
                        <div class="sig-label">Attested by:</div>
                        <div class="sig-person">
                            <div class="sig-name">CB RONIE E. SUGAROL, MPBM</div>
                            <div class="sig-role">Quality Assurance Director / Administrative Officer</div>
                        </div>
                    </td>
                    <td>
                        <div class="sig-person">
                            <div class="sig-name">WINNIE T. CANCEJO, Ph.D</div>
                            <div class="sig-role">Vice President for Academic Affairs</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Footer (100% PANTAY SA ISANG LINYA) -->
        <table class="sheet-footer-table">
            <tr>
                <td class="footer-left">
                    This printed document is for reference use only unless stamped as a <strong>CONTROLLED</strong> copy by the Quality Assurance Office (QAO).
                </td>
                <td class="footer-date"><?php echo date('d/m/Y'); ?></td>
                <td class="footer-page">Page 1 of 1</td>
            </tr>
        </table>
    </div>

</div>

<script>
function downloadPDF() {
    const element = document.getElementById('printable-document');
    const period = <?php echo json_encode($school_year_display . '_' . $semester_display); ?>;
    const filename = 'QMS_Form_015_School_Summary_' + period + '.pdf';

    const opt = {
        margin:       0,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    const btn = event.target.closest('button');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
    }).catch(err => {
        console.error(err);
        btn.innerHTML = origHtml;
        btn.disabled = false;
        window.print();
    });
}

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
