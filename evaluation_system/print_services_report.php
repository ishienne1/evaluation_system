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

$service_filter = $_GET['service'] ?? 'all';
$school_filter  = $_GET['school'] ?? 'all';

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
    $leftLogo  = $base64LogoMCNP;
    $rightLogo = $base64LogoISAP;
} elseif ($school_filter === 'isap') {
    $sc = $schools['isap'];
    $leftLogo  = $base64LogoISAP;
    $rightLogo = '';
} else {
    $sc = $schools['mcnp'];
    $leftLogo  = $base64LogoMCNP;
    $rightLogo = '';
}

// Preserve $base64Logo for backward compatibility
$base64Logo = $leftLogo;

$report_title = ($service_filter === 'all') ? "ALL CAMPUS SERVICES" : strtoupper($service_filter);

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

$service_sql_cond = "";
$bind_types = "i";
$bind_params = [&$period_id];
if ($service_filter !== 'all') {
    $service_sql_cond = " AND e.target_name = ?";
    $bind_types .= "s";
    $bind_params[] = &$service_filter;
}

// 1. Overall Rating
$overall_sql = "
    SELECT AVG(e.rating) as grand_avg, COUNT(e.id) as total_evals, COUNT(DISTINCT e.student_id) as unique_students
    FROM evaluations e 
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $service_sql_cond
";
$overall_stmt = $conn->prepare($overall_sql);
$overall_stmt->bind_param($bind_types, ...$bind_params);
$overall_stmt->execute();
$overall_stats = $overall_stmt->get_result()->fetch_assoc();
$grand_avg = round((float)($overall_stats['grand_avg'] ?? 0), 2);
$total_evals = (int)($overall_stats['total_evals'] ?? 0);
$unique_students = (int)($overall_stats['unique_students'] ?? 0);

// Respondent role breakdown
$role_breakdown_sql = "
    SELECT u.role, COUNT(DISTINCT e.student_id) as cnt
    FROM evaluations e
    JOIN users u ON e.student_id = u.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $service_sql_cond
    GROUP BY u.role
";
$role_stmt = $conn->prepare($role_breakdown_sql);
$role_stmt->bind_param($bind_types, ...$bind_params);
$role_stmt->execute();
$role_res = $role_stmt->get_result();
$role_parts = [];
while ($rr = $role_res->fetch_assoc()) {
    $r_name = ucfirst($rr['role']);
    if ($rr['role'] === 'faculty') $r_name = 'Faculty';
    elseif ($rr['role'] === 'dean') $r_name = 'Deans';
    elseif ($rr['role'] === 'student') $r_name = 'Students';
    elseif ($rr['role'] === 'visitor') $r_name = 'Visitors';
    $role_parts[] = $r_name . ': ' . $rr['cnt'];
}
$role_breakdown_text = !empty($role_parts) ? implode(', ', $role_parts) : 'Students: ' . $unique_students;

// 2. Services Breakdown
$services_comp_sql = "
    SELECT e.target_name,
        AVG(e.rating) as service_avg,
        COUNT(e.id) as eval_count,
        COUNT(DISTINCT e.student_id) as student_eval_count
    FROM evaluations e
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $service_sql_cond
    GROUP BY e.target_name
    ORDER BY service_avg DESC
";
$services_comp_stmt = $conn->prepare($services_comp_sql);
$services_comp_stmt->bind_param($bind_types, ...$bind_params);
$services_comp_stmt->execute();
$services_comparisons = $services_comp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Category Performance Breakdown
$cat_sql = "
    SELECT q.category, AVG(r.rating) as score, COUNT(r.id) as responses
    FROM evaluation_responses r
    JOIN evaluation_questions q ON r.question_id = q.id
    JOIN evaluations e ON r.evaluation_id = e.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $service_sql_cond
    GROUP BY q.category ORDER BY score DESC
";
$cat_stmt = $conn->prepare($cat_sql);
$cat_stmt->bind_param($bind_types, ...$bind_params);
$cat_stmt->execute();
$categories = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Net Promoter Score (NPS / MPS) Analysis
$nps_sql = "
    SELECT 
        COUNT(r.id) as total_loyalty,
        SUM(CASE WHEN r.rating >= 9 THEN 1 ELSE 0 END) as promoters,
        SUM(CASE WHEN r.rating BETWEEN 7 AND 8 THEN 1 ELSE 0 END) as passives,
        SUM(CASE WHEN r.rating <= 6 THEN 1 ELSE 0 END) as detractors,
        AVG(r.rating) as mps_avg
    FROM evaluation_responses r
    JOIN evaluation_questions q ON r.question_id = q.id
    JOIN evaluations e ON r.evaluation_id = e.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL AND q.category = 'Loyalty' $service_sql_cond
";
$nps_stmt = $conn->prepare($nps_sql);
$nps_stmt->bind_param($bind_types, ...$bind_params);
$nps_stmt->execute();
$nps_data = $nps_stmt->get_result()->fetch_assoc();

$nps_total = (int)($nps_data['total_loyalty'] ?? 0);
$nps_promoters = (int)($nps_data['promoters'] ?? 0);
$nps_passives = (int)($nps_data['passives'] ?? 0);
$nps_detractors = (int)($nps_data['detractors'] ?? 0);
$mps_mean = round((float)($nps_data['mps_avg'] ?? 0), 2);

$nps_score = $nps_total > 0 ? round((($nps_promoters - $nps_detractors) / $nps_total) * 100) : 0;
$promoters_pct = $nps_total > 0 ? round(($nps_promoters / $nps_total) * 100, 1) : 0;
$passives_pct = $nps_total > 0 ? round(($nps_passives / $nps_total) * 100, 1) : 0;
$detractors_pct = $nps_total > 0 ? round(($nps_detractors / $nps_total) * 100, 1) : 0;

