<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';
include_once 'process_sentiment.php';

// Access control — admin, system admin, dean, faculty allowed
$allowed_roles = ['admin', 'system admin', 'dean', 'faculty'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php"); exit();
}

$role          = $_SESSION['role'];
$session_id    = $_SESSION['id'] ?? 0;
$session_dept  = $_SESSION['dept_id'] ?? null;

// Faculty can only export their own
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
    $avg_stmt = $conn->prepare("SELECT AVG(rating) as avg_r, COUNT(*) as cnt FROM evaluations WHERE faculty_id=? AND academic_period_id=?");
    $avg_stmt->bind_param("ii", $faculty_id, $period_id);
    $avg_stmt->execute();
    $avg_row = $avg_stmt->get_result()->fetch_assoc();
    $overall_avg     = round((float)($avg_row['avg_r'] ?? 0), 2);
    $total_responses = (int)($avg_row['cnt'] ?? 0);

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

    $com_stmt = $conn->prepare("
        SELECT e.feedback, e.rating, e.created_at
        FROM evaluations e
        WHERE e.faculty_id=? AND e.academic_period_id=? AND e.feedback IS NOT NULL AND TRIM(e.feedback) != ''
        ORDER BY e.created_at DESC
    ");
    $com_stmt->bind_param("ii", $faculty_id, $period_id);
    $com_stmt->execute();
    $all_comments = $com_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

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

$criteria_rows = [];
if (!empty($category_scores)) {
    $idx = 1;
    foreach ($category_scores as $cs) {
        $cName = trim($cs['category']);
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
    $criteria_rows = [
        ['criteria' => 'I. Conduct of Online Class',   'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
        ['criteria' => 'II. Conduct of In-person Class', 'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
        ['criteria' => 'III. Feedbacking Mechanism',     'responses' => $total_responses ?: '-', 'mean' => $overall_avg ? number_format($overall_avg, 2) : '-'],
    ];
}

$descriptive_interpretation = ($overall_avg > 0) ? getRankInterpretation($overall_avg) : 'N/A';

$schools = [
    'mcnp' => [
        'name'    => 'MEDICAL COLLEGES OF NORTHERN PHILIPPINES',
        'address' => 'Alimannao Hills, Peñablanca, Cagayan 3502 Philippines',
        'phone'   => 'Telefax No: (+63) (078) 304-1010 Website: www.mcnp.edu.ph',
        'email'   => 'E-Mail Address: qaoffice@mcnp.edu.ph/dco@mcnp.edu.ph',
        'short'   => 'MCNP',
    ],
    'isap' => [
        'name'    => 'INTERNATIONAL SCHOOL OF ASIA AND THE PACIFIC',
        'address' => 'Alimannao Hills, Peñablanca, Cagayan 3502 Philippines',
        'phone'   => 'Telefax No: (+63) (078) 304-1010 Website: www.isap.edu.ph',
        'email'   => 'E-Mail Address: qaoffice@isap.edu.ph/dco@isap.edu.ph',
        'short'   => 'ISAP',
    ],
];
$sc = $schools[$school] ?? $schools['mcnp'];

// Word Document Headers
$filename = "QMS_Form_013_" . preg_replace('/[^a-zA-Z0-9]/', '_', $formatted_faculty_sig_name) . ".doc";
header("Content-Type: application/vnd.ms-word");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Content-disposition: attachment; filename=\"$filename\"");
?>
<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
<head>
<meta charset="utf-8">
<title>Summary of Evaluation — <?php echo htmlspecialchars($formatted_faculty_sig_name); ?></title>
<style>
body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; margin: 20px; }
table { border-collapse: collapse; width: 100%; }
.border-table td, .border-table th { border: 1px solid black; padding: 4px 6px; }
.text-center { text-align: center; }
.bold { font-weight: bold; }
.italic { font-style: italic; }
.header-box { border: 1px solid black; font-size: 8pt; width: 180px; }
.header-box td { border: 1px solid black; padding: 2px 4px; }
.page-break { page-break-before: always; }
</style>
</head>
<body>

<!-- Top Header Row with Box at Top Right -->
<table style="width: 100%; margin-bottom: 8px;">
    <tr>
        <td style="width: 180px;"></td>
        <td class="text-center" style="vertical-align: top;">
            <div style="font-size: 13pt; font-weight: bold;"><?php echo htmlspecialchars($sc['name']); ?></div>
            <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['address']); ?></div>
            <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['phone']); ?></div>
            <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['email']); ?></div>
        </td>
        <td style="width: 180px; vertical-align: top;" align="right">
            <table class="header-box">
                <tr><td>Document No.<br><b>QMS-DCO-Form No: 013</b></td></tr>
                <tr><td>Effective Date: <b>July 2019</b></td></tr>
                <tr><td>Revision No. <b>00</b></td></tr>
            </table>
        </td>
    </tr>
