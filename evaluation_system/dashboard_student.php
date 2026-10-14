<?php
session_start();
include 'db_connect.php';

$is_impersonating = isset($_SESSION['original_admin_id']);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') { header("Location: index.php"); exit(); }

$student_id = $_SESSION['id'];
$active_period = $conn->query("SELECT * FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;

// Get student's enrolled classes for the active period
$student_info_stmt = $conn->prepare("SELECT dept_id, course_id, school FROM users WHERE id = ?");
$student_info_stmt->bind_param("i", $student_id);
$student_info_stmt->execute();
$student_info = $student_info_stmt->get_result()->fetch_assoc();
$student_dept_id = (int)($student_info['dept_id'] ?? 0);
$student_course_id = (int)($student_info['course_id'] ?? 0);

// Ensure $_SESSION['school'] always matches the student's actual registered school
$actual_school = !empty($student_info['school']) ? strtolower(trim($student_info['school'])) : null;
if (!$actual_school) {
    $actual_school = ($student_dept_id <= 4 && $student_dept_id > 0) ? 'isap' : 'mcnp';
}
$_SESSION['school'] = $actual_school;
$_SESSION['dept_id'] = $student_dept_id;
$_SESSION['course_id'] = $student_course_id;

$my_classes_stmt = $conn->prepare(
    "SELECT 
        c.id as class_id, f.id as faculty_id, f.full_name as faculty_name,
        s.id as subject_id, s.subject_code, s.subject_description,
        (SELECT COUNT(*) FROM evaluations WHERE student_id = ? AND faculty_id = f.id AND subject_id = s.id AND academic_period_id = ?) as is_evaluated
    FROM classes c
    JOIN users f ON c.faculty_id = f.id
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.period_id = ? 
      AND (
          (
              c.id IN (SELECT class_id FROM enrollments WHERE student_id = ?)
              AND (? = 0 OR s.course_id = ? OR s.course_id IS NULL)
          )
          OR (
              NOT EXISTS (SELECT 1 FROM enrollments WHERE student_id = ?)
              AND (
                  (? > 0 AND s.course_id = ?)
                  OR (? = 0 AND s.course_id IS NULL AND s.dept_id = ?)
              )
          )
      )
    ORDER BY s.subject_code ASC"
);
$my_classes_stmt->bind_param(
    "iiiiiiiiiii",
    $student_id, $active_id, $active_id,
    $student_id, $student_course_id, $student_course_id,
    $student_id,
    $student_course_id, $student_course_id,
    $student_course_id, $student_dept_id
);
$my_classes_stmt->execute();
$my_classes_result = $my_classes_stmt->get_result();
$my_classes_array = $my_classes_result->fetch_all(MYSQLI_ASSOC);

$total_classes = count($my_classes_array);
$completed_count = 0;
foreach ($my_classes_array as $class) {
    if ($class['is_evaluated']) {
        $completed_count++;
    }
}
$progress_percentage = $total_classes > 0 ? round(($completed_count / $total_classes) * 100) : 0;

// MCNP-ISAP Campus Services List
$services = [
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
$total_services = count($services);

// Fetch all evaluated services for this student and period in 1 clean query
$evaluated_services_targets = [];
if ($active_id) {
    $std_eval_chk = $conn->prepare("SELECT target_name FROM evaluations WHERE student_id = ? AND academic_period_id = ? AND faculty_id IS NULL");
    $std_eval_chk->bind_param("ii", $student_id, $active_id);
    $std_eval_chk->execute();
    $std_eval_res = $std_eval_chk->get_result();
    while ($r = $std_eval_res->fetch_assoc()) {
        $evaluated_services_targets[$r['target_name']] = true;
    }
}
$completed_services_count = count($evaluated_services_targets);
$services_progress_percentage = $total_services > 0 ? round(($completed_services_count / $total_services) * 100) : 0;

$total_all = $total_classes + $total_services;
$completed_all = $completed_count + $completed_services_count;
$overall_progress_percentage = $total_all > 0 ? round(($completed_all / $total_all) * 100) : 0;


// Calendar events
$calendar_events = [];

// Recent evaluations data
$eval_query = $conn->prepare("
    SELECT e.created_at, e.rating, e.target_name, f.full_name AS teacher_name, s.subject_code 
    FROM evaluations e 
    LEFT JOIN users f ON e.faculty_id = f.id 
    LEFT JOIN subjects s ON e.subject_id = s.id 
    WHERE e.student_id = ?
    ORDER BY e.created_at DESC LIMIT 5
");
$eval_query->bind_param("i", $student_id);
$eval_query->execute();
$eval_result = $eval_query->get_result();

// Data for Student's Rating History Chart
$history_query = $conn->prepare("
    SELECT DATE_FORMAT(e.created_at, '%b %d') as eval_date, e.rating
    FROM evaluations e
    WHERE e.student_id = ? AND e.academic_period_id = ?
    ORDER BY e.created_at ASC LIMIT 15
");
$history_query->bind_param("ii", $student_id, $active_id);
$history_query->execute();
$history_result = $history_query->get_result();

$history_labels = [];
$history_data = [];
while ($row = $history_result->fetch_assoc()) {
    $history_labels[] = $row['eval_date'];
    $history_data[] = $row['rating'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">

        <?php $active_page_student = 'dashboard'; $mobile_page_title = 'Student Dashboard'; include 'student_sidebar.php'; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'already_evaluated'): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Already Evaluated',
                    text: 'You have already evaluated this faculty and class for the current academic period.',
                    confirmButtonColor: '#0d2158'
                });
            }
        });
        </script>
        <?php endif; ?>

        <main class="main-content">

            <!-- Header Bar -->
            <div class="header-bar-modern">
                <div style="display: flex; align-items: center; gap: 12px;">

                    <div>
                        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 2px;">Student Dashboard</h1>
                        <p style="font-size: 13px; color: var(--text-muted); font-weight: 500; margin: 0;"><?php echo date('l, F j, Y'); ?></p>
                    </div>
                    <!-- Static Period Badge -->
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: rgba(132, 90, 223, 0.08); border: 1px solid rgba(132, 90, 223, 0.2); border-radius: 10px; padding: 5px 12px; font-size: 12px; font-weight: 700; color: #845adf;">
                        <i class="fas fa-graduation-cap"></i>
                        <?php echo ($active_period['semester'] ?? 'No Period') . ' ' . ($active_period['school_year'] ?? ''); ?>
                    </span>
                </div>
                
                <div class="search-box-modern" style="position: relative;">
                    <i class="fas fa-search" style="color:var(--text-muted);"></i>
                    <input type="text" id="dashboardSearch" placeholder="Search evaluations, history..." onkeyup="filterDashboard()">
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
                            <div style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Student</div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); margin-left: 2px;"></i>
                    </div>

                    <!-- Student Profile Dropdown Menu -->
                    <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                        <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 3px;">Student</div>
                        </div>
                        <div style="padding: 6px 0;">
                            <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                            </a>
                            <a href="dashboard_student.php#classes-section" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-chalkboard-teacher" style="color:#6366f1; width:16px;"></i> Evaluate Instructors
                            </a>
                            <a href="dashboard_student.php#evaluation-section" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-university" style="color:#10b981; width:16px;"></i> Evaluate Facilities
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



            <!-- Main 2-Column Grid -->
            <div class="dashboard-grid-layout">

                <!-- LEFT COLUMN -->
                <div>
                    <!-- Progress Bar Card -->
                    <div class="card-modern">
                        <h3 style="font-size:16px; font-weight:700; color:var(--text-main); margin-bottom: 20px;"><i class="fas fa-tasks" style="color: var(--accent); margin-right: 8px;"></i>Evaluation Progress Breakdown</h3>
                        
                        <!-- Classes progress -->
                        <div style="margin-bottom: 18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span style="font-size: 13px; font-weight: 600; color: var(--text-main);">Academic Instructors</span>
                                <span style="font-size: 13px; font-weight: 700; color: var(--accent);"><?php echo $progress_percentage; ?>%</span>
                            </div>
                            <div style="height:8px; background: var(--border-color); border-radius: 4px; overflow:hidden;">
                                <div style="width: <?php echo $progress_percentage; ?>%; background: var(--accent); height:100%; border-radius:4px; transition: width 0.8s ease-out;"></div>
                            </div>
                            <small style="font-size:11.5px; color:var(--text-muted); display:block; margin-top:4px;">
                                Evaluated <strong><?php echo $completed_count; ?></strong> / <strong><?php echo $total_classes; ?></strong> class faculty.
                            </small>
                        </div>
                        
                        <!-- Services progress -->
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span style="font-size: 13px; font-weight: 600; color: var(--text-main);">Campus Facilities & Services</span>
                                <span style="font-size: 13px; font-weight: 700; color: #10b981;"><?php echo $services_progress_percentage; ?>%</span>
                            </div>
                            <div style="height:8px; background: var(--border-color); border-radius: 4px; overflow:hidden;">
                                <div style="width: <?php echo $services_progress_percentage; ?>%; background: #10b981; height:100%; border-radius:4px; transition: width 0.8s ease-out;"></div>
                            </div>
                            <small style="font-size:11.5px; color:var(--text-muted); display:block; margin-top:4px;">
                                Evaluated <strong><?php echo $completed_services_count; ?></strong> / <strong><?php echo $total_services; ?></strong> campus services.
                            </small>
                        </div>
                    </div>

                    <!-- Classes Table -->
                    <div class="card-modern" id="classes-section">
                        <h3 style="margin-bottom: 16px; font-size: 16px; font-weight: 700;"><i class="fas fa-chalkboard-teacher" style="color: var(--accent); margin-right: 8px;"></i>My Classes to Evaluate</h3>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%;">
                        <table class="table-star" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Faculty</th>
                                    <th>Subject</th>
                                    <th style="text-align:right; white-space:nowrap; width: 140px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($total_classes > 0) {
                                    foreach ($my_classes_array as $class) {
                                        echo "<tr>";
                                        echo "<td><strong>" . htmlspecialchars($class['faculty_name']) . "</strong></td>";
                                        echo "<td><span class='category-badge'>" . htmlspecialchars($class['subject_code']) . "</span> " . htmlspecialchars($class['subject_description']) . "</td>";
                                        echo "<td style='text-align:right; white-space:nowrap;'>";
                                        if ($class['is_evaluated']) {
                                            echo "<span class='category-badge' style='background:#f0fdf4; color:#16a34a; white-space:nowrap;'><i class='fas fa-check-circle'></i> Completed</span>";
                                        } else {
                                            echo "<a href='evaluation_form.php?faculty_id={$class['faculty_id']}&subject_id={$class['subject_id']}' class='btn-evaluate-action'>Evaluate Now <i class='fas fa-arrow-right' style='font-size:10px;'></i></a>";
                                        }
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='3' style='text-align:center; color:var(--text-muted); padding:24px;'>You are not enrolled in any classes for this period.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <!-- Evaluation History Chart -->
                    <div class="card-modern">
                        <h3 style="margin-bottom: 16px; font-size: 16px; font-weight: 700;"><i class="fas fa-chart-line" style="color: var(--accent); margin-right: 8px;"></i>My Evaluation History</h3>
                        <?php if ($completed_count > 0): ?>
                            <div style="height: 240px; width: 100%;">
                                <canvas id="evalHistoryChart"></canvas>
                            </div>
                        <?php else: ?>
                            <p style='text-align:center; color:var(--text-muted); padding:24px;'>No evaluation history to display yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div>
                    <!-- Recent Evaluations -->
                    <div class="card-modern">
                        <h3 style="margin-bottom: 14px; font-size: 16px; font-weight: 700;"><i class="fas fa-history" style="color: var(--accent); margin-right: 8px;"></i>Recent Activity</h3>
                        <?php
                        if ($eval_result->num_rows > 0) {
                            while ($row = $eval_result->fetch_assoc()) {
                                $date = date('M d', strtotime($row['created_at']));
                                $rating = number_format($row['rating'], 1);
                                $is_faculty = $row['target_name'] == 'Faculty';
                                $badge_class = $is_faculty ? 'info' : 'success';
                                $badge_icon = $is_faculty ? 'fa-user-tie' : 'fa-building';
                                $name = $is_faculty ? htmlspecialchars($row['teacher_name'] ?? 'N/A') : htmlspecialchars($row['target_name']);
                                echo "<div class='activity-item-modern'>
                                    <div class='activity-item-badge {$badge_class}'><i class='fas {$badge_icon}'></i></div>
                                    <div style='flex:1;'>
                                        <div style='font-size:13px; font-weight:700; color:var(--text-main);'>{$name}</div>
                                        <div style='font-size:12px; color:var(--text-muted);'>{$date} · Rating: {$rating} ⭐</div>
                                    </div>
                                </div>";
                            }
                        } else {
                            echo "<p style='text-align:center; color:var(--text-muted); padding:16px; font-size:13px;'>No evaluations submitted yet.</p>";
                        }
                        ?>
                    </div>

                    <!-- Academic Period Info -->
                    <div class="card-modern" style="background: linear-gradient(135deg, var(--sidebar-active-bg), var(--card-bg)); border: 1px solid var(--sidebar-active-bg);">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                            <div class="stat-icon indigo" style="width:42px; height:42px; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-calendar-check"></i>
                            </div>
                            <div>
                                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Current Period</div>
                                <div style="font-size: 15px; font-weight: 800; color: var(--text-main);"><?php echo $active_period['semester'] ?? 'No active period'; ?></div>
                            </div>
                        </div>
                        <?php if (isset($active_period['school_year'])): ?>
                        <div style="font-size: 13px; color: var(--text-muted); margin-left: 54px;">SY <?php echo $active_period['school_year']; ?></div>
                        <?php endif; ?>

                        <!-- Interactive Mini Calendar -->
                        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 15px 0;">
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <span id="cal-month-year" style="font-size: 13px; font-weight: 700; color: var(--text-main);"></span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" onclick="prevMonth()" aria-label="Previous Month" style="background: var(--sidebar-active-bg); border: 1px solid var(--border-color); color: var(--text-main); cursor: pointer; padding: 6px 11px; border-radius: 8px; min-width: 32px; min-height: 32px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;"><i class="fas fa-chevron-left" style="font-size: 11px;"></i></button>
                                <button type="button" onclick="nextMonth()" aria-label="Next Month" style="background: var(--sidebar-active-bg); border: 1px solid var(--border-color); color: var(--text-main); cursor: pointer; padding: 6px 11px; border-radius: 8px; min-width: 32px; min-height: 32px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;"><i class="fas fa-chevron-right" style="font-size: 11px;"></i></button>
                            </div>
                        </div>

                        <div class="cal-weekdays-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-size: 10px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                        </div>

                        <div id="cal-days-grid" class="cal-days-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center;">
                            <!-- Days will be dynamically loaded via JS -->
                        </div>

                        <!-- Mini Calendar Events List -->
                        <div id="cal-events-display" style="margin-top: 15px; font-size: 11px; padding: 10px; border-radius: 8px; background: rgba(0, 0, 0, 0.02); border: 1px solid var(--border-color); display: none;">
                            <div style="font-weight: 700; color: var(--text-main); margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
                                <span id="cal-events-date-label"></span>
                                <button type="button" onclick="document.getElementById('cal-events-display').style.display='none'" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:10px;"><i class="fas fa-times"></i></button>
                            </div>
                            <div id="cal-events-list"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MCNP-ISAP Campus Services Evaluation Section (Required) -->
            <div id="evaluation-section" class="card-modern" style="margin-top: 8px;">
                <div id="facilities-tab">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 6px;">
                        <h3 style="font-size: 16px; font-weight: 700; margin: 0;">
                            <i class="fas fa-university" style="color: var(--accent); margin-right: 8px;"></i>MCNP-ISAP Campus Services
                        </h3>
                        <span style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 6px; letter-spacing: 0.5px;">
                            <i class="fas fa-exclamation-circle"></i> REQUIRED FOR ENROLLED STUDENTS
                        </span>
                    </div>
                    <p style="color:var(--text-muted); font-size: 13px; margin-bottom: 18px;">As an enrolled student at MCNP-ISAP, you are required to evaluate the school facilities and administrative offices. Please rate each campus service below.</p>
                    <div class="course-grid">
                        <?php foreach($services as $s):
                            $done = isset($evaluated_services_targets[$s['name']]);
                        ?>
                        <div class="course-card" <?php if(!$done): ?>onclick="window.location.href='evaluate_facility.php?target=<?php echo urlencode($s['name']); ?>&cat=<?php echo $s['cat']; ?>'" style="cursor: pointer;"<?php else: ?>style="cursor: default;"<?php endif; ?>>
                            <div class="course-icon" style="<?php echo $done ? 'background: rgba(34, 197, 94, 0.15); color: #22c55e;' : ''; ?>">
                                <i class="fas <?php echo $s['icon']; ?>"></i>
                            </div>
                            <div class="course-name"><?php echo htmlspecialchars($s['name']); ?></div>
                            <div class="course-desc">
                                <?php echo htmlspecialchars($s['desc']); ?>
                            </div>
                            <?php if($done): ?>
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
            </div>

            <footer>
                <i class="fas fa-laptop"></i> Student Portal · ID: <?php echo $_SESSION['username']; ?> · <?php echo $_SESSION['full_name']; ?>
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

        // Evaluation History Chart
        const historyCtx = document.getElementById('evalHistoryChart');
        if (historyCtx) {
            new Chart(historyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($history_labels); ?>,
                    datasets: [{
                        label: 'My Given Rating',
                        data: <?php echo json_encode($history_data); ?>,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.08)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        borderWidth: 2.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { beginAtZero: true, max: 5, grid: { color: 'rgba(0,0,0,0.04)' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

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

        // Mini Calendar Logic
        const calendarEvents = <?php echo json_encode($calendar_events); ?>;
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        let selectedDate = null;
        const today = new Date();

        function renderCalendar() {
            const monthYearText = document.getElementById('cal-month-year');
            const daysGrid = document.getElementById('cal-days-grid');
            if (!monthYearText || !daysGrid) return;

            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            monthYearText.textContent = months[currentMonth] + " " + currentYear;

            daysGrid.innerHTML = '';

            const firstDayIndex = new Date(currentYear, currentMonth, 1).getDay();
            const totalDays = new Date(currentYear, currentMonth + 1, 0).getDate();

            for (let i = 0; i < firstDayIndex; i++) {
                const emptyCell = document.createElement('span');
                emptyCell.className = 'cal-empty-cell';
                emptyCell.style.color = 'transparent';
                daysGrid.appendChild(emptyCell);
            }

            for (let day = 1; day <= totalDays; day++) {
                const dayCell = document.createElement('span');
                dayCell.textContent = day;
                dayCell.className = 'cal-day-cell';
                dayCell.style.borderRadius = '50%';
                dayCell.style.fontSize = '11.5px';
                dayCell.style.display = 'inline-flex';
                dayCell.style.alignItems = 'center';
                dayCell.style.justifyContent = 'center';
                dayCell.style.width = '28px';
                dayCell.style.height = '28px';
                dayCell.style.margin = '2px auto';
                dayCell.style.position = 'relative';
                dayCell.style.cursor = 'pointer';
                dayCell.style.transition = 'all 0.2s';

                const monthStr = String(currentMonth + 1).padStart(2, '0');
                const dayStr = String(day).padStart(2, '0');
                const dateStr = `${currentYear}-${monthStr}-${dayStr}`;

                // Filter events for this day
                const dayEvents = calendarEvents.filter(ev => ev.date === dateStr);

                // Highlight today
                const isToday = day === today.getDate() && currentMonth === today.getMonth() && currentYear === today.getFullYear();
                if (isToday) {
                    dayCell.classList.add('today');
                    dayCell.style.background = 'var(--accent, #3b82f6)';
                    dayCell.style.color = '#ffffff';
                    dayCell.style.fontWeight = '700';
                    dayCell.style.boxShadow = '0 2px 6px rgba(59, 130, 246, 0.4)';
                } else {
                    dayCell.style.color = 'var(--text-main)';
                }

                // Selected day border style
                if (selectedDate === dateStr) {
                    dayCell.classList.add('selected');
                    dayCell.style.border = '2.5px solid var(--accent-gold, #f59e0b)';
                }

                // If day has events, show a dot at the bottom and title popup
                if (dayEvents.length > 0) {
                    dayCell.classList.add('has-event');
                    const dot = document.createElement('span');
                    dot.style.position = 'absolute';
                    dot.style.bottom = '2px';
                    dot.style.width = '4px';
                    dot.style.height = '4px';
                    dot.style.borderRadius = '50%';
                    dot.style.background = isToday ? '#ffffff' : 'var(--accent-gold, #f59e0b)';
                    dayCell.appendChild(dot);

                    dayCell.title = dayEvents.map(e => e.title).join('\n');
                }

                // Event listener on click
                dayCell.addEventListener('click', () => {
                    selectedDate = dateStr;
                    renderCalendar(); // re-render to update selected day styling
                    showDayEvents(dateStr, dayEvents);
                });

                daysGrid.appendChild(dayCell);
            }
        }

        function showDayEvents(dateStr, events) {
            const displayDiv = document.getElementById('cal-events-display');
            const dateLabel = document.getElementById('cal-events-date-label');
            const listDiv = document.getElementById('cal-events-list');
            if (!displayDiv || !dateLabel || !listDiv) return;

            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            const formattedDate = new Date(dateStr).toLocaleDateString(undefined, options);

            dateLabel.textContent = "Events on " + formattedDate;
            listDiv.innerHTML = '';

            if (events.length > 0) {
                events.forEach(ev => {
                    const item = document.createElement('div');
                    item.style.marginBottom = '8px';
                    item.style.borderLeft = '3px solid var(--accent-gold, #f59e0b)';
                    item.style.paddingLeft = '8px';
                    item.style.textAlign = 'left';
                    
                    const title = document.createElement('div');
                    title.style.fontWeight = '700';
                    title.style.color = 'var(--text-main)';
                    title.style.fontSize = '12px';
                    title.textContent = ev.title;
                    
                    const content = document.createElement('div');
                    content.style.color = 'var(--text-muted)';
                    content.style.marginTop = '2px';
                    content.style.fontSize = '11px';
                    content.textContent = ev.content;

                    item.appendChild(title);
                    item.appendChild(content);
                    listDiv.appendChild(item);
                });
            } else {
                const item = document.createElement('div');
                item.style.color = 'var(--text-muted)';
                item.style.fontStyle = 'italic';
                item.style.textAlign = 'left';
                item.textContent = 'No events scheduled for this day.';
                listDiv.appendChild(item);
            }
            displayDiv.style.display = 'block';
        }

        window.prevMonth = function() {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            // Hide daily events panel when month changes
            const displayDiv = document.getElementById('cal-events-display');
            if (displayDiv) displayDiv.style.display = 'none';
            selectedDate = null;
            renderCalendar();
        };

        window.nextMonth = function() {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            // Hide daily events panel when month changes
            const displayDiv = document.getElementById('cal-events-display');
            if (displayDiv) displayDiv.style.display = 'none';
            selectedDate = null;
            renderCalendar();
        };

        document.addEventListener('DOMContentLoaded', renderCalendar);
        
        window.scrollToSection = function(id) {
            if (window.appScrollToSection) {
                window.appScrollToSection(id);
            } else {
                const element = document.getElementById(id);
                if (element) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

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