if (!function_exists('getNpsInterpretation')) {
    function getNpsInterpretation($score) {
        if ($score >= 70) return 'World Class';
        if ($score >= 50) return 'Excellent';
        if ($score >= 30) return 'Great';
        if ($score >= 0)  return 'Good';
        return 'Needs Improvement';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QMS-DCO-Form No: 016 — CAMPUS SERVICES EVALUATION (<?php echo htmlspecialchars($report_title); ?>)</title>
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
            <i class="fas fa-university"></i> Campus Services — <?php echo htmlspecialchars($report_title); ?>
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
                    <div class="sub-title">CAMPUS SERVICES EVALUATION REPORT</div>
                </div>
                <table class="doc-control-box">
                    <tr><td>Document No.<br><strong>QMS-DCO-Form No: 016</strong></td></tr>
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
                    <td class="lbl" style="width: 22%;">Facility / Office Scope</td>
                    <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($report_title); ?></td>
                    <td class="lbl" style="width: 19%;">Services Rating:</td>
                    <td style="width: 15%; font-weight: bold;"><?php echo number_format($grand_avg, 2); ?> (<?php echo getRankInterpretation($grand_avg); ?>)</td>
                </tr>
                <tr>
                    <td class="lbl">Total Evaluations</td>
                    <td><?php echo $total_evals; ?> responses</td>
                    <td class="lbl">Unique Evaluators:</td>
                    <td><?php echo $unique_students; ?> respondents</td>
                </tr>
                <tr>
                    <td class="lbl">Respondent Demographics</td>
                    <td colspan="3"><?php echo htmlspecialchars($role_breakdown_text); ?></td>
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

            <!-- Services Performance Table -->
            <?php if (!empty($services_comparisons)): ?>
            <table class="results-table">
                <tr>
                    <td colspan="5" class="results-banner">CAMPUS SERVICES & OFFICES BREAKDOWN</td>
                </tr>
                <tr>
                    <th style="width: 8%;">No.</th>
                    <th>Campus Facility / Administrative Office</th>
                    <th style="width: 18%;">Evaluations</th>
                    <th style="width: 16%;">Average Score</th>
                    <th style="width: 24%;">Descriptive Interpretation</th>
                </tr>
                <?php 
                $sNum = 1;
                foreach ($services_comparisons as $sc_item): 
                    $score = round((float)($sc_item['service_avg'] ?? 0), 2);
                ?>
                <tr>
                    <td style="text-align: center; font-weight: bold;"><?php echo $sNum++; ?></td>
                    <td style="font-weight: bold;"><?php echo htmlspecialchars($sc_item['target_name']); ?></td>
                    <td style="text-align: center;"><?php echo (int)$sc_item['eval_count']; ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($score, 2); ?></td>
                    <td style="text-align: center;"><?php echo getRankInterpretation($score); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <!-- Criteria Category Breakdown -->
            <?php if (!empty($categories)): ?>
            <table class="results-table" style="margin-top: 6px;">
                <tr>
                    <td colspan="4" class="results-banner">CRITERIA CATEGORY BREAKDOWN</td>
                </tr>
                <tr>
                    <th>Criteria Category</th>
                    <th style="width: 20%;">Average Score</th>
                    <th style="width: 18%;">Responses</th>
                    <th style="width: 26%;">Descriptive Interpretation</th>
                </tr>
                <?php foreach ($categories as $cat): 
                    $score = round((float)($cat['score'] ?? 0), 2);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($cat['category']); ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($score, 2); ?></td>
                    <td style="text-align: center;"><?php echo (int)$cat['responses']; ?></td>
                    <td style="text-align: center;"><?php echo getRankInterpretation($score); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <!-- Net Promoter Score (NPS / MPS) Analysis -->
            <?php if ($nps_total > 0): ?>
            <table class="results-table" style="margin-top: 6px;">
                <tr>
                    <td colspan="5" class="results-banner">NET PROMOTER SCORE (NPS / MPS) INSTITUTIONAL ANALYSIS</td>
                </tr>
                <tr>
                    <th style="width: 22%;">Net Promoter Score (NPS)</th>
                    <th style="width: 22%;">Mean Promoter Score (MPS)</th>
                    <th style="width: 18%;">Promoters (9–10)</th>
                    <th style="width: 18%;">Passives (7–8)</th>
                    <th style="width: 20%;">Detractors (1–6)</th>
                </tr>
                <tr>
                    <td style="text-align: center; font-weight: bold; font-size: 11pt;">
                        <?php echo ($nps_score > 0 ? '+' : '') . $nps_score; ?>
                        <br><small style="font-size: 8pt; font-weight: normal; color: #475569;"><?php echo getNpsInterpretation($nps_score); ?></small>
                    </td>
                    <td style="text-align: center; font-weight: bold; font-size: 11pt;">
                        <?php echo number_format($mps_mean, 2); ?> <small style="font-size: 8pt; font-weight: normal; color: #475569;">/ 10.00</small>
                    </td>
                    <td style="text-align: center; color: #10b981; font-weight: bold;">
                        <?php echo $promoters_pct; ?>% (<?php echo $nps_promoters; ?>)
                    </td>
                    <td style="text-align: center; color: #f59e0b; font-weight: bold;">
                        <?php echo $passives_pct; ?>% (<?php echo $nps_passives; ?>)
                    </td>
                    <td style="text-align: center; color: #ef4444; font-weight: bold;">
                        <?php echo $detractors_pct; ?>% (<?php echo $nps_detractors; ?>)
                    </td>
                </tr>
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
    const filename = 'QMS_Form_016_Campus_Services_' + period + '.pdf';

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