</table>

<!-- Titles Block (Centered) -->
<div class="text-center" style="margin-bottom: 10px;">
    <div style="font-weight: bold; font-size: 10pt;">QUALITY ASSURANCE OFFICE</div>
    <div style="font-weight: bold; font-size: 11pt;">SUMMARY OF EVALUATION</div>
    <div style="font-weight: bold; font-size: 10pt;">STUDENT-FACULTY EVALUATION</div>
</div>

<table style="margin-bottom: 6px; font-weight: bold; font-size: 9.5pt;">
    <tr>
        <td align="left">SCHOOL YEAR: <span style="font-style: italic;"><?php echo htmlspecialchars($school_year_display); ?></span></td>
        <td align="right">SEMESTER: <span style="font-style: italic;"><?php echo htmlspecialchars($semester_display); ?></span></td>
    </tr>
</table>

<!-- Faculty Info Table -->
<table class="border-table" style="margin-bottom: 8px; font-size: 9pt;">
    <tr>
        <td style="width: 22%; font-weight: bold;">Name of Faculty</td>
        <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($formatted_faculty_table_name); ?></td>
        <td style="width: 19%; font-weight: bold;">Employment Status:</td>
        <td style="width: 15%; font-weight: bold;"><?php echo htmlspecialchars($employment_status); ?></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Subject/s Taught</td>
        <td colspan="3"><?php echo htmlspecialchars($subjects_taught); ?></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Other Assignment/s</td>
        <td colspan="3"><?php echo htmlspecialchars($other_assignments); ?></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">College/Department</td>
        <td colspan="3" style="font-weight: bold;"><?php echo htmlspecialchars($college_dept); ?></td>
    </tr>
</table>

<!-- Rank Equivalent Box -->
<table style="border: 1px solid black; margin-bottom: 8px; font-size: 8.5pt;">
    <tr>
        <td colspan="2" class="text-center bold" style="padding-top: 4px;">RANK EQUIVALENT</td>
    </tr>
    <tr>
        <td style="padding: 2px 20px;">4.20 – 5.0 &nbsp;&nbsp;&nbsp;=&nbsp;&nbsp; Excellent &nbsp;&nbsp;(95 – 100)<br>3.40 – 4.19 &nbsp;&nbsp;=&nbsp;&nbsp; Very Good &nbsp;(90 – 94)</td>
        <td style="padding: 2px 20px;">2.60 – 3.39 &nbsp;&nbsp;=&nbsp;&nbsp; Good &nbsp;&nbsp;(86 – 89)<br>1.80 – 2.59 &nbsp;&nbsp;=&nbsp;&nbsp; Fair &nbsp;&nbsp;&nbsp;(80 – 85)</td>
    </tr>
    <tr>
        <td colspan="2" class="text-center" style="padding-bottom: 4px;">1.0 – 1.79 &nbsp;&nbsp;=&nbsp;&nbsp; Poor &nbsp;&nbsp;&nbsp;(75 – 79)</td>
    </tr>
</table>

