<?php 
session_start();
include 'db_connect.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

if (isset($_GET['toggle_status'])) {
    $new_status = $_GET['toggle_status'] == 'open' ? 'open' : 'closed';
    $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'evaluation_status'");
    $stmt->bind_param("s", $new_status);
    $stmt->execute();
    header("Location: dashboard_admin.php?msg=status_updated"); exit();
}

if (isset($_GET['toggle_faculty_visibility'])) {
    $new_status = $_GET['toggle_faculty_visibility'] == 'visible' ? 'visible' : 'hidden';
    $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'faculty_results_visible'");
    $stmt->bind_param("s", $new_status);
    $stmt->execute();
    header("Location: dashboard_admin.php?msg=faculty_visibility_updated");
    exit();
}



$active_period  = $conn->query("SELECT * FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id      = $active_period['id'] ?? 0;
$max_rating_val = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'")->fetch_assoc()['setting_value'] ?? 5;
$system_name    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'Faculty Evaluation System';
$min_feedback   = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_feedback_length'")->fetch_assoc()['setting_value'] ?? 0;
$eval_status    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'evaluation_status'")->fetch_assoc()['setting_value'] ?? 'closed';
$fac_visible    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'faculty_results_visible'")->fetch_assoc()['setting_value'] ?? 'hidden';

$theme_settings_q = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'theme_%'");
$theme_settings = [];
if ($theme_settings_q) { while($row = $theme_settings_q->fetch_assoc()){ $theme_settings[$row['setting_key']] = $row['setting_value']; } }
$theme_primary    = $theme_settings['theme_primary'] ?? '#283593';
$theme_accent     = $theme_settings['theme_accent'] ?? '#b71c1c';
$theme_background = $theme_settings['theme_background'] ?? '#f4f6f9';
$theme_dark_primary = $theme_settings['theme_dark_primary'] ?? '#1a237e';
$theme_dark_accent = $theme_settings['theme_dark_accent'] ?? '#e53935';
$theme_dark_background = $theme_settings['theme_dark_background'] ?? '#1a233a';
$theme_dark_card = $theme_settings['theme_dark_card'] ?? '#283147';
$theme_dark_text = $theme_settings['theme_dark_text'] ?? '#e8eaf6';

$periods_query = $conn->query("SELECT * FROM academic_periods ORDER BY is_active DESC, school_year DESC, semester ASC");
$all_periods = [];
if ($periods_query) { $all_periods = $periods_query->fetch_all(MYSQLI_ASSOC); }

$selected_period_id = $_GET['period_id'] ?? $active_id;

$total_students = $conn->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetch_row()[0];
$total_faculty = $conn->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND status='active'")->fetch_row()[0];
$pending_registrations = $conn->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetch_row()[0];

// Total submitted evaluations in this period (for Evaluations Submitted card)
$total_reports = 0;
$t_stmt = $conn->prepare("SELECT COUNT(id) FROM evaluations WHERE academic_period_id = ?");
$t_stmt->bind_param("i", $selected_period_id);
$t_stmt->execute();
$t_stmt->bind_result($total_reports);
$t_stmt->fetch();
$t_stmt->close();

// 1. Total expected class evaluations across active students in this period
$enr_expected_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT en.id) 
    FROM enrollments en
    JOIN classes c ON en.class_id = c.id
    JOIN users u ON en.student_id = u.id
    WHERE c.period_id = ? AND u.role = 'student' AND u.status = 'active'
");
$enr_expected_stmt->bind_param("i", $selected_period_id);
$enr_expected_stmt->execute();
$total_expected_evals = (int)$enr_expected_stmt->get_result()->fetch_row()[0];
$enr_expected_stmt->close();

// Also count for active students without explicit enrollments (course-assigned classes)
$no_enr_stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    JOIN users u ON u.course_id = s.course_id AND u.role = 'student' AND u.status = 'active'
    WHERE c.period_id = ? 
      AND NOT EXISTS (SELECT 1 FROM enrollments en WHERE en.student_id = u.id)
");
$no_enr_stmt->bind_param("i", $selected_period_id);
$no_enr_stmt->execute();
$total_expected_evals += (int)$no_enr_stmt->get_result()->fetch_row()[0];
$no_enr_stmt->close();

// 2. Completed class evaluations submitted by active students
$completed_evals_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT CONCAT(e.student_id, '_', e.faculty_id, '_', COALESCE(e.subject_id, 0)))
    FROM evaluations e
    JOIN users u ON e.student_id = u.id
    WHERE e.academic_period_id = ? 
      AND e.faculty_id IS NOT NULL 
      AND u.role = 'student' 
      AND u.status = 'active'
");
$completed_evals_stmt->bind_param("i", $selected_period_id);
$completed_evals_stmt->execute();
$completed_evals_count = (int)$completed_evals_stmt->get_result()->fetch_row()[0];
$completed_evals_stmt->close();

$completion_rate = $total_expected_evals > 0 ? min(100, round(($completed_evals_count / $total_expected_evals) * 100, 1)) : 0;

$excellent = $conn->query("SELECT COUNT(*) FROM evaluations WHERE academic_period_id = '$selected_period_id' AND rating >= 4.5")->fetch_row()[0] ?? 0;
$very_good = $conn->query("SELECT COUNT(*) FROM evaluations WHERE academic_period_id = '$selected_period_id' AND rating >= 4.0 AND rating < 4.5")->fetch_row()[0] ?? 0;
$good = $conn->query("SELECT COUNT(*) FROM evaluations WHERE academic_period_id = '$selected_period_id' AND rating >= 3.0 AND rating < 4.0")->fetch_row()[0] ?? 0;
$needs_improvement = $conn->query("SELECT COUNT(*) FROM evaluations WHERE academic_period_id = '$selected_period_id' AND rating < 3.0")->fetch_row()[0] ?? 0;

// AI Insight is now generated dynamically via AJAX to ai_generate_summary.php
// No hardcoded template text — real comment analysis is performed on demand

$summary_period_text = "";
foreach($all_periods as $p) {
    if ($p['id'] == $selected_period_id) {
        $summary_period_text = "for " . htmlspecialchars($p['semester'] . ' ' . $p['school_year']);
        break;
    }
}
if (empty($summary_period_text)) { $summary_period_text = "for the selected period"; }
?>
<!DOCTYPE html>
<html>
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">

