<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

$allowed_roles = ['admin', 'system admin', 'dean', 'faculty'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php"); exit();
}

$format = $_GET['format'] ?? 'excel';
$faculty_name = trim($_GET['faculty_name'] ?? 'GANAL, MARICRIS');
$school_year = '2026-2027';
$semester = 'First';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="STUDENT_FACULTY_EVALUATION_TEMPLATE.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($out, ['STUDENT FACULTY EVALUATION RESPONSES SUMMARY']);
    fputcsv($out, ['Name of Faculty: ' . $faculty_name]);
    fputcsv($out, ['School Year: ' . $school_year, 'Semester: ' . $semester]);
    fputcsv($out, []); // blank line

    // Header Row
    $h = ['No.'];
    for ($i = 1; $i <= 16; $i++) $h[] = 'Online_' . $i;
    $h[] = 'Online_AVG';
    for ($i = 1; $i <= 15; $i++) $h[] = 'InPerson_' . $i;
    $h[] = 'InPerson_AVG';
    for ($i = 1; $i <= 6; $i++) $h[] = 'Feedback_' . $i;
    $h[] = 'Feedback_AVG';
    $h[] = 'Comments and Suggestions for Improvement';
    fputcsv($out, $h);

    // 3 Sample Rows
    $sample_comments = [
        "Keep up good communication sa mga students po ma'am",
        "She is very approachable and explains concepts clearly.",
        "Very good at explaining topics with hands-on examples."
    ];

    for ($r = 1; $r <= 3; $r++) {
        $row = [$r];
        for ($i = 0; $i < 16; $i++) $row[] = '5.00';
        $row[] = '5.00'; // avg
        for ($i = 0; $i < 15; $i++) $row[] = '5.00';
        $row[] = '5.00'; // avg
        for ($i = 0; $i < 6; $i++) $row[] = '5.00';
        $row[] = '5.00'; // avg
        $row[] = $sample_comments[$r - 1];
        fputcsv($out, $row);
    }
    fputcsv($out, ['>>>>>>>>>>>>>>NOTHING FOLLOWS<<<<<<<<<<<<<<']);
    fclose($out);
    exit();
}