<!-- Results Table -->
<table class="border-table" style="margin-bottom: 8px; font-size: 9pt;">
    <tr>
        <td colspan="4" class="text-center bold" style="letter-spacing: 2px;">R E S U L T / S</td>
    </tr>
    <tr>
        <th style="width: 34%;">CRITERIA</th>
        <th style="width: 14%;">No. of Responses</th>
        <th style="width: 14%;">Mean</th>
        <th style="width: 38%;">COMMENTS AND SUGGESTIONS FOR IMPROVEMENT</th>
    </tr>
    <?php 
    $rowCount = count($criteria_rows);
    foreach ($criteria_rows as $i => $row): 
    ?>
    <tr>
        <td><?php echo htmlspecialchars($row['criteria']); ?></td>
        <td class="text-center"><?php echo htmlspecialchars($row['responses']); ?></td>
        <td class="text-center"><?php echo htmlspecialchars($row['mean']); ?></td>
        <?php if ($i === 0): ?>
            <td rowspan="<?php echo $rowCount; ?>" class="text-center bold" style="vertical-align: middle;">SEE ATTACHED</td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <tr style="font-weight: bold;">
        <td colspan="2" align="right" style="padding-right: 15px;">OVERALL RATING</td>
        <td class="text-center"><?php echo ($overall_avg > 0) ? number_format($overall_avg, 2) : '-'; ?></td>
        <td></td>
    </tr>
    <tr style="font-weight: bold;">
        <td colspan="2" align="right" style="padding-right: 15px;">DESCRIPTIVE INTERPRETATION</td>
        <td colspan="2" class="text-center"><?php echo htmlspecialchars($descriptive_interpretation); ?></td>
    </tr>
</table>

<!-- Signatories Table -->
<table class="border-table" style="margin-bottom: 6px; font-size: 8.5pt;">
    <!-- ROW 1 -->
    <tr>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px; border-right: 1px solid black;">
            <div style="font-weight: bold; font-style: italic; margin-bottom: 12px;">Prepared by:</div>
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;">DOMINGA M. QUINAGORAN, LPT, MBA</div>
                <div style="font-style: italic; font-size: 8pt;">Quality Assurance Moderator for Administration</div>
            </div>
        </td>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px;">
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;">CINDY T. DULDULAO, PTRP, MPH</div>
                <div style="font-style: italic; font-size: 8pt;">Quality Assurance Moderator for Academics</div>
            </div>
        </td>
    </tr>
    <!-- ROW 2 -->
    <tr>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px; border-right: 1px solid black;">
            <div style="font-weight: bold; font-style: italic; margin-bottom: 12px;">Attested by:</div>
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;"><?php echo htmlspecialchars($supervisor_name); ?></div>
                <div style="font-style: italic; font-size: 8pt;">Immediate Supervisor Name and Signature</div>
            </div>
        </td>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px;">
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;">CB RONIE E. SUGAROL, MPBM</div>
                <div style="font-style: italic; font-size: 8pt;">Quality Assurance Director / Administrative Officer</div>
            </div>
        </td>
    </tr>
    <!-- ROW 3 -->
    <tr>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px; border-right: 1px solid black;">
            <div style="font-weight: bold; font-style: italic; margin-bottom: 2px;">Conforme:</div>
            <div style="font-style: italic; font-size: 7.8pt; line-height: 1.25; margin-bottom: 12px;">
                I have carefully reviewed and accepted my students' evaluation results during the <u><?php echo $semester_word; ?> semester</u> of the <u><?php echo htmlspecialchars($school_year_display); ?></u> school year.
            </div>
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;"><?php echo htmlspecialchars($formatted_faculty_sig_name); ?></div>
                <div style="font-style: italic; font-size: 8pt;">Faculty Member Name and Signature</div>
            </div>
        </td>
        <td style="width: 50%; vertical-align: bottom; padding: 6px 12px;">
            <div class="text-center">
                <div style="font-weight: bold; text-decoration: underline;">WINNIE T. CANCEJO, Ph.D</div>
                <div style="font-style: italic; font-size: 8pt;">Vice President for Academic Affairs</div>
            </div>
        </td>
    </tr>
</table>

