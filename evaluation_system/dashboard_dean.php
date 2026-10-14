<?php
session_start();
include 'db_connect.php';
include 'process_sentiment.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'dean') { header("Location: index.php"); exit(); }

$dept_id = $_SESSION['dept_id'];
$dept_stmt = $conn->prepare("SELECT dept_name FROM departments WHERE id = ?");
$dept_stmt->bind_param("i", $dept_id);
$dept_stmt->execute();
$dept_name = $dept_stmt->get_result()->fetch_row()[0] ?? "Department";

$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;
$period_label = isset($active_period['semester']) ? $active_period['semester'] . " " . $active_period['school_year'] : "No Active Period";

$dept_students_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND dept_id=?");
$dept_students_stmt->bind_param("i", $dept_id);
$dept_students_stmt->execute();
$dept_students = $dept_students_stmt->get_result()->fetch_row()[0];

$dept_eval_stmt = $conn->prepare("SELECT COUNT(DISTINCT e.student_id) FROM evaluations e JOIN users u ON e.student_id = u.id WHERE u.dept_id=? AND u.role='student' AND e.faculty_id IS NOT NULL AND e.academic_period_id=?");
$dept_eval_stmt->bind_param("ii", $dept_id, $active_id);
$dept_eval_stmt->execute();
$dept_eval_students = $dept_eval_stmt->get_result()->fetch_row()[0];
$dept_participation = $dept_students > 0 ? round(($dept_eval_students / $dept_students) * 100, 1) : 0;

