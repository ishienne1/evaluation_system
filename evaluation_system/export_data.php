<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

$report_type = $_GET['report'] ?? '';
$active_period = $conn->query("SELECT id FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;

// --- Period Filter Logic ---
$selected_period_id = $_GET['period_id'] ?? $active_id;

$params = [$selected_period_id];
$types = 'i';

$filename = "export_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

switch ($report_type) {
    case 'dept_perf':
        $filename = "department_performance_period_{$selected_period_id}.csv";
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        fputcsv($output, ['Department', 'Average Rating']);

        $query = $conn->prepare(
            "SELECT d.dept_alias, AVG(e.rating) as avg_rating
             FROM departments d
             LEFT JOIN users u ON d.id = u.dept_id AND u.role = 'faculty'
             LEFT JOIN evaluations e ON u.id = e.faculty_id AND e.academic_period_id = ?
             GROUP BY d.id, d.dept_alias
             ORDER BY d.dept_alias ASC"
        );
        $query->bind_param($types, ...$params);
        $query->execute();
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['dept_alias'], round($row['avg_rating'] ?? 0, 2)]);
        }
        break;

    case 'school_perf':
        $filename = "school_performance_period_{$selected_period_id}.csv";
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        fputcsv($output, ['Category', 'Average Rating']);

        $query = $conn->prepare(
            "SELECT q.category, AVG(r.rating) as avg_rating
             FROM evaluation_responses r
             JOIN evaluation_questions q ON r.question_id = q.id
             JOIN evaluations e ON r.evaluation_id = e.id
             WHERE e.academic_period_id = ?
               AND q.category NOT IN ('Teaching', 'Professionalism', 'Assessment', 'Support')
               AND e.faculty_id IS NULL
             GROUP BY q.category
             ORDER BY q.category"
        );
        $query->bind_param($types, ...$params);
        $query->execute();
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['category'], round($row['avg_rating'] ?? 0, 2)]);
        }
        break;

    case 'monthly_summary':
        $filename = "monthly_summary_period_{$selected_period_id}.csv";
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        fputcsv($output, ['Month', 'Total Evaluations Generated']);

        $query = $conn->prepare(
            "SELECT 
                DATE_FORMAT(e.created_at, '%M %Y') as month_name,
                COUNT(e.id) as eval_count
             FROM evaluations e
             WHERE e.academic_period_id = ?
             GROUP BY DATE_FORMAT(e.created_at, '%Y-%m'), month_name
             ORDER BY MIN(e.created_at) ASC"
        );
        $query->bind_param($types, ...$params);
        $query->execute();
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['month_name'], $row['eval_count']]);
        }
        break;

    default:
        fputcsv($output, ['Error', 'Invalid report type specified.']);
        break;
}

fclose($output);
exit();
?>