<!-- Footer Page 1 (100% PANTAY SA ISANG LINYA) -->
<table style="width: 100%; font-size: 6.8pt; margin-top: 4px; border: none !important;">
    <tr>
        <td align="left" style="font-style: italic; white-space: nowrap; border: none !important;">This printed document is for reference use only unless stamped as a <b>CONTROLLED</b> copy by the Quality Assurance Office (QAO).</td>
        <td align="right" style="white-space: nowrap; padding-left: 20px; width: 1%; border: none !important;"><?php echo date('d/m/Y'); ?></td>
        <td align="right" style="white-space: nowrap; padding-left: 20px; width: 1%; border: none !important;">Page 1 of 2</td>
    </tr>
</table>

<br class="page-break" style="page-break-before: always;">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- 📄 PAGE 2: ATTACHMENT                                      -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="text-center" style="margin-bottom: 10px;">
    <div style="font-size: 13pt; font-weight: bold;"><?php echo htmlspecialchars($sc['name']); ?></div>
    <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['address']); ?></div>
    <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['phone']); ?></div>
    <div style="font-size: 8pt;"><?php echo htmlspecialchars($sc['email']); ?></div>
</div>

<div class="text-center" style="margin-bottom: 12px;">
    <div style="font-weight: bold; font-size: 10pt;">QUALITY ASSURANCE OFFICE</div>
    <div style="font-weight: bold; font-size: 11pt;">SUMMARY OF EVALUATION</div>
    <div style="font-weight: bold; font-size: 10pt;">STUDENT-FACULTY EVALUATION</div>
    <div style="font-weight: bold; font-size: 10pt; text-decoration: underline; margin-top: 4px;">ATTACHMENT: COMMENTS AND SUGGESTIONS FOR IMPROVEMENT</div>
</div>

<table class="border-table" style="margin-bottom: 10px; font-size: 9pt;">
    <tr>
        <td style="width: 22%; font-weight: bold;">Name of Faculty</td>
        <td style="width: 44%; font-weight: bold;"><?php echo htmlspecialchars($formatted_faculty_table_name); ?></td>
        <td style="width: 19%; font-weight: bold;">School Year:</td>
        <td style="width: 15%; font-weight: bold;"><?php echo htmlspecialchars($school_year_display); ?></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">College/Department</td>
        <td style="font-weight: bold;"><?php echo htmlspecialchars($college_dept); ?></td>
        <td style="font-weight: bold;">Semester:</td>
        <td style="font-weight: bold;"><?php echo htmlspecialchars($semester_display); ?></td>
    </tr>
</table>

<table class="border-table" style="margin-bottom: 15px; font-size: 9pt;">
    <tr style="background: #f0f0f0;">
        <th style="width: 8%;">No.</th>
        <th style="width: 72%;">Comments / Suggestions Submitted by Students</th>
        <th style="width: 20%;">Sentiment / Classification</th>
    </tr>
    <?php if (empty($all_comments)): ?>
        <tr>
            <td colspan="3" class="text-center" style="padding: 20px; color: #666;">
                <em>No written comments or suggestions submitted for this evaluation period.</em>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($all_comments as $idx => $com): 
            $feedbackText = trim($com['feedback']);
            $sentiment = function_exists('analyzeSentiment') ? analyzeSentiment($feedbackText, $com['rating'] ?? 5) : 'Positive';
        ?>
        <tr>
            <td class="text-center bold"><?php echo ($idx + 1); ?></td>
            <td>&ldquo;<?php echo htmlspecialchars($feedbackText); ?>&rdquo;</td>
            <td class="text-center bold"><?php echo htmlspecialchars($sentiment); ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>

<!-- Footer Page 2 (100% PANTAY SA ISANG LINYA) -->
<table style="width: 100%; font-size: 6.8pt; margin-top: 4px; border: none !important;">
    <tr>
        <td align="left" style="font-style: italic; white-space: nowrap; border: none !important;">This printed document is for reference use only unless stamped as a <b>CONTROLLED</b> copy by the Quality Assurance Office (QAO).</td>
        <td align="right" style="white-space: nowrap; padding-left: 20px; width: 1%; border: none !important;"><?php echo date('d/m/Y'); ?></td>
        <td align="right" style="white-space: nowrap; padding-left: 20px; width: 1%; border: none !important;">Page 2 of 2</td>
    </tr>
</table>

</body>
</html>
