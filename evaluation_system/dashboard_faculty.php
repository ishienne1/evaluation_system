<?php
session_start();
include 'db_connect.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') { header("Location: index.php"); exit(); }

// Check for impersonation session
$is_impersonating = isset($_SESSION['original_admin_id']);

$faculty_id = $_SESSION['id'];
$dept_id = $_SESSION['dept_id'] ?? null;
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc() ?: [];
$active_id = $active_period['id'] ?? 0;
$period_label = isset($active_period['semester']) ? $active_period['semester'] . " " . $active_period['school_year'] : "No Active Period";

$avg_rating = 0;
$total_evals = 0;
$perf_labels = ['Teaching', 'Professionalism', 'Assessment', 'Support'];
$my_perf_data = [0, 0, 0, 0];
$school_perf_data = [0, 0, 0, 0];
$category_chart_labels = [];
$category_chart_data = [];
$dist_data = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

$stmt = $conn->prepare("SELECT AVG(rating), COUNT(*) FROM evaluations WHERE faculty_id = ? AND academic_period_id = ?");
$stmt->bind_param("ii", $faculty_id, $active_id);
$stmt->execute();
$stmt->bind_result($avg_rating, $total_evals); 
$stmt->fetch();
$stmt->close();

$dept_avg_stmt = $conn->prepare("SELECT AVG(e.rating) FROM evaluations e JOIN users u ON e.faculty_id = u.id WHERE u.dept_id = ? AND e.academic_period_id = ?");
$dept_avg_stmt->bind_param("ii", $dept_id, $active_id);
$dept_avg_stmt->execute();
$dept_avg_res = $dept_avg_stmt->get_result()->fetch_row();
$dept_avg = $dept_avg_res ? $dept_avg_res[0] : 0;
$status_msg = ($avg_rating >= $dept_avg) ? "Above Dept. Average" : "Below Dept. Average";

$top_cat_stmt = $conn->prepare("SELECT q.category, AVG(r.rating) as score FROM evaluation_responses r JOIN evaluation_questions q ON r.question_id = q.id JOIN evaluations e ON r.evaluation_id = e.id WHERE e.faculty_id = ? AND e.academic_period_id = ? GROUP BY q.category ORDER BY score DESC LIMIT 1");
$top_cat_stmt->bind_param("ii", $faculty_id, $active_id);
$top_cat_stmt->execute();
$top_cat_data = $top_cat_stmt->get_result()->fetch_assoc() ?: [];

$max_rating_row = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'")->fetch_assoc();
$max_rating = $max_rating_row ? $max_rating_row['setting_value'] : 5;