// Pre-load course participation data for line chart
$course_stats_stmt = $conn->prepare("
    SELECT 
        c.course_alias, 
        COUNT(DISTINCT u.id) as total_students,
        COUNT(DISTINCT e.student_id) as evaluated_count
    FROM courses c
    LEFT JOIN users u ON c.id = u.course_id AND u.role = 'student'
    LEFT JOIN evaluations e ON u.id = e.student_id AND e.faculty_id IS NOT NULL AND e.academic_period_id = ?
    WHERE c.dept_id = ? 
    GROUP BY c.id, c.course_alias
    ORDER BY c.course_alias ASC
");
$course_stats_stmt->bind_param("ii", $active_id, $dept_id);

// Dean Evaluations Received from Department Faculty
$dean_id = (int)$_SESSION['id'];
$dean_fac_evals = [];
$dean_eval_count = 0;
$dean_leadership_avg = 0;
if ($active_id && $dean_id) {
    $dean_fac_evals_stmt = $conn->prepare("
        SELECT e.id, e.rating, e.feedback, e.created_at, u.full_name as faculty_name, u.username as faculty_emp_id
        FROM evaluations e
        JOIN users u ON e.student_id = u.id
        WHERE e.faculty_id = ? AND e.target_name = 'Dean' AND e.academic_period_id = ?
        ORDER BY e.created_at DESC
    ");
    $dean_fac_evals_stmt->bind_param("ii", $dean_id, $active_id);
    $dean_fac_evals_stmt->execute();
    $dean_fac_evals = $dean_fac_evals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $dean_eval_count = count($dean_fac_evals);
    $dean_total_rating = 0;
    foreach ($dean_fac_evals as $dfe) {
        $dean_total_rating += (float)$dfe['rating'];
    }
    $dean_leadership_avg = $dean_eval_count > 0 ? round($dean_total_rating / $dean_eval_count, 2) : 0;
}

// Dean Campus Facilities & Services Configuration
$facility_services = [
    ['name' => 'General School', 'cat' => 'School', 'icon' => 'fa-school', 'desc' => 'Overall institutional standards & administration'],
    ['name' => 'Canteen Services', 'cat' => 'Canteen', 'icon' => 'fa-utensils', 'desc' => 'Food quality, hygiene, and dining staff'],
    ['name' => 'Science Lab', 'cat' => 'Laboratory', 'icon' => 'fa-flask', 'desc' => 'Scientific equipment, reagents, and lab safety'],
    ['name' => 'Computer Lab', 'cat' => 'Laboratory', 'icon' => 'fa-laptop-code', 'desc' => 'Workstations, software, and connectivity'],
    ['name' => 'School Gym', 'cat' => 'Gym', 'icon' => 'fa-dumbbell', 'desc' => 'Sports facilities, equipment, and courts'],
    ['name' => 'Registrar Office', 'cat' => 'Registrar', 'icon' => 'fa-file-invoice', 'desc' => 'Academic records, credentials, and assistance'],
    ['name' => 'Finance Office', 'cat' => 'Finance', 'icon' => 'fa-money-bill-wave', 'desc' => 'Billing, cashiers, and accounting services'],
    ['name' => 'School Library', 'cat' => 'Library', 'icon' => 'fa-book-reader', 'desc' => 'Books, quiet study spaces, and research databases'],
    ['name' => 'Security Unit', 'cat' => 'Security', 'icon' => 'fa-shield-alt', 'desc' => 'Campus safety, entry guards, and protocols'],
    ['name' => 'Guidance Office', 'cat' => 'Guidance', 'icon' => 'fa-hands-helping', 'desc' => 'Counseling, student welfare, and support'],
    ['name' => 'School Clinic', 'cat' => 'Clinic', 'icon' => 'fa-medkit', 'desc' => 'First aid, medical personnel, and health care'],
    ['name' => 'IT & WiFi Services', 'cat' => 'IT-Services', 'icon' => 'fa-wifi', 'desc' => 'Campus WiFi, technical support, and systems'],
    ['name' => 'Classrooms', 'cat' => 'Classrooms', 'icon' => 'fa-door-open', 'desc' => 'Lighting, ventilation, acoustics, and smart TVs'],
    ['name' => 'Comfort Rooms', 'cat' => 'Facilities', 'icon' => 'fa-restroom', 'desc' => 'Restroom hygiene, sanitation, and water supply'],
    ['name' => 'Campus Common Areas', 'cat' => 'Facilities', 'icon' => 'fa-users', 'desc' => 'Corridors, hallways, grounds, and benches']
];

$evaluated_targets = [];
if ($active_id) {
    $dean_eval_chk = $conn->prepare("SELECT target_name FROM evaluations WHERE student_id = ? AND academic_period_id = ? AND faculty_id IS NULL");
    $dean_eval_chk->bind_param("ii", $dean_id, $active_id);
    $dean_eval_chk->execute();
    $dean_eval_res = $dean_eval_chk->get_result();
    while ($r = $dean_eval_res->fetch_assoc()) {
        $evaluated_targets[$r['target_name']] = true;
    }
}
$completed_facilities_count = count($evaluated_targets);
$total_facilities_count = count($facility_services);
$facilities_pct = $total_facilities_count > 0 ? round(($completed_facilities_count / $total_facilities_count) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard — MCNP-ISAP</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">

    <div style="display: flex; min-height: 100vh; position: relative;">
        <!-- Sidebar -->
        <?php $active_page_dean = 'dashboard'; $mobile_page_title = 'Dean Dashboard'; include 'dean_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            
            <!-- Header Bar -->
            <div class="header-bar-modern">
                <div style="display: flex; align-items: center; gap: 12px;">

                    <div>
                        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 2px;">Dean Dashboard</h1>
                        <p style="font-size: 13px; color: var(--text-muted); font-weight: 500; margin: 0;"><?php echo date('l, F j, Y'); ?></p>
                    </div>
                    <!-- Static Period Badge -->
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: rgba(132, 90, 223, 0.08); border: 1px solid rgba(132, 90, 223, 0.2); border-radius: 10px; padding: 5px 12px; font-size: 12px; font-weight: 700; color: #845adf;">
                        <i class="fas fa-clipboard-list"></i>
                        <?php echo $period_label; ?>
                    </span>
                </div>
                
                <div class="search-box-modern" style="position: relative;">
                    <i class="fas fa-search" style="color:var(--text-muted);"></i>
                    <input type="text" id="dashboardSearch" placeholder="Search dashboard, reports..." onkeyup="filterDashboard()">
                    <!-- Search Results Dropdown -->
                    <div id="searchResults" style="display:none; position:absolute; top:calc(100% + 10px); left:0; width: 100%; background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; box-shadow:0 15px 30px rgba(0,0,0,0.15); max-height:300px; overflow-y:auto; z-index:9999; backdrop-filter:blur(10px);">
                    </div>
                </div>
                
                <div style="position: relative; display: inline-block;">
                    <div class="profile-widget-modern" id="profileWidget" style="cursor: pointer; user-select: none;">
                        <?php
                        $avatar_display = $_SESSION['avatar'] ?? 'uploads/avatars/default.png';
                        if (!file_exists($avatar_display)) $avatar_display = 'uploads/avatars/default.png';
                        ?>
                        <img src="<?php echo htmlspecialchars($avatar_display); ?>" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color);">
                        <div style="text-align: left; line-height: 1.15;">
                            <div style="font-size: 12.5px; font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Dean / Dept Head</div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); margin-left: 2px;"></i>
                    </div>

                    <!-- Dean Profile Dropdown Menu -->
                    <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                        <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 3px;">Dean / Department Head</div>
                        </div>
                        <div style="padding: 6px 0;">
                            <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                            </a>
                            <a href="dean_manage_accounts.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-tie" style="color:#6366f1; width:16px;"></i> Manage Faculty
                            </a>
                            <a href="dean_manage_students.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-users" style="color:#10b981; width:16px;"></i> Manage Students
                            </a>
                            <a href="manage_subjects.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-book-open" style="color:#f59e0b; width:16px;"></i> Manage Subjects
                            </a>
                        </div>
                        <div style="border-top: 1px solid var(--border-color); padding: 6px 0 2px;">
                            <?php if (!empty($_SESSION['original_admin_id'])): ?>
                                <a href="logout.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 700; color: #8b5cf6; text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='rgba(139,92,246,0.08)'" onmouseout="this.style.background='transparent'">
                                    <i class="fas fa-user-secret" style="width:16px;"></i> Return to Admin
                                </a>
                            <?php endif; ?>
                            <a href="logout.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: #ef4444; text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.08)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-sign-out-alt" style="width:16px;"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>



            <!-- Stat Cards Grid -->
            <div class="modern-stat-grid">
                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Faculty Members</p>
                        <h3><?php 
                            $fac_count_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role='faculty' AND dept_id=?");
                            $fac_count_stmt->bind_param("i", $dept_id);
                            $fac_count_stmt->execute();
                            echo $fac_count_stmt->get_result()->fetch_row()[0]; 
                        ?></h3>
                    </div>
                    <div class="stat-icon blue" style="background: var(--purple-glow); color: var(--accent);"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                
                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Dept. Avg Rating</p>
                        <h3 style="color: #f59e0b;"><?php 
                            $avg_rating_stmt = $conn->prepare("SELECT AVG(rating) FROM evaluations e JOIN users u ON e.faculty_id = u.id WHERE u.dept_id = ? AND e.academic_period_id = ?");
                            $avg_rating_stmt->bind_param("ii", $dept_id, $active_id);
                            $avg_rating_stmt->execute();
                            $res = $avg_rating_stmt->get_result()->fetch_row()[0];
                            echo $res ? number_format($res, 2) . " ★" : '0.00 ★';
                        ?></h3>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-star"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Critically Low Ratings</p>
                        <h3 style="color: #ef4444;"><?php 
                            $low_rating_stmt = $conn->prepare("SELECT COUNT(*) FROM evaluations e JOIN users u ON e.faculty_id = u.id WHERE u.dept_id = ? AND e.rating < 3.0 AND e.academic_period_id = ?");
                            $low_rating_stmt->bind_param("ii", $dept_id, $active_id);
                            $low_rating_stmt->execute();
                            echo $low_rating_stmt->get_result()->fetch_row()[0]; 
                        ?></h3>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Dept. Participation Rate</p>
                        <h3 style="color: #10b981;"><?php echo $dept_participation; ?>%</h3>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-users"></i></div>
                </div>

                <?php
                // Count faculty below 3.0 avg threshold
                $below_threshold_stmt = $conn->prepare("
                    SELECT COUNT(DISTINCT u.id)
                    FROM users u
                    JOIN evaluations e ON u.id = e.faculty_id
                    WHERE u.dept_id = ? AND e.academic_period_id = ?
                    GROUP BY u.id
                    HAVING AVG(e.rating) < 3.0
                ");
                $below_threshold_count = 0;
                if ($below_threshold_stmt) {
                    $below_threshold_stmt->bind_param("ii", $dept_id, $active_id);
                    $below_threshold_stmt->execute();
                    $below_threshold_count = $below_threshold_stmt->get_result()->num_rows;
                }
                ?>
                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Faculty Below 3.0 Avg</p>
                        <h3 style="color: <?php echo $below_threshold_count > 0 ? '#ef4444' : '#10b981'; ?>">
                            <?php echo $below_threshold_count; ?>
                        </h3>
                    </div>
                    <div class="stat-icon <?php echo $below_threshold_count > 0 ? 'red' : 'green'; ?>"><i class="fas fa-user-times"></i></div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-info">
                        <p>Dean Leadership Rating</p>
                        <h3 style="color: #845adf;">
                            <?php echo $dean_eval_count > 0 ? number_format($dean_leadership_avg, 2) . " ★" : 'Pending'; ?>
                        </h3>
                        <small style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo $dean_eval_count; ?> Faculty Review<?php echo $dean_eval_count == 1 ? '' : 's'; ?></small>
                    </div>
                    <div class="stat-icon purple" style="background: rgba(132, 90, 223, 0.12); color: var(--accent);"><i class="fas fa-user-tie"></i></div>
                </div>
            </div>

            <!-- ROW 1: Analytics & Department Charts -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 24px; margin-bottom: 24px;">
                
                <!-- Course Participation Rate Card — Line Graph -->
                <div class="card-modern" style="margin-bottom: 0;">
                    <h3 style="margin-bottom: 8px; font-size: 16px; font-weight: 800;"><i class="fas fa-chart-line" style="color: var(--accent); margin-right: 8px;"></i> Course Participation Rate</h3>
                    <p style="color:var(--text-muted); font-size:12px; margin-bottom:20px;">Students enrolled vs. students who completed evaluation (current period)</p>
                    
                    <?php
                    // Rebuild for chart data
                    $course_stats_stmt->execute();
                    $course_chart = $course_stats_stmt->get_result();
                    $chart_course_labels = [];
                    $chart_total_students = [];
                    $chart_eval_students = [];
                    if ($course_chart && $course_chart->num_rows > 0) {
                        while($cc = $course_chart->fetch_assoc()) {
                            $chart_course_labels[] = $cc['course_alias'];
                            $chart_total_students[] = (int)$cc['total_students'];
                            $chart_eval_students[] = (int)$cc['evaluated_count'];
                        }
                    }
                    ?>
                    <?php if (!empty($chart_course_labels)): ?>
                    <div style="height: 280px; width: 100%; position:relative;">
                        <canvas id="coursePartChart"></canvas>
                    </div>
                    <?php else: ?>
                    <p style='color:var(--text-muted); font-size: 14px; text-align:center; padding: 40px;'>No course participation data available yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Departmental Services Chart Card -->
                <?php
                    // Data for Departmental Service Ratings Chart
                    $dept_services_stmt = $conn->prepare("SELECT q.category, AVG(r.rating) as avg_rating
                         FROM evaluation_responses r
                         JOIN evaluation_questions q ON r.question_id = q.id
                         JOIN evaluations e ON r.evaluation_id = e.id
                         WHERE e.academic_period_id = ?
                           AND q.category NOT IN ('Teaching', 'Professionalism', 'Assessment', 'Support')
                           AND e.faculty_id IS NULL
                         GROUP BY q.category
                         ORDER BY avg_rating DESC");
                    $dept_services_stmt->bind_param("i", $active_id);
                    $dept_services_stmt->execute();
                    $dept_services_query = $dept_services_stmt->get_result();
                    $dept_service_labels = [];
                    $dept_service_data = [];
                    if ($dept_services_query) {
                        while($row = $dept_services_query->fetch_assoc()) {
                            $dept_service_labels[] = $row['category'];
                            $dept_service_data[] = round($row['avg_rating'], 2);
                        }
                    }
                ?>
                <div class="card-modern" style="margin-bottom: 0;">
                     <h3 style="margin-bottom:8px; font-size: 16px; font-weight: 800;"><i class="fas fa-building" style="color: var(--accent); margin-right: 8px;"></i> Departmental Service Ratings</h3>
                     <p style="color:var(--text-muted); font-size:12px; margin-bottom:20px;">
                        Average feedback ratings for school services from students in your department.
                     </p>
                     <?php if (!empty($dept_service_labels)): ?>
                        <div style="height: 280px; width: 100%;">
                            <canvas id="deptServicesChart"></canvas>
                        </div>
                     <?php else: ?>
                        <p style='color:var(--text-muted); font-size: 14px; text-align:center; padding: 40px;'>No service evaluation data available yet.</p>
                     <?php endif; ?>
                </div>
            </div>

            <!-- ROW 2: Dean's Toolkit & Alerts -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 24px;">
                <!-- Dean's Toolkit -->
                <div class="card-modern" style="margin-bottom: 0; padding: 26px;">
                    <h3 style="margin-bottom:12px; font-size: 16px; font-weight: 800;"><i class="fas fa-tools" style="color: var(--accent); margin-right: 8px;"></i> Dean's Toolkit</h3>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">Quick access to department settings, reports, and faculty lists.</p>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;">
                        <a href="view_results.php" class="nav-link-btn" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 10px; padding: 12px; font-weight: 700; background: var(--purple-glow); color: var(--accent); border: none;">
                            <i class="fas fa-chart-pie"></i> View Reports
                        </a>
                        <a href="manage_subjects.php" class="nav-link-btn" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 10px; padding: 12px; font-weight: 700; background: var(--bg-color); color: var(--text-main); border: 1.5px solid var(--border-color);">
                            <i class="fas fa-book-open"></i> Manage Subjects
                        </a>
                        <a href="manage_instructors.php" class="nav-link-btn" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 10px; padding: 12px; font-weight: 700; background: var(--bg-color); color: var(--text-main); border: 1.5px solid var(--border-color);">
                            <i class="fas fa-chalkboard-teacher"></i> View Instructors
                        </a>
                    </div>
                </div>

                <!-- Faculty Below Threshold Alert or Performance Status -->
                <?php
                $below_fac_stmt = $conn->prepare("
                    SELECT u.full_name, AVG(e.rating) as avg_r, COUNT(e.id) as cnt
                    FROM users u
                    JOIN evaluations e ON u.id = e.faculty_id
                    WHERE u.dept_id = ? AND e.academic_period_id = ?
                    GROUP BY u.id, u.full_name
                    HAVING avg_r < 3.0
                    ORDER BY avg_r ASC
                ");
                $below_fac_list = [];
                if ($below_fac_stmt) {
                    $below_fac_stmt->bind_param("ii", $dept_id, $active_id);
                    $below_fac_stmt->execute();
                    $below_fac_list = $below_fac_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                ?>
                <?php if (!empty($below_fac_list)): ?>
                <div class="card-modern" style="margin-bottom: 0; border-left: 4px solid #ef4444;">
                    <h3 style="margin-bottom: 12px; font-size: 15px; font-weight: 800; color: #ef4444;">
                        <i class="fas fa-exclamation-circle"></i> Faculty Below Threshold (avg &lt; 3.0)
                    </h3>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">The following instructors have an average rating below 3.0 and may need departmental support or review.</p>
                    <div style="max-height: 140px; overflow-y: auto;">
                        <?php foreach($below_fac_list as $bf): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: rgba(239,68,68,0.1); width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #ef4444;">
                                    <i class="fas fa-user-tie" style="font-size: 12px;"></i>
                                </div>
                                <span style="font-size: 13px; font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($bf['full_name']); ?></span>
                            </div>
                            <span style="background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                                <?php echo number_format($bf['avg_r'], 2); ?> avg
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-modern" style="margin-bottom: 0; border-left: 4px solid #10b981;">
                    <h3 style="margin-bottom: 12px; font-size: 15px; font-weight: 800; color: #10b981;">
                        <i class="fas fa-check-circle"></i> Department Faculty Performance
                    </h3>
                    <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">
                        All faculty members in <strong><?php echo htmlspecialchars($dept_name); ?></strong> are currently performing at or above the 3.0 rating standard.
                    </p>
                    <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.1); color: #059669; padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700;">
                        <i class="fas fa-medal"></i> 100% Quality Benchmark Achieved
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ROW 3: Instructors Evaluation Table Card (FULL WIDTH) -->
            <?php
            // Pre-fetch all classes/subjects for this department to calculate Dean evaluation progress
            $dept_classes_query = "
                SELECT c.id as class_id, c.faculty_id, u.full_name as instructor_name, u.username as employee_id,
                       s.id as subject_id, s.subject_code, s.subject_description, s.year_level, s.section
                FROM classes c
                JOIN users u ON c.faculty_id = u.id
                JOIN subjects s ON c.subject_id = s.id
                WHERE c.period_id = ? AND (u.dept_id = ? OR s.dept_id = ?)
                ORDER BY s.subject_code ASC, u.full_name ASC
            ";
            $inst_eval_stmt = $conn->prepare($dept_classes_query);
            $inst_eval_stmt->bind_param("iii", $active_id, $dept_id, $dept_id);
            $inst_eval_stmt->execute();
            $inst_res = $inst_eval_stmt->get_result();
            $dept_classes_list = $inst_res ? $inst_res->fetch_all(MYSQLI_ASSOC) : [];

            // If classes table has no entries for this dept, fall back to subjects table assignments
            if (empty($dept_classes_list)) {
                $sub_fb_stmt = $conn->prepare("
                    SELECT s.id as subject_id, s.subject_code, s.subject_description, s.year_level, s.section,
                           u.id as faculty_id, u.full_name as instructor_name, u.username as employee_id, 0 as class_id
                    FROM subjects s
                    JOIN users u ON s.faculty_id = u.id
                    WHERE s.dept_id = ?
                    ORDER BY s.subject_code ASC
                ");
                $sub_fb_stmt->bind_param("i", $dept_id);
                $sub_fb_stmt->execute();
                $dept_classes_list = $sub_fb_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }

            // Fetch evaluations submitted by THIS Dean for the active period
            $dean_fac_evals = [];
            if ($active_id) {
                $dfe_chk = $conn->prepare("SELECT faculty_id, subject_id, rating, created_at FROM evaluations WHERE student_id = ? AND academic_period_id = ? AND faculty_id IS NOT NULL");
                $dfe_chk->bind_param("ii", $dean_id, $active_id);
                $dfe_chk->execute();
                $dfe_res = $dfe_chk->get_result();
                while ($r = $dfe_res->fetch_assoc()) {
                    $key = $r['faculty_id'] . '_' . ($r['subject_id'] ?? 0);
                    $dean_fac_evals[$key] = $r;
                    $dean_fac_evals['fac_' . $r['faculty_id']] = $r; // fallback matching by faculty ID
                }
            }

            $total_fac_classes = count($dept_classes_list);
            $completed_fac_count = 0;
            foreach ($dept_classes_list as $row_item) {
                $k = $row_item['faculty_id'] . '_' . $row_item['subject_id'];
                if (isset($dean_fac_evals[$k]) || isset($dean_fac_evals['fac_' . $row_item['faculty_id']])) {
                    $completed_fac_count++;
                }
            }
            $fac_eval_pct = $total_fac_classes > 0 ? round(($completed_fac_count / $total_fac_classes) * 100) : 0;
            ?>

            <div class="card-modern" id="instructors-eval" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
                    <div>
                        <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-chalkboard-teacher" style="color: var(--accent);"></i> Faculty Performance & Instruction Evaluation
                        </h3>
                        <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                            As Dean, evaluate the instructional quality, methodology, and professionalism of instructors in your department.
                        </p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="background: <?php echo ($completed_fac_count == $total_fac_classes && $total_fac_classes > 0) ? '#10b981' : 'var(--accent)'; ?>; color: white; font-size: 12px; font-weight: 800; padding: 6px 14px; border-radius: 20px; letter-spacing: 0.5px; box-shadow: 0 4px 12px rgba(132, 90, 223, 0.2);">
                            <i class="fas <?php echo ($completed_fac_count == $total_fac_classes && $total_fac_classes > 0) ? 'fa-check-double' : 'fa-clipboard-check'; ?>"></i>
                            <?php echo $completed_fac_count; ?> / <?php echo $total_fac_classes; ?> Evaluated by Dean (<?php echo $fac_eval_pct; ?>%)
                        </span>
                    </div>
                </div>

                <!-- Progress bar for Dean Faculty Evaluation -->
                <div style="width: 100%; height: 8px; background: rgba(0,0,0,0.06); border-radius: 4px; overflow: hidden; margin: 14px 0 20px 0;">
                    <div style="width: <?php echo $fac_eval_pct; ?>%; height: 100%; background: linear-gradient(90deg, var(--accent), #10b981); border-radius: 4px; transition: width 0.4s ease;"></div>
                </div>

                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th style="min-width: 170px;">Subject</th>
                                <th style="min-width: 170px;">Faculty Instructor</th>
                                <th style="min-width: 130px;">Year & Section</th>
                                <th style="min-width: 130px;">Student Score</th>
                                <th style="min-width: 150px;">Dean Status</th>
                                <th style="text-align: right; min-width: 270px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!empty($dept_classes_list)):
                                foreach($dept_classes_list as $ie):
                                    $fac_id = (int)$ie['faculty_id'];
                                    $subj_id = (int)$ie['subject_id'];
                                    $eval_key = $fac_id . '_' . $subj_id;
                                    $dean_eval_info = $dean_fac_evals[$eval_key] ?? ($dean_fac_evals['fac_' . $fac_id] ?? null);
                                    $is_dean_done = !empty($dean_eval_info);
                                    $dean_score = $is_dean_done ? number_format((float)$dean_eval_info['rating'], 2) : null;

                                    // Get computed evaluation stats from students
                                    $avg_stmt = $conn->prepare("SELECT AVG(rating) as avg_r, COUNT(*) as cnt FROM evaluations WHERE faculty_id = ? AND academic_period_id = ?");
                                    $avg_stmt->bind_param("ii", $fac_id, $active_id);
                                    $avg_stmt->execute();
                                    $avg_row = $avg_stmt->get_result()->fetch_assoc();
                                    $comp_avg = round((float)($avg_row['avg_r'] ?? 0), 2);
                                    $comp_cnt = (int)($avg_row['cnt'] ?? 0);

                                    // Category breakdown
                                    $cat_stmt = $conn->prepare("
                                        SELECT q.category, AVG(r.rating) as cat_avg
                                        FROM evaluation_responses r
                                        JOIN evaluation_questions q ON r.question_id = q.id
                                        JOIN evaluations e ON r.evaluation_id = e.id
                                        WHERE e.faculty_id = ? AND e.academic_period_id = ?
                                        GROUP BY q.category ORDER BY q.category ASC
                                    ");
                                    $cat_stmt->bind_param("ii", $fac_id, $active_id);
                                    $cat_stmt->execute();
                                    $cat_scores = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                                    // Comments & Sentiment
                                    $com_stmt = $conn->prepare("SELECT feedback, rating FROM evaluations WHERE faculty_id = ? AND academic_period_id = ? AND feedback IS NOT NULL AND feedback != '' ORDER BY created_at DESC");
                                    $com_stmt->bind_param("ii", $fac_id, $active_id);
                                    $com_stmt->execute();
                                    $raw_coms = $com_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                                    $good_coms = [];
                                    $bad_coms = [];
                                    foreach($raw_coms as $rc) {
                                        if (analyzeSentiment($rc['feedback'], $rc['rating']) === 'Positive') {
                                            $good_coms[] = $rc;
                                        } else {
                                            $bad_coms[] = $rc;
                                        }
                                    }

                                    $payload = [
                                        'subject_code' => $ie['subject_code'],
                                        'subject_desc' => $ie['subject_description'],
                                        'instructor' => $ie['instructor_name'],
                                        'year_level' => $ie['year_level'] ?? '3rd Year',
                                        'section' => $ie['section'] ?? '3-A',
                                        'avg_rating' => $comp_avg,
                                        'total_evals' => $comp_cnt,
                                        'category_scores' => $cat_scores,
                                        'good_comments' => $good_coms,
                                        'bad_comments' => $bad_coms
                                    ];
                                    $payload_json = htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($ie['subject_code']); ?></strong><br>
                                    <small style="color:var(--text-muted);"><?php echo htmlspecialchars($ie['subject_description']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($ie['instructor_name']); ?></strong>
                                    <?php if (!empty($ie['employee_id'])): ?>
                                    <br><small style="color:var(--text-muted); font-size:11px;">ID: <?php echo htmlspecialchars($ie['employee_id']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="category-badge"><?php echo htmlspecialchars($ie['year_level'] ?? '1st Year'); ?></span>
                                    <span class="category-badge" style="background:#eef2ff; color:#4338ca;"><?php echo htmlspecialchars($ie['section'] ?? 'A'); ?></span>
                                </td>
                                <td>
                                    <?php if ($comp_cnt > 0): ?>
                                        <span class="category-badge" style="background: rgba(245, 158, 11, 0.12); color: #d97706; font-weight: 800;">
                                            <?php echo number_format($comp_avg, 2); ?> ★ (<?php echo $comp_cnt; ?>)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">No student reviews</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_dean_done): ?>
                                        <span style="background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 700; padding: 5px 11px; border-radius: 6px; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-check-circle"></i> Evaluated (<?php echo $dean_score; ?> ★)
                                        </span>
                                    <?php else: ?>
                                        <span style="background: rgba(245, 158, 11, 0.12); color: #d97706; font-weight: 700; padding: 5px 11px; border-radius: 6px; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-hourglass-half"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; align-items:center; gap:6px; justify-content:flex-end;">
                                        <?php if (!$is_dean_done): ?>
                                            <a href="evaluation_form.php?faculty_id=<?php echo $fac_id; ?>&subject_id=<?php echo $subj_id; ?>" class="btn-action btn-action-text" style="width: auto !important; min-width: fit-content !important; height: auto !important; min-height: 32px !important; white-space: nowrap !important; background: var(--accent); color: white; border: none; padding: 7px 14px !important; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex !important; align-items: center; gap: 6px; font-size: 12px; box-shadow: 0 3px 8px rgba(132, 90, 223, 0.25);">
                                                <i class="fas fa-star"></i> Evaluate Faculty
                                            </a>
                                        <?php else: ?>
                                            <a href="evaluation_form.php?faculty_id=<?php echo $fac_id; ?>&subject_id=<?php echo $subj_id; ?>" class="btn-action btn-action-text" style="width: auto !important; min-width: fit-content !important; height: auto !important; min-height: 32px !important; white-space: nowrap !important; background: rgba(132, 90, 223, 0.12); color: var(--accent); border: 1px solid rgba(132, 90, 223, 0.3); padding: 7px 14px !important; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex !important; align-items: center; gap: 6px; font-size: 12px;">
                                                <i class="fas fa-edit"></i> Edit Dean Eval
                                            </a>
                                        <?php endif; ?>

                                        <button type="button" class="btn-action btn-action-text" style="background:var(--purple-glow); color:var(--accent); border:1px solid rgba(132,90,223,0.3); padding:7px 12px; border-radius:8px; cursor:pointer; font-size: 12px;" onclick='openComputedModal(<?php echo $payload_json; ?>)'>
                                            <i class="fas fa-calculator"></i> Student Summary
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; color:var(--text-muted); padding:30px;">
                                    <i class="fas fa-book-open" style="font-size:28px; display:block; margin-bottom:8px; opacity:0.3;"></i>
                                    No assigned subjects or classes found for your department.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ROW 4: Faculty Performance Ranking Table -->
            <div class="card-modern" id="rankings" style="margin-bottom: 24px;">
                <h3 style="margin-bottom: 16px; font-size: 16px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-trophy" style="color: #f59e0b;"></i> Instructor Rankings
                </h3>
                <div class="table-responsive">
                    <table class="table-star"> 
                        <thead>
                            <tr>
                                <th>Faculty Name</th>
                                <th>Avg Rating</th>
                                <th>Reviews</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $fac_query_stmt = $conn->prepare("SELECT 
                                            u.full_name, 
                                            AVG(e.rating) as avg_r,
                                            COUNT(e.id) as eval_count
                                          FROM users u 
                                          LEFT JOIN evaluations e ON u.id = e.faculty_id AND e.academic_period_id = ?
                                          WHERE u.role='faculty' AND u.dept_id= ?
                                          GROUP BY u.id, u.full_name
                                          ORDER BY avg_r DESC");
                            $fac_query_stmt->bind_param("ii", $active_id, $dept_id);
                            $fac_query_stmt->execute();
                            $fac = $fac_query_stmt->get_result();
                            
                            $rank = 1;
                            if ($fac && $fac->num_rows > 0) {
                                while($row = $fac->fetch_assoc()) {
                                    $rating_val = $row['avg_r'] ? number_format((float)$row['avg_r'], 2) : '0.00';
                                    echo "<tr>";
                                    echo "<td style='font-weight: 700;'>" . $rank++ . ". " . htmlspecialchars($row['full_name']) . "</td>";
                                    echo "<td><span class='category-badge' style='background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-weight: bold;'>{$rating_val} ★</span></td>";
                                    echo "<td style='color: var(--text-muted);'>{$row['eval_count']} eval</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='3' style='text-align:center; color:var(--text-muted); padding: 15px;'>No faculty data found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Computed Evaluation Modal for Dean -->
            <div id="computedEvalModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:25px; width:90%; max-width:700px; max-height:90vh; overflow-y:auto; color:var(--text-main); box-shadow:0 20px 40px rgba(0,0,0,0.3);" id="printModalArea">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                        <div>
                            <h3 class="modal-title" style="margin:0; font-size:18px;" id="modal_instructor_title">Computed Evaluation Summary</h3>
                            <small style="color:var(--text-muted);" id="modal_subject_subtitle"></small>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="button" onclick="window.print()" class="btn-action" style="background:#eef2ff; color:#4338ca; padding:8px 16px;"><i class="fas fa-print"></i> Print</button>
                            <button type="button" class="modal-close-btn" onclick="closeComputedModal()" aria-label="Close modal">&times;</button>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:8px; padding:15px; text-align:center;">
                            <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Overall Computed Score</div>
                            <div style="font-size:3rem; font-weight:800; color:var(--accent);" id="modal_overall_score">0.00</div>
                            <span class="category-badge" style="background:#d1fae5; color:#065f46;" id="modal_rating_interp">Rating</span>
                        </div>
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:8px; padding:15px;">
                            <div style="font-size:13px; margin-bottom:6px;"><strong>Year Level:</strong> <span id="modal_year_level"></span></div>
                            <div style="font-size:13px; margin-bottom:6px;"><strong>Section:</strong> <span id="modal_section"></span></div>
                            <div style="font-size:13px; margin-bottom:6px;"><strong>Total Student Reviews:</strong> <span id="modal_total_reviews"></span></div>
                            <div style="font-size:13px;"><strong>Period:</strong> <span><?php echo $period_label; ?></span></div>
                        </div>
                    </div>

                    <h4 style="margin-bottom:12px;"><i class="fas fa-list-ol"></i> Category Performance Breakdown</h4>
                    <table class="table-modern" style="margin-bottom:20px;">
                        <thead>
                            <tr><th>Category</th><th style="text-align:right;">Mean Score</th></tr>
                        </thead>
                        <tbody id="modal_category_tbody">
                        </tbody>
                    </table>

                    <h4 style="margin-bottom:12px;"><i class="fas fa-comments"></i> Student Feedback & Comment Analysis</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <!-- Good Comments Box -->
                        <div style="border:1px solid var(--border-color); border-radius:8px; padding:15px; background:rgba(16,185,129,0.03);">
                            <h5 style="color:#10b981; margin:0 0 10px 0;"><i class="fas fa-thumbs-up"></i> Good Comments (<span id="modal_good_count">0</span>)</h5>
                            <div id="modal_good_comments_list" style="max-height:200px; overflow-y:auto; font-size:12px;"></div>
                        </div>
                        <!-- Bad Comments Box -->
                        <div style="border:1px solid var(--border-color); border-radius:8px; padding:15px; background:rgba(239,68,68,0.03);">
                            <h5 style="color:#ef4444; margin:0 0 10px 0;"><i class="fas fa-thumbs-down"></i> Bad / Improvement Comments (<span id="modal_bad_count">0</span>)</h5>
                            <div id="modal_bad_comments_list" style="max-height:200px; overflow-y:auto; font-size:12px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function getInterp(val) {
                    if (val >= 4.5) return 'Outstanding';
                    if (val >= 3.5) return 'Very Satisfactory';
                    if (val >= 2.5) return 'Satisfactory';
                    if (val >= 1.5) return 'Unsatisfactory';
                    if (val > 0) return 'Poor';
                    return 'No Ratings';
                }

                function openComputedModal(data) {
                    document.getElementById('modal_instructor_title').innerText = data.instructor;
                    document.getElementById('modal_subject_subtitle').innerText = data.subject_code + ' - ' + data.subject_desc;
                    document.getElementById('modal_overall_score').innerText = Number(data.avg_rating).toFixed(2);
                    document.getElementById('modal_rating_interp').innerText = getInterp(data.avg_rating);
                    document.getElementById('modal_year_level').innerText = data.year_level;
                    document.getElementById('modal_section').innerText = data.section;
                    document.getElementById('modal_total_reviews').innerText = data.total_evals;

                    // Categories table
                    let catHtml = '';
                    if (data.category_scores && data.category_scores.length > 0) {
                        data.category_scores.forEach(c => {
                            catHtml += `<tr><td>${c.category}</td><td style="text-align:right;"><strong>${Number(c.cat_avg).toFixed(2)}</strong></td></tr>`;
                        });
                    } else {
                        catHtml = '<tr><td colspan="2" style="text-align:center; color:var(--text-muted);">No category data available</td></tr>';
                    }
                    document.getElementById('modal_category_tbody').innerHTML = catHtml;

                    // Good Comments
                    document.getElementById('modal_good_count').innerText = data.good_comments ? data.good_comments.length : 0;
                    let goodHtml = '';
                    if (data.good_comments && data.good_comments.length > 0) {
                        data.good_comments.forEach(gc => {
                            goodHtml += `<div style="padding:6px; border-bottom:1px solid rgba(0,0,0,0.05);">"${gc.feedback}"</div>`;
                        });
                    } else {
                        goodHtml = '<div style="color:var(--text-muted); text-align:center;">No positive comments</div>';
                    }
                    document.getElementById('modal_good_comments_list').innerHTML = goodHtml;

                    // Bad Comments
                    document.getElementById('modal_bad_count').innerText = data.bad_comments ? data.bad_comments.length : 0;
                    let badHtml = '';
                    if (data.bad_comments && data.bad_comments.length > 0) {
                        data.bad_comments.forEach(bc => {
                            badHtml += `<div style="padding:6px; border-bottom:1px solid rgba(0,0,0,0.05);">"${bc.feedback}"</div>`;
                        });
                    } else {
                        badHtml = '<div style="color:var(--text-muted); text-align:center;">No negative comments</div>';
                    }
                    document.getElementById('modal_bad_comments_list').innerHTML = badHtml;

                    document.getElementById('computedEvalModal').style.display = 'flex';
                }

                function closeComputedModal() {
                    document.getElementById('computedEvalModal').style.display = 'none';
                }
            </script>

            <!-- Faculty Reviews on Dean Leadership Section -->
            <div id="dean-reviews" class="card-modern" style="margin-top: 25px; border-left: 5px solid var(--accent);">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-user-tie" style="color: var(--accent);"></i> Faculty Leadership Appraisal (Reviews on Dean)
                        </h3>
                        <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                            360° leadership evaluations and constructive feedback submitted by your department faculty for <strong><?php echo htmlspecialchars($period_label); ?></strong>.
                        </p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="background: rgba(132, 90, 223, 0.12); color: var(--accent); font-weight: 800; font-size: 13px; padding: 6px 14px; border-radius: 20px;">
                            <i class="fas fa-star" style="color: #f59e0b;"></i> <?php echo $dean_eval_count > 0 ? number_format($dean_leadership_avg, 2) . ' / 5.00' : 'No Ratings Yet'; ?>
                        </span>
                        <span style="background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 800; font-size: 13px; padding: 6px 14px; border-radius: 20px;">
                            <i class="fas fa-users"></i> <?php echo $dean_eval_count; ?> Faculty Review<?php echo $dean_eval_count == 1 ? '' : 's'; ?>
                        </span>
                    </div>
                </div>

                <?php if ($dean_eval_count > 0): ?>
                    <div style="display: grid; gap: 14px;">
                        <?php foreach ($dean_fac_evals as $dfe): 
                            $sentiment = analyzeSentiment($dfe['feedback'], (float)$dfe['rating']);
                            $s_color = '#10b981';
                            if (strtolower($sentiment) === 'negative') $s_color = '#ef4444';
                            elseif (strtolower($sentiment) === 'neutral') $s_color = '#64748b';
                        ?>
                        <div style="background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px 20px; transition: all 0.2s;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-weight: 800; font-size: 14px; color: var(--text-main);">
                                        <i class="fas fa-user-circle" style="color: var(--accent); margin-right: 4px;"></i> <?php echo htmlspecialchars($dfe['faculty_name']); ?>
                                    </span>
                                    <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 600;">(<?php echo htmlspecialchars($dfe['faculty_emp_id']); ?>)</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="background: rgba(245, 158, 11, 0.12); color: #d97706; font-weight: 800; font-size: 12.5px; padding: 4px 10px; border-radius: 12px;">
                                        ★ <?php echo number_format((float)$dfe['rating'], 2); ?>
                                    </span>
                                    <span style="background: <?php echo $s_color; ?>15; color: <?php echo $s_color; ?>; font-weight: 700; font-size: 11.5px; padding: 4px 10px; border-radius: 12px;">
                                        <?php echo htmlspecialchars($sentiment); ?>
                                    </span>
                                    <span style="font-size: 11.5px; color: var(--text-muted);">
                                        <?php echo date('M j, Y', strtotime($dfe['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                            <p style="margin: 0; font-size: 13.5px; line-height: 1.6; color: var(--text-main);">
                                "<?php echo htmlspecialchars($dfe['feedback']); ?>"
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 35px 20px; color: var(--text-muted); background: var(--input-bg); border-radius: 12px; border: 1px dashed var(--border-color);">
                        <i class="fas fa-comments" style="font-size: 32px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                        No faculty evaluations for the Dean have been recorded for <strong><?php echo htmlspecialchars($period_label); ?></strong> yet.
                    </div>
                <?php endif; ?>
            </div>

            <!-- MCNP-ISAP Campus Facilities Evaluation Section for Dean -->
            <div id="facilities-section" class="card-modern" style="margin-top: 25px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-university" style="color: var(--accent);"></i> Campus Facilities & Services Evaluation
                        </h3>
                        <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                            As a Dean / Department Head, your evaluation of university facilities and campus services provides crucial leadership insight for quality assurance and institutional development.
                        </p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="background: <?php echo $completed_facilities_count == $total_facilities_count ? '#10b981' : 'var(--accent)'; ?>; color: white; font-size: 12px; font-weight: 800; padding: 6px 14px; border-radius: 20px; letter-spacing: 0.5px; box-shadow: 0 4px 12px rgba(132, 90, 223, 0.2);">
                            <i class="fas <?php echo $completed_facilities_count == $total_facilities_count ? 'fa-check-double' : 'fa-clipboard-check'; ?>"></i>
                            <?php echo $completed_facilities_count; ?> / <?php echo $total_facilities_count; ?> Completed (<?php echo $facilities_pct; ?>%)
                        </span>
                    </div>
                </div>

                <!-- Progress bar -->
                <div style="width: 100%; height: 8px; background: rgba(0,0,0,0.06); border-radius: 4px; overflow: hidden; margin: 15px 0 20px 0;">
                    <div style="width: <?php echo $facilities_pct; ?>%; height: 100%; background: linear-gradient(90deg, var(--accent), #10b981); border-radius: 4px; transition: width 0.4s ease;"></div>
                </div>

                <div class="course-grid">
                    <?php foreach ($facility_services as $s): 
                        $done = isset($evaluated_targets[$s['name']]);
                    ?>
                    <div class="course-card" <?php if (!$done): ?>onclick="window.location.href='evaluate_facility.php?target=<?php echo urlencode($s['name']); ?>&cat=<?php echo $s['cat']; ?>'" style="cursor: pointer;"<?php else: ?>style="cursor: default;"<?php endif; ?>>
                        <div class="course-icon" style="<?php echo $done ? 'background: rgba(34, 197, 94, 0.15); color: #22c55e;' : ''; ?>">
                            <i class="fas <?php echo $s['icon']; ?>"></i>
                        </div>
                        <div class="course-name"><?php echo htmlspecialchars($s['name']); ?></div>
                        <div class="course-desc">
                            <?php echo htmlspecialchars($s['desc']); ?>
                        </div>
                        <?php if ($done): ?>
                            <span style="color: #22c55e; font-size: 12.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fas fa-check-circle"></i> Evaluated
                            </span>
                        <?php else: ?>
                            <span class="eval-action-btn">
                                Evaluate <i class="fas fa-arrow-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <footer>
                <i class="fas fa-university"></i> Dean Portal · ID: <?php echo $_SESSION['username']; ?> · <?php echo $_SESSION['full_name']; ?>
            </footer>
        </main>
    </div>

    <script>
        function scrollToSection(id) {
            if (window.appScrollToSection) {
                window.appScrollToSection(id);
            } else {
                const element = document.getElementById(id);
                if (element) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
            updateThemeIcon(targetTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeBtn i');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);

        // Department Services Chart (Ynex style custom colors)
        const deptServicesCtx = document.getElementById('deptServicesChart');
        if (deptServicesCtx) {
            new Chart(deptServicesCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($dept_service_labels); ?>,
                    datasets: [{
                        label: 'Average Rating',
                        data: <?php echo json_encode($dept_service_data); ?>,
                        backgroundColor: 'rgba(132, 90, 223, 0.85)',
                        hoverBackgroundColor: '#845adf',
                        borderRadius: 6,
                        borderWidth: 0,
                        barThickness: 15
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        x: { 
                            beginAtZero: true, 
                            max: 5,
                            grid: { color: 'rgba(0,0,0,0.04)' }
                        },
                        y: {
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false } 
                    }
                }
            });
        }

        // Course Participation Line Chart
        const coursePartCtx = document.getElementById('coursePartChart');
        if (coursePartCtx) {
            new Chart(coursePartCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_course_labels ?? []); ?>,
                    datasets: [
                        {
                            label: 'Total Students',
                            data: <?php echo json_encode($chart_total_students ?? []); ?>,
                            borderColor: 'rgba(99,102,241,0.9)',
                            backgroundColor: 'rgba(99,102,241,0.12)',
                            borderWidth: 2.5,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            pointBackgroundColor: 'rgba(99,102,241,1)'
                        },
                        {
                            label: 'Evaluated Students',
                            data: <?php echo json_encode($chart_eval_students ?? []); ?>,
                            borderColor: 'rgba(16,185,129,0.9)',
                            backgroundColor: 'rgba(16,185,129,0.10)',
                            borderWidth: 2.5,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            pointBackgroundColor: 'rgba(16,185,129,1)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: '#888',
                                font: { size: 12, weight: '600' },
                                usePointStyle: true,
                                padding: 18
                            }
                        },
                        tooltip: {
                            callbacks: {
                                afterBody: function(ctx) {
                                    const total = ctx[0]?.raw || 0;
                                    const evals = ctx[1]?.raw || 0;
                                    const pct = total > 0 ? Math.round((evals / total) * 100) : 0;
                                    return ['', `Completion: ${pct}%`];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(0,0,0,0.04)' },
                            ticks: { color: '#888', font: { size: 12 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.04)' },
                            ticks: { color: '#888', font: { size: 11 }, stepSize: 1 }
                        }
                    }
                }
            });
        }


        let currentFocus = -1;
        const searchInput = document.getElementById('dashboardSearch');
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                let resultsBox = document.getElementById('searchResults');
                if (!resultsBox || resultsBox.style.display === 'none') return;
                
                let items = resultsBox.getElementsByTagName('a');
                if (items.length === 0) return;
                
                if (e.keyCode == 40) { // Arrow Down
                    currentFocus++;
                    addActive(items);
                    e.preventDefault();
                } else if (e.keyCode == 38) { // Arrow Up
                    currentFocus--;
                    addActive(items);
                    e.preventDefault();
                } else if (e.keyCode == 13) { // Enter
                    e.preventDefault();
                    if (currentFocus > -1) {
                        if (items[currentFocus]) items[currentFocus].click();
                    } else if (items.length > 0) {
                        items[0].click();
                    }
                }
            });
        }

        function addActive(items) {
            if (!items) return false;
            removeActive(items);
            if (currentFocus >= items.length) currentFocus = 0;
            if (currentFocus < 0) currentFocus = (items.length - 1);
            items[currentFocus].style.background = 'var(--sidebar-active-bg)';
            items[currentFocus].scrollIntoView({ block: "nearest", behavior: "smooth" });
        }

        function removeActive(items) {
            for (let i = 0; i < items.length; i++) {
                items[i].style.background = 'transparent';
            }
        }

        function filterDashboard() {
            let input = document.getElementById('dashboardSearch').value.toLowerCase().trim();
            let resultsBox = document.getElementById('searchResults');
            currentFocus = -1;
            
            // Filter dashboard cards inline
            let cards = document.querySelectorAll('.card, .card-modern, .stat-card-modern, .table-modern tr');
            cards.forEach(card => {
                if(card.tagName.toLowerCase() === 'tr' && card.parentElement.tagName.toLowerCase() === 'thead') return;
                let text = card.innerText.toLowerCase();
                card.style.display = text.includes(input) ? '' : 'none';
            });

            if (input === '') {
                resultsBox.style.display = 'none';
                return;
            }

            let links = document.querySelectorAll('.sidebar .nav-link');
            let html = '';
            let addedLinks = new Set();

            links.forEach(link => {
                if (link.getAttribute('onclick') && link.getAttribute('onclick').includes('nextElementSibling')) return;
                let text = link.textContent.trim();
                if (text.toLowerCase().includes(input)) {
                    let href = link.getAttribute('href');
                    if (!href || href === '#' || addedLinks.has(text)) return;
                    addedLinks.add(text);

                    let iconHtml = link.querySelector('i') ? link.querySelector('i').outerHTML : '';
                    let regex = new RegExp("(" + input + ")", "gi");
                    let highlightedText = text.replace(regex, "<mark style='background:rgba(245,158,11,0.3); color:inherit; padding:0 2px; border-radius:3px;'>$1</mark>");
                    
                    html += `
                        <a href="${href}" class="search-result-item" style="display:flex; align-items:center; gap:12px; padding:12px 18px; color:var(--text-main); text-decoration:none; border-bottom:1px solid rgba(255,255,255,0.05); font-size:14px; transition:background 0.2s;" onmouseover="this.style.background='var(--sidebar-active-bg)'" onmouseout="if(currentFocus === -1 || !this.isSameNode(document.getElementById('searchResults').getElementsByTagName('a')[currentFocus])) this.style.background='transparent'">
                            <span style="color:var(--text-muted); width:20px; text-align:center;">${iconHtml}</span>
                            <span>${highlightedText}</span>
                            <i class="fas fa-chevron-right" style="margin-left:auto; font-size:10px; color:var(--text-muted); opacity:0.5;"></i>
                        </a>
                    `;
                }
            });
            
            if (html === '') {
                html = `<div style="padding:15px; color:var(--text-muted); text-align:center; font-size:14px;">No results found for "${input}"</div>`;
            }
            resultsBox.innerHTML = html;
            resultsBox.style.display = 'block';
        }

        document.addEventListener('click', function(e) {
            let searchBox = document.querySelector('.search-box-modern');
            let resultsBox = document.getElementById('searchResults');
            if (searchBox && !searchBox.contains(e.target) && resultsBox) {
                resultsBox.style.display = 'none';
            }

            let profileWidget = document.getElementById('profileWidget');
            let profileDropdown = document.getElementById('profileDropdown');
            if (profileWidget && profileDropdown) {
                if (profileWidget.contains(e.target)) {
                    e.stopPropagation();
                    profileDropdown.style.display = profileDropdown.style.display === 'none' ? 'block' : 'none';
                } else if (!profileDropdown.contains(e.target)) {
                    profileDropdown.style.display = 'none';
                }
            }
        });

</script>
</body>
</html>