// Default: Excel (.xls HTML table with XML workbook tags)
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"STUDENT_FACULTY_EVALUATION_TEMPLATE.xls\"");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]>
<xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>Template</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; }
  table { border-collapse: collapse; }
  .th-title { font-size: 11pt; font-weight: bold; text-align: left; }
  .th-meta { font-size: 9.5pt; font-weight: bold; text-align: left; }
  .grid-table th {
      border: 0.5pt solid #000000;
      background-color: #f2f2f2;
      font-weight: bold;
      font-size: 8.5pt;
      text-align: center;
      vertical-align: middle;
      padding: 3px;
  }
  .grid-table td {
      border: 0.5pt solid #000000;
      font-size: 8.5pt;
      padding: 2.5px 3px;
      vertical-align: middle;
  }
  .col-no { text-align: center; font-weight: bold; width: 35px; }
  .col-item { text-align: center; width: 38px; }
  .col-avg { text-align: center; font-weight: bold; width: 44px; background-color: #e2e8f0; }
  .col-comment { text-align: left; font-size: 8.5pt; width: 350px; white-space: normal; padding-left: 6px; }
  .row-summary td { font-weight: bold; background-color: #e5e7eb; border-top: 1.5pt solid #000; }
</style>
</head>
<body>

<table>
    <tr>
        <td colspan="42" class="th-title">STUDENT FACULTY EVALUATION RESPONSES SUMMARY</td>
    </tr>
    <tr>
        <td colspan="42" class="th-meta">Name of Faculty: &nbsp;&nbsp;<b><?php echo htmlspecialchars($faculty_name); ?></b></td>
    </tr>
    <tr>
        <td colspan="18" class="th-meta">School Year: &nbsp;&nbsp;<b><?php echo htmlspecialchars($school_year); ?></b></td>
        <td colspan="24" class="th-meta">Semester: &nbsp;&nbsp;<b><?php echo htmlspecialchars($semester); ?></b></td>
    </tr>
    <tr><td colspan="42"></td></tr>
</table>

<table class="grid-table">
    <tr>
        <th rowspan="3" class="col-no">No.</th>
        <th colspan="17">Conduct of Online Class</th>
        <th colspan="16">Conduct of In-person Class</th>
        <th colspan="7">Feedbacking Mechanism</th>
        <th rowspan="3" style="width: 350px;">Comments and Suggestions for Improvement</th>
    </tr>
    <tr>
        <th colspan="16">I T E M</th>
        <th rowspan="2" class="col-avg">AVG</th>
        <th colspan="15">I T E M</th>
        <th rowspan="2" class="col-avg">AVG</th>
        <th colspan="6">I T E M</th>
        <th rowspan="2" class="col-avg">AVG</th>
    </tr>
    <tr>
        <?php for ($i = 1; $i <= 16; $i++): ?><th class="col-item"><?php echo $i; ?></th><?php endfor; ?>
        <?php for ($i = 1; $i <= 15; $i++): ?><th class="col-item"><?php echo $i; ?></th><?php endfor; ?>
        <?php for ($i = 1; $i <= 6; $i++): ?><th class="col-item"><?php echo $i; ?></th><?php endfor; ?>
    </tr>

    <!-- Sample 5 rows -->
    <?php
    $sample_data = [
        ['no' => 1, 'score' => '5.00', 'comment' => 'Keep up good communication sa mga students po ma\'am'],
        ['no' => 2, 'score' => '4.80', 'comment' => 'Very approachable adviser and supportive in projects.'],
        ['no' => 3, 'score' => '4.50', 'comment' => 'Hands-on magturo, pinapadali ang mahirap na lessons.'],
        ['no' => 4, 'score' => '5.00', 'comment' => 'Excellent teaching methodology and clear explanations.'],
        ['no' => 5, 'score' => '4.75', 'comment' => 'Approachable and always ready to assist students during consultation hours.']
    ];
    foreach ($sample_data as $s):
    ?>
    <tr>
        <td class="col-no"><?php echo $s['no']; ?></td>
        <?php for ($i = 1; $i <= 16; $i++): ?><td class="col-item"><?php echo $s['score']; ?></td><?php endfor; ?>
        <td class="col-avg"><?php echo $s['score']; ?></td>
        <?php for ($i = 1; $i <= 15; $i++): ?><td class="col-item"><?php echo $s['score']; ?></td><?php endfor; ?>
        <td class="col-avg"><?php echo $s['score']; ?></td>
        <?php for ($i = 1; $i <= 6; $i++): ?><td class="col-item"><?php echo $s['score']; ?></td><?php endfor; ?>
        <td class="col-avg"><?php echo $s['score']; ?></td>
        <td class="col-comment"><?php echo htmlspecialchars($s['comment']); ?></td>
    </tr>
    <?php endforeach; ?>

    <!-- Summary Row -->
    <tr class="row-summary">
        <td class="col-no">AVG</td>
        <?php for ($i = 1; $i <= 16; $i++): ?><td class="col-item">4.81</td><?php endfor; ?>
        <td class="col-avg">4.81</td>
        <?php for ($i = 1; $i <= 15; $i++): ?><td class="col-item">4.81</td><?php endfor; ?>
        <td class="col-avg">4.81</td>
        <?php for ($i = 1; $i <= 6; $i++): ?><td class="col-item">4.81</td><?php endfor; ?>
        <td class="col-avg">4.81</td>
        <td class="col-comment" style="font-weight: bold;">OVERALL RATING: 4.81</td>
    </tr>

    <tr>
        <td colspan="42" style="text-align: center; font-weight: bold; letter-spacing: 2px; padding: 6px; background-color: #fafafa; border: 0.5pt solid #000;">
            &gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;&gt;NOTHING FOLLOWS&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;
        </td>
    </tr>
</table>

</body>
</html>