<div style="display:flex; min-height:100vh; position:relative;">

    <?php $active_page = 'dashboard'; $mobile_page_title = 'Admin Dashboard'; include 'admin_sidebar.php'; ?>

    <main class="main-content" style="overflow-y:auto;">

        <div class="header-bar-modern">

            <div class="search-box-modern" style="position: relative;">
                <i class="fas fa-search" style="color:var(--text-muted);"></i>
                <input type="text" id="dashboardSearch" placeholder="Search evaluations, faculty, logs..." onkeyup="filterDashboard()">
                <!-- Search Results Dropdown -->
                <div id="searchResults" style="display:none; position:absolute; top:calc(100% + 10px); left:0; width: 100%; background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; box-shadow:0 15px 30px rgba(0,0,0,0.15); max-height:300px; overflow-y:auto; z-index:9999; backdrop-filter:blur(10px);">
                </div>
            </div>
            
            <div class="desktop-header-actions" style="display:flex; align-items:center; gap:20px;">
                <div style="display:flex; gap:18px; font-size:18px; color:var(--text-muted); align-items:center;">
                    <div style="position: relative; display: inline-block;">
                        <i class="fas fa-bell" title="Notifications" id="notifBell" style="cursor:pointer; transition: 0.2s;"></i>
                        <span id="desktopNotifBadge" style="position: absolute; top: -3px; right: -3px; background: #ef4444; border: 1.5px solid var(--bg-color); border-radius: 50%; width: 8px; height: 8px; display: none;"></span>
                        
                        <div id="notifDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 15px); right:-50px; width:330px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 10px 0;">
                            <div style="padding: 10px 16px; border-bottom: 1px solid var(--border-color); font-weight: 700; font-size: 13px; color: var(--text-main); display: flex; justify-content: space-between; align-items: center;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span>Notifications</span>
                                    <span id="desktopNotifPill" style="font-size: 10px; background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 2px 8px; border-radius: 10px; font-weight: 700; display:none;"></span>
                                </div>
                                <button type="button" id="desktopMarkAllBtn" onclick="markAllDesktopNotifs(event)" title="Mark all as read" style="background:none; border:none; color:var(--accent); font-size:11px; font-weight:600; cursor:pointer; padding:2px 6px; border-radius:4px; display:none; align-items:center; gap:4px; transition:0.2s;">
                                    <i class="fas fa-check-double"></i> Mark all read
                                </button>
                            </div>
                            <div id="desktopNotifList" style="max-height:260px; overflow-y:auto; padding: 5px 0;">
                                <?php
                                $pending_notifs = $conn->query("SELECT id, full_name, role, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
                                if ($pending_notifs && $pending_notifs->num_rows > 0) {
                                    while($p = $pending_notifs->fetch_assoc()) {
                                        $nid = 'pending_user_' . $p['id'];
                                        $nts = strtotime($p['created_at']);
                                        echo "<div class='desktop-notif-item' data-id='{$nid}' data-timestamp='{$nts}' style='display:flex; align-items:center; justify-content:space-between; padding: 10px 16px; border-bottom:1px solid var(--border-color); transition: opacity 0.25s, transform 0.25s;'>
                                                <a href='pending_approvals.php' onclick='handleDesktopNotifClick(event, this, \"{$nid}\")' style='display:flex; text-decoration:none; flex:1; align-items:flex-start;'>
                                                    <div style='background:rgba(239, 68, 68, 0.1); color:#ef4444; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; margin-right:12px; font-size:12px; flex-shrink:0;'><i class='fas fa-user-plus'></i></div>
                                                    <div style='text-align:left;'>
                                                        <div style='font-size:12px; font-weight:700; color:var(--text-main); line-height:1.2;'>" . htmlspecialchars($p['full_name']) . "</div>
                                                        <div style='font-size:10.5px; color:var(--text-muted); margin-top:2px;'>Registered " . ucfirst($p['role'] == 'faculty' ? 'Teacher' : $p['role']) . "</div>
                                                        <div style='font-size:9px; color:var(--text-muted); margin-top:4px;'><i class='fas fa-clock'></i> " . date('h:i A', $nts) . "</div>
                                                    </div>
                                                </a>
                                                <button type='button' onclick='dismissDesktopNotifItem(event, this, \"{$nid}\")' title='Dismiss' style='background:none; border:none; color:var(--text-muted); cursor:pointer; padding:4px 6px; font-size:14px; opacity:0.6; transition:0.2s;'>&times;</button>
                                              </div>";
                                    }
                                }
                                $log_notifs = $conn->query("SELECT message, timestamp FROM system_logs ORDER BY timestamp DESC LIMIT 3");
                                if ($log_notifs && $log_notifs->num_rows > 0) {
                                    while($l = $log_notifs->fetch_assoc()) {
                                        $nid = 'log_' . md5($l['timestamp'] . $l['message']);
                                        $nts = strtotime($l['timestamp']);
                                        echo "<div class='desktop-notif-item' data-id='{$nid}' data-timestamp='{$nts}' style='display:flex; align-items:center; justify-content:space-between; padding: 10px 16px; border-bottom:1px solid var(--border-color); transition: opacity 0.25s, transform 0.25s;'>
                                                <div style='display:flex; flex:1; align-items:flex-start;'>
                                                    <div style='background:rgba(59, 130, 246, 0.1); color:#3b82f6; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; margin-right:12px; font-size:12px; flex-shrink:0;'><i class='fas fa-info-circle'></i></div>
                                                    <div style='text-align:left; flex:1;'>
                                                        <div style='font-size:11px; color:var(--text-main); line-height:1.3;'>" . htmlspecialchars($l['message']) . "</div>
                                                        <div style='font-size:9px; color:var(--text-muted); margin-top:4px;'><i class='fas fa-clock'></i> " . date('h:i A', $nts) . "</div>
                                                    </div>
                                                </div>
                                                <button type='button' onclick='dismissDesktopNotifItem(event, this, \"{$nid}\")' title='Dismiss' style='background:none; border:none; color:var(--text-muted); cursor:pointer; padding:4px 6px; font-size:14px; opacity:0.6; transition:0.2s;'>&times;</button>
                                              </div>";
                                    }
                                }
                                ?>
                                <div id="desktopEmptyNotifs" style="display:none; padding: 25px 16px; text-align:center; font-size:12px; color:var(--text-muted);">
                                    <i class="fas fa-check-circle" style="font-size:24px; color:#10b981; display:block; margin-bottom:8px; opacity:0.9;"></i>
                                    You're all caught up! No notifications.
                                </div>
                            </div>
                            <div style="padding: 8px 16px; text-align: center; border-top: 1px solid var(--border-color);">
                                <a href="pending_approvals.php" style="font-size: 11px; font-weight: 700; color: var(--accent); text-decoration: none;">View Pending Approvals</a>
                            </div>
                        </div>
                    </div>

                    <div style="position: relative; display: inline-block;">
                        <i class="fas fa-comment-dots" title="Messages" id="messageIcon" style="cursor:pointer; transition: 0.2s;"></i>
                        <?php
                        $feedback_count_res = $conn->query("SELECT COUNT(*) FROM evaluations WHERE feedback IS NOT NULL AND feedback != ''");
                        $feedback_count = $feedback_count_res ? $feedback_count_res->fetch_row()[0] : 0;
                        if ($feedback_count > 0):
                        ?>
                            <span style="position: absolute; top: -3px; right: -3px; background: var(--accent); border: 1.5px solid var(--bg-color); border-radius: 50%; width: 8px; height: 8px;"></span>
                        <?php endif; ?>
                        
                        <div id="messageDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 15px); right:-20px; width:320px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 10px 0;">
                            <div style="padding: 10px 16px; border-bottom: 1px solid var(--border-color); font-weight: 700; font-size: 13px; color: var(--text-main); display: flex; justify-content: space-between; align-items: center;">
                                <span>Recent Student Feedback</span>
                                <span style="font-size: 10px; background: rgba(99, 102, 241, 0.1); color: var(--accent); padding: 2px 8px; border-radius: 10px; font-weight: 700;">Recent</span>
                            </div>
                            <div style="max-height:250px; overflow-y:auto; padding: 5px 0;">
                                <?php
                                $recent_feedbacks = $conn->query("
                                    SELECT e.feedback, e.created_at, e.target_name, f.full_name AS teacher 
                                    FROM evaluations e 
                                    LEFT JOIN users f ON e.faculty_id = f.id 
                                    WHERE e.feedback IS NOT NULL AND e.feedback != '' 
                                    ORDER BY e.created_at DESC 
                                    LIMIT 4
                                ");
                                if ($recent_feedbacks && $recent_feedbacks->num_rows > 0) {
                                    while($f = $recent_feedbacks->fetch_assoc()) {
                                        $truncated_feedback = strlen($f['feedback']) > 55 ? substr($f['feedback'], 0, 52) . '...' : $f['feedback'];
                                        $to_label = $f['teacher'] ? "for " . htmlspecialchars($f['teacher']) : "for " . htmlspecialchars($f['target_name'] ?: 'School Facility');
                                        $target_url = $f['teacher'] ? "view_results.php?report_type=faculty" : "view_results.php?report_type=facility";
                                        echo "<a href='{$target_url}' style='display:block; padding: 10px 16px; border-bottom:1px solid var(--border-color); text-align:left; text-decoration:none;'>
                                                <div style='display:flex; justify-content:space-between; align-items:center;'>
                                                    <span style='font-size:12px; font-weight:700; color:var(--text-main);'><i class='fas fa-user-secret' style='margin-right:5px; color:var(--text-muted); font-size:11px;'></i>Anonymous</span>
                                                    <span style='font-size:9.5px; color:var(--text-muted);'>" . date('M d', strtotime($f['created_at'])) . "</span>
                                                </div>
                                                <div style='font-size:10px; color:var(--accent); font-weight:600; margin-top:2px;'><i class='" . ($f['teacher'] ? "fas fa-chalkboard-teacher" : "fas fa-university") . "' style='margin-right:4px;'></i>" . $to_label . "</div>
                                                <p style='font-size:11.5px; color:var(--text-muted); margin: 4px 0 0 0; line-height:1.3; font-style:italic;'>\"" . htmlspecialchars($truncated_feedback) . "\"</p>
                                              </a>";
                                    }
                                } else {
                                    echo "<div style='padding: 20px; text-align:center; font-size:12px; color:var(--text-muted);'><i class='fas fa-comment-slash' style='font-size:24px; display:block; margin-bottom:8px; opacity:0.5;'></i>No feedback comments</div>";
                                }
                                ?>
                            </div>
                            <div style="padding: 8px 16px; text-align: center; border-top: 1px solid var(--border-color);">
                                <a href="view_results.php" style="font-size: 11px; font-weight: 700; color: var(--accent); text-decoration: none;">View Evaluation Reports</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="position: relative; display: inline-block;">
                    <div class="profile-widget-modern" id="profileWidget" style="cursor: pointer; user-select: none;">
                        <?php
                        $avatar_path = $_SESSION['avatar'] ?? 'uploads/avatars/default.png';
                        if (!file_exists($avatar_path)) {
                            $avatar_path = 'uploads/avatars/default.png';
                        }
                        $display_role = ($_SESSION['role'] === 'admin') ? 'QA Administrator' : (($_SESSION['role'] === 'system admin') ? 'ICT Admin' : ucwords($_SESSION['role']));
                        ?>
                        <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Avatar" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                        <div style="text-align:left; line-height:1.1;">
                            <div style="font-weight:700; font-size:12px; color:var(--text-main);"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size:10px; color:var(--text-muted); font-weight:600;"><?php echo htmlspecialchars($display_role); ?></div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:10px; color:var(--text-muted);"></i>
                    </div>

                    <!-- User Account Dropdown Menu -->
                    <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                        <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 3px;"><?php echo htmlspecialchars($display_role); ?></div>
                        </div>
                        <div style="padding: 6px 0;">
                            <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                            </a>
                            <a href="manage_users.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-users-cog" style="color:#6366f1; width:16px;"></i> User Accounts
                            </a>
                            <a href="pending_approvals.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-clock" style="color:#f59e0b; width:16px;"></i> Pending Approvals
                            </a>
                            <a href="system_settings.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-cog" style="color:var(--accent); width:16px;"></i> System Settings & Ratings
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
        </div>



        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'settings_updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> System settings updated successfully.</div>
            <?php elseif ($_GET['msg'] === 'faculty_visibility_updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Faculty result visibility has been updated.</div>
            <?php elseif ($_GET['msg'] === 'status_updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Evaluation access status updated.</div>
            <?php elseif ($_GET['msg'] === 'deleted'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> User account deleted.</div>
            <?php elseif ($_GET['msg'] === 'theme_reset'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Theme has been reset to default colors.</div>
            <?php elseif ($_GET['msg'] === 'reset_ok'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> User password has been reset to 'password123'.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="modern-stat-grid">
            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Active Students</p>
                    <h3><?php echo number_format($total_students); ?></h3>
                </div>
                <div class="stat-icon blue">
                    <i class="fas fa-user-graduate"></i>
                </div>
            </div>

            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Teachers/Faculty</p>
                    <h3><?php echo number_format($total_faculty); ?></h3>
                </div>
                <div class="stat-icon purple">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
            </div>

            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Evaluations Submitted</p>
                    <h3><?php echo number_format($total_reports); ?></h3>
                </div>
                <div class="stat-icon orange">
                    <i class="fas fa-poll"></i>
                </div>
            </div>

            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Completion Rate</p>
                    <h3 style="color: #10b981;"><?php echo $completion_rate; ?>%</h3>
                </div>
                <div class="stat-icon green">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>

            <div class="stat-card-modern">
                <div class="stat-info">
                    <p>Pending Approvals</p>
                    <h3 style="color: <?php echo $pending_registrations > 0 ? '#ef4444' : '#10b981'; ?>"><?php echo $pending_registrations; ?></h3>
                </div>
                <div class="stat-icon <?php echo $pending_registrations > 0 ? 'red' : 'green'; ?>">
                    <i class="fas fa-user-clock"></i>
                </div>
            </div>
        </div>

        <div class="dashboard-grid-layout">
            
            <div>
                <div class="card-modern">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                        <h3 style="margin:0; font-size:16px; font-weight:800; color:var(--text-main);">
                            <i class="fas fa-calendar-alt" style="color:var(--accent);"></i> Academic Period Control
                        </h3>
                        <div>
                            <?php if ($eval_status == 'open'): ?>
                                <span style="background:#d1fae5; color:#065f46; padding:6px 12px; border-radius:20px; font-size:11px; font-weight:700;"><i class="fas fa-circle" style="font-size:7px;"></i> Evaluation OPEN</span>
                            <?php else: ?>
                                <span style="background:#fee2e2; color:#991b1b; padding:6px 12px; border-radius:20px; font-size:11px; font-weight:700;"><i class="fas fa-circle" style="font-size:7px;"></i> Evaluation CLOSED</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; gap:20px; align-items:center; flex-wrap:wrap;">
                        <div style="flex: 1; min-width: 250px;">
                            <form method="GET" action="dashboard_admin.php" style="margin:0;">
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--text-muted); margin-bottom:8px;">SELECT EVALUATION PERIOD</label>
                                <select name="period_id" onchange="this.form.submit()" style="width:100%; padding: 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); font-size: 13px; font-weight: 600;">
                                    <?php
                                    foreach($all_periods as $p) {
                                        $selected = ($selected_period_id == $p['id']) ? 'selected' : '';
                                        $is_active_label = $p['is_active'] ? ' (Active)' : '';
                                        echo "<option value='{$p['id']}' $selected>" . htmlspecialchars($p['semester'] . ' ' . $p['school_year']) . $is_active_label . "</option>";
                                    }
                                    ?>
                                </select>
                            </form>
                        </div>
                        
                        <div style="text-align: right; min-width: 200px;">
                            <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:8px;">ACCESS TRIGGER</label>
                            <?php if ($eval_status == 'open'): ?>
                                <a href="dashboard_admin.php?toggle_status=closed" class="btn-action" style="background:#ef4444; color:white; padding:12px 24px; border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; display:inline-block;"><i class="fas fa-lock"></i> Close Evaluation Access</a>
                            <?php else: ?>
                                <a href="dashboard_admin.php?toggle_status=open" class="btn-action" style="background:#10b981; color:white; padding:12px 24px; border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; display:inline-block;"><i class="fas fa-lock-open"></i> Open Evaluation Access</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-modern">
                    <h3 style="margin-bottom:15px; font-size:16px; font-weight:800; color:var(--text-main);">
                        <i class="fas fa-star" style="color:#f59e0b;"></i> Top Rated Faculty Members
                    </h3>
                    <div class="table-responsive">
                        <table class="table-star">
                            <thead>
                                <tr>
                                    <th>Faculty Member</th>
                                    <th>Department</th>
                                    <th>Evaluation Rating</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $star_faculty_stmt = $conn->prepare("
                                    SELECT u.id, u.full_name, u.avatar, d.dept_alias, AVG(e.rating) as avg_rating, COUNT(e.id) as eval_count 
                                    FROM users u 
                                    JOIN departments d ON u.dept_id = d.id 
                                    JOIN evaluations e ON u.id = e.faculty_id 
                                    WHERE e.academic_period_id = ? 
                                    GROUP BY u.id, u.full_name, u.avatar, d.dept_alias 
                                    ORDER BY avg_rating DESC 
                                    LIMIT 3
                                ");
                                $star_faculty_result = [];
                                if ($star_faculty_stmt) {
                                    $star_faculty_stmt->bind_param("i", $selected_period_id);
                                    $star_faculty_stmt->execute();
                                    $star_faculty_result = $star_faculty_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $star_faculty_stmt->close();
                                }
                                if (empty($star_faculty_result)) {
                                    $fallback_query = $conn->query("
                                        SELECT u.id, u.full_name, u.avatar, d.dept_alias, AVG(e.rating) as avg_rating, COUNT(e.id) as eval_count 
                                        FROM users u 
                                        JOIN departments d ON u.dept_id = d.id 
                                        JOIN evaluations e ON u.id = e.faculty_id 
                                        GROUP BY u.id, u.full_name, u.avatar, d.dept_alias 
                                        ORDER BY avg_rating DESC 
                                        LIMIT 3
                                    ");
                                    if ($fallback_query) {
                                        $star_faculty_result = $fallback_query->fetch_all(MYSQLI_ASSOC);
                                    }
                                }
                                if (!empty($star_faculty_result)):
                                    foreach($star_faculty_result as $fac):
                                        $fac_avatar = $fac['avatar'] ?? 'uploads/avatars/default.png';
                                        if (!file_exists($fac_avatar)) { $fac_avatar = 'uploads/avatars/default.png'; }
                                        $rating = round($fac['avg_rating'], 2);
                                        $pct_rating = round(($rating / $max_rating_val) * 100, 0);
                                        ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:12px;">
                                                    <img src="<?php echo htmlspecialchars($fac_avatar); ?>" class="faculty-avatar-circle" alt="Avatar">
                                                    <span style="font-weight:700;"><?php echo htmlspecialchars($fac['full_name']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="category-badge"><?php echo htmlspecialchars($fac['dept_alias']); ?></span>
                                            </td>
                                            <td>
                                                <strong style="color:var(--accent); font-size:15px;"><?php echo number_format($rating, 2); ?></strong> / <?php echo $max_rating_val; ?>
                                            </td>
                                            <td>
                                                <span style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700;"><?php echo $pct_rating; ?>% rating</span>
                                            </td>
                                        </tr>
                                        <?php
                                    endforeach;
                                else:
                                ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">No evaluation data available.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="ai-section" class="card-modern" style="border-left: 5px solid #8b5cf6;">
                    <h3 style="margin-bottom:10px; font-size:16px; font-weight:800; color:var(--text-main);">
                        <i class="fas fa-robot" style="color:#8b5cf6;"></i> Generative AI Evaluation Insights
                    </h3>
                    <p style="color:var(--text-muted); font-size: 13px; line-height: 1.5; margin-bottom:15px;">
                        Analyze real evaluation comments and generate data-driven insights with sentiment distribution.
                    </p>
                    
                    <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <div class="input-group" style="margin-bottom:0; flex:1; min-width:200px;">
                            <div class="input-label">Select Analysis Focus</div>
                            <select id="aiTargetSelect" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:10px; background:var(--input-bg); color:var(--text-main); font-weight:600;">
                                <option value="Overall Institution">Overall Institution (All Comments)</option>
                                <option value="Facilities & Campus Services">Facilities & Campus Services</option>
                                <?php
                                $ai_depts = $conn->query("SELECT id, dept_alias, dept_name FROM departments ORDER BY dept_alias");
                                if ($ai_depts) {
                                    while($d = $ai_depts->fetch_assoc()) {
                                        echo '<option value="dept_' . $d['id'] . '">' . htmlspecialchars($d['dept_alias'] . ' — ' . $d['dept_name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <button type="button" onclick="generateAIInsight()" id="aiGenerateBtn" class="login-btn" style="background: #8b5cf6; width: auto; padding: 12px 24px; border-radius:10px; margin-bottom:0; display:inline-flex; align-items:center; gap:8px; white-space:nowrap;">
                            <i class="fas fa-magic"></i> Generate Insights
                        </button>
                    </div>

                    <!-- AI Results Container -->
                    <div id="aiResultsContainer" style="display:none; margin-top: 20px; animation: fadeIn 0.4s ease;">
                        <!-- Loading State -->
                        <div id="aiLoading" style="display:none; text-align:center; padding:40px 20px;">
                            <div style="display:inline-block; width:40px; height:40px; border:3px solid var(--border-color); border-top-color:#8b5cf6; border-radius:50%; animation: spin 0.8s linear infinite;"></div>
                            <p style="color:var(--text-muted); margin-top:15px; font-size:13px;">Analyzing evaluation comments...</p>
                        </div>
                        
                        <!-- Results Content -->
                        <div id="aiResultContent" style="display:none;">
                            <!-- Header Stats Row -->
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:18px;">
                                <div style="background:rgba(139, 92, 246, 0.06); border:1px solid rgba(139, 92, 246, 0.15); border-radius:10px; padding:14px; text-align:center;">
                                    <div style="font-size:11px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Total Comments</div>
                                    <div id="aiTotalComments" style="font-size:24px; font-weight:800; color:#8b5cf6;">0</div>
                                </div>
                                <div style="background:rgba(16, 185, 129, 0.06); border:1px solid rgba(16, 185, 129, 0.15); border-radius:10px; padding:14px; text-align:center;">
                                    <div style="font-size:11px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Positive</div>
                                    <div id="aiPositiveCount" style="font-size:24px; font-weight:800; color:#10b981;">0</div>
                                </div>
                                <div style="background:rgba(239, 68, 68, 0.06); border:1px solid rgba(239, 68, 68, 0.15); border-radius:10px; padding:14px; text-align:center;">
                                    <div style="font-size:11px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Negative</div>
                                    <div id="aiNegativeCount" style="font-size:24px; font-weight:800; color:#ef4444;">0</div>
                                </div>
                                <div style="background:rgba(59, 130, 246, 0.06); border:1px solid rgba(59, 130, 246, 0.15); border-radius:10px; padding:14px; text-align:center;">
                                    <div style="font-size:11px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Overall</div>
                                    <div id="aiOverallSentiment" style="font-size:13px; font-weight:800; color:#3b82f6;">—</div>
                                </div>
                            </div>

                            <!-- Sentiment Ratio Bar -->
                            <div style="margin-bottom:18px;">
                                <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:var(--text-muted); margin-bottom:5px;">
                                    <span><i class="fas fa-smile" style="color:#10b981;"></i> Positive</span>
                                    <span id="aiRatioLabel">0%</span>
                                    <span><i class="fas fa-frown" style="color:#ef4444;"></i> Negative</span>
                                </div>
                                <div style="height:8px; background:#fee2e2; border-radius:4px; overflow:hidden;">
                                    <div id="aiRatioBar" style="height:100%; background:linear-gradient(90deg, #10b981, #34d399); width:0%; transition: width 0.8s ease; border-radius:4px;"></div>
                                </div>
                            </div>

                            <!-- Positive Summary -->
                            <div style="padding:16px; background:rgba(16, 185, 129, 0.05); border:1px solid rgba(16, 185, 129, 0.2); border-radius:10px; margin-bottom:12px;">
                                <strong style="color:#10b981; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                                    <i class="fas fa-plus-circle"></i> Positive Feedback Summary
                                </strong>
                                <div id="aiPositiveSummary" style="color:var(--text-main); font-size:13px; line-height:1.7;"></div>
                            </div>

                            <!-- Negative Summary -->
                            <div style="padding:16px; background:rgba(239, 68, 68, 0.05); border:1px solid rgba(239, 68, 68, 0.2); border-radius:10px; margin-bottom:12px;">
                                <strong style="color:#ef4444; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                                    <i class="fas fa-minus-circle"></i> Needs Improvement Summary
                                </strong>
                                <div id="aiNegativeSummary" style="color:var(--text-main); font-size:13px; line-height:1.7;"></div>
                            </div>

                            <!-- Theme Badges -->
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                <div>
                                    <div style="font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">Top Positive Themes</div>
                                    <div id="aiPositiveThemes" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">Top Negative Themes</div>
                                    <div id="aiNegativeThemes" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div>
                <div class="card-modern" style="position:relative;">
                    <h3 style="margin-bottom:15px; font-size:15px; font-weight:800; color:var(--text-main);">
                        <i class="fas fa-chart-pie" style="color:#6366f1;"></i> Evaluation Statistics
                    </h3>
                    
                    <div style="position:relative; height: 180px; width: 100%; margin-bottom:15px;">
                        <canvas id="ratingStatsChart"></canvas>
                        <div class="donut-center-text">
                            <h4 style="color:var(--text-main);"><?php echo $total_reports; ?></h4>
                            <p>Evaluations</p>
                        </div>
                    </div>
                </div>

                <div class="card-modern">
                    <h3 style="margin-bottom:15px; font-size:15px; font-weight:800; color:var(--text-main);">
                        <i class="fas fa-university" style="color:#3b82f6;"></i> Department Performance
                    </h3>
                    
                    <?php
                    $dept_perf_sql = "SELECT d.dept_alias, AVG(e.rating) as avg_rating FROM departments d LEFT JOIN users u ON d.id = u.dept_id AND u.role = 'faculty' LEFT JOIN evaluations e ON u.id = e.faculty_id AND e.academic_period_id = ? GROUP BY d.id, d.dept_alias ORDER BY avg_rating DESC";
                    $dept_perf_stmt = $conn->prepare($dept_perf_sql);
                    $dept_perf_stmt->bind_param("i", $selected_period_id);
                    $dept_perf_stmt->execute();
                    $dept_perf_query = $dept_perf_stmt->get_result();
                    $dept_perf_labels = [];
                    $dept_perf_data = [];
                    if ($dept_perf_query) {
                        while($row = $dept_perf_query->fetch_assoc()) {
                            $dept_perf_labels[] = $row['dept_alias'];
                            $dept_perf_data[] = round($row['avg_rating'] ?? 0, 2);
                        }
                    }
                    
                    foreach($dept_perf_labels as $index => $label) {
                        $rating = $dept_perf_data[$index];
                        $pct = $max_rating_val > 0 ? round(($rating / $max_rating_val) * 100, 0) : 0;
                        $colors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899'];
                        $color = $colors[$index % count($colors)];
                        if ($rating > 0):
                        ?>
                        <div class="perf-row">
                            <div class="perf-row-label">
                                <span>College of <?php echo htmlspecialchars($label); ?></span>
                                <span><?php echo number_format($rating, 2); ?></span>
                            </div>
                            <div class="perf-row-bar-outer">
                                <div class="perf-row-bar-inner" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
                            </div>
                        </div>
                        <?php
                        endif;
                    }
                    ?>
                </div>

                <div class="card-modern">
                    <h3 style="margin-bottom:15px; font-size:15px; font-weight:800; color:var(--text-main);">
                        <i class="fas fa-history" style="color:var(--accent);"></i> Recent Submissions
                    </h3>
                    <div style="max-height:220px; overflow-y:auto; padding-right:4px;">
                        <?php
                        $recent_sql = "SELECT e.created_at, u.role, u.full_name AS student, e.target_name, e.rating, f.full_name AS teacher FROM evaluations e JOIN users u ON e.student_id = u.id LEFT JOIN users f ON e.faculty_id = f.id WHERE e.academic_period_id = ? ORDER BY e.created_at DESC LIMIT 4";
                        $recent_stmt = $conn->prepare($recent_sql);
                        $recent_stmt->bind_param("i", $selected_period_id);
                        $recent_stmt->execute();
                        $recent = $recent_stmt->get_result();
                        if ($recent->num_rows === 0): ?>
                            <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                No recent evaluation activities.
                            </div>
                        <?php else: 
                            while ($r = $recent->fetch_assoc()):
                                $badge_class = 'success';
                                if ($r['rating'] < 3.0) $badge_class = 'warning';
                                $target = $r['teacher'] ? $r['teacher'] : $r['target_name'];
                                $evaluator_display = ($r['role'] === 'student') ? '<i class="fas fa-user-secret" style="margin-right:4px; opacity:0.7;"></i>Anonymous' : htmlspecialchars($r['student']);
                        ?>
                            <div class="activity-item-modern">
                                <div class="activity-item-badge <?php echo $badge_class; ?>">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div style="flex:1; font-size:12px; line-height:1.2; text-align:left;">
                                    <div style="font-weight:700; color:var(--text-main);"><?php echo $evaluator_display; ?></div>
                                    <div style="color:var(--text-muted); margin-top:2px;">Rated: <?php echo htmlspecialchars($target); ?></div>
                                </div>
                                <div style="text-align:right; font-size:12px; font-weight:700; color:var(--accent);">
                                    <?php echo number_format($r['rating'], 1); ?>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>
            </div>
        </div>



        <footer>
            <i class="fas fa-shield-alt"></i>
            Admin Portal &middot; <?php echo htmlspecialchars($_SESSION['username']); ?> &middot; <?php echo htmlspecialchars($_SESSION['full_name']); ?>
        </footer>
    </main>
</div>

<script>


    const ratingStatsCtx = document.getElementById('ratingStatsChart');
    if (ratingStatsCtx) {
        new Chart(ratingStatsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Excellent (4.5+)', 'Very Good (4.0+)', 'Good (3.0+)', 'Needs Imp. (<3.0)'],
                datasets: [{
                    data: [
                        <?php echo $excellent; ?>, 
                        <?php echo $very_good; ?>, 
                        <?php echo $good; ?>, 
                        <?php echo $needs_improvement; ?>
                    ],
                    backgroundColor: ['#8b5cf6', '#3b82f6', '#10b981', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 8,
                            padding: 10,
                            font: { size: 10, weight: 'bold' }
                        }
                    }
                },
                cutout: '72%',
                maintainAspectRatio: false
            }
        });
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

    let currentFocus = -1;
    
    document.getElementById('dashboardSearch').addEventListener('keydown', function(e) {
        let resultsBox = document.getElementById('searchResults');
        if (!resultsBox || resultsBox.style.display === 'none') return;
        
        let items = resultsBox.getElementsByTagName('a');
        if (items.length === 0) return;
        
        if (e.keyCode == 40) { // Arrow Down
            currentFocus++;
            addActive(items);
            e.preventDefault(); // prevent cursor from moving
        } else if (e.keyCode == 38) { // Arrow Up
            currentFocus--;
            addActive(items);
            e.preventDefault();
        } else if (e.keyCode == 13) { // Enter
            e.preventDefault();
            if (currentFocus > -1) {
                if (items[currentFocus]) items[currentFocus].click();
            } else {
                // If they press enter but haven't used arrows, click the first one if exists
                if (items.length > 0) items[0].click();
            }
        } else if (e.keyCode == 39 || e.keyCode == 37) { // Right or Left arrow (optional, can just ignore or treat as up/down)
            // Do nothing for left/right so they can move text cursor
        }
    });

    function addActive(items) {
        if (!items) return false;
        removeActive(items);
        if (currentFocus >= items.length) currentFocus = 0;
        if (currentFocus < 0) currentFocus = (items.length - 1);
        items[currentFocus].style.background = 'var(--sidebar-active-bg)';
        // scroll into view smoothly
        items[currentFocus].scrollIntoView({ block: "nearest", behavior: "smooth" });
    }

    function removeActive(items) {
        for (let i = 0; i < items.length; i++) {
            items[i].style.background = 'transparent';
        }
    }

    // Client-side search filter
    function filterDashboard() {
        let input = document.getElementById('dashboardSearch').value.toLowerCase().trim();
        let resultsBox = document.getElementById('searchResults');
        
        // Reset focus on new search
        currentFocus = -1;
        
        // Filter dashboard cards inline
        let cards = document.querySelectorAll('.card, .card-modern, .stat-card-modern, .table-modern tr');
        cards.forEach(card => {
            // Skip table headers
            if(card.tagName.toLowerCase() === 'tr' && card.parentElement.tagName.toLowerCase() === 'thead') return;
            
            let text = card.innerText.toLowerCase();
            if(text.includes(input)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });

        if (input === '') {
            resultsBox.style.display = 'none';
            return;
        }

        // Search through the sidebar links for the Dropdown
        let links = document.querySelectorAll('.sidebar .nav-link');
        let html = '';
        
        // We use a Set to avoid duplicates if any
        let addedLinks = new Set();

        links.forEach(link => {
            // Ignore links that just toggle dropdowns
            if (link.getAttribute('onclick') && link.getAttribute('onclick').includes('nextElementSibling')) return;

            let text = link.textContent.trim();
            if (text.toLowerCase().includes(input)) {
                let href = link.getAttribute('href');
                if (!href || href === '#' || addedLinks.has(text)) return;
                addedLinks.add(text);

                let iconHtml = link.querySelector('i') ? link.querySelector('i').outerHTML : '';
                // Highlight the searched text
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

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        let searchBox = document.querySelector('.search-box-modern');
        let resultsBox = document.getElementById('searchResults');
        if (searchBox && !searchBox.contains(e.target)) {
            if(resultsBox) resultsBox.style.display = 'none';
        }

        // Toggle drop downs
        let notifBell = document.getElementById('notifBell');
        let notifDropdown = document.getElementById('notifDropdown');
        let messageIcon = document.getElementById('messageIcon');
        let messageDropdown = document.getElementById('messageDropdown');
        let profileWidget = document.getElementById('profileWidget');
        let profileDropdown = document.getElementById('profileDropdown');

        if (notifBell && notifDropdown) {
            if (e.target === notifBell || notifBell.contains(e.target)) {
                e.stopPropagation();
                notifDropdown.style.display = notifDropdown.style.display === 'none' ? 'block' : 'none';
                if (messageDropdown) messageDropdown.style.display = 'none';
                if (profileDropdown) profileDropdown.style.display = 'none';
            } else if (!notifDropdown.contains(e.target)) {
                notifDropdown.style.display = 'none';
            }
        }

        if (messageIcon && messageDropdown) {
            if (e.target === messageIcon || messageIcon.contains(e.target)) {
                e.stopPropagation();
                messageDropdown.style.display = messageDropdown.style.display === 'none' ? 'block' : 'none';
                if (notifDropdown) notifDropdown.style.display = 'none';
                if (profileDropdown) profileDropdown.style.display = 'none';
            } else if (!messageDropdown.contains(e.target)) {
                messageDropdown.style.display = 'none';
            }
        }

        if (profileWidget && profileDropdown) {
            if (profileWidget.contains(e.target)) {
                e.stopPropagation();
                profileDropdown.style.display = profileDropdown.style.display === 'none' ? 'block' : 'none';
                if (notifDropdown) notifDropdown.style.display = 'none';
                if (messageDropdown) messageDropdown.style.display = 'none';
            } else if (!profileDropdown.contains(e.target)) {
                profileDropdown.style.display = 'none';
            }
        }
    });

    // Desktop Notifications Management
    const currentAdminId = '<?php echo (int)($_SESSION['user_id'] ?? 0); ?>';
    const deskStorageKeyRead = 'mcnp_read_notifs_' + currentAdminId;
    const deskStorageKeyCleared = 'mcnp_notifs_cleared_time_' + currentAdminId;

    function getDesktopReadIds() {
        try { return JSON.parse(localStorage.getItem(deskStorageKeyRead) || '[]'); } catch(e) { return []; }
    }
    function saveDesktopReadIds(ids) {
        try { localStorage.setItem(deskStorageKeyRead, JSON.stringify(ids)); } catch(e) {}
    }
    function getDesktopClearedTime() {
        return parseInt(localStorage.getItem(deskStorageKeyCleared) || '0', 10);
    }

    function updateDesktopNotifsUI() {
        const readIds = getDesktopReadIds();
        const clearedTime = getDesktopClearedTime();
        const items = document.querySelectorAll('.desktop-notif-item');
        let unreadCount = 0;

        items.forEach(item => {
            const id = item.getAttribute('data-id');
            const ts = parseInt(item.getAttribute('data-timestamp') || '0', 10);
            const isRead = readIds.includes(id) || (clearedTime > 0 && ts <= clearedTime);
            if (isRead) {
                item.style.display = 'none';
            } else {
                item.style.display = 'flex';
                unreadCount++;
            }
        });

        const badge = document.getElementById('desktopNotifBadge');
        const pill = document.getElementById('desktopNotifPill');
        const markAllBtn = document.getElementById('desktopMarkAllBtn');
        const emptyDiv = document.getElementById('desktopEmptyNotifs');

        if (badge) badge.style.display = (unreadCount > 0) ? 'block' : 'none';
        if (pill) {
            if (unreadCount > 0) {
                pill.textContent = unreadCount + ' new';
                pill.style.display = 'inline-block';
            } else {
                pill.style.display = 'none';
            }
        }
        if (markAllBtn) markAllBtn.style.display = (unreadCount > 0) ? 'inline-flex' : 'none';
        if (emptyDiv) emptyDiv.style.display = (unreadCount === 0) ? 'block' : 'none';
    }

    function markAllDesktopNotifs(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        const items = document.querySelectorAll('.desktop-notif-item');
        const readIds = getDesktopReadIds();
        items.forEach(item => {
            const id = item.getAttribute('data-id');
            if (id && !readIds.includes(id)) readIds.push(id);
            item.style.transition = 'opacity 0.25s, transform 0.25s';
            item.style.opacity = '0';
            item.style.transform = 'translateX(10px)';
            setTimeout(() => { item.style.display = 'none'; }, 250);
        });
        saveDesktopReadIds(readIds);
        localStorage.setItem(deskStorageKeyCleared, Math.floor(Date.now() / 1000).toString());
        setTimeout(updateDesktopNotifsUI, 260);

        const btn = document.getElementById('desktopMarkAllBtn');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check" style="color:var(--accent);"></i> All read!';
            setTimeout(() => { btn.innerHTML = orig; }, 1200);
        }
    }

    function dismissDesktopNotifItem(e, btn, notifId) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        const item = btn.closest('.desktop-notif-item');
        if (item) {
            item.style.transition = 'opacity 0.2s, transform 0.2s';
            item.style.opacity = '0';
            item.style.transform = 'translateX(10px)';
            setTimeout(() => {
                item.style.display = 'none';
                const readIds = getDesktopReadIds();
                if (!readIds.includes(notifId)) {
                    readIds.push(notifId);
                    saveDesktopReadIds(readIds);
                }
                updateDesktopNotifsUI();
            }, 200);
        }
    }

    function handleDesktopNotifClick(e, link, notifId) {
        const readIds = getDesktopReadIds();
        if (!readIds.includes(notifId)) {
            readIds.push(notifId);
            saveDesktopReadIds(readIds);
        }
    }

    // Initialize desktop notifications on DOM load
    document.addEventListener('DOMContentLoaded', updateDesktopNotifsUI);

    // Smooth scroll hash helper
    function scrollToSection(id) {
        const element = document.getElementById(id);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (window.location.hash) {
            const targetHash = window.location.hash.substring(1);
            setTimeout(function() {
                scrollToSection(targetHash);
            }, 150);
        }
    });

    window.addEventListener("hashchange", function() {
        if (window.location.hash) {
            const targetHash = window.location.hash.substring(1);
            scrollToSection(targetHash);
        }
    });



    // ─── Generative AI Insight AJAX Engine ───────────────────────────────
    function generateAIInsight() {
        const targetVal = document.getElementById('aiTargetSelect').value;
        const periodId = <?php echo (int)$selected_period_id; ?>;
        const btn = document.getElementById('aiGenerateBtn');
        const container = document.getElementById('aiResultsContainer');
        const loading = document.getElementById('aiLoading');
        const content = document.getElementById('aiResultContent');

        // Show container and loading
        container.style.display = 'block';
        loading.style.display = 'block';
        content.style.display = 'none';
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';

        // Build POST data
        const formData = new FormData();
        formData.append('period_id', periodId);
        
        if (targetVal === 'Facilities & Campus Services') {
            formData.append('target_evaluation', 'Facilities & Campus Services');
        } else if (targetVal.startsWith('dept_')) {
            formData.append('dept_id', targetVal.replace('dept_', ''));
            formData.append('target_evaluation', 'Department');
        } else {
            formData.append('target_evaluation', targetVal);
        }

        fetch('ai_generate_summary.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic"></i> Generate Insights';

            if (data.error) {
                content.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> ' + data.error + '</div>';
                content.style.display = 'block';
                return;
            }

            // Populate stats
            document.getElementById('aiTotalComments').textContent = data.total_comments;
            document.getElementById('aiPositiveCount').textContent = data.positive_count;
            document.getElementById('aiNegativeCount').textContent = data.negative_count;
            document.getElementById('aiOverallSentiment').textContent = data.overall_sentiment;
            
            // Color the overall sentiment
            const sentEl = document.getElementById('aiOverallSentiment');
            if (data.overall_sentiment.includes('Positive')) sentEl.style.color = '#10b981';
            else if (data.overall_sentiment.includes('Negative')) sentEl.style.color = '#ef4444';
            else sentEl.style.color = '#f59e0b';

            // Ratio bar
            document.getElementById('aiRatioBar').style.width = data.sentiment_ratio + '%';
            document.getElementById('aiRatioLabel').textContent = data.sentiment_ratio + '% positive';

            // Summaries
            document.getElementById('aiPositiveSummary').innerHTML = data.positive_summary;
            document.getElementById('aiNegativeSummary').innerHTML = data.negative_summary;

            // Theme badges - Positive
            const posThemesEl = document.getElementById('aiPositiveThemes');
            posThemesEl.innerHTML = '';
            if (data.top_positive_themes && data.top_positive_themes.length > 0) {
                data.top_positive_themes.forEach(t => {
                    posThemesEl.innerHTML += `<span style="background:rgba(16,185,129,0.1); color:#059669; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-check-circle" style="font-size:9px;"></i> ${t.theme} <span style="background:rgba(16,185,129,0.2); padding:1px 5px; border-radius:4px; font-size:9px;">×${t.count}</span></span>`;
                });
            } else {
                posThemesEl.innerHTML = '<span style="color:var(--text-muted); font-size:11px;">No themes detected</span>';
            }

            // Theme badges - Negative
            const negThemesEl = document.getElementById('aiNegativeThemes');
            negThemesEl.innerHTML = '';
            if (data.top_negative_themes && data.top_negative_themes.length > 0) {
                data.top_negative_themes.forEach(t => {
                    negThemesEl.innerHTML += `<span style="background:rgba(239,68,68,0.1); color:#dc2626; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-exclamation-circle" style="font-size:9px;"></i> ${t.theme} <span style="background:rgba(239,68,68,0.2); padding:1px 5px; border-radius:4px; font-size:9px;">×${t.count}</span></span>`;
                });
            } else {
                negThemesEl.innerHTML = '<span style="color:var(--text-muted); font-size:11px;">No themes detected</span>';
            }

            content.style.display = 'block';
        })
        .catch(err => {
            loading.style.display = 'none';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic"></i> Generate Insights';
            content.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Failed to generate insights. Please try again.</div>';
            content.style.display = 'block';
            console.error('AI Insight Error:', err);
        });
    }

</script>
<style>
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>
</body>
</html>
