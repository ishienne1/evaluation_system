<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db_connect.php';
include_once 'process_sentiment.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'dean'])) { 
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'system admin') {
        header("Location: dashboard_ict.php");
    } else {
        header("Location: index.php"); 
    }
    exit(); 
}

$role = $_SESSION['role'];
$dept_id_session = $_SESSION['dept_id'] ?? null;

// --- 1. Get & Sanitize Filter Parameters ---
$periods = $conn->query("SELECT * FROM academic_periods ORDER BY is_active DESC, school_year DESC");
$selected_period_id = (int)($_GET['period_id'] ?? ($conn->query("SELECT id FROM academic_periods WHERE is_active = 1")->fetch_assoc()['id'] ?? 0));

$selected_school = isset($_GET['school']) && in_array(strtolower($_GET['school']), ['mcnp', 'isap']) ? strtolower($_GET['school']) : '';

$selected_dept_id = null;
if ($role == 'admin' || $role == 'system admin') {
    if (!empty($_GET['dept_id'])) {
        $check_dept = (int)$_GET['dept_id'];
        if ($selected_school === 'isap') {
            $is_valid = $conn->query("SELECT id FROM departments WHERE id = $check_dept AND dept_alias IN ('CASTE','CBEM','CITE','COCJE')")->num_rows > 0;
            if ($is_valid) $selected_dept_id = $check_dept;
        } elseif ($selected_school === 'mcnp') {
            $is_valid = $conn->query("SELECT id FROM departments WHERE id = $check_dept AND dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')")->num_rows > 0;
            if ($is_valid) $selected_dept_id = $check_dept;
        } else {
            $selected_dept_id = $check_dept;
        }
    }
} else {
    // Dean is strictly locked to their department
    $selected_dept_id = (int)$dept_id_session;
    if ($selected_dept_id) {
        $d_row = $conn->query("SELECT dept_alias FROM departments WHERE id = $selected_dept_id")->fetch_assoc();
        if ($d_row) {
            $selected_school = in_array($d_row['dept_alias'], ['CASTE','CBEM','CITE','COCJE']) ? 'isap' : 'mcnp';
        }
    }
}

// Program / Course filter
$selected_course_id = !empty($_GET['course_id']) ? (int)$_GET['course_id'] : null;
if ($selected_course_id) {
    $c_check = $conn->query("SELECT c.id, c.dept_id, d.dept_alias FROM courses c JOIN departments d ON c.dept_id = d.id WHERE c.id = $selected_course_id")->fetch_assoc();
    if (!$c_check) {
        $selected_course_id = null;
    } else {
        if ($selected_dept_id && $c_check['dept_id'] != $selected_dept_id) {
            $selected_course_id = null;
        }
        if ($selected_school === 'isap' && !in_array($c_check['dept_alias'], ['CASTE','CBEM','CITE','COCJE'])) {
            $selected_course_id = null;
        }
        if ($selected_school === 'mcnp' && in_array($c_check['dept_alias'], ['CASTE','CBEM','CITE','COCJE'])) {
            $selected_course_id = null;
        }
    }
}

