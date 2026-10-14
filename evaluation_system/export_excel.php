<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';
/** @var mysqli $conn */

// Access control — admin (QA), dean, faculty allowed; ICT blocked
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'dean', 'faculty'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'system admin') {
        header("Location: dashboard_ict.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$role         = $_SESSION['role'];
$session_id   = $_SESSION['id'] ?? 0;
$session_dept = $_SESSION['dept_id'] ?? null;

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
$school  = 'mcnp';

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
if (isset($_GET['school']) && in_array(strtolower($_GET['school']), ['mcnp','isap'])) {
    $school = strtolower($_GET['school']);
}

// Dean can only see their department
if ($role === 'dean' && $faculty && $faculty['dept_id'] != $session_dept) {
    header("Location: view_results.php"); exit();
}

// Faculty Names Formatting: LASTNAME, FIRSTNAME [MIDDLE]
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
$subjects_taught = !empty($subjects_list) ? implode(', ', $subjects_list) : 'N/A';
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

// School Details
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

// Fetch 37 QAO Questions indexed by category
$q_online = [];
$q_inperson = [];
$q_feedbacking = [];

$q_res = $conn->query("SELECT id, category, question_text FROM evaluation_questions WHERE category IN ('Conduct of Online Class', 'Conduct of In-person Class', 'Feedbacking Mechanism') ORDER BY id ASC");
if ($q_res) {
    while ($q_row = $q_res->fetch_assoc()) {
        if ($q_row['category'] === 'Conduct of Online Class') {
            $q_online[] = $q_row['id'];
        } elseif ($q_row['category'] === 'Conduct of In-person Class') {
            $q_inperson[] = $q_row['id'];
        } elseif ($q_row['category'] === 'Feedbacking Mechanism') {
            $q_feedbacking[] = $q_row['id'];
        }
    }
}

// Fetch all evaluations for this faculty in active period
$evaluations = [];
if ($faculty_id && $period_id) {
    $e_stmt = $conn->prepare("
        SELECT id, rating, feedback, created_at, student_id 
        FROM evaluations 
        WHERE faculty_id = ? AND academic_period_id = ?
        ORDER BY id ASC
    ");
    $e_stmt->bind_param("ii", $faculty_id, $period_id);
    $e_stmt->execute();
    $evaluations = $e_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch all item responses for these evaluations
$responses_map = [];
if (!empty($evaluations)) {
    $eval_ids = array_column($evaluations, 'id');
    $ids_in = implode(',', array_map('intval', $eval_ids));
    $r_res = $conn->query("SELECT evaluation_id, question_id, rating FROM evaluation_responses WHERE evaluation_id IN ($ids_in)");
    if ($r_res) {
        while ($r_row = $r_res->fetch_assoc()) {
            $responses_map[$r_row['evaluation_id']][$r_row['question_id']] = (float)$r_row['rating'];
        }
    }
}

// Prepare Rows Data
$rows_data = [];
$col_sums_online = array_fill(0, 16, 0);
$col_counts_online = array_fill(0, 16, 0);
$col_sums_inperson = array_fill(0, 15, 0);
$col_counts_inperson = array_fill(0, 15, 0);
$col_sums_feedbacking = array_fill(0, 6, 0);
$col_counts_feedbacking = array_fill(0, 6, 0);

$row_num = 1;
foreach ($evaluations as $ev) {
    $eId = $ev['id'];
    $feedback = trim($ev['feedback'] ?? '');
    if (empty($feedback)) $feedback = 'N/A';

    // Online items
    $online_ratings = [];
    foreach ($q_online as $idx => $qid) {
        $val = $responses_map[$eId][$qid] ?? ($ev['rating'] ?: 5.0);
        $online_ratings[] = $val;
        $col_sums_online[$idx] += $val;
        $col_counts_online[$idx]++;
    }
    while (count($online_ratings) < 16) {
        $val = (float)($ev['rating'] ?: 5.0);
        $online_ratings[] = $val;
        $idx = count($online_ratings) - 1;
        $col_sums_online[$idx] += $val;
        $col_counts_online[$idx]++;
    }
    $online_avg = !empty($online_ratings) ? array_sum($online_ratings) / count($online_ratings) : 0;

    // In-person items
    $inperson_ratings = [];
    foreach ($q_inperson as $idx => $qid) {
        $val = $responses_map[$eId][$qid] ?? ($ev['rating'] ?: 5.0);
        $inperson_ratings[] = $val;
        $col_sums_inperson[$idx] += $val;
        $col_counts_inperson[$idx]++;
    }
    while (count($inperson_ratings) < 15) {
        $val = (float)($ev['rating'] ?: 5.0);
        $inperson_ratings[] = $val;
        $idx = count($inperson_ratings) - 1;
        $col_sums_inperson[$idx] += $val;
        $col_counts_inperson[$idx]++;
    }
    $inperson_avg = !empty($inperson_ratings) ? array_sum($inperson_ratings) / count($inperson_ratings) : 0;

    // Feedbacking items
    $feedbacking_ratings = [];
    foreach ($q_feedbacking as $idx => $qid) {
        $val = $responses_map[$eId][$qid] ?? ($ev['rating'] ?: 5.0);
        $feedbacking_ratings[] = $val;
        $col_sums_feedbacking[$idx] += $val;
        $col_counts_feedbacking[$idx]++;
    }
    while (count($feedbacking_ratings) < 6) {
        $val = (float)($ev['rating'] ?: 5.0);
        $feedbacking_ratings[] = $val;
        $idx = count($feedbacking_ratings) - 1;
        $col_sums_feedbacking[$idx] += $val;
        $col_counts_feedbacking[$idx]++;
    }
    $feedbacking_avg = !empty($feedbacking_ratings) ? array_sum($feedbacking_ratings) / count($feedbacking_ratings) : 0;

    $rows_data[] = [
        'no'          => $row_num++,
        'online'      => $online_ratings,
        'online_avg'  => $online_avg,
        'inperson'    => $inperson_ratings,
        'inperson_avg'=> $inperson_avg,
        'feedbacking' => $feedbacking_ratings,
        'feed_avg'    => $feedbacking_avg,
        'comment'     => $feedback,
    ];
}

// Compute Column Means
$online_means = [];
for ($i = 0; $i < 16; $i++) {
    $online_means[$i] = ($col_counts_online[$i] > 0) ? ($col_sums_online[$i] / $col_counts_online[$i]) : 0;
}
$online_overall_mean = !empty($online_means) ? array_sum($online_means) / count($online_means) : 0;

$inperson_means = [];
for ($i = 0; $i < 15; $i++) {
    $inperson_means[$i] = ($col_counts_inperson[$i] > 0) ? ($col_sums_inperson[$i] / $col_counts_inperson[$i]) : 0;
}
$inperson_overall_mean = !empty($inperson_means) ? array_sum($inperson_means) / count($inperson_means) : 0;

$feedbacking_means = [];
for ($i = 0; $i < 6; $i++) {
    $feedbacking_means[$i] = ($col_counts_feedbacking[$i] > 0) ? ($col_sums_feedbacking[$i] / $col_counts_feedbacking[$i]) : 0;
}
$feedbacking_overall_mean = !empty($feedbacking_means) ? array_sum($feedbacking_means) / count($feedbacking_means) : 0;

$all_category_means = array_filter([$online_overall_mean, $inperson_overall_mean, $feedbacking_overall_mean]);
$grand_overall_rating = !empty($all_category_means) ? (array_sum($all_category_means) / count($all_category_means)) : 0;

function getExcelRankInterpretation($score) {
    if ($score >= 4.20) return 'Excellent';
    if ($score >= 3.40) return 'Very Good';
    if ($score >= 2.60) return 'Good';
    if ($score >= 1.80) return 'Fair';
    if ($score >= 1.00) return 'Poor';
    return 'N/A';
}
$descriptive_interpretation = ($grand_overall_rating > 0) ? getExcelRankInterpretation($grand_overall_rating) : 'N/A';

// Export filename
$clean_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $formatted_faculty_table_name);
$filename = "STUDENT_FACULTY_EVALUATION_REPORT_" . $clean_name . ".xls";

// CSV Mode if explicitly requested
if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('.xls', '.csv', $filename) . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($out, ['STUDENT FACULTY EVALUATION RESPONSES SUMMARY']);
    fputcsv($out, ['Name of Faculty: ' . $formatted_faculty_table_name]);
    fputcsv($out, ['School Year: ' . $school_year_display, 'Semester: ' . $semester_display]);
    fputcsv($out, []);

    $h1 = ['No.'];
    for ($i = 1; $i <= 16; $i++) $h1[] = 'Conduct of Online Class - Item ' . $i;
    $h1[] = 'Online AVG';
    for ($i = 1; $i <= 15; $i++) $h1[] = 'Conduct of In-person Class - Item ' . $i;
    $h1[] = 'In-person AVG';
    for ($i = 1; $i <= 6; $i++) $h1[] = 'Feedbacking Mechanism - Item ' . $i;
    $h1[] = 'Feedbacking AVG';
    $h1[] = 'Comments and Suggestions for Improvement';
    fputcsv($out, $h1);

    foreach ($rows_data as $r) {
        $row_csv = [$r['no']];
        foreach ($r['online'] as $v) $row_csv[] = number_format($v, 2);
        $row_csv[] = number_format($r['online_avg'], 2);
        foreach ($r['inperson'] as $v) $row_csv[] = number_format($v, 2);
        $row_csv[] = number_format($r['inperson_avg'], 2);
        foreach ($r['feedbacking'] as $v) $row_csv[] = number_format($v, 2);
        $row_csv[] = number_format($r['feed_avg'], 2);
        $row_csv[] = $r['comment'];
        fputcsv($out, $row_csv);
    }

    $sum_csv = ['AVERAGE'];
    foreach ($online_means as $m) $sum_csv[] = number_format($m, 2);
    $sum_csv[] = number_format($online_overall_mean, 2);
    foreach ($inperson_means as $m) $sum_csv[] = number_format($m, 2);
    $sum_csv[] = number_format($inperson_overall_mean, 2);
    foreach ($feedbacking_means as $m) $sum_csv[] = number_format($m, 2);
    $sum_csv[] = number_format($feedbacking_overall_mean, 2);
    $sum_csv[] = 'OVERALL RATING: ' . number_format($grand_overall_rating, 2);
    fputcsv($out, $sum_csv);
    fputcsv($out, ['>>>>>>>>>>>>>>NOTHING FOLLOWS<<<<<<<<<<<<<<']);
    fclose($out);
    exit();
}

// ── Multi-Sheet SpreadsheetML Workbook ─────────────────────────
// Sheet 1: Official Form 013 Summary (with Document Control Box on top right)
// Sheet 2: Student Responses and Comments (42 columns)
$sheet1_name = "MCNP 85% (3)";
$sheet2_name = "summary of eval result (3)";
$total_respondents = count($rows_data);

function xmlSafe($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Headers for native Excel (.xls)
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");

echo '<?xml version="1.0"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Author>Quality Assurance Office</Author>
  <Created><?php echo date('Y-m-d\TH:i:s\Z'); ?></Created>
  <Company><?php echo xmlSafe($sc['name']); ?></Company>
 </DocumentProperties>
<?php
echo <<<'XML'
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Borders/>
   <Font ss:FontName="Arial" ss:Size="9"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <!-- Sheet 1: Letterhead & Doc Box -->
  <Style ss:ID="sSchoolName">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#000000"/>
  </Style>
  <Style ss:ID="sSchoolSub">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="8" ss:Color="#333333"/>
  </Style>
  <Style ss:ID="sDocBox">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sDocBoxBold">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  </Style>
  <!-- Sheet 1: Titles -->
  <Style ss:ID="sOfficeTitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sMainTitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sSubTitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sPeriodBar">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="9.5" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sPeriodBarRight">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Arial" ss:Size="9.5" ss:Bold="1"/>
  </Style>
  <!-- Sheet 1: Info Box -->
  <Style ss:ID="sInfoLbl">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sInfoVal">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sInfoValBold">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
  <!-- Sheet 1: Rank Box -->
  <Style ss:ID="sRankBox">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sRankBoxTitle">
   <Borders>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>
  <!-- Sheet 1: Results Table -->
  <Style ss:ID="sResultBanner">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sResultHead">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sResultCriteria">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sResultNum">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <NumberFormat ss:Format="0.00"/>
  </Style>
  <Style ss:ID="sResultAttached">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sResultTotalLbl">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sResultTotalNum">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.00"/>
  </Style>
  <Style ss:ID="sResultInterp">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
  </Style>
  <!-- Signatories -->
  <Style ss:ID="sSigBox">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Vertical="Top"/>
  </Style>
  <Style ss:ID="sSigLbl">
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Top"/>
  </Style>
  <Style ss:ID="sSigName">
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
  </Style>
  <Style ss:ID="sSigRole">
   <Font ss:FontName="Arial" ss:Size="7.5"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Top"/>
  </Style>
  <Style ss:ID="sSigConforme">
   <Font ss:FontName="Arial" ss:Size="7.5" ss:Italic="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Top" ss:WrapText="1"/>
  </Style>
  <!-- Sheet 2: Responses Summary Styles -->
  <Style ss:ID="sSheet2Title">
   <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sSheet2Meta">
   <Font ss:FontName="Arial" ss:Size="9.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sThHead">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sTdNo">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sTdItem">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <NumberFormat ss:Format="0.00"/>
  </Style>
  <Style ss:ID="sTdAvg">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#E2E8F0" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.00"/>
  </Style>
  <Style ss:ID="sTdComment">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8"/>
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="sTdSummaryAvg">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.00"/>
  </Style>
  <Style ss:ID="sTdNothing">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Arial" ss:Size="8.5" ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Interior ss:Color="#FAFAFA" ss:Pattern="Solid"/>
  </Style>
 </Styles>
XML;
?>

 <!-- ═══════════════════════════════════════════════════════════ -->
 <!-- 📄 SHEET 1: OFFICIAL FORM 013 RATINGS SUMMARY              -->
 <!-- ═══════════════════════════════════════════════════════════ -->
 <Worksheet ss:Name="<?php echo xmlSafe($sheet1_name); ?>">
  <Table ss:DefaultRowHeight="16">
   <Column ss:Index="1" ss:Width="35"/>
   <Column ss:Index="2" ss:Width="110"/>
   <Column ss:Index="3" ss:Width="65"/>
   <Column ss:Index="4" ss:Width="85"/>
   <Column ss:Index="5" ss:Width="70"/>
   <Column ss:Index="6" ss:Width="70"/>
   <Column ss:Index="7" ss:Width="70"/>
   <Column ss:Index="8" ss:Width="85"/>
   <Column ss:Index="9" ss:Width="85"/>
   <Column ss:Index="10" ss:Width="65"/>
   <Column ss:Index="11" ss:Width="65"/>

   <!-- Row 1: School Name + Document Control Box Row 1 (flush top-right) -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="7" ss:StyleID="sSchoolName"><Data ss:Type="String"><?php echo xmlSafe($sc['name']); ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="sDocBox"><Data ss:Type="String">Document No: QMS-DCO-Form No: 013</Data></Cell>
   </Row>

   <!-- Row 2: Address + Document Control Box Row 2 -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="7" ss:StyleID="sSchoolSub"><Data ss:Type="String"><?php echo xmlSafe($sc['address']); ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="sDocBox"><Data ss:Type="String">Effective Date: July 2019</Data></Cell>
   </Row>

   <!-- Row 3: Phone/Website + Document Control Box Row 3 -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="7" ss:StyleID="sSchoolSub"><Data ss:Type="String"><?php echo xmlSafe($sc['phone']); ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="sDocBox"><Data ss:Type="String">Revision No. 00</Data></Cell>
   </Row>

   <!-- Row 4: Email -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="7" ss:StyleID="sSchoolSub"><Data ss:Type="String"><?php echo xmlSafe($sc['email']); ?></Data></Cell>
    <Cell ss:Index="9"></Cell>
   </Row>

   <!-- Row 5: Spacer -->
   <Row ss:Height="8"><Cell></Cell></Row>

   <!-- Row 6: Office Title -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="10" ss:StyleID="sOfficeTitle"><Data ss:Type="String">QUALITY ASSURANCE OFFICE</Data></Cell>
   </Row>
   <!-- Row 7: Main Title -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="10" ss:StyleID="sMainTitle"><Data ss:Type="String">SUMMARY OF EVALUATION</Data></Cell>
   </Row>
   <!-- Row 8: Sub Title -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="10" ss:StyleID="sSubTitle"><Data ss:Type="String">STUDENT-FACULTY EVALUATION</Data></Cell>
   </Row>

   <!-- Row 9: Period Bar -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="5" ss:StyleID="sPeriodBar"><Data ss:Type="String">SCHOOL YEAR: <?php echo xmlSafe($school_year_display); ?></Data></Cell>
    <Cell ss:MergeAcross="4" ss:StyleID="sPeriodBarRight"><Data ss:Type="String">SEMESTER: <?php echo xmlSafe($semester_display); ?></Data></Cell>
   </Row>

   <!-- Row 10: Faculty Info Line 1 -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoLbl"><Data ss:Type="String">Name of Faculty</Data></Cell>
    <Cell ss:MergeAcross="4" ss:StyleID="sInfoValBold"><Data ss:Type="String"><?php echo xmlSafe($formatted_faculty_table_name); ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoLbl"><Data ss:Type="String">Employment Status:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoValBold"><Data ss:Type="String"><?php echo xmlSafe($employment_status); ?></Data></Cell>
   </Row>

   <!-- Row 11: Faculty Info Line 2 -->
   <Row ss:Height="26">
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoLbl"><Data ss:Type="String">Subject/s Taught</Data></Cell>
    <Cell ss:MergeAcross="8" ss:StyleID="sInfoVal"><Data ss:Type="String"><?php echo xmlSafe($subjects_taught); ?></Data></Cell>
   </Row>

   <!-- Row 12: Faculty Info Line 3 -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoLbl"><Data ss:Type="String">Other Assignment/s</Data></Cell>
    <Cell ss:MergeAcross="8" ss:StyleID="sInfoVal"><Data ss:Type="String"><?php echo xmlSafe($other_assignments); ?></Data></Cell>
   </Row>

   <!-- Row 13: Faculty Info Line 4 -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="1" ss:StyleID="sInfoLbl"><Data ss:Type="String">College/Department</Data></Cell>
    <Cell ss:MergeAcross="8" ss:StyleID="sInfoValBold"><Data ss:Type="String"><?php echo xmlSafe($college_dept); ?></Data></Cell>
   </Row>

   <!-- Row 14: Spacer -->
   <Row ss:Height="6"><Cell></Cell></Row>

   <!-- Row 15: Rank Equivalent Box Title -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="10" ss:StyleID="sRankBoxTitle"><Data ss:Type="String">RANK EQUIVALENT</Data></Cell>
   </Row>
   <!-- Row 16: Rank Line 1 -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="4" ss:StyleID="sRankBox"><Data ss:Type="String">4.20 - 5.0   =   Excellent   (95 - 100)</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sRankBox"><Data ss:Type="String">2.60 - 3.39   =   Good   (86 - 89)</Data></Cell>
   </Row>
   <!-- Row 17: Rank Line 2 -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="4" ss:StyleID="sRankBox"><Data ss:Type="String">3.40 - 4.19   =   Very Good   (90 - 94)</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sRankBox"><Data ss:Type="String">1.80 - 2.59   =   Fair   (80 - 85)</Data></Cell>
   </Row>
   <!-- Row 18: Rank Line 3 -->
   <Row ss:Height="15">
    <Cell ss:MergeAcross="10" ss:StyleID="sRankBox"><Data ss:Type="String">1.0 - 1.79   =   Poor   (75 - 79)</Data></Cell>
   </Row>

   <!-- Row 19: Spacer -->
   <Row ss:Height="6"><Cell></Cell></Row>

   <!-- Row 20: Results Banner -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="10" ss:StyleID="sResultBanner"><Data ss:Type="String">R E S U L T / S</Data></Cell>
   </Row>

   <!-- Row 21: Table Header -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="sResultHead"><Data ss:Type="String">CRITERIA</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sResultHead"><Data ss:Type="String">No. of&#10;Responses</Data></Cell>
    <Cell ss:StyleID="sResultHead"><Data ss:Type="String">Mean</Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="sResultHead"><Data ss:Type="String">COMMENTS AND SUGGESTIONS&#10;FOR IMPROVEMENT</Data></Cell>
   </Row>

   <!-- Row 22: Online Class -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="4" ss:StyleID="sResultCriteria"><Data ss:Type="String">I. Conduct of Online Class</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo $total_respondents; ?></Data></Cell>
    <Cell ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo number_format($online_overall_mean, 2, '.', ''); ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:MergeDown="2" ss:StyleID="sResultAttached"><Data ss:Type="String">SEE ATTACHED</Data></Cell>
   </Row>

   <!-- Row 23: In-person Class -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="4" ss:StyleID="sResultCriteria"><Data ss:Type="String">II. Conduct of In-person Class</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo $total_respondents; ?></Data></Cell>
    <Cell ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo number_format($inperson_overall_mean, 2, '.', ''); ?></Data></Cell>
    <Cell ss:Index="9"></Cell>
   </Row>

   <!-- Row 24: Feedbacking Mechanism -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="4" ss:StyleID="sResultCriteria"><Data ss:Type="String">III. Feedbacking Mechanism</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo $total_respondents; ?></Data></Cell>
    <Cell ss:StyleID="sResultNum"><Data ss:Type="Number"><?php echo number_format($feedbacking_overall_mean, 2, '.', ''); ?></Data></Cell>
    <Cell ss:Index="9"></Cell>
   </Row>

   <!-- Row 25: Overall Rating -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="sResultTotalLbl"><Data ss:Type="String">OVERALL RATING</Data></Cell>
    <Cell ss:StyleID="sResultTotalNum"><Data ss:Type="Number"><?php echo number_format($grand_overall_rating, 2, '.', ''); ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="sResultTotalLbl"><Data ss:Type="String"></Data></Cell>
   </Row>

   <!-- Row 26: Descriptive Interpretation -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="sResultTotalLbl"><Data ss:Type="String">DESCRIPTIVE INTERPRETATION</Data></Cell>
    <Cell ss:MergeAcross="3" ss:StyleID="sResultInterp"><Data ss:Type="String"><?php echo xmlSafe($descriptive_interpretation); ?></Data></Cell>
   </Row>

   <!-- Row 27: Spacer -->
   <Row ss:Height="8"><Cell></Cell></Row>

   <!-- Signatories -->
   <!-- Row 28: Prepared by Header -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigLbl"><Data ss:Type="String">Prepared by:</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigLbl"><Data ss:Type="String"></Data></Cell>
   </Row>
   <!-- Row 29: Prepared by Names -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigName"><Data ss:Type="String">DOMINGA M. QUINAGORAN, LPT, MBA</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigName"><Data ss:Type="String">CINDY T. DULDULAO, PTRP, MPH</Data></Cell>
   </Row>
   <!-- Row 30: Prepared by Roles -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigRole"><Data ss:Type="String">Quality Assurance Moderator for Administration</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigRole"><Data ss:Type="String">Quality Assurance Moderator for Academics</Data></Cell>
   </Row>

   <!-- Row 31: Attested by Header -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigLbl"><Data ss:Type="String">Attested by:</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigLbl"><Data ss:Type="String"></Data></Cell>
   </Row>
   <!-- Row 32: Attested by Names -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigName"><Data ss:Type="String"><?php echo xmlSafe($supervisor_name); ?></Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigName"><Data ss:Type="String">CB RONIE E. SUGAROL, MPBM</Data></Cell>
   </Row>
   <!-- Row 33: Attested by Roles -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigRole"><Data ss:Type="String">Immediate Supervisor Name and Signature</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigRole"><Data ss:Type="String">Quality Assurance Director / Administrative Officer</Data></Cell>
   </Row>

   <!-- Row 34: Conforme Header & Text -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigLbl"><Data ss:Type="String">Conforme:</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigLbl"><Data ss:Type="String"></Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigConforme"><Data ss:Type="String">I have carefully reviewed and accepted my students' evaluation results during the <?php echo xmlSafe($semester_word); ?> semester of the <?php echo xmlSafe($school_year_display); ?> school year.</Data></Cell>
    <Cell ss:MergeAcross="5"><Data ss:Type="String"></Data></Cell>
   </Row>
   <!-- Row 35: Conforme / VPAA Names -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigName"><Data ss:Type="String"><?php echo xmlSafe($formatted_faculty_sig_name); ?></Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigName"><Data ss:Type="String">WINNIE T. CANCEJO, Ph.D</Data></Cell>
   </Row>
   <!-- Row 36: Conforme / VPAA Roles -->
   <Row ss:Height="16">
    <Cell ss:MergeAcross="4" ss:StyleID="sSigRole"><Data ss:Type="String">Faculty Member Name and Signature</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sSigRole"><Data ss:Type="String">Vice President for Academic Affairs</Data></Cell>
   </Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <DisplayGridlines/>
   <Print>
    <ValidPrinterInfo/>
    <PaperSizeIndex>9</PaperSizeIndex>
    <HorizontalResolution>600</HorizontalResolution>
    <VerticalResolution>600</VerticalResolution>
   </Print>
  </WorksheetOptions>
 </Worksheet>

 <!-- ═══════════════════════════════════════════════════════════ -->
 <!-- 📄 SHEET 2: STUDENT EVALUATION RESPONSES & COMMENTS        -->
 <!-- ═══════════════════════════════════════════════════════════ -->
 <Worksheet ss:Name="<?php echo xmlSafe($sheet2_name); ?>">
  <Table ss:DefaultRowHeight="16">
   <Column ss:Index="1" ss:Width="35"/>
   <?php for ($c = 2; $c <= 17; $c++): ?>
    <Column ss:Index="<?php echo $c; ?>" ss:Width="38"/>
   <?php endfor; ?>
   <Column ss:Index="18" ss:Width="46"/>
   <?php for ($c = 19; $c <= 33; $c++): ?>
    <Column ss:Index="<?php echo $c; ?>" ss:Width="38"/>
   <?php endfor; ?>
   <Column ss:Index="34" ss:Width="46"/>
   <?php for ($c = 35; $c <= 40; $c++): ?>
    <Column ss:Index="<?php echo $c; ?>" ss:Width="38"/>
   <?php endfor; ?>
   <Column ss:Index="41" ss:Width="46"/>
   <Column ss:Index="42" ss:Width="360"/>

   <!-- Row 1: Title -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="41" ss:StyleID="sSheet2Title"><Data ss:Type="String">STUDENT FACULTY EVALUATION RESPONSES SUMMARY</Data></Cell>
   </Row>

   <!-- Row 2: Faculty Name -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="41" ss:StyleID="sSheet2Meta"><Data ss:Type="String">Name of Faculty:   <?php echo xmlSafe($formatted_faculty_table_name); ?></Data></Cell>
   </Row>

   <!-- Row 3: School Year and Semester -->
   <Row ss:Height="18">
    <Cell ss:MergeAcross="17" ss:StyleID="sSheet2Meta"><Data ss:Type="String">School Year:   <?php echo xmlSafe($school_year_display); ?></Data></Cell>
    <Cell ss:MergeAcross="23" ss:StyleID="sSheet2Meta"><Data ss:Type="String">Semester:   <?php echo xmlSafe($semester_display); ?></Data></Cell>
   </Row>

   <!-- Row 4: Spacer -->
   <Row ss:Height="6"><Cell></Cell></Row>

   <!-- Row 5: Table Header 1 -->
   <Row ss:Height="22">
    <Cell ss:MergeDown="2" ss:StyleID="sThHead"><Data ss:Type="String">No.</Data></Cell>
    <Cell ss:MergeAcross="16" ss:StyleID="sThHead"><Data ss:Type="String">Conduct of Online Class</Data></Cell>
    <Cell ss:MergeAcross="15" ss:StyleID="sThHead"><Data ss:Type="String">Conduct of In-person Class</Data></Cell>
    <Cell ss:MergeAcross="6" ss:StyleID="sThHead"><Data ss:Type="String">Feedbacking Mechanism</Data></Cell>
    <Cell ss:MergeDown="2" ss:StyleID="sThHead"><Data ss:Type="String">Comments and Suggestions for Improvement</Data></Cell>
   </Row>

   <!-- Row 6: Table Header 2 -->
   <Row ss:Height="18">
    <Cell ss:Index="2" ss:MergeAcross="15" ss:StyleID="sThHead"><Data ss:Type="String">I T E M</Data></Cell>
    <Cell ss:MergeDown="1" ss:StyleID="sThHead"><Data ss:Type="String">AVG</Data></Cell>
    <Cell ss:MergeAcross="14" ss:StyleID="sThHead"><Data ss:Type="String">I T E M</Data></Cell>
    <Cell ss:MergeDown="1" ss:StyleID="sThHead"><Data ss:Type="String">AVG</Data></Cell>
    <Cell ss:MergeAcross="5" ss:StyleID="sThHead"><Data ss:Type="String">I T E M</Data></Cell>
    <Cell ss:MergeDown="1" ss:StyleID="sThHead"><Data ss:Type="String">AVG</Data></Cell>
   </Row>

   <!-- Row 7: Table Header 3 (Numbers) -->
   <Row ss:Height="18">
    <!-- Online 1..16 -->
    <?php for ($i = 1; $i <= 16; $i++): ?>
     <Cell <?php if ($i === 1) echo 'ss:Index="2" '; ?> ss:StyleID="sThHead"><Data ss:Type="Number"><?php echo $i; ?></Data></Cell>
    <?php endfor; ?>
    <!-- In-person 1..15 -->
    <?php for ($i = 1; $i <= 15; $i++): ?>
     <Cell <?php if ($i === 1) echo 'ss:Index="19" '; ?> ss:StyleID="sThHead"><Data ss:Type="Number"><?php echo $i; ?></Data></Cell>
    <?php endfor; ?>
    <!-- Feedbacking 1..6 -->
    <?php for ($i = 1; $i <= 6; $i++): ?>
     <Cell <?php if ($i === 1) echo 'ss:Index="35" '; ?> ss:StyleID="sThHead"><Data ss:Type="Number"><?php echo $i; ?></Data></Cell>
    <?php endfor; ?>
   </Row>

   <!-- Data Rows -->
   <?php if (!empty($rows_data)): ?>
    <?php foreach ($rows_data as $r): ?>
     <Row ss:Height="18">
      <Cell ss:StyleID="sTdNo"><Data ss:Type="Number"><?php echo $r['no']; ?></Data></Cell>
      <!-- Online -->
      <?php foreach ($r['online'] as $v): ?>
       <Cell ss:StyleID="sTdItem"><Data ss:Type="Number"><?php echo number_format($v, 2, '.', ''); ?></Data></Cell>
      <?php endforeach; ?>
      <Cell ss:StyleID="sTdAvg"><Data ss:Type="Number"><?php echo number_format($r['online_avg'], 2, '.', ''); ?></Data></Cell>
      <!-- In-person -->
      <?php foreach ($r['inperson'] as $v): ?>
       <Cell ss:StyleID="sTdItem"><Data ss:Type="Number"><?php echo number_format($v, 2, '.', ''); ?></Data></Cell>
      <?php endforeach; ?>
      <Cell ss:StyleID="sTdAvg"><Data ss:Type="Number"><?php echo number_format($r['inperson_avg'], 2, '.', ''); ?></Data></Cell>
      <!-- Feedbacking -->
      <?php foreach ($r['feedbacking'] as $v): ?>
       <Cell ss:StyleID="sTdItem"><Data ss:Type="Number"><?php echo number_format($v, 2, '.', ''); ?></Data></Cell>
      <?php endforeach; ?>
      <Cell ss:StyleID="sTdAvg"><Data ss:Type="Number"><?php echo number_format($r['feed_avg'], 2, '.', ''); ?></Data></Cell>
      <!-- Comment -->
      <Cell ss:StyleID="sTdComment"><Data ss:Type="String"><?php echo xmlSafe($r['comment']); ?></Data></Cell>
     </Row>
    <?php endforeach; ?>

    <!-- Summary Row -->
    <Row ss:Height="20">
     <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="String"></Data></Cell>
     <?php foreach ($online_means as $m): ?>
      <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($m, 2, '.', ''); ?></Data></Cell>
     <?php endforeach; ?>
     <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($online_overall_mean, 2, '.', ''); ?></Data></Cell>

     <?php foreach ($inperson_means as $m): ?>
      <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($m, 2, '.', ''); ?></Data></Cell>
     <?php endforeach; ?>
     <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($inperson_overall_mean, 2, '.', ''); ?></Data></Cell>

     <?php foreach ($feedbacking_means as $m): ?>
      <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($m, 2, '.', ''); ?></Data></Cell>
     <?php endforeach; ?>
     <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="Number"><?php echo number_format($feedbacking_overall_mean, 2, '.', ''); ?></Data></Cell>

     <Cell ss:StyleID="sTdSummaryAvg"><Data ss:Type="String">OVERALL RATING: <?php echo number_format($grand_overall_rating, 2, '.', ''); ?></Data></Cell>
    </Row>
   <?php else: ?>
    <Row ss:Height="24">
     <Cell ss:MergeAcross="41" ss:StyleID="sTdNothing"><Data ss:Type="String">No student evaluations found for this faculty member in this academic period.</Data></Cell>
    </Row>
   <?php endif; ?>

   <!-- Row Nothing Follows -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="41" ss:StyleID="sTdNothing"><Data ss:Type="String">&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;NOTHING FOLLOWS&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;</Data></Cell>
   </Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <DisplayGridlines/>
  </WorksheetOptions>
 </Worksheet>
</Workbook>