// Query assigned teaching classes and enrollment counts for active period
$fac_classes_stmt = $conn->prepare("
    SELECT c.id as class_id, s.id as subject_id, s.subject_code, s.subject_description, 
           s.year_level, s.section,
           (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id) as enrolled_count
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.faculty_id = ? AND c.period_id = ?
    ORDER BY s.subject_code ASC
");
$fac_classes_stmt->bind_param("ii", $faculty_id, $active_id);
$fac_classes_stmt->execute();
$my_teaching_classes = $fac_classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_teaching_classes = count($my_teaching_classes);
$total_enrolled_students = 0;
foreach ($my_teaching_classes as $tc_row) {
    $total_enrolled_students += (int)$tc_row['enrolled_count'];
}

// Query College Dean for this faculty's department
$dept_id_int = (int)($dept_id ?? 0);
$my_dean_stmt = $conn->prepare("
    SELECT u.id, u.full_name, u.username, u.avatar, u.dept_id,
           d.dept_name, d.dept_alias
    FROM users u
    LEFT JOIN departments d ON u.dept_id = d.id
    WHERE u.role = 'dean' AND u.status = 'active' AND (u.dept_id = ? OR ? = 0)
    ORDER BY (u.dept_id = ?) DESC
    LIMIT 1
");
$my_dean_stmt->bind_param("iii", $dept_id_int, $dept_id_int, $dept_id_int);
$my_dean_stmt->execute();
$my_dept_dean = $my_dean_stmt->get_result()->fetch_assoc();

// Check if this faculty member has evaluated their Dean for active period
$dean_eval_data = null;
$is_dean_evaluated = false;
if ($my_dept_dean && $active_id) {
    $chk_d = $conn->prepare("SELECT id, rating, feedback, created_at FROM evaluations WHERE student_id = ? AND faculty_id = ? AND target_name = 'Dean' AND academic_period_id = ?");
    $dean_user_id = (int)$my_dept_dean['id'];
    $chk_d->bind_param("iii", $faculty_id, $dean_user_id, $active_id);
    $chk_d->execute();
    $dean_eval_data = $chk_d->get_result()->fetch_assoc();
    $is_dean_evaluated = !empty($dean_eval_data);
}

$recent_avg_stmt = $conn->prepare("SELECT AVG(rating) FROM (SELECT rating FROM evaluations WHERE faculty_id = ? AND academic_period_id = ? ORDER BY created_at DESC LIMIT 10) as sub");
$recent_avg_stmt->bind_param("ii", $faculty_id, $active_id);
$recent_avg_stmt->execute();
$recent_avg_res = $recent_avg_stmt->get_result()->fetch_row();
$recent_avg = $recent_avg_res ? $recent_avg_res[0] : 0;

$strengths = [];
$weaknesses = [];
$cat_scores_stmt = $conn->prepare("SELECT q.category, AVG(r.rating) as score FROM evaluation_responses r 
                           JOIN evaluation_questions q ON r.question_id = q.id 
                           JOIN evaluations e ON r.evaluation_id = e.id 
                           WHERE e.faculty_id = ? AND e.academic_period_id = ?
                           GROUP BY q.category ORDER BY score DESC");
$cat_scores_stmt->bind_param("ii", $faculty_id, $active_id);
$cat_scores_stmt->execute();
$cat_scores_result = $cat_scores_stmt->get_result();
while($row = $cat_scores_result->fetch_assoc()) {
    if ($row['score'] >= 4.0) {
        $strengths[] = $row['category'];
    } elseif ($row['score'] < 3.0) {
        $weaknesses[] = $row['category'];
    }
}

$fac_visible_row = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'faculty_results_visible'")->fetch_assoc();
$fac_visible = $fac_visible_row ? $fac_visible_row['setting_value'] : 'hidden';

// Department Rank: rank this faculty within their dept
$rank_stmt = $conn->prepare("
    SELECT ranked.rank_pos FROM (
        SELECT faculty_id, RANK() OVER (ORDER BY AVG(rating) DESC) as rank_pos
        FROM evaluations
        WHERE academic_period_id = ?
        GROUP BY faculty_id
    ) ranked
    WHERE ranked.faculty_id = ?
");
$dept_rank = 'N/A';
if ($rank_stmt) {
    $rank_stmt->bind_param("ii", $active_id, $faculty_id);
    $rank_stmt->execute();
    $rank_result = $rank_stmt->get_result()->fetch_row();
    $dept_rank = $rank_result ? '#' . $rank_result[0] : 'N/A';
}

// Previous period avg for trend comparison
$prev_period_res = $conn->query("SELECT id FROM academic_periods WHERE is_active = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$prev_period_id = $prev_period_res['id'] ?? 0;
$prev_avg_stmt = $conn->prepare("SELECT AVG(rating) FROM evaluations WHERE faculty_id = ? AND academic_period_id = ?");
$prev_avg = 0;
if ($prev_avg_stmt && $prev_period_id) {
    $prev_avg_stmt->bind_param("ii", $faculty_id, $prev_period_id);
    $prev_avg_stmt->execute();
    $prev_avg_res = $prev_avg_stmt->get_result()->fetch_row();
    $prev_avg = ($prev_avg_res && $prev_avg_res[0] !== null) ? round((float)$prev_avg_res[0], 2) : 0;
}
$trend_diff = round(((float)($avg_rating ?? 0)) - $prev_avg, 2);
$trend_icon = $trend_diff >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
$trend_color = $trend_diff >= 0 ? '#10b981' : '#ef4444';

include 'process_sentiment.php';

// Campus Facilities & Services Configuration
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
    $fac_eval_chk = $conn->prepare("SELECT target_name FROM evaluations WHERE student_id = ? AND academic_period_id = ? AND faculty_id IS NULL");
    $fac_eval_chk->bind_param("ii", $faculty_id, $active_id);
    $fac_eval_chk->execute();
    $fac_eval_res = $fac_eval_chk->get_result();
    while ($r = $fac_eval_res->fetch_assoc()) {
        $evaluated_targets[$r['target_name']] = true;
    }
}
$completed_facilities_count = count($evaluated_targets);
$total_facilities_count = count($facility_services);
$facilities_pct = $total_facilities_count > 0 ? round(($completed_facilities_count / $total_facilities_count) * 100) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">

    <div style="display: flex; min-height: 100vh; position: relative;">
        <?php $active_page_faculty = 'dashboard'; $mobile_page_title = 'Faculty Dashboard'; include 'faculty_sidebar.php'; ?>

        <main class="main-content">
            <!-- Header Bar -->
            <div class="header-bar-modern">
                <div style="display: flex; align-items: center; gap: 12px;">

                    <div>
                        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 2px;">Faculty Dashboard</h1>
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
                    <input type="text" id="dashboardSearch" placeholder="Search dashboard, history..." onkeyup="filterDashboard()">
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
                            <div style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Faculty</div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); margin-left: 2px;"></i>
                    </div>

                    <!-- Faculty Profile Dropdown Menu -->
                    <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                        <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 3px;">Faculty / Instructor</div>
                        </div>
                        <div style="padding: 6px 0;">
                            <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                            </a>
                            <a href="faculty_enrollment.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-graduate" style="color:#6366f1; width:16px;"></i> Subject Enrollment
                            </a>
                            <a href="dashboard_faculty.php#analytics" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-chart-bar" style="color:#10b981; width:16px;"></i> Analytics & Feedback
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

        <?php if ($fac_visible == 'hidden'): ?>
            <div class="card" style="text-align: center; padding: 60px 20px; border-top: 5px solid #f59e0b;">
                <i class="fas fa-lock" style="font-size: 60px; color: #cbd5e1; margin-bottom: 20px; display: inline-block; animation: float 3s ease-in-out infinite;"></i>
                <h2 style="margin-bottom: 10px;">Results are Currently Locked</h2>
                <p style="color: var(--text-muted); max-width: 500px; margin: 0 auto;">
                    The administration has temporarily restricted access to the evaluation results (e.g., pending grade encoding). You will be able to view your analytics once the results are released.
                </p>
            </div>
        <?php else: ?>


        <!-- Modern Stat Cards -->
        <div class="modern-stat-grid">
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Overall Rating</p>
                    <h3 style="color: #f59e0b;"><?php echo number_format((float)($avg_rating ?? 0), 2); ?> ★</h3>
                </div>
                <div class="stat-icon orange"><i class="fas fa-star"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Total Feedback</p>
                    <h3><?php echo $total_evals; ?></h3>
                </div>
                <div class="stat-icon blue"><i class="fas fa-comments"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Enrolled Students</p>
                    <h3 style="color: #10b981;"><?php echo $total_enrolled_students; ?></h3>
                </div>
                <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Teaching Subjects</p>
                    <h3 style="color: var(--accent);"><?php echo $total_teaching_classes; ?></h3>
                </div>
                <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Standing</p>
                    <h3 style="font-size: 16px;"><?php echo $status_msg; ?></h3>
                </div>
                <div class="stat-icon <?php echo ($avg_rating >= $dept_avg) ? 'green' : 'red'; ?>"><i class="fas <?php echo ($avg_rating >= $dept_avg) ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i></div>
            </div>
        </div>

        <!-- My Teaching Classes & Student Enrollment Section -->
        <div id="my-classes" class="card-modern" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-chalkboard-teacher" style="color: var(--accent);"></i> My Teaching Subjects & Student Enrollment
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                        Manage your student rosters and enroll students into your subjects for <strong><?php echo htmlspecialchars($period_label); ?></strong>.
                    </p>
                </div>
                <a href="faculty_enrollment.php" class="login-btn" style="width: auto; padding: 9px 18px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; box-shadow: 0 4px 12px rgba(132, 90, 223, 0.25);">
                    <i class="fas fa-user-plus"></i> Open Enrollment Portal
                </a>
            </div>

            <?php if (!empty($my_teaching_classes)): ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Subject Code</th>
                            <th style="min-width: 220px;">Description</th>
                            <th style="min-width: 130px;">Year & Section</th>
                            <th style="min-width: 150px;">Enrolled Students</th>
                            <th style="text-align: right; min-width: 160px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_teaching_classes as $tc): ?>
                        <tr>
                            <td>
                                <strong style="font-size: 14.5px; color: var(--accent);"><?php echo htmlspecialchars($tc['subject_code']); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($tc['subject_description']); ?></strong>
                            </td>
                            <td>
                                <span class="category-badge"><?php echo htmlspecialchars($tc['year_level'] ?? '1st Year'); ?></span>
                                <span class="category-badge" style="background:#eef2ff; color:#4338ca;"><?php echo htmlspecialchars($tc['section'] ?? 'Sec A'); ?></span>
                            </td>
                            <td>
                                <span style="background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 800; padding: 5px 12px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fas fa-users"></i> <?php echo $tc['enrolled_count']; ?> Students
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <a href="faculty_enrollment.php?class_id=<?php echo $tc['class_id']; ?>" class="btn-action btn-action-text" style="width: auto !important; min-width: fit-content !important; height: auto !important; min-height: 32px !important; background: var(--accent); color: white; padding: 7px 15px !important; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex !important; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap !important; box-shadow: 0 2px 6px rgba(132, 90, 223, 0.25);">
                                    <i class="fas fa-user-plus"></i> Enroll Students
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 35px 20px; color: var(--text-muted);">
                <i class="fas fa-book-open" style="font-size: 32px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                No subjects are assigned to you for the active period. Please coordinate with your Dean.
            </div>
            <?php endif; ?>
        </div>

        <!-- College Dean & Academic Leadership Evaluation Section -->
        <div id="dean-eval" class="card-modern" style="margin-bottom: 24px; position: relative; overflow: hidden; border-left: 5px solid var(--accent);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-user-tie" style="color: var(--accent);"></i> College Dean Evaluation (Academic Leadership Review)
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                        Evaluate your College Dean's academic governance, support for faculty welfare, and departmental leadership for <strong><?php echo htmlspecialchars($period_label); ?></strong>.
                    </p>
                </div>
                
                <?php if ($is_dean_evaluated): ?>
                    <span style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 800; padding: 7px 16px; border-radius: 20px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(16,185,129,0.15);">
                        <i class="fas fa-check-circle"></i> Evaluated (<?php echo number_format((float)$dean_eval_data['rating'], 2); ?> ★)
                    </span>
                <?php else: ?>
                    <span style="background: rgba(245, 158, 11, 0.15); color: #d97706; font-weight: 800; padding: 7px 16px; border-radius: 20px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-clock"></i> Evaluation Pending
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($my_dept_dean): 
                $d_avatar = $my_dept_dean['avatar'] ?? 'uploads/avatars/default.png';
                if (!file_exists($d_avatar)) $d_avatar = 'uploads/avatars/default.png';
            ?>
            <div style="background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <img src="<?php echo htmlspecialchars($d_avatar); ?>" alt="Dean" style="width: 58px; height: 58px; border-radius: 50%; object-fit: cover; border: 2.5px solid var(--accent); box-shadow: 0 3px 10px rgba(132, 90, 223, 0.2);">
                    <div>
                        <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--accent); letter-spacing: 0.5px;">
                            College Dean
                        </div>
                        <h4 style="margin: 2px 0 3px 0; font-size: 17px; font-weight: 800; color: var(--text-main);">
                            <?php echo htmlspecialchars($my_dept_dean['full_name']); ?>
                        </h4>
                        <div style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">
                            <?php echo htmlspecialchars($my_dept_dean['dept_alias'] ?? 'Department'); ?> — <?php echo htmlspecialchars($my_dept_dean['dept_name'] ?? 'College'); ?>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <?php if ($is_dean_evaluated): ?>
                        <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                            Submitted <?php echo date('M j, Y', strtotime($dean_eval_data['created_at'])); ?>
                        </span>
                        <a href="evaluate_dean.php?dean_id=<?php echo $my_dept_dean['id']; ?>" class="btn-action btn-action-text" style="width: auto !important; min-width: fit-content !important; height: auto !important; min-height: 34px !important; background: rgba(132, 90, 223, 0.12); color: var(--accent); border: 1px solid rgba(132, 90, 223, 0.3); padding: 8px 18px !important; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex !important; align-items: center; gap: 6px; font-size: 13px; white-space: nowrap !important;">
                            <i class="fas fa-edit"></i> Edit / Review Evaluation
                        </a>
                    <?php else: ?>
                        <a href="evaluate_dean.php?dean_id=<?php echo $my_dept_dean['id']; ?>" class="login-btn" style="width: auto; padding: 10px 22px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; box-shadow: 0 4px 14px rgba(132, 90, 223, 0.3);">
                            <i class="fas fa-star"></i> Evaluate Dean Now <i class="fas fa-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                <i class="fas fa-user-slash" style="font-size: 32px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                No active Dean assigned to your department. Please contact the administrator.
            </div>
            <?php endif; ?>
        </div>

        <!-- Additional Stats Row -->
        <div class="modern-stat-grid">
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Top Performing Area</p>
                    <h3 style="font-size: 16px; color: var(--accent);"><?php echo $top_cat_data['category'] ?? 'N/A'; ?></h3>
                </div>
                <div class="stat-icon purple"><i class="fas fa-trophy"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Recent Trend (Last 10)</p>
                    <h3><?php echo number_format((float)($recent_avg ?? 0), 2); ?> <span style="font-size: 13px; color: var(--text-muted);">avg</span></h3>
                </div>
                <div class="stat-icon indigo"><i class="fas fa-chart-line"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>School-wide Rank</p>
                    <h3 style="color: #f59e0b;"><?php echo $dept_rank; ?></h3>
                </div>
                <div class="stat-icon orange"><i class="fas fa-medal"></i></div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>vs. Previous Period</p>
                    <h3 style="color: <?php echo $trend_color; ?>; font-size: 16px;">
                        <i class="fas <?php echo $trend_icon; ?>"></i>
                        <?php echo $trend_diff >= 0 ? '+' : ''; echo number_format($trend_diff, 2); ?>
                    </h3>
                </div>
                <div class="stat-icon <?php echo $trend_diff >= 0 ? 'green' : 'red'; ?>"><i class="fas fa-chart-bar"></i></div>
            </div>
        </div>

        <div class="grid-2" style="margin-bottom: 20px;">
            <div class="card-modern">
                <h3 style="margin-bottom: 12px;"><i class="fas fa-award" style="color:#22c55e; margin-right: 6px;"></i> Key Strengths</h3>
                <ul style="margin-top: 10px; list-style: none; padding: 0;">
                    <?php foreach($strengths as $s): ?>
                        <li style="margin-bottom: 8px; font-size: 14px;"><i class="fas fa-check-circle" style="color:#22c55e; margin-right: 6px;"></i> <?php echo $s; ?></li>
                    <?php endforeach; if(empty($strengths)) echo "<li style='color:var(--text-muted);'>Keep working to build strengths!</li>"; ?>
                </ul>
            </div>
            <div class="card-modern">
                <h3 style="margin-bottom: 12px;"><i class="fas fa-lightbulb" style="color:#ef4444; margin-right: 6px;"></i> Areas for Improvement</h3>
                <ul style="margin-top: 10px; list-style: none; padding: 0;">
                    <?php foreach($weaknesses as $w): ?>
                        <li style="margin-bottom: 8px; font-size: 14px;"><i class="fas fa-exclamation-circle" style="color:#ef4444; margin-right: 6px;"></i> <?php echo $w; ?></li>
                    <?php endforeach; if(empty($weaknesses)) echo "<li style='color:var(--text-muted);'>Great job! No major weaknesses identified.</li>"; ?>
                </ul>
            </div>
        </div>

        <?php
            // --- Data for Charts ---

            // 1. Performance by Category (for Bar Chart)
            $category_data_stmt = $conn->prepare("
                SELECT q.category, AVG(r.rating) as score 
                FROM evaluation_responses r 
                JOIN evaluation_questions q ON r.question_id = q.id 
                JOIN evaluations e ON r.evaluation_id = e.id 
                WHERE e.faculty_id = ? AND e.academic_period_id = ?
                GROUP BY q.category ORDER BY q.category ASC
            ");
            $category_data_stmt->bind_param("ii", $faculty_id, $active_id);
            $category_data_stmt->execute();
            $category_result = $category_data_stmt->get_result();
            $category_chart_labels = [];
            $category_chart_data = [];
            while($row = $category_result->fetch_assoc()) {
                $category_chart_labels[] = $row['category'];
                $category_chart_data[] = round($row['score'], 2);
            }

            $perf_labels = ['Teaching', 'Professionalism', 'Assessment', 'Support'];
            $my_perf_data = [];
            $school_perf_data = [];
            $comparison_stmt = $conn->prepare("
                SELECT 
                    q.category,
                    AVG(CASE WHEN e.faculty_id = ? THEN r.rating ELSE NULL END) as my_score,
                    AVG(r.rating) as school_score
                FROM evaluation_responses r
                JOIN evaluation_questions q ON r.question_id = q.id
                JOIN evaluations e ON r.evaluation_id = e.id
                WHERE e.academic_period_id = ? AND q.category IN ('Teaching', 'Professionalism', 'Assessment', 'Support')
                GROUP BY q.category
            ");
            $comparison_stmt->bind_param("ii", $faculty_id, $active_id);
            $comparison_stmt->execute();
            $comparison_result = $comparison_stmt->get_result();
            $comparison_data = [];
            foreach ($perf_labels as $label) {
                $comparison_data[$label] = [
                    'my_score' => 0,
                    'school_score' => 0
                ];
            }
            while ($row = $comparison_result->fetch_assoc()) {
                if (isset($comparison_data[$row['category']])) {
                    $comparison_data[$row['category']] = [
                        'my_score' => round($row['my_score'] ?? 0, 2),
                        'school_score' => round($row['school_score'] ?? 0, 2)
                    ];
                }
            }
            foreach($perf_labels as $label) {
                $my_perf_data[] = $comparison_data[$label]['my_score'];
                $school_perf_data[] = $comparison_data[$label]['school_score'];
            }
        ?>

        <div class="card">
            <h3><i class="fas fa-balance-scale"></i> My Performance vs. School Average</h3>
            <p style="color:var(--text-muted); font-size:13px; margin-bottom:15px;">
                This chart compares your average score in key categories against the overall average of all faculty for the current period.
            </p>
            <?php if ($total_evals > 0): ?>
            <div style="height: 300px; width: 100%;">
                <canvas id="comparisonChart"></canvas>
            </div>
            <?php else: ?>
            <p style='color:var(--text-muted); font-size: 14px; text-align:center; padding: 20px;'>No evaluation data available to generate comparison chart.</p>
            <?php endif; ?>
        </div>

        <div id="analytics" class="grid-2">
            <div class="card">
                <h3><i class="fas fa-chart-bar"></i> Performance by Category</h3>
                <?php if (!empty($category_chart_labels)): ?>
                <canvas id="categoryChart"></canvas>
                <?php else: ?>
                <p style='color:var(--text-muted); font-size: 14px; text-align:center; padding: 20px;'>No data to display.</p>
                <?php endif; ?>
            </div>
            <?php
                $dist_data = array_fill(1, $max_rating, 0);
                $dist_stmt = $conn->prepare("
                    SELECT r.rating, COUNT(*) as count 
                    FROM evaluation_responses r 
                    JOIN evaluations e ON r.evaluation_id = e.id 
                    WHERE e.faculty_id = ? AND e.academic_period_id = ?
                    GROUP BY r.rating
                ");
                $dist_stmt->bind_param("ii", $faculty_id, $active_id);
                $dist_stmt->execute();
                $dist_result = $dist_stmt->get_result();
                while ($row = $dist_result->fetch_assoc()) {
                    $dist_data[$row['rating']] = $row['count'];
                }
            ?>
            <div class="card">
                <h3><i class="fas fa-signal"></i> Rating Distribution</h3>
                <canvas id="distributionChart"></canvas>
            </div>
        </div>

        <div id="feedback" class="card" style="margin-top:20px;">
            <h3><i class="fas fa-comments"></i> Recent Student Comments</h3>
                <div style="margin-top:15px;">
                    <?php
                    $comments_stmt = $conn->prepare("SELECT feedback, rating FROM evaluations WHERE faculty_id = ? AND academic_period_id = ? AND feedback IS NOT NULL AND feedback != '' ORDER BY created_at DESC LIMIT 5");
                    $comments_stmt->bind_param("ii", $faculty_id, $active_id);
                    $comments_stmt->execute();
                    $comments_result = $comments_stmt->get_result();
                    if ($comments_result->num_rows > 0) {
                        while($c = $comments_result->fetch_assoc()) {
                            $sentiment = analyzeSentiment($c['feedback'], $c['rating']);
                            $color = $sentiment == 'Positive' ? '#10b981' : '#ef4444';
                            echo "<div style='padding:10px; border-bottom:1px solid var(--border-color); font-size:14px; color: var(--text-main);'>
                                    <span style='color:$color; font-weight:bold;'>[Sentiment: {$sentiment}]</span> " . htmlspecialchars($c['feedback']) . "
                                  </div>";
                        }
                    } else {
                        echo "<p style='color:var(--text-muted); font-size: 14px; text-align:center; padding: 20px;'>No comments have been submitted yet.</p>";
                    }
                    ?>
                </div>
        </div>
        <?php endif; ?>

        <!-- MCNP-ISAP Campus Facilities Evaluation Section -->
        <div id="facilities-section" class="card-modern" style="margin-top: 25px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
                <div>
                    <h3 style="font-size: 18px; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-university" style="color: var(--accent);"></i> Campus Facilities & Services Evaluation
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0 0;">
                        As a faculty member, please share your assessment and feedback regarding campus facilities and administrative offices to help improve institutional services.
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
            <i class="fas fa-chalkboard-teacher"></i> Faculty Portal · ID: <?php echo $_SESSION['username']; ?> · <?php echo $_SESSION['full_name']; ?>
        </footer>
        </main>

    </div>

    <?php if ($fac_visible != 'hidden'): ?>
    <script>
        // Comparison Chart
        const compCtx = document.getElementById('comparisonChart');
        if (compCtx) {
            new Chart(compCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($perf_labels); ?>,
                    datasets: [
                        {
                            label: 'My Score',
                            data: <?php echo json_encode($my_perf_data); ?>,
                            backgroundColor: 'rgba(132, 90, 223, 0.85)',
                            hoverBackgroundColor: '#845adf',
                            borderRadius: 4
                        },
                        {
                            label: 'School Average',
                            data: <?php echo json_encode($school_perf_data); ?>,
                            backgroundColor: 'rgba(245, 158, 11, 0.7)',
                            hoverBackgroundColor: '#f59e0b',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { beginAtZero: true, max: <?php echo $max_rating; ?>, grid: { color: 'rgba(0,0,0,0.04)' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
        // Category-wise Chart
        const catCtx = document.getElementById('categoryChart');
        if (catCtx) {
            new Chart(catCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($category_chart_labels); ?>,
                    datasets: [{
                        label: 'Score',
                        data: <?php echo json_encode($category_chart_data); ?>,
                        backgroundColor: 'rgba(132, 90, 223, 0.85)',
                        hoverBackgroundColor: '#845adf',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    scales: { 
                        y: { beginAtZero: true, max: <?php echo $max_rating; ?>, grid: { color: 'rgba(0,0,0,0.04)' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // Rating Distribution Chart
        const distCtx = document.getElementById('distributionChart');
        if (distCtx) {
            new Chart(distCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($dist_data)); ?>,
                    datasets: [{ 
                        label: 'Count', 
                        data: <?php echo json_encode(array_values($dist_data)); ?>, 
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4
                    }]
                },
                options: { 
                    responsive: true,
                    scales: { 
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } } 
                }
            });
        }
    </script>
    <?php endif; ?>
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

        // Search Dropdown Navigation Logic
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