// Faculty filter
$selected_faculty_id = !empty($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : null;
if ($selected_faculty_id) {
    $f_check = $conn->query("SELECT u.id, u.dept_id, u.course_id, u.school, d.dept_alias FROM users u LEFT JOIN departments d ON u.dept_id = d.id WHERE u.id = $selected_faculty_id AND u.role = 'faculty'")->fetch_assoc();
    if (!$f_check) {
        $selected_faculty_id = null;
    } else {
        if ($selected_course_id && $f_check['course_id'] != $selected_course_id) {
            $selected_faculty_id = null;
        }
        if ($selected_dept_id && $f_check['dept_id'] != $selected_dept_id) {
            $selected_faculty_id = null;
        }
        if ($selected_school && $f_check['school'] !== $selected_school) {
            $selected_faculty_id = null;
        }
    }
}

// --- 2. Data Fetching ---
$faculty_data = null;
$category_scores = [];
$comments = [];
$good_comments = [];
$bad_comments = [];
$overall_avg = 0;
$max_rating = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'")->fetch_assoc()['setting_value'] ?? 5;

// Fetch Performance History across Periods for Line Graph
$period_history_labels = [];
$period_history_data = [];

$hist_where = [];
if ($selected_faculty_id) {
    $hist_where[] = "e.faculty_id = " . (int)$selected_faculty_id;
} elseif ($selected_course_id) {
    $hist_where[] = "u.course_id = " . (int)$selected_course_id;
} elseif ($selected_dept_id) {
    $hist_where[] = "u.dept_id = " . (int)$selected_dept_id;
} elseif ($selected_school === 'isap') {
    $hist_where[] = "(u.school = 'isap' OR d.dept_alias IN ('CASTE','CBEM','CITE','COCJE'))";
} elseif ($selected_school === 'mcnp') {
    $hist_where[] = "(u.school = 'mcnp' OR (d.dept_alias IS NOT NULL AND d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')))";
}

$hist_sql = "SELECT p.semester, p.school_year, AVG(e.rating) as avg_r
            FROM academic_periods p
            LEFT JOIN evaluations e ON e.academic_period_id = p.id
            LEFT JOIN users u ON e.faculty_id = u.id
            LEFT JOIN departments d ON u.dept_id = d.id";
if (!empty($hist_where)) {
    $hist_sql .= " WHERE (" . implode(' AND ', $hist_where) . ") OR e.id IS NULL";
}
$hist_sql .= " GROUP BY p.id ORDER BY p.school_year ASC, p.semester ASC";
$hist_res = $conn->query($hist_sql);
if ($hist_res) {
    while($h = $hist_res->fetch_assoc()) {
        $period_history_labels[] = $h['semester'] . ' ' . $h['school_year'];
        $period_history_data[] = round((float)($h['avg_r'] ?? 0), 2);
    }
}

if ($selected_faculty_id) {
    // Get Faculty Info with department and course info
    $fac_stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.school, u.dept_id, u.course_id,
               d.dept_alias, d.dept_name,
               c.course_alias, c.course_name
        FROM users u 
        LEFT JOIN departments d ON u.dept_id = d.id 
        LEFT JOIN courses c ON u.course_id = c.id 
        WHERE u.id = ? AND u.role = 'faculty'
    ");
    $fac_stmt->bind_param("i", $selected_faculty_id);
    $fac_stmt->execute();
    $faculty_data = $fac_stmt->get_result()->fetch_assoc();

    if ($faculty_data) {
        // Get Overall Average
        $avg_stmt = $conn->prepare("SELECT AVG(rating) as avg_r FROM evaluations WHERE faculty_id = ? AND academic_period_id = ?");
        $avg_stmt->bind_param("ii", $selected_faculty_id, $selected_period_id);
        $avg_stmt->execute();
        $overall_avg = $avg_stmt->get_result()->fetch_assoc()['avg_r'] ?? 0;

        // Get Scores by Category
        $cat_stmt = $conn->prepare("
            SELECT q.category, AVG(r.rating) as score 
            FROM evaluation_responses r 
            JOIN evaluation_questions q ON r.question_id = q.id 
            JOIN evaluations e ON r.evaluation_id = e.id 
            WHERE e.faculty_id = ? AND e.academic_period_id = ?
            GROUP BY q.category ORDER BY q.category ASC
        ");
        $cat_stmt->bind_param("ii", $selected_faculty_id, $selected_period_id);
        $cat_stmt->execute();
        $category_scores = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Get Comments & Analyze Sentiment
        $com_stmt = $conn->prepare("SELECT feedback, rating FROM evaluations WHERE faculty_id = ? AND academic_period_id = ? AND feedback IS NOT NULL AND feedback != '' ORDER BY created_at DESC");
        $com_stmt->bind_param("ii", $selected_faculty_id, $selected_period_id);
        $com_stmt->execute();
        $comments = $com_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($comments as $com) {
            $sentiment = analyzeSentiment($com['feedback'], $com['rating']);
            $com['sentiment'] = $sentiment;
            if ($sentiment === 'Positive') {
                $good_comments[] = $com;
            } else {
                $bad_comments[] = $com;
            }
        }
    }
}

// Compute Rating Interpretation Scale
if (!function_exists('getRatingInterpretation')) {
    function getRatingInterpretation($val) {
        if ($val >= 4.5) return 'Outstanding';
        if ($val >= 3.5) return 'Very Satisfactory';
        if ($val >= 2.5) return 'Satisfactory';
        if ($val >= 1.5) return 'Unsatisfactory';
        if ($val > 0) return 'Poor';
        return 'No Ratings Yet';
    }
}

if (!function_exists('getFacilityIcon')) {
    function getFacilityIcon($name) {
        $map = [
            'General School' => 'fa-school',
            'Canteen Services' => 'fa-utensils',
            'Science Lab' => 'fa-flask',
            'Computer Lab' => 'fa-laptop-code',
            'School Gym' => 'fa-dumbbell',
            'Registrar Office' => 'fa-file-invoice',
            'Finance Office' => 'fa-money-bill-wave',
            'School Library' => 'fa-book-reader',
            'Security Unit' => 'fa-shield-alt',
            'Guidance Office' => 'fa-hands-helping',
            'School Clinic' => 'fa-medkit',
            'IT & WiFi Services' => 'fa-wifi',
            'Classrooms' => 'fa-door-open',
            'Comfort Rooms' => 'fa-restroom',
            'Campus Common Areas' => 'fa-users'
        ];
        return $map[$name] ?? 'fa-building';
    }
}

// Report Type switcher: 'faculty' or 'facility'
$report_type = $_GET['report_type'] ?? ($_GET['type'] ?? 'faculty');
if (!in_array($report_type, ['faculty', 'facility']) || ($role === 'dean' && $report_type === 'facility')) {
    $report_type = 'faculty';
}

// Check total facility evaluations in selected period for badge and notifications
$facility_eval_count_res = $conn->query("SELECT COUNT(*) FROM evaluations WHERE academic_period_id = $selected_period_id AND faculty_id IS NULL");
$facility_eval_count = $facility_eval_count_res ? (int)$facility_eval_count_res->fetch_row()[0] : 0;

// Facility Evaluations Data Fetching
$selected_service = $_GET['service'] ?? 'all';
$all_services = [
    'General School', 'Canteen Services', 'Science Lab', 'Computer Lab',
    'School Gym', 'Registrar Office', 'Finance Office', 'School Library',
    'Security Unit', 'Guidance Office', 'School Clinic', 'IT & WiFi Services',
    'Classrooms', 'Comfort Rooms', 'Campus Common Areas'
];
$db_services_res = $conn->query("SELECT DISTINCT target_name FROM evaluations WHERE faculty_id IS NULL AND target_name IS NOT NULL AND target_name != ''");
if ($db_services_res) {
    while($row = $db_services_res->fetch_assoc()) {
        if (!in_array($row['target_name'], $all_services)) {
            $all_services[] = $row['target_name'];
        }
    }
}

$fac_service_cond = "";
$fac_bind_types = "i";
$fac_bind_params = [&$selected_period_id];
if ($selected_service !== 'all' && !empty($selected_service)) {
    $fac_service_cond = " AND e.target_name = ?";
    $fac_bind_types .= "s";
    $fac_bind_params[] = &$selected_service;
}

// 1. Overall Stats for Facilities
$fac_overall_sql = "
    SELECT AVG(e.rating) as grand_avg, COUNT(e.id) as total_evals, COUNT(DISTINCT e.student_id) as unique_respondents
    FROM evaluations e 
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $fac_service_cond
";
$fac_overall_stmt = $conn->prepare($fac_overall_sql);
$fac_overall_stmt->bind_param($fac_bind_types, ...$fac_bind_params);
$fac_overall_stmt->execute();
$fac_overall_stats = $fac_overall_stmt->get_result()->fetch_assoc();
$fac_grand_avg = round((float)($fac_overall_stats['grand_avg'] ?? 0), 2);
$fac_total_evals = (int)($fac_overall_stats['total_evals'] ?? 0);
$fac_unique_respondents = (int)($fac_overall_stats['unique_respondents'] ?? 0);

// 2. Evaluator role breakdown
$fac_role_sql = "
    SELECT u.role, COUNT(DISTINCT e.student_id) as cnt
    FROM evaluations e
    JOIN users u ON e.student_id = u.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $fac_service_cond
    GROUP BY u.role
";
$fac_role_stmt = $conn->prepare($fac_role_sql);
$fac_role_stmt->bind_param($fac_bind_types, ...$fac_bind_params);
$fac_role_stmt->execute();
$fac_role_res = $fac_role_stmt->get_result();
$fac_role_parts = [];
if ($fac_role_res) {
    while ($rr = $fac_role_res->fetch_assoc()) {
        $r_label = ucfirst($rr['role']);
        if ($rr['role'] === 'faculty') $r_label = 'Faculty';
        elseif ($rr['role'] === 'dean') $r_label = 'Deans';
        elseif ($rr['role'] === 'student') $r_label = 'Students';
        elseif ($rr['role'] === 'visitor') $r_label = 'Visitors';
        $fac_role_parts[] = $r_label . ': ' . $rr['cnt'];
    }
}
$fac_role_breakdown_text = !empty($fac_role_parts) ? implode(', ', $fac_role_parts) : 'None';

// 3. Services comparisons breakdown
$fac_services_comp_sql = "
    SELECT e.target_name,
        AVG(e.rating) as service_avg,
        COUNT(e.id) as eval_count,
        COUNT(DISTINCT e.student_id) as respondent_count
    FROM evaluations e
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $fac_service_cond
    GROUP BY e.target_name
    ORDER BY service_avg DESC, eval_count DESC
";
$fac_services_comp_stmt = $conn->prepare($fac_services_comp_sql);
$fac_services_comp_stmt->bind_param($fac_bind_types, ...$fac_bind_params);
$fac_services_comp_stmt->execute();
$fac_services_comparisons = $fac_services_comp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$fac_rated_services_count = count($fac_services_comparisons);

// 4. Category scores for facility responses
$fac_cat_sql = "
    SELECT q.category, AVG(r.rating) as score, COUNT(r.id) as responses
    FROM evaluation_responses r
    JOIN evaluation_questions q ON r.question_id = q.id
    JOIN evaluations e ON r.evaluation_id = e.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL $fac_service_cond
    GROUP BY q.category ORDER BY score DESC
";
$fac_cat_stmt = $conn->prepare($fac_cat_sql);
$fac_cat_stmt->bind_param($fac_bind_types, ...$fac_bind_params);
$fac_cat_stmt->execute();
$fac_category_scores = $fac_cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 5. Sentiments & Comments for facilities
$fac_comments_sql = "
    SELECT e.feedback, e.rating, e.target_name, e.created_at, u.role as respondent_role
    FROM evaluations e
    LEFT JOIN users u ON e.student_id = u.id
    WHERE e.academic_period_id = ? AND e.faculty_id IS NULL AND e.feedback IS NOT NULL AND e.feedback != '' $fac_service_cond
    ORDER BY e.created_at DESC
";
$fac_comments_stmt = $conn->prepare($fac_comments_sql);
$fac_comments_stmt->bind_param($fac_bind_types, ...$fac_bind_params);
$fac_comments_stmt->execute();
$fac_comments_res = $fac_comments_stmt->get_result();

$fac_good_comments = [];
$fac_bad_comments = [];
if ($fac_comments_res) {
    while ($row = $fac_comments_res->fetch_assoc()) {
        $sentiment = analyzeSentiment($row['feedback'], $row['rating']);
        $row['sentiment'] = $sentiment;
        if ($sentiment === 'Positive') {
            $fac_good_comments[] = $row;
        } else {
            $fac_bad_comments[] = $row;
        }
    }
}

// 6. Historical Line Graph across Periods for facilities
$fac_hist_labels = [];
$fac_hist_data = [];
$fac_hist_sql = "
    SELECT p.semester, p.school_year, AVG(e.rating) as avg_r
    FROM academic_periods p
    LEFT JOIN evaluations e ON e.academic_period_id = p.id AND e.faculty_id IS NULL " . ($selected_service !== 'all' && !empty($selected_service) ? "AND e.target_name = '" . $conn->real_escape_string($selected_service) . "'" : "") . "
    GROUP BY p.id
    ORDER BY p.school_year ASC, p.semester ASC
";
$fac_hist_res = $conn->query($fac_hist_sql);
if ($fac_hist_res) {
    while($h = $fac_hist_res->fetch_assoc()) {
        $fac_hist_labels[] = $h['semester'] . ' ' . $h['school_year'];
        $fac_hist_data[] = round((float)($h['avg_r'] ?? 0), 2);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once 'theme_style.php'; ?>
    <title>Evaluation Reports</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">
    <div class="split-container">
        <?php 
        if ($role == 'system admin') {
            $active_page = 'results'; 
            include 'ict_sidebar.php';
        } elseif ($role == 'admin') {
            $active_page = 'results'; 
            include 'admin_sidebar.php';
        } else {
            $active_page_dean = 'reports';
            include 'dean_sidebar.php';
        }
        ?>

        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                <h2 style="color:var(--text-main); margin:0;"><i class="fas fa-chart-line" style="color:var(--accent);"></i> Evaluation Reports & Analytics</h2>
            </div>

            <!-- Report Type Tabs -->
            <div class="report-tabs-nav" style="display:flex; gap:12px; margin-bottom:25px; flex-wrap:wrap;">
                <a href="view_results.php?report_type=faculty<?php echo $selected_period_id ? '&period_id='.$selected_period_id : ''; ?>" 
                   style="padding: 10px 22px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; <?php echo ($report_type === 'faculty') ? 'background: var(--accent); color: white; box-shadow: 0 4px 14px rgba(37,99,235,0.3);' : 'background: var(--card-bg); color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
                    <i class="fas fa-chalkboard-teacher"></i> Faculty Evaluations
                </a>
                <?php if ($role !== 'dean'): ?>
                <a href="view_results.php?report_type=facility<?php echo $selected_period_id ? '&period_id='.$selected_period_id : ''; ?>" 
                   style="padding: 10px 22px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; <?php echo ($report_type === 'facility') ? 'background: var(--accent); color: white; box-shadow: 0 4px 14px rgba(37,99,235,0.3);' : 'background: var(--card-bg); color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
                    <i class="fas fa-university"></i> Facilities & Campus Services
                    <?php if ($facility_eval_count > 0): ?>
                        <span style="background: <?php echo ($report_type === 'facility') ? '#ef4444' : 'var(--accent)'; ?>; color: white; border-radius: 12px; padding: 2px 8px; font-size: 11px; font-weight: 800;"><?php echo $facility_eval_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>

            <?php if ($report_type === 'facility'): ?>
            <!-- Facility Filter Section -->
            <div class="card" style="margin-bottom:25px;">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Facility & Campus Services Report</h3>
                <form id="facilityFilterForm" method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; align-items:flex-end;">
                    <input type="hidden" name="report_type" value="facility">

                    <!-- 1. Academic Period -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Academic Period</div>
                        <select name="period_id" onchange="document.getElementById('facilityFilterForm').submit()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <?php 
                            $periods->data_seek(0);
                            while($p = $periods->fetch_assoc()): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($selected_period_id == $p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['semester'] . ' ' . $p['school_year']); ?>
                                <?php echo $p['is_active'] ? ' (Active)' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- 2. Facility / Campus Service Target -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Facility / Campus Service</div>
                        <select name="service" onchange="document.getElementById('facilityFilterForm').submit()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="all" <?php echo ($selected_service === 'all') ? 'selected' : ''; ?>>-- All Campus Facilities & Services --</option>
                            <?php foreach($all_services as $srv): ?>
                            <option value="<?php echo htmlspecialchars($srv); ?>" <?php echo ($selected_service === $srv) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($srv); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($selected_service !== 'all'): ?>
                    <div style="margin-bottom: 2px;">
                        <a href="view_results.php?report_type=facility&period_id=<?php echo $selected_period_id; ?>" class="btn-action" style="background:var(--text-muted); color:white; padding:11px 16px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600;"><i class="fas fa-undo"></i> Reset Service Filter</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Facility Trend Line Graph Card -->
            <div class="card" style="margin-bottom:25px;">
                <h3 style="margin-bottom:10px;">
                    <i class="fas fa-chart-line" style="color:#4338ca;"></i> 
                    <?php 
                    if ($selected_service !== 'all') {
                        echo "Performance History — " . htmlspecialchars($selected_service);
                    } else {
                        echo "Campus Facilities Satisfaction Trends across Periods";
                    }
                    ?>
                </h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Average rating trends across all recorded academic periods.</p>
                <div style="height: 250px; width: 100%;">
                    <canvas id="facilityLineGraph"></canvas>
                </div>
            </div>

            <?php if ($fac_total_evals > 0): ?>
            <!-- Facility Report Header Card -->
            <div class="card" style="margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                            <h3 style="margin:0; font-size:1.3rem;">
                                <i class="fas <?php echo getFacilityIcon($selected_service); ?>" style="color:var(--accent); margin-right:6px;"></i>
                                <?php echo ($selected_service === 'all') ? 'All Campus Facilities & Services' : htmlspecialchars($selected_service); ?>
                            </h3>
                            <span class="category-badge" style="background:#e0e7ff; color:#3730a3; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:12px; font-size:12px;">
                                FACILITY EVALUATION REPORT
                            </span>
                        </div>
                        <p style="color:var(--text-muted); font-size:13px; margin:3px 0;">
                            <i class="fas fa-calendar-alt" style="color:var(--accent); width:18px;"></i> <strong>Academic Period:</strong> <?php $periods->data_seek(0); while($p=$periods->fetch_assoc()){ if($p['id']==$selected_period_id) echo htmlspecialchars($p['semester'].' '.$p['school_year']); } ?>
                        </p>
                        <p style="color:var(--text-muted); font-size:13px; margin:3px 0;">
                            <i class="fas fa-users" style="color:var(--accent); width:18px;"></i> <strong>Evaluator Breakdown:</strong> <?php echo htmlspecialchars($fac_role_breakdown_text); ?>
                        </p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <a href="print_services_report.php?period_id=<?php echo $selected_period_id; ?>&service=<?php echo urlencode($selected_service); ?>" target="_blank" class="btn-action" style="background:var(--accent); color:white; padding:10px 18px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:8px; font-weight:600;"><i class="fas fa-print"></i> Print Official Facility Report</a>
                    </div>
                </div>
            </div>

            <!-- 4 KPI Stat Cards -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:25px;">
                <div class="card" style="text-align:center; padding:20px;">
                    <p style="color:var(--text-muted); font-size:13px; margin-bottom:6px; font-weight:600;">Overall Computed Mean</p>
                    <h1 style="font-size:2.8rem; color:var(--accent); margin:0; line-height:1.1;"><?php echo number_format($fac_grand_avg, 2); ?></h1>
                    <div style="margin-top:8px;">
                        <span class="category-badge" style="background:<?php echo $fac_grand_avg >= 3.5 ? '#d1fae5' : ($fac_grand_avg >= 2.5 ? '#fef3c7' : '#fee2e2'); ?>; color:<?php echo $fac_grand_avg >= 3.5 ? '#065f46' : ($fac_grand_avg >= 2.5 ? '#92400e' : '#991b1b'); ?>; font-size:12px; padding:4px 12px; border-radius:20px; font-weight:700;">
                            <?php echo getRatingInterpretation($fac_grand_avg); ?>
                        </span>
                    </div>
                </div>

                <div class="card" style="text-align:center; padding:20px;">
                    <p style="color:var(--text-muted); font-size:13px; margin-bottom:6px; font-weight:600;">Total Evaluations</p>
                    <h1 style="font-size:2.8rem; color:var(--text-main); margin:0; line-height:1.1;"><?php echo number_format($fac_total_evals); ?></h1>
                    <p style="color:var(--text-muted); font-size:12px; margin-top:8px;">Submitted evaluation forms</p>
                </div>

                <div class="card" style="text-align:center; padding:20px;">
                    <p style="color:var(--text-muted); font-size:13px; margin-bottom:6px; font-weight:600;">Unique Respondents</p>
                    <h1 style="font-size:2.8rem; color:#10b981; margin:0; line-height:1.1;"><?php echo number_format($fac_unique_respondents); ?></h1>
                    <p style="color:var(--text-muted); font-size:12px; margin-top:8px;">Active users who rated</p>
                </div>

                <div class="card" style="text-align:center; padding:20px;">
                    <p style="color:var(--text-muted); font-size:13px; margin-bottom:6px; font-weight:600;">Facilities Rated</p>
                    <h1 style="font-size:2.8rem; color:#f59e0b; margin:0; line-height:1.1;"><?php echo $fac_rated_services_count; ?></h1>
                    <p style="color:var(--text-muted); font-size:12px; margin-top:8px;">Campus services with data</p>
                </div>
            </div>

            <!-- Campus Services Performance Table & Category Scores Chart -->
            <div class="grid-2" style="margin-bottom:25px;">
                <div class="card">
                    <h4 style="margin-bottom:15px; display:flex; align-items:center; justify-content:space-between;">
                        <span><i class="fas fa-list-ol" style="color:var(--accent); margin-right:6px;"></i> Campus Services Performance</span>
                        <span style="font-size:11px; font-weight:600; color:var(--text-muted);"><?php echo count($fac_services_comparisons); ?> rated</span>
                    </h4>
                    <div class="table-responsive">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border-color); text-align:left; color:var(--text-muted);">
                                    <th style="padding:8px 6px;">Service</th>
                                    <th style="padding:8px 6px; text-align:center;">Evals</th>
                                    <th style="padding:8px 6px; text-align:right;">Mean Rating</th>
                                    <th style="padding:8px 6px; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($fac_services_comparisons as $sc): 
                                    $s_avg = round((float)$sc['service_avg'], 2);
                                    $s_lbl = getRatingInterpretation($s_avg);
                                ?>
                                <tr style="border-bottom:1px solid var(--border-color);">
                                    <td style="padding:10px 6px; font-weight:600; color:var(--text-main);">
                                        <i class="fas <?php echo getFacilityIcon($sc['target_name']); ?>" style="color:var(--accent); width:18px; margin-right:4px;"></i>
                                        <a href="view_results.php?report_type=facility&period_id=<?php echo $selected_period_id; ?>&service=<?php echo urlencode($sc['target_name']); ?>" style="color:inherit; text-decoration:none;">
                                            <?php echo htmlspecialchars($sc['target_name']); ?>
                                        </a>
                                    </td>
                                    <td style="padding:10px 6px; text-align:center; color:var(--text-muted);">
                                        <?php echo $sc['eval_count']; ?>
                                    </td>
                                    <td style="padding:10px 6px; text-align:right; font-weight:700; color:#4338ca;">
                                        <?php echo number_format($s_avg, 2); ?> ★
                                    </td>
                                    <td style="padding:10px 6px; text-align:center;">
                                        <span class="category-badge" style="font-size:11px; padding:2px 8px; border-radius:10px; background:<?php echo $s_avg >= 3.5 ? '#d1fae5' : ($s_avg >= 2.5 ? '#fef3c7' : '#fee2e2'); ?>; color:<?php echo $s_avg >= 3.5 ? '#065f46' : ($s_avg >= 2.5 ? '#92400e' : '#991b1b'); ?>;">
                                            <?php echo $s_lbl; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h4 style="margin-bottom:15px;"><i class="fas fa-chart-bar" style="color:var(--accent); margin-right:6px;"></i> Scores by Criteria Category</h4>
                    <?php if (!empty($fac_category_scores)): ?>
                    <div style="height: 250px;"><canvas id="facilityCategoryChart"></canvas></div>
                    <?php else: ?>
                    <p style="text-align:center; color:var(--text-muted); padding:40px 20px;">No category criteria responses available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sentiment Distribution Chart for Facility -->
            <?php 
            $fac_total_comments = count($fac_good_comments) + count($fac_bad_comments);
            if ($fac_total_comments > 0): 
            ?>
            <div class="card" style="margin-top:20px; margin-bottom:5px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <h4 style="margin:0;"><i class="fas fa-chart-pie" style="color:#8b5cf6; margin-right:6px;"></i> Sentiment Distribution</h4>
                    <button type="button" onclick="generateFacilityAISummary()" id="facilityAiBtn" style="background:#8b5cf6; color:white; border:none; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-robot"></i> Generate AI Summary
                    </button>
                </div>
                <div style="display:grid; grid-template-columns: 180px 1fr; gap:20px; align-items:center; margin-top:15px;">
                    <div style="height: 160px;"><canvas id="facilitySentimentPieChart"></canvas></div>
                    <div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                            <div style="width:14px; height:14px; border-radius:50%; background:#10b981;"></div>
                            <span style="font-size:13px; color:var(--text-main);">Positive: <strong><?php echo count($fac_good_comments); ?></strong> (<?php echo round((count($fac_good_comments) / $fac_total_comments) * 100); ?>%)</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="width:14px; height:14px; border-radius:50%; background:#ef4444;"></div>
                            <span style="font-size:13px; color:var(--text-main);">Negative: <strong><?php echo count($fac_bad_comments); ?></strong> (<?php echo round((count($fac_bad_comments) / $fac_total_comments) * 100); ?>%)</span>
                        </div>
                    </div>
                </div>
                <!-- Facility AI Summary Container -->
                <div id="facilityAiResults" style="display:none; margin-top:15px; padding:16px; background:rgba(139,92,246,0.04); border:1px solid rgba(139,92,246,0.15); border-radius:10px;">
                    <div id="facilityAiLoading" style="display:none; text-align:center; padding:20px;"><div style="display:inline-block; width:30px; height:30px; border:3px solid var(--border-color); border-top-color:#8b5cf6; border-radius:50%; animation: spin 0.8s linear infinite;"></div></div>
                    <div id="facilityAiContent" style="display:none;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Feedback & Comments Sentiment Analysis -->
            <div class="grid-2" style="margin-top:25px;">
                <!-- Good Comments -->
                <div class="card" style="border-top: 4px solid #10b981;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0; color:#10b981;"><i class="fas fa-smile"></i> Good / Positive Comments</h3>
                        <span class="category-badge" style="background:#d1fae5; color:#065f46;"><?php echo count($fac_good_comments); ?> comments</span>
                    </div>
                    <div style="max-height: 350px; overflow-y: auto;">
                    <?php if (!empty($fac_good_comments)): ?>
                        <?php foreach($fac_good_comments as $gc): ?>
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-left:4px solid #10b981; border-radius:8px; padding:12px; margin-bottom:10px; font-size:13px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <span style="font-weight:700; color:var(--accent); font-size:11px; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fas <?php echo getFacilityIcon($gc['target_name']); ?>"></i> <?php echo htmlspecialchars($gc['target_name']); ?>
                                    <span class="category-badge" style="font-size:9.5px; padding:2px 6px; background:rgba(99,102,241,0.1); color:var(--accent);"><?php echo htmlspecialchars(ucfirst($gc['respondent_role'] ?? 'Respondent')); ?></span>
                                </span>
                                <small style="color:var(--text-muted); font-size:10px;"><?php echo date('M d, Y', strtotime($gc['created_at'])); ?></small>
                            </div>
                            <p style="margin:0; color:var(--text-main);">"<?php echo htmlspecialchars($gc['feedback']); ?>"</p>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                                <span class="category-badge" style="background:rgba(16,185,129,0.1); color:#10b981; font-size:10px; padding:2px 8px; border-radius:6px; font-weight:700;"><i class="fas fa-smile" style="margin-right:3px;"></i> Positive</span>
                                <small style="color:var(--text-muted);">Rating: <strong><?php echo number_format($gc['rating'], 1); ?> ★</strong></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center; color:var(--text-muted); padding:20px;">No positive comments logged for facilities.</p>
                    <?php endif; ?>
                    </div>
                </div>

                <!-- Bad Comments -->
                <div class="card" style="border-top: 4px solid #ef4444;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0; color:#ef4444;"><i class="fas fa-frown"></i> Bad / Needs Improvement Comments</h3>
                        <span class="category-badge" style="background:#fee2e2; color:#991b1b;"><?php echo count($fac_bad_comments); ?> comments</span>
                    </div>
                    <div style="max-height: 350px; overflow-y: auto;">
                    <?php if (!empty($fac_bad_comments)): ?>
                        <?php foreach($fac_bad_comments as $bc): ?>
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-left:4px solid #ef4444; border-radius:8px; padding:12px; margin-bottom:10px; font-size:13px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <span style="font-weight:700; color:#ef4444; font-size:11px; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fas <?php echo getFacilityIcon($bc['target_name']); ?>"></i> <?php echo htmlspecialchars($bc['target_name']); ?>
                                    <span class="category-badge" style="font-size:9.5px; padding:2px 6px; background:rgba(239,68,68,0.1); color:#ef4444;"><?php echo htmlspecialchars(ucfirst($bc['respondent_role'] ?? 'Respondent')); ?></span>
                                </span>
                                <small style="color:var(--text-muted); font-size:10px;"><?php echo date('M d, Y', strtotime($bc['created_at'])); ?></small>
                            </div>
                            <p style="margin:0; color:var(--text-main);">"<?php echo htmlspecialchars($bc['feedback']); ?>"</p>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                                <span class="category-badge" style="background:rgba(239,68,68,0.1); color:#ef4444; font-size:10px; padding:2px 8px; border-radius:6px; font-weight:700;"><i class="fas fa-frown" style="margin-right:3px;"></i> Negative</span>
                                <small style="color:var(--text-muted);">Rating: <strong><?php echo number_format($bc['rating'], 1); ?> ★</strong></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center; color:var(--text-muted); padding:20px;">No critical/needs improvement comments logged for facilities.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card" style="text-align:center; padding:60px 20px;">
                <i class="fas fa-university" style="font-size:60px; color:#cbd5e1; margin-bottom:20px;"></i>
                <h2 style="margin-bottom:10px;">No Facility Evaluations Recorded</h2>
                <p style="color:var(--text-muted); max-width:500px; margin:0 auto;">There are no evaluations submitted for campus facilities and services in this academic period. Once students, faculty, or deans rate campus facilities, the analytics, averages, and feedback will automatically appear here.</p>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Faculty Evaluation Section -->
            <?php if ($facility_eval_count > 0): ?>
            <div style="background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main);">
                    <i class="fas fa-info-circle" style="color: var(--accent); font-size: 16px;"></i>
                    <span>There are <strong><?php echo $facility_eval_count; ?> facility & campus services evaluations</strong> recorded for this period.</span>
                </div>
                <a href="view_results.php?report_type=facility&period_id=<?php echo $selected_period_id; ?>" style="background: var(--accent); color: white; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fas fa-university"></i> View Facility Report &rarr;
                </a>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="card" style="margin-bottom:25px;">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Faculty Evaluation Report</h3>
                <form id="filterForm" method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap:15px; align-items:flex-end;">
                    <input type="hidden" name="report_type" value="faculty">
                    
                    <!-- 1. Academic Period -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Academic Period</div>
                        <select name="period_id" onchange="document.getElementById('filterForm').submit()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <?php 
                            $periods->data_seek(0);
                            while($p = $periods->fetch_assoc()): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($selected_period_id == $p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['semester'] . ' ' . $p['school_year']); ?>
                                <?php echo $p['is_active'] ? ' (Active)' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- 2. School (Admin/System Admin only) -->
                    <?php if ($role == 'admin' || $role == 'system admin'): ?>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">School</div>
                        <select name="school" onchange="onSchoolChange()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Schools</option>
                            <option value="mcnp" <?php echo ($selected_school === 'mcnp') ? 'selected' : ''; ?>>MCNP</option>
                            <option value="isap" <?php echo ($selected_school === 'isap') ? 'selected' : ''; ?>>ISAP</option>
                        </select>
                    </div>

                    <!-- 3. Department -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Department</div>
                        <select name="dept_id" onchange="onDeptChange()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Departments</option>
                            <?php 
                            $dept_sql = "SELECT * FROM departments";
                            if ($selected_school === 'isap') {
                                $dept_sql .= " WHERE dept_alias IN ('CASTE','CBEM','CITE','COCJE')";
                            } elseif ($selected_school === 'mcnp') {
                                $dept_sql .= " WHERE dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')";
                            }
                            $dept_sql .= " ORDER BY dept_alias";
                            
                            $depts = $conn->query($dept_sql);
                            while($d = $depts->fetch_assoc()): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($selected_dept_id == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_alias'] . ' - ' . $d['dept_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- 4. Program / Course -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Program / Course</div>
                        <select name="course_id" onchange="onCourseChange()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Programs / Courses</option>
                            <?php
                            $course_sql = "SELECT c.id, c.course_alias, c.course_name, d.dept_alias FROM courses c JOIN departments d ON c.dept_id = d.id WHERE 1=1";
                            if ($selected_dept_id) {
                                $course_sql .= " AND c.dept_id = " . (int)$selected_dept_id;
                            } elseif ($selected_school === 'isap') {
                                $course_sql .= " AND d.dept_alias IN ('CASTE','CBEM','CITE','COCJE')";
                            } elseif ($selected_school === 'mcnp') {
                                $course_sql .= " AND d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')";
                            }
                            $course_sql .= " ORDER BY c.course_alias ASC";
                            $courses_res = $conn->query($course_sql);
                            while($c = $courses_res->fetch_assoc()): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($selected_course_id == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['course_alias'] . ' - ' . $c['course_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- 5. Faculty -->
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Faculty Member</div>
                        <select name="faculty_id" onchange="document.getElementById('filterForm').submit()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">-- Select Faculty --</option>
                            <?php
                            $fac_sql = "SELECT u.id, u.full_name, c.course_alias, d.dept_alias 
                                        FROM users u 
                                        LEFT JOIN courses c ON u.course_id = c.id
                                        LEFT JOIN departments d ON u.dept_id = d.id
                                        WHERE u.role = 'faculty' AND u.status = 'active'";
                            if ($selected_course_id) {
                                $fac_sql .= " AND u.course_id = " . (int)$selected_course_id;
                            } elseif ($selected_dept_id) {
                                $fac_sql .= " AND u.dept_id = " . (int)$selected_dept_id;
                            } elseif ($selected_school === 'isap') {
                                $fac_sql .= " AND (u.school = 'isap' OR d.dept_alias IN ('CASTE','CBEM','CITE','COCJE'))";
                            } elseif ($selected_school === 'mcnp') {
                                $fac_sql .= " AND (u.school = 'mcnp' OR (d.dept_alias IS NOT NULL AND d.dept_alias NOT IN ('CASTE','CBEM','CITE','COCJE')))";
                            }
                            if ($role == 'dean' && $dept_id_session) {
                                $fac_sql .= " AND u.dept_id = " . (int)$dept_id_session;
                            }
                            $fac_sql .= " ORDER BY u.full_name ASC";
                            $faculties = $conn->query($fac_sql);
                            if ($faculties) {
                                while($f = $faculties->fetch_assoc()): 
                                    $c_label = !empty($f['course_alias']) ? " [{$f['course_alias']}]" : (!empty($f['dept_alias']) ? " [{$f['dept_alias']}]" : "");
                                ?>
                                <option value="<?php echo $f['id']; ?>" <?php echo ($selected_faculty_id == $f['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($f['full_name'] . $c_label); ?>
                                </option>
                                <?php endwhile;
                            } ?>
                        </select>
                    </div>

                    <?php if ($selected_school || $selected_dept_id || $selected_course_id || $selected_faculty_id): ?>
                    <div style="margin-bottom: 2px;">
                        <a href="view_results.php?period_id=<?php echo $selected_period_id; ?>" class="btn-action" style="background:var(--text-muted); color:white; padding:11px 16px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600;"><i class="fas fa-undo"></i> Reset</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Performance Line Graph Card -->
            <div class="card" style="margin-bottom:25px;">
                <h3 style="margin-bottom:10px;">
                    <i class="fas fa-chart-line" style="color:#4338ca;"></i> 
                    <?php 
                    if ($selected_faculty_id && $faculty_data) {
                        echo "Performance History — " . htmlspecialchars($faculty_data['full_name']);
                    } elseif ($selected_course_id) {
                        echo "Program Performance Trends";
                    } elseif ($selected_dept_id) {
                        echo "Department Performance Trends";
                    } elseif ($selected_school) {
                        echo strtoupper($selected_school) . " Performance Trends";
                    } else {
                        echo "Historical Evaluation Trends across Periods";
                    }
                    ?>
                </h3>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Average rating trends across all recorded academic periods.</p>
                <div style="height: 250px; width: 100%;">
                    <canvas id="departmentLineGraph"></canvas>
                </div>
            </div>

            <?php if ($selected_faculty_id && $faculty_data): ?>
            <!-- Report Content -->
            <div id="report-content">
                <div class="card" style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                                <h3 style="margin:0; font-size:1.3rem;"><?php echo htmlspecialchars($faculty_data['full_name']); ?></h3>
                                <span class="category-badge" style="background:<?php echo ($faculty_data['school']=='isap')?'#fee2e2':'#dbeafe'; ?>; color:<?php echo ($faculty_data['school']=='isap')?'#991b1b':'#1e40af'; ?>; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:12px; font-size:12px;">
                                    <?php echo htmlspecialchars(strtoupper($faculty_data['school'] ?? 'N/A')); ?>
                                </span>
                            </div>
                            <p style="color:var(--text-muted); font-size:13px; margin:3px 0;">
                                <i class="fas fa-building" style="color:var(--accent); width:18px;"></i> <strong>Department:</strong> <?php echo htmlspecialchars(($faculty_data['dept_alias'] ?? 'N/A') . (!empty($faculty_data['dept_name']) ? ' — ' . $faculty_data['dept_name'] : '')); ?>
                            </p>
                            <p style="color:var(--text-muted); font-size:13px; margin:3px 0;">
                                <i class="fas fa-graduation-cap" style="color:var(--accent); width:18px;"></i> <strong>Assigned Program:</strong> <?php echo htmlspecialchars(($faculty_data['course_alias'] ?? 'General') . (!empty($faculty_data['course_name']) ? ' — ' . $faculty_data['course_name'] : '')); ?>
                            </p>
                            <p style="color:var(--text-muted); font-size:13px; margin:3px 0;">
                                <i class="fas fa-calendar-alt" style="color:var(--accent); width:18px;"></i> <strong>Period:</strong> <?php $periods->data_seek(0); while($p=$periods->fetch_assoc()){ if($p['id']==$selected_period_id) echo htmlspecialchars($p['semester'].' '.$p['school_year']); } ?>
                            </p>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <a href="print_report.php?faculty_id=<?php echo $selected_faculty_id; ?>&period_id=<?php echo $selected_period_id; ?>&school=<?php echo htmlspecialchars($faculty_data['school'] ?? 'mcnp'); ?>" target="_blank" class="btn-action" style="background:var(--accent); color:white; padding:10px 18px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:8px; font-weight:600;"><i class="fas fa-print"></i> Print Evaluation Report</a>
                            <a href="export_word.php?faculty_id=<?php echo $selected_faculty_id; ?>&period_id=<?php echo $selected_period_id; ?>&school=<?php echo htmlspecialchars($faculty_data['school'] ?? 'mcnp'); ?>" class="btn-action" style="background:#2563eb; color:white; padding:10px 18px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:8px; font-weight:600;"><i class="fas fa-file-word"></i> Download Word</a>
                            <a href="export_excel.php?faculty_id=<?php echo $selected_faculty_id; ?>&period_id=<?php echo $selected_period_id; ?>&school=<?php echo htmlspecialchars($faculty_data['school'] ?? 'mcnp'); ?>" class="btn-action" style="background:#107c41; color:white; padding:10px 18px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:8px; font-weight:600;"><i class="fas fa-file-excel"></i> Export Excel Summary</a>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="card" style="text-align:center;">
                        <p style="color:var(--text-muted); font-size:14px; margin-bottom:10px;">Overall Computed Mean</p>
                        <h1 style="font-size:3.5rem; color:#4338ca; margin:0;"><?php echo number_format($overall_avg, 2); ?></h1>
                        <div style="margin-top:10px;">
                            <span class="category-badge" style="background:#d1fae5; color:#065f46; font-size:14px; padding:6px 16px; border-radius:20px;">
                                <?php echo getRatingInterpretation($overall_avg); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card">
                        <h4 style="margin-bottom:15px;">Scores by Category</h4>
                        <?php if (!empty($category_scores)): ?>
                        <div style="height: 200px;"><canvas id="categoryChart"></canvas></div>
                        <?php else: ?>
                        <p style="text-align:center; color:var(--text-muted); padding:20px;">No category data available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sentiment Distribution Chart -->
                <?php if (!empty($comments)): ?>
                <div class="card" style="margin-top:20px; margin-bottom:5px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <h4 style="margin:0;"><i class="fas fa-chart-pie" style="color:#8b5cf6; margin-right:6px;"></i> Sentiment Distribution</h4>
                        <button type="button" onclick="generateFacultyAISummary()" id="facultyAiBtn" style="background:#8b5cf6; color:white; border:none; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                            <i class="fas fa-robot"></i> Generate AI Summary
                        </button>
                    </div>
                    <div style="display:grid; grid-template-columns: 180px 1fr; gap:20px; align-items:center; margin-top:15px;">
                        <div style="height: 160px;"><canvas id="sentimentPieChart"></canvas></div>
                        <div>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                                <div style="width:14px; height:14px; border-radius:50%; background:#10b981;"></div>
                                <span style="font-size:13px; color:var(--text-main);">Positive: <strong><?php echo count($good_comments); ?></strong> (<?php echo $total_c = count($comments); echo $total_c > 0 ? round((count($good_comments) / $total_c) * 100) : 0; ?>%)</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="width:14px; height:14px; border-radius:50%; background:#ef4444;"></div>
                                <span style="font-size:13px; color:var(--text-main);">Negative: <strong><?php echo count($bad_comments); ?></strong> (<?php echo $total_c > 0 ? round((count($bad_comments) / $total_c) * 100) : 0; ?>%)</span>
                            </div>
                        </div>
                    </div>
                    <!-- Faculty AI Summary Container -->
                    <div id="facultyAiResults" style="display:none; margin-top:15px; padding:16px; background:rgba(139,92,246,0.04); border:1px solid rgba(139,92,246,0.15); border-radius:10px;">
                        <div id="facultyAiLoading" style="display:none; text-align:center; padding:20px;"><div style="display:inline-block; width:30px; height:30px; border:3px solid var(--border-color); border-top-color:#8b5cf6; border-radius:50%; animation: spin 0.8s linear infinite;"></div></div>
                        <div id="facultyAiContent" style="display:none;"></div>
                    </div>
                </div>
                <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
                <?php endif; ?>

                <!-- Comment Analysis (Good & Bad Comments) -->
                <div class="grid-2" style="margin-top:25px;">
                    <!-- Good Comments -->
                    <div class="card" style="border-top: 4px solid #10b981;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h3 style="margin:0; color:#10b981;"><i class="fas fa-smile"></i> Good / Positive Comments</h3>
                            <span class="category-badge" style="background:#d1fae5; color:#065f46;"><?php echo count($good_comments); ?> comments</span>
                        </div>
                        <div style="max-height: 350px; overflow-y: auto;">
                        <?php if (!empty($good_comments)): ?>
                            <?php foreach($good_comments as $gc): ?>
                            <div style="background:var(--bg-color); border:1px solid var(--border-color); border-left:4px solid #10b981; border-radius:8px; padding:12px; margin-bottom:10px; font-size:13px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <span class="category-badge" style="background:rgba(16,185,129,0.1); color:#10b981; font-size:10px; padding:2px 8px; border-radius:6px; font-weight:700;"><i class="fas fa-smile" style="margin-right:3px;"></i> Positive</span>
                                    <small style="color:var(--text-muted);">Rating: <strong><?php echo number_format($gc['rating'], 1); ?> ★</strong></small>
                                </div>
                                <p style="margin:0; color:var(--text-main);">"<?php echo htmlspecialchars($gc['feedback']); ?>"</p>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align:center; color:var(--text-muted); padding:20px;">No positive comments logged.</p>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bad Comments -->
                    <div class="card" style="border-top: 4px solid #ef4444;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h3 style="margin:0; color:#ef4444;"><i class="fas fa-frown"></i> Bad / Needs Improvement Comments</h3>
                            <span class="category-badge" style="background:#fee2e2; color:#991b1b;"><?php echo count($bad_comments); ?> comments</span>
                        </div>
                        <div style="max-height: 350px; overflow-y: auto;">
                        <?php if (!empty($bad_comments)): ?>
                            <?php foreach($bad_comments as $bc): ?>
                            <div style="background:var(--bg-color); border:1px solid var(--border-color); border-left:4px solid #ef4444; border-radius:8px; padding:12px; margin-bottom:10px; font-size:13px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <span class="category-badge" style="background:rgba(239,68,68,0.1); color:#ef4444; font-size:10px; padding:2px 8px; border-radius:6px; font-weight:700;"><i class="fas fa-frown" style="margin-right:3px;"></i> Negative</span>
                                    <small style="color:var(--text-muted);">Rating: <strong><?php echo number_format($bc['rating'], 1); ?> ★</strong></small>
                                </div>
                                <p style="margin:0; color:var(--text-main);">"<?php echo htmlspecialchars($bc['feedback']); ?>"</p>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align:center; color:var(--text-muted); padding:20px;">No critical/bad comments logged.</p>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif (isset($_GET['faculty_id'])): ?>
            <div class="card" style="text-align:center; padding:40px;">
                <i class="fas fa-inbox" style="font-size:40px; color:#cbd5e1; margin-bottom:15px;"></i>
                <h3 style="color:var(--text-main);">No Data Found</h3>
                <p style="color:var(--text-muted);">There is no evaluation data for the selected faculty in this academic period.</p>
            </div>
            <?php else: ?>
            <div class="card" style="text-align:center; padding:60px 20px;">
                <i class="fas fa-search" style="font-size:60px; color:#cbd5e1; margin-bottom:20px;"></i>
                <h2 style="margin-bottom:10px;">Select a Faculty to View Report</h2>
                <p style="color:var(--text-muted); max-width:500px; margin:0 auto;">Use the filters above to select an academic period, school, department, and assigned program to evaluate faculty members.</p>
            </div>
            <?php endif; ?>
            <?php endif; // End report_type switch ?>

            <footer>
                <?php if ($role == 'admin' || $role == 'system admin'): ?>
                    <i class="fas fa-shield-alt"></i> Admin Panel &middot; <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?>
                <?php else: ?>
                    <i class="fas fa-university"></i> Dean Portal &middot; <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Dean'); ?>
                <?php endif; ?>
            </footer>
        </main>
    </div>
    <script>
        function onSchoolChange() {
            const form = document.getElementById('filterForm');
            const dept = form.querySelector('[name="dept_id"]');
            const course = form.querySelector('[name="course_id"]');
            const fac = form.querySelector('[name="faculty_id"]');
            if (dept) dept.value = '';
            if (course) course.value = '';
            if (fac) fac.value = '';
            form.submit();
        }

        function onDeptChange() {
            const form = document.getElementById('filterForm');
            const course = form.querySelector('[name="course_id"]');
            const fac = form.querySelector('[name="faculty_id"]');
            if (course) course.value = '';
            if (fac) fac.value = '';
            form.submit();
        }

        function onCourseChange() {
            const form = document.getElementById('filterForm');
            const fac = form.querySelector('[name="faculty_id"]');
            if (fac) fac.value = '';
            form.submit();
        }

        // Performance Line Graph for Faculty (Line Chart)
        const lineCtx = document.getElementById('departmentLineGraph');
        if (lineCtx) {
            new Chart(lineCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($period_history_labels); ?>,
                    datasets: [{
                        label: 'Average Rating',
                        data: <?php echo json_encode($period_history_data); ?>,
                        borderColor: '#4338ca',
                        backgroundColor: 'rgba(67, 56, 202, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 5 }
                    }
                }
            });
        }

        <?php if ($selected_faculty_id && !empty($category_scores)): ?>
        const catCtx = document.getElementById('categoryChart');
        if (catCtx) {
            new Chart(catCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($category_scores, 'category')); ?>,
                    datasets: [{
                        label: 'Average Score',
                        data: <?php echo json_encode(array_column($category_scores, 'score')); ?>,
                        backgroundColor: 'rgba(13, 33, 88, 0.8)',
                        borderColor: 'rgba(13, 33, 88, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    scales: { x: { beginAtZero: true, max: <?php echo $max_rating; ?> } },
                    plugins: { legend: { display: false } }
                }
            });
        }
        <?php endif; ?>

        // Facility Performance Line Graph
        const facLineCtx = document.getElementById('facilityLineGraph');
        if (facLineCtx) {
            new Chart(facLineCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($fac_hist_labels ?? []); ?>,
                    datasets: [{
                        label: 'Average Facility Rating',
                        data: <?php echo json_encode($fac_hist_data ?? []); ?>,
                        borderColor: '#4338ca',
                        backgroundColor: 'rgba(67, 56, 202, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 5 }
                    }
                }
            });
        }

        // Facility Category Bar Chart
        const facCatCtx = document.getElementById('facilityCategoryChart');
        if (facCatCtx) {
            new Chart(facCatCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(!empty($fac_category_scores) ? array_column($fac_category_scores, 'category') : []); ?>,
                    datasets: [{
                        label: 'Average Score',
                        data: <?php echo json_encode(!empty($fac_category_scores) ? array_map(function($x){ return round((float)$x['score'], 2); }, $fac_category_scores) : []); ?>,
                        backgroundColor: 'rgba(13, 33, 88, 0.8)',
                        borderColor: 'rgba(13, 33, 88, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    scales: { x: { beginAtZero: true, max: <?php echo $max_rating; ?> } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // Sentiment Distribution Pie Chart for Faculty
        <?php if ($selected_faculty_id && !empty($comments)): ?>
        const sentPieCtx = document.getElementById('sentimentPieChart');
        if (sentPieCtx) {
            new Chart(sentPieCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Positive', 'Negative'],
                    datasets: [{
                        data: [<?php echo count($good_comments); ?>, <?php echo count($bad_comments); ?>],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
        <?php endif; ?>

        // Faculty AI Summary Generator
        function generateFacultyAISummary() {
            const btn = document.getElementById('facultyAiBtn');
            const container = document.getElementById('facultyAiResults');
            const loading = document.getElementById('facultyAiLoading');
            const content = document.getElementById('facultyAiContent');

            container.style.display = 'block';
            loading.style.display = 'block';
            content.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';

            const formData = new FormData();
            formData.append('period_id', <?php echo (int)$selected_period_id; ?>);
            <?php if ($selected_faculty_id): ?>
            formData.append('faculty_id', <?php echo (int)$selected_faculty_id; ?>);
            <?php endif; ?>
            formData.append('target_evaluation', 'Faculty');

            fetch('ai_generate_summary.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Generate AI Summary';

                if (data.error) {
                    content.innerHTML = '<p style="color:#ef4444; text-align:center;"><i class="fas fa-exclamation-triangle"></i> ' + data.error + '</p>';
                    content.style.display = 'block';
                    return;
                }

                let html = '<div style="margin-bottom:12px;"><strong style="color:#8b5cf6; font-size:12px;"><i class="fas fa-robot"></i> AI-GENERATED SUMMARY</strong> <span style="color:var(--text-muted); font-size:11px;">(' + data.total_comments + ' comments analyzed)</span></div>';
                html += '<div style="padding:12px; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.15); border-radius:8px; margin-bottom:8px;"><strong style="color:#10b981; font-size:12px;"><i class="fas fa-plus-circle"></i> Positive</strong><div style="font-size:13px; color:var(--text-main); margin-top:4px; line-height:1.6;">' + data.positive_summary + '</div></div>';
                html += '<div style="padding:12px; background:rgba(239,68,68,0.05); border:1px solid rgba(239,68,68,0.15); border-radius:8px;"><strong style="color:#ef4444; font-size:12px;"><i class="fas fa-minus-circle"></i> Needs Improvement</strong><div style="font-size:13px; color:var(--text-main); margin-top:4px; line-height:1.6;">' + data.negative_summary + '</div></div>';

                if (data.top_positive_themes && data.top_positive_themes.length > 0) {
                    html += '<div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:4px;">';
                    data.top_positive_themes.forEach(t => {
                        html += '<span style="background:rgba(16,185,129,0.1); color:#059669; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:700;">' + t.theme + ' ×' + t.count + '</span>';
                    });
                    html += '</div>';
                }

                content.innerHTML = html;
                content.style.display = 'block';
            })
            .catch(err => {
                loading.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Generate AI Summary';
                content.innerHTML = '<p style="color:#ef4444; text-align:center;">Failed to generate summary.</p>';
                content.style.display = 'block';
            });
        }

        // Facility Sentiment Distribution Pie Chart
        <?php if (!empty($fac_good_comments) || !empty($fac_bad_comments)): ?>
        const facSentPieCtx = document.getElementById('facilitySentimentPieChart');
        if (facSentPieCtx) {
            new Chart(facSentPieCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Positive', 'Negative'],
                    datasets: [{
                        data: [<?php echo count($fac_good_comments); ?>, <?php echo count($fac_bad_comments); ?>],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
        <?php endif; ?>

        // Facility AI Summary Generator
        function generateFacilityAISummary() {
            const btn = document.getElementById('facilityAiBtn');
            const container = document.getElementById('facilityAiResults');
            const loading = document.getElementById('facilityAiLoading');
            const content = document.getElementById('facilityAiContent');

            container.style.display = 'block';
            loading.style.display = 'block';
            content.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';

            const formData = new FormData();
            formData.append('period_id', <?php echo (int)$selected_period_id; ?>);
            formData.append('target_evaluation', 'Facilities & Campus Services');
            <?php if (!empty($selected_service) && $selected_service !== 'all'): ?>
            formData.append('service', '<?php echo htmlspecialchars($selected_service); ?>');
            <?php endif; ?>

            fetch('ai_generate_summary.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Generate AI Summary';

                if (data.error) {
                    content.innerHTML = '<p style="color:#ef4444; text-align:center;"><i class="fas fa-exclamation-triangle"></i> ' + data.error + '</p>';
                    content.style.display = 'block';
                    return;
                }

                let html = '<div style="margin-bottom:12px;"><strong style="color:#8b5cf6; font-size:12px;"><i class="fas fa-robot"></i> AI-GENERATED FACILITY SUMMARY</strong> <span style="color:var(--text-muted); font-size:11px;">(' + data.total_comments + ' comments analyzed)</span></div>';
                html += '<div style="padding:12px; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.15); border-radius:8px; margin-bottom:8px;"><strong style="color:#10b981; font-size:12px;"><i class="fas fa-plus-circle"></i> Positive</strong><div style="font-size:13px; color:var(--text-main); margin-top:4px; line-height:1.6;">' + data.positive_summary + '</div></div>';
                html += '<div style="padding:12px; background:rgba(239,68,68,0.05); border:1px solid rgba(239,68,68,0.15); border-radius:8px;"><strong style="color:#ef4444; font-size:12px;"><i class="fas fa-minus-circle"></i> Needs Improvement</strong><div style="font-size:13px; color:var(--text-main); margin-top:4px; line-height:1.6;">' + data.negative_summary + '</div></div>';

                if (data.top_positive_themes && data.top_positive_themes.length > 0) {
                    html += '<div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:4px;">';
                    data.top_positive_themes.forEach(t => {
                        html += '<span style="background:rgba(16,185,129,0.1); color:#059669; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:700;">' + t.theme + ' ×' + t.count + '</span>';
                    });
                    html += '</div>';
                }

                content.innerHTML = html;
                content.style.display = 'block';
            })
            .catch(err => {
                loading.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Generate AI Summary';
                content.innerHTML = '<p style="color:#ef4444; text-align:center;">Failed to generate facility summary.</p>';
                content.style.display = 'block';
            });
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
            const mIcon = document.getElementById('mobileThemeIcon');
            if (mIcon) mIcon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const saved = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', saved);
        updateThemeIcon(saved);
    </script>
</body>
</html>