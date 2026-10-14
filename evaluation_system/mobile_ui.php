<?php
/**
 * Mobile UI Components — shared across all sidebar includes.
 * Renders: top app bar, bottom nav, notification panel, profile panel.
 * 
 * Required variables (set before including):
 *   $mobile_page_title  — e.g. "Dashboard", "Admin Dashboard"
 *   $mobile_user_role   — e.g. "student", "admin", "faculty", "dean", "system admin"
 *   $mobile_dashboard_url — e.g. "dashboard_student.php"
 *   $mobile_nav_items   — array of bottom nav items (optional, auto-set per role)
 */

// Resolve avatar
$mobile_avatar = $_SESSION['avatar'] ?? 'uploads/avatars/default.png';
if (!file_exists($mobile_avatar)) $mobile_avatar = 'uploads/avatars/default.png';
$mobile_full_name = htmlspecialchars($_SESSION['full_name'] ?? 'User');
$mobile_role_display = ucfirst($mobile_user_role ?? $_SESSION['role'] ?? 'User');

// Auto-set nav items per role if not provided
if (!isset($mobile_nav_items)) {
    $role = $_SESSION['role'] ?? 'student';
    switch ($role) {
        case 'admin':
            $mobile_nav_items = [
                ['url' => 'dashboard_admin.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard', 'key' => 'dashboard'],
                ['url' => 'manage_users.php', 'icon' => 'fa-users', 'label' => 'Users', 'key' => 'users'],
                ['url' => 'view_results.php', 'icon' => 'fa-chart-pie', 'label' => 'Reports', 'key' => 'results'],
                ['url' => 'profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profile', 'key' => 'profile'],
            ];
            break;
        case 'student':
            $mobile_nav_items = [
                ['url' => 'dashboard_student.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard', 'key' => 'dashboard'],
                ['url' => 'dashboard_student.php#classes-section', 'icon' => 'fa-chalkboard-teacher', 'label' => 'Evaluate', 'key' => 'classes-section'],
                ['url' => 'dashboard_student.php#evaluation-section', 'icon' => 'fa-university', 'label' => 'Facilities', 'key' => 'evaluation-section'],
                ['url' => 'profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profile', 'key' => 'profile'],
            ];
            break;
        case 'faculty':
            $mobile_nav_items = [
                ['url' => 'dashboard_faculty.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard', 'key' => 'dashboard'],
                ['url' => 'faculty_enrollment.php', 'icon' => 'fa-user-graduate', 'label' => 'Enroll', 'key' => 'enrollment'],
                ['url' => 'dashboard_faculty.php#dean-eval', 'icon' => 'fa-user-tie', 'label' => 'Dean', 'key' => 'dean-eval'],
                ['url' => 'dashboard_faculty.php#facilities-section', 'icon' => 'fa-university', 'label' => 'Facilities', 'key' => 'facilities-section'],
                ['url' => 'profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profile', 'key' => 'profile'],
            ];
            break;
        case 'dean':
            $mobile_nav_items = [
                ['url' => 'dashboard_dean.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard', 'key' => 'dashboard'],
                ['url' => 'dashboard_dean.php#instructors-eval', 'icon' => 'fa-chalkboard-teacher', 'label' => 'Faculty', 'key' => 'instructors-eval'],
                ['url' => 'dashboard_dean.php#facilities-section', 'icon' => 'fa-university', 'label' => 'Facilities', 'key' => 'facilities-section'],
                ['url' => 'view_results.php', 'icon' => 'fa-chart-pie', 'label' => 'Reports', 'key' => 'reports'],
            ];
            break;
        case 'system admin':
            $mobile_nav_items = [
                ['url' => 'dashboard_ict.php', 'icon' => 'fa-desktop', 'label' => 'Dashboard', 'key' => 'ict_dashboard'],
                ['url' => 'manage_users.php', 'icon' => 'fa-user-shield', 'label' => 'Users', 'key' => 'users'],
                ['url' => 'system_settings.php', 'icon' => 'fa-cog', 'label' => 'Settings', 'key' => 'system_settings'],
                ['url' => 'profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profile', 'key' => 'profile'],
            ];
            break;
        default:
            $mobile_nav_items = [
                ['url' => $mobile_dashboard_url ?? 'index.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard', 'key' => 'dashboard'],
                ['url' => 'profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profile', 'key' => 'profile'],
            ];
    }
}

// Determine active nav key
$current_page_key = '';
if (isset($active_page)) $current_page_key = $active_page;
elseif (isset($active_page_student)) $current_page_key = $active_page_student;
elseif (isset($active_page_faculty)) $current_page_key = $active_page_faculty;
elseif (isset($active_page_dean)) $current_page_key = $active_page_dean;

// ═══════════ LIVE MOBILE NOTIFICATIONS ═══════════
if (!isset($conn) || !$conn) {
    @include_once __DIR__ . '/db_connect.php';
}

$mobile_notifications = [];
$mobile_unread_count = 0;

if (isset($conn) && $conn) {
    $cur_role = strtolower($_SESSION['role'] ?? '');
    $cur_uid = (int)($_SESSION['user_id'] ?? 0);

    // 1. Pending approvals (Admin, System Admin, Dean)
    if (in_array($cur_role, ['admin', 'system admin', 'dean'])) {
        $p_res = @$conn->query("SELECT id, full_name, role, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
        if ($p_res) {
            while ($p = $p_res->fetch_assoc()) {
                $role_lbl = ucfirst($p['role'] == 'faculty' ? 'Teacher' : $p['role']);
                $link = ($cur_role === 'dean') ? 'dean_manage_accounts.php' : 'pending_approvals.php';
                $mobile_notifications[] = [
                    'id' => 'pending_user_' . $p['id'],
                    'timestamp' => strtotime($p['created_at']),
                    'title' => 'Pending Approval: ' . htmlspecialchars($p['full_name']),
                    'text' => "Registered as {$role_lbl}. Awaiting account activation.",
                    'time' => date('M d, g:i A', strtotime($p['created_at'])),
                    'icon' => 'fa-user-plus',
                    'icon_bg' => 'rgba(239, 68, 68, 0.12)',
                    'icon_color' => '#ef4444',
                    'link' => $link,
                    'status' => 'unread'
                ];
                $mobile_unread_count++;
            }
        }
    }

    // 2. Recent evaluation feedbacks (Admin, System Admin, Dean)
    if (in_array($cur_role, ['admin', 'system admin', 'dean'])) {
        $fb_res = @$conn->query("
            SELECT e.id AS eval_id, e.feedback, e.created_at, e.target_name, f.full_name AS teacher 
            FROM evaluations e 
            LEFT JOIN users f ON e.faculty_id = f.id 
            WHERE e.feedback IS NOT NULL AND e.feedback != '' 
            ORDER BY e.created_at DESC 
            LIMIT 4
        ");
        if ($fb_res) {
            while ($fb = $fb_res->fetch_assoc()) {
                $raw_snippet = trim($fb['feedback']);
                $snippet = strlen($raw_snippet) > 55 ? substr($raw_snippet, 0, 52) . '...' : $raw_snippet;
                $target_lbl = $fb['teacher'] ? ("Teacher " . $fb['teacher']) : ($fb['target_name'] ?: 'School Facility');
                $target_link = $fb['teacher'] ? "view_results.php?report_type=faculty" : "view_results.php?report_type=facility";
                $mobile_notifications[] = [
                    'id' => 'feedback_' . ($fb['eval_id'] ?? md5($fb['created_at'] . $fb['feedback'])),
                    'timestamp' => strtotime($fb['created_at']),
                    'title' => 'New Feedback Received',
                    'text' => "For {$target_lbl}: \"" . htmlspecialchars($snippet) . "\"",
                    'time' => date('M d, g:i A', strtotime($fb['created_at'])),
                    'icon' => 'fa-comment-dots',
                    'icon_bg' => 'rgba(99, 102, 241, 0.12)',
                    'icon_color' => '#6366f1',
                    'link' => $target_link,
                    'status' => 'unread'
                ];
                $mobile_unread_count++;
            }
        }
    }

    // 3. System activity logs (Admin, System Admin)
    if (in_array($cur_role, ['admin', 'system admin'])) {
        $log_res = @$conn->query("SELECT message, timestamp FROM system_logs ORDER BY timestamp DESC LIMIT 3");
        if ($log_res) {
            while ($lg = $log_res->fetch_assoc()) {
                $mobile_notifications[] = [
                    'id' => 'log_' . md5($lg['timestamp'] . $lg['message']),
                    'timestamp' => strtotime($lg['timestamp']),
                    'title' => 'System Log Activity',
                    'text' => htmlspecialchars($lg['message']),
                    'time' => date('M d, g:i A', strtotime($lg['timestamp'])),
                    'icon' => 'fa-info-circle',
                    'icon_bg' => 'rgba(59, 130, 246, 0.12)',
                    'icon_color' => '#3b82f6',
                    'link' => 'system_settings.php',
                    'status' => 'read'
                ];
            }
        }
    }

    // 4. Faculty specific notifications
    if ($cur_role === 'faculty') {
        $fac_fb = @$conn->query("SELECT id AS eval_id, feedback, created_at, rating FROM evaluations WHERE faculty_id = $cur_uid AND feedback IS NOT NULL AND feedback != '' ORDER BY created_at DESC LIMIT 5");
        if ($fac_fb && $fac_fb->num_rows > 0) {
            while ($ff = $fac_fb->fetch_assoc()) {
                $raw_snippet = trim($ff['feedback']);
                $snippet = strlen($raw_snippet) > 55 ? substr($raw_snippet, 0, 52) . '...' : $raw_snippet;
                $mobile_notifications[] = [
                    'id' => 'fac_fb_' . ($ff['eval_id'] ?? md5($ff['created_at'] . $ff['feedback'])),
                    'timestamp' => strtotime($ff['created_at']),
                    'title' => 'Student Feedback (' . $ff['rating'] . '★)',
                    'text' => '"' . htmlspecialchars($snippet) . '"',
                    'time' => date('M d, g:i A', strtotime($ff['created_at'])),
                    'icon' => 'fa-star',
                    'icon_bg' => 'rgba(245, 158, 11, 0.12)',
                    'icon_color' => '#f59e0b',
                    'link' => 'dashboard_faculty.php#analytics',
                    'status' => 'unread'
                ];
                $mobile_unread_count++;
            }
        }
    }

    // 5. Active evaluation period notice (Student, Faculty, Dean)
    if (in_array($cur_role, ['student', 'faculty', 'dean'])) {
        $per_res = @$conn->query("SELECT school_year, semester FROM academic_periods WHERE is_active = 1 LIMIT 1");
        if ($per_res && $per_row = $per_res->fetch_assoc()) {
            $mobile_notifications[] = [
                'id' => 'period_active_' . md5($per_row['school_year'] . $per_row['semester']),
                'timestamp' => time() - 3600,
                'title' => 'Evaluation Period Active',
                'text' => "{$per_row['semester']} {$per_row['school_year']} evaluation is ongoing.",
                'time' => 'Active now',
                'icon' => 'fa-graduation-cap',
                'icon_bg' => 'rgba(132, 90, 223, 0.12)',
                'icon_color' => '#845adf',
                'link' => ($cur_role === 'student') ? 'dashboard_student.php#classes-section' : 'dashboard_faculty.php',
                'status' => 'read'
            ];
        }
    }
}
?>

<!-- ═══════════ MOBILE TOP APP BAR ═══════════ -->
<div class="mobile-top-bar" id="mobileTopBar">
    <div class="mtb-left">
        <button class="mtb-hamburger" onclick="toggleSidebar(event)" aria-label="Open Menu">
            <i class="fas fa-bars"></i>
        </button>
        <span class="mtb-title"><?php echo $mobile_page_title ?? 'Dashboard'; ?></span>
    </div>
    <div class="mtb-right">
        <button class="mtb-icon-btn" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="mobileThemeIcon"></i>
        </button>
        <button class="mtb-icon-btn" onclick="toggleMobileNotif()" aria-label="Notifications" style="position:relative;">
            <i class="fas fa-bell"></i>
            <span class="mtb-notif-badge <?php echo ($mobile_unread_count > 0) ? 'has-notif' : ''; ?>" id="mobileNotifBadge"></span>
        </button>
        <a href="profile.php#change-photo" class="mtb-avatar-link" onclick="return goToChangePhoto(event)" title="Change Profile Photo" aria-label="Change profile photo" style="display:flex; align-items:center; background:none; border:none; padding:0; cursor:pointer; text-decoration:none;">
            <img src="<?php echo htmlspecialchars($mobile_avatar); ?>" 
                 class="mtb-avatar" 
                 alt="Profile"
                 onerror="this.src='uploads/avatars/default.png'">
            <span class="mtb-avatar-cam" aria-hidden="true"><i class="fas fa-camera"></i></span>
        </a>
    </div>
</div>

<!-- ═══════════ MOBILE NOTIFICATION PANEL ═══════════ -->
<div class="mobile-notif-panel" id="mobileNotifPanel">
    <div class="mnp-header">
        <h3 style="display:flex; align-items:center; gap:6px;">
            <span>Notifications</span>
            <span id="mnpHeaderPill" style="font-size:11.5px; font-weight:700; color:#ef4444; background:rgba(239,68,68,0.12); padding:2px 8px; border-radius:12px; display:<?php echo ($mobile_unread_count > 0) ? 'inline-block' : 'none'; ?>;"><?php echo $mobile_unread_count; ?> new</span>
        </h3>
        <div class="mnp-header-actions">
            <button id="mnpMarkAllBtn" onclick="markAllNotifRead()" title="Mark all as read" style="display:flex; align-items:center; gap:4px;">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
            <button onclick="toggleMobileNotif()" style="font-size:16px;" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="mnp-tabs">
        <button class="mnp-tab active" id="mnpTabAll" onclick="switchNotifTab(this, 'all')">All (<?php echo count($mobile_notifications); ?>)</button>
        <button class="mnp-tab" id="mnpTabUnread" onclick="switchNotifTab(this, 'unread')">Unread (<?php echo $mobile_unread_count; ?>)</button>
    </div>
    <div class="mnp-list" id="mobileNotifList">
        <?php if (!empty($mobile_notifications)): ?>
            <?php foreach ($mobile_notifications as $notif): ?>
                <div class="mnp-item-wrapper" style="position:relative; display:flex; align-items:center;">
                    <a href="<?php echo htmlspecialchars($notif['link']); ?>" 
                       class="mnp-item" 
                       data-id="<?php echo htmlspecialchars($notif['id']); ?>"
                       data-timestamp="<?php echo $notif['timestamp'] ?? 0; ?>"
                       data-status="<?php echo $notif['status']; ?>" 
                       onclick="handleNotifItemClick(event, this, '<?php echo htmlspecialchars($notif['id']); ?>')"
                       style="text-decoration:none; display:flex; position:relative; flex:1; transition: opacity 0.25s, transform 0.25s;">
                        <div class="mnp-item-icon" style="background:<?php echo $notif['icon_bg']; ?>; color:<?php echo $notif['icon_color']; ?>;">
                            <i class="fas <?php echo $notif['icon']; ?>"></i>
                        </div>
                        <div class="mnp-item-content">
                            <h4><?php echo $notif['title']; ?></h4>
                            <p><?php echo $notif['text']; ?></p>
                            <small><i class="fas fa-clock" style="margin-right:4px;"></i><?php echo $notif['time']; ?></small>
                        </div>
                        <span class="mnp-unread-dot" title="Unread" style="width:8px; height:8px; background:#ef4444; border-radius:50%; margin-left:8px; margin-top:6px; flex-shrink:0; display:<?php echo ($notif['status'] === 'unread') ? 'inline-block' : 'none'; ?>;"></span>
                    </a>
                    <button type="button" class="mnp-dismiss-btn" onclick="dismissMobileNotif(event, this, '<?php echo htmlspecialchars($notif['id']); ?>')" title="Dismiss" style="position:absolute; right:8px; top:12px; background:none; border:none; color:var(--text-muted); cursor:pointer; padding:4px 6px; font-size:16px; opacity:0.6; z-index:5; line-height:1; transition:0.2s;">&times;</button>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="mnp-empty" id="mnpEmptyState">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
        <?php endif; ?>
        <div class="mnp-empty" id="mnpNoUnreadState" style="display:none;">
            <i class="fas fa-check-circle" style="color:var(--accent); font-size:32px; display:block; margin-bottom:8px; opacity:0.8;"></i>
            <p>You're all caught up! No unread notifications.</p>
        </div>
    </div>
    <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'system admin', 'dean'])): ?>
        <div class="mnp-footer">
            <a href="pending_approvals.php">View All Pending Approvals <i class="fas fa-arrow-right" style="font-size:11px; margin-left:4px;"></i></a>
        </div>
    <?php elseif (($_SESSION['role'] ?? '') === 'student'): ?>
        <div class="mnp-footer">
            <a href="dashboard_student.php#classes-section">Go to Evaluations <i class="fas fa-arrow-right" style="font-size:11px; margin-left:4px;"></i></a>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════ MOBILE PROFILE PANEL ═══════════ -->
<div class="mobile-profile-panel" id="mobileProfilePanel">
    <div class="mpp-user-info">
        <img src="<?php echo htmlspecialchars($mobile_avatar); ?>" alt="Avatar" onerror="this.src='uploads/avatars/default.png'">
        <div>
            <div class="mpp-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
            <div class="mpp-role"><?php 
                $mrole = $_SESSION['role'] ?? '';
                echo htmlspecialchars($mrole === 'admin' ? 'QA Administrator' : ($mrole === 'system admin' ? 'ICT Admin' : ($mrole === 'dean' ? 'Dean / Department Head' : ucwords($mrole))));
            ?></div>
        </div>
    </div>
    <div style="padding: 6px 0;">
        <a href="profile.php" class="mpp-menu-item">
            <i class="fas fa-user-circle"></i>
            <span>My Profile</span>
        </a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a href="manage_users.php" class="mpp-menu-item"><i class="fas fa-users-cog"></i><span>User Accounts</span></a>
            <a href="pending_approvals.php" class="mpp-menu-item"><i class="fas fa-user-clock"></i><span>Pending Approvals</span></a>
            <a href="system_settings.php" class="mpp-menu-item"><i class="fas fa-cog"></i><span>System Settings & Ratings</span></a>
        <?php elseif (($_SESSION['role'] ?? '') === 'dean'): ?>
            <a href="dean_manage_accounts.php" class="mpp-menu-item"><i class="fas fa-user-tie"></i><span>Manage Faculty</span></a>
            <a href="dean_manage_students.php" class="mpp-menu-item"><i class="fas fa-users"></i><span>Manage Students</span></a>
            <a href="manage_subjects.php" class="mpp-menu-item"><i class="fas fa-book-open"></i><span>Manage Subjects</span></a>
        <?php elseif (($_SESSION['role'] ?? '') === 'faculty'): ?>
            <a href="faculty_enrollment.php" class="mpp-menu-item"><i class="fas fa-user-graduate"></i><span>Subject Enrollment</span></a>
            <a href="dashboard_faculty.php#analytics" class="mpp-menu-item"><i class="fas fa-chart-bar"></i><span>Performance Analytics</span></a>
        <?php elseif (($_SESSION['role'] ?? '') === 'student'): ?>
            <a href="dashboard_student.php#classes-section" class="mpp-menu-item"><i class="fas fa-chalkboard-teacher"></i><span>Evaluate Instructors</span></a>
            <a href="dashboard_student.php#evaluation-section" class="mpp-menu-item"><i class="fas fa-university"></i><span>Evaluate Facilities</span></a>
        <?php elseif (($_SESSION['role'] ?? '') === 'system admin'): ?>
            <a href="manage_users.php" class="mpp-menu-item"><i class="fas fa-user-shield"></i><span>System Users</span></a>
            <a href="system_settings.php" class="mpp-menu-item"><i class="fas fa-cog"></i><span>System Settings</span></a>
            <a href="dashboard_ict.php?section=audit" class="mpp-menu-item"><i class="fas fa-history"></i><span>Audit Trail</span></a>
        <?php endif; ?>
    </div>
    <div class="mpp-bottom">
        <?php if (!empty($_SESSION['original_admin_id'])): ?>
            <a href="logout.php" class="mpp-menu-item" style="color:#8b5cf6; font-weight:700;"><i class="fas fa-user-secret" style="color:#8b5cf6;"></i><span>Return to Admin</span></a>
        <?php endif; ?>
        <a href="logout.php" class="mpp-menu-item logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
</div>

<!-- ═══════════ MOBILE BOTTOM NAV ═══════════ -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
    <?php foreach ($mobile_nav_items as $nav): ?>
        <a href="<?php echo $nav['url']; ?>" 
           class="mbn-item <?php echo ($current_page_key === $nav['key']) ? 'active' : ''; ?>">
            <i class="fas <?php echo $nav['icon']; ?>"></i>
            <span><?php echo $nav['label']; ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<!-- ═══════════ MOBILE PANEL OVERLAY ═══════════ -->
<div class="mobile-panel-overlay" id="mobilePanelOverlay" onclick="closeAllMobilePanels()"></div>

<script>
// ── Mobile Panel Toggle Functions ──
function toggleMobileNotif() {
    const panel = document.getElementById('mobileNotifPanel');
    const profilePanel = document.getElementById('mobileProfilePanel');
    const overlay = document.getElementById('mobilePanelOverlay');
    
    if (profilePanel) profilePanel.classList.remove('open');
    if (panel) {
        panel.classList.toggle('open');
        if (overlay) overlay.classList.toggle('open', panel.classList.contains('open'));
    }
}

function toggleMobileProfile() {
    const notifPanel = document.getElementById('mobileNotifPanel');
    const profilePanel = document.getElementById('mobileProfilePanel');
    const overlay = document.getElementById('mobilePanelOverlay');
    
    if (notifPanel) notifPanel.classList.remove('open');
    if (profilePanel) {
        profilePanel.classList.toggle('open');
        if (overlay) overlay.classList.toggle('open', profilePanel.classList.contains('open'));
    }
}

function closeAllMobilePanels() {
    const panels = document.querySelectorAll('.mobile-notif-panel, .mobile-profile-panel');
    panels.forEach(p => p.classList.remove('open'));
    const overlay = document.getElementById('mobilePanelOverlay');
    if (overlay) overlay.classList.remove('open');
}

// ── Top-bar avatar → Change Profile Photo ──
// On your own profile page the hidden avatar input exists, so open the picker
// directly (inside the tap gesture, which browsers require). Everywhere else,
// follow the link to profile.php#change-photo, which opens the photo prompt.
function goToChangePhoto(e) {
    try {
        closeAllMobilePanels();
        const input = document.getElementById('avatar-input');
        if (input && input.type === 'file') {
            if (e) e.preventDefault();
            input.click();
            return false;
        }
    } catch (err) { /* fall back to normal navigation */ }
    return true;
}
window.goToChangePhoto = goToChangePhoto;

// ── Unified Mobile Sidebar Functions ──
let lastSidebarOpenTime = 0;

function openSidebar(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
    lastSidebarOpenTime = Date.now();
}

function closeSidebar(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    // Debounce ghost clicks / touch-through within 250ms of opening
    if (Date.now() - lastSidebarOpenTime < 250) return;
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
}

function toggleSidebar(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const sidebar = document.querySelector('.sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        closeSidebar(e);
    } else {
        openSidebar(e);
    }
}

window.openSidebar = openSidebar;
window.closeSidebar = closeSidebar;
window.toggleSidebar = toggleSidebar;

// ── Notification Persistence & Management ──
const currentUserId = '<?php echo (int)($_SESSION['user_id'] ?? 0); ?>';
const storageKeyReadIds = 'mcnp_read_notifs_' + currentUserId;
const storageKeyClearedAll = 'mcnp_notifs_cleared_time_' + currentUserId;

function getReadNotifIds() {
    try {
        return JSON.parse(localStorage.getItem(storageKeyReadIds) || '[]');
    } catch (e) {
        return [];
    }
}

function saveReadNotifIds(ids) {
    try {
        localStorage.setItem(storageKeyReadIds, JSON.stringify(ids));
    } catch (e) {}
}

function getClearedAllTime() {
    return parseInt(localStorage.getItem(storageKeyClearedAll) || '0', 10);
}

function updateNotifBadgesAndTabs() {
    const readIds = getReadNotifIds();
    const clearedTime = getClearedAllTime();
    const wrappers = document.querySelectorAll('.mnp-item-wrapper');
    let unreadCount = 0;

    wrappers.forEach(wrap => {
        const item = wrap.querySelector('.mnp-item');
        if (!item) return;
        const id = item.getAttribute('data-id');
        const ts = parseInt(item.getAttribute('data-timestamp') || '0', 10);
        const isRead = readIds.includes(id) || (clearedTime > 0 && ts <= clearedTime);

        if (isRead) {
            item.setAttribute('data-status', 'read');
            wrap.style.display = 'none'; // Smoothly disappear once read/cleared!
        } else {
            item.setAttribute('data-status', 'unread');
            wrap.style.display = 'flex';
            unreadCount++;
        }
    });

    // Update mobile top bar bell badge (the red dot)
    const badge = document.getElementById('mobileNotifBadge');
    if (badge) {
        if (unreadCount > 0) {
            badge.classList.add('has-notif');
        } else {
            badge.classList.remove('has-notif');
        }
    }

    // Update desktop header badge if present
    const desktopBadge = document.getElementById('desktopNotifBadge');
    if (desktopBadge) {
        desktopBadge.style.display = (unreadCount > 0) ? 'block' : 'none';
    }

    // Update header pill
    const headerPill = document.getElementById('mnpHeaderPill');
    if (headerPill) {
        if (unreadCount > 0) {
            headerPill.textContent = unreadCount + ' new';
            headerPill.style.display = 'inline-block';
        } else {
            headerPill.style.display = 'none';
        }
    }

    // Update tab counters
    const unreadTab = document.getElementById('mnpTabUnread');
    if (unreadTab) {
        unreadTab.textContent = 'Unread (' + unreadCount + ')';
    }
    const allTab = document.getElementById('mnpTabAll');
    if (allTab) {
        allTab.textContent = 'All (' + unreadCount + ')';
    }

    // Update Mark All Read button visibility
    const markAllBtn = document.getElementById('mnpMarkAllBtn');
    if (markAllBtn) {
        markAllBtn.style.display = (unreadCount > 0) ? 'flex' : 'none';
    }

    // Update empty states
    const noUnread = document.getElementById('mnpNoUnreadState');
    const emptyAll = document.getElementById('mnpEmptyState');
    if (unreadCount === 0) {
        if (noUnread) noUnread.style.display = 'block';
        if (emptyAll) emptyAll.style.display = 'none';
    } else {
        if (noUnread) noUnread.style.display = 'none';
        if (emptyAll) emptyAll.style.display = 'none';
    }
}

function handleNotifItemClick(event, element, notifId) {
    const readIds = getReadNotifIds();
    if (!readIds.includes(notifId)) {
        readIds.push(notifId);
        saveReadNotifIds(readIds);
    }
    const wrap = element.closest('.mnp-item-wrapper');
    if (wrap) {
        wrap.style.display = 'none';
    }
    updateNotifBadgesAndTabs();
    // Allow natural navigation to the target link href
}

function dismissMobileNotif(event, btn, notifId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const wrap = btn.closest('.mnp-item-wrapper');
    if (wrap) {
        wrap.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        wrap.style.opacity = '0';
        wrap.style.transform = 'translateX(12px)';
        setTimeout(() => {
            wrap.style.display = 'none';
            const readIds = getReadNotifIds();
            if (!readIds.includes(notifId)) {
                readIds.push(notifId);
                saveReadNotifIds(readIds);
            }
            updateNotifBadgesAndTabs();
        }, 200);
    }
}

function markAllNotifRead() {
    const wrappers = document.querySelectorAll('.mnp-item-wrapper');
    const readIds = getReadNotifIds();
    wrappers.forEach(wrap => {
        const item = wrap.querySelector('.mnp-item');
        if (item) {
            const id = item.getAttribute('data-id');
            if (id && !readIds.includes(id)) {
                readIds.push(id);
            }
        }
        wrap.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        wrap.style.opacity = '0';
        wrap.style.transform = 'translateX(12px)';
        setTimeout(() => {
            wrap.style.display = 'none';
        }, 250);
    });
    saveReadNotifIds(readIds);
    localStorage.setItem(storageKeyClearedAll, Math.floor(Date.now() / 1000).toString());

    setTimeout(() => {
        updateNotifBadgesAndTabs();
    }, 260);

    // Visual button feedback
    const btn = document.getElementById('mnpMarkAllBtn');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-check" style="color:var(--accent);"></i> All read!';
        setTimeout(() => { 
            btn.innerHTML = '<i class="fas fa-check-double"></i> Mark all read';
            btn.style.display = 'none';
        }, 1200);
    }
}

function switchNotifTab(btn, tab) {
    document.querySelectorAll('.mnp-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    updateNotifBadgesAndTabs();
}

// ── Keep mobile theme icon in sync ──
document.addEventListener('DOMContentLoaded', function() {
    updateNotifBadgesAndTabs();
    const savedTheme = localStorage.getItem('theme') || 'light';
    const mobileIcon = document.getElementById('mobileThemeIcon');
    if (mobileIcon) {
        mobileIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    
    // Override toggleTheme to also update mobile icon
    const origToggle = window.toggleTheme;
    window.toggleTheme = function() {
        if (origToggle) origToggle();
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const mIcon = document.getElementById('mobileThemeIcon');
        if (mIcon) {
            mIcon.className = current === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    };
    
    // Auto-close sidebar ONLY when clicking in-page anchor hash links (e.g. href="#classes-section")
    document.querySelectorAll('.sidebar a[href*="#"]').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 900) {
                closeSidebar();
            }
        });
    });
    
    // Swipe to close sidebar (strictly horizontal swipe left, never during vertical scrolling)
    let touchStartX = 0;
    let touchStartY = 0;
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            if (e.touches && e.touches[0]) {
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
            }
        }, { passive: true });
        sidebar.addEventListener('touchend', function(e) {
            if (Date.now() - lastSidebarOpenTime < 300) return;
            if (e.changedTouches && e.changedTouches[0]) {
                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;
                const diffX = touchStartX - touchEndX;
                const diffY = Math.abs(touchStartY - touchEndY);
                // Must be an intentional horizontal swipe left, NOT vertical scrolling
                if (diffX > 90 && diffY < 40) {
                    closeSidebar();
                }
            }
        }, { passive: true });
    }
});

// ═══════════ UNIVERSAL SIDEBAR ACTIVE & NAVIGATION SYNCHRONIZER ═══════════
(function() {
    let isClickScrolling = false;
    let clickScrollTimer = null;

    function getCurrentFilename() {
        const path = window.location.pathname;
        return path.substring(path.lastIndexOf('/') + 1) || 'index.php';
    }

    // Set active link in sidebar and mobile bottom nav
    function setActiveSidebarLink(targetLink) {
        if (!targetLink) return;

        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            targetLink.classList.add('active');
        }

        // Synchronize mobile bottom nav
        const href = targetLink.getAttribute('href') || '';
        const dataSec = targetLink.getAttribute('data-section') || '';
        const bottomNav = document.querySelector('.mobile-bottom-nav');
        if (bottomNav) {
            bottomNav.querySelectorAll('.mbn-item').forEach(bItem => {
                bItem.classList.remove('active');
                const bHref = bItem.getAttribute('href') || '';
                if ((dataSec && bHref.includes('#' + dataSec)) || (href && (bHref === href || bHref.endsWith(href)))) {
                    bItem.classList.add('active');
                }
            });
        }
    }

    function findLinkBySection(secId) {
        if (!secId) return null;
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return null;

        let link = sidebar.querySelector(`.nav-link[data-section="${secId}"]`);
        if (link) return link;

        link = sidebar.querySelector(`.nav-link[href*="#${secId}"]`);
        return link;
    }

    function appScrollToSection(id) {
        const currentFile = getCurrentFilename();
        if (id === 'top' || !id) {
            isClickScrolling = true;
            clearTimeout(clickScrollTimer);
            clickScrollTimer = setTimeout(() => { isClickScrolling = false; }, 900);

            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (window.history.replaceState) {
                history.replaceState(null, null, currentFile);
            }
            const topLink = document.querySelector('.sidebar .nav-link[data-section="top"]') ||
                            document.querySelector(`.sidebar .nav-link[href="${currentFile}"]`);
            if (topLink) setActiveSidebarLink(topLink);
            if (window.innerWidth <= 900 && typeof closeSidebar === 'function') closeSidebar();
            return;
        }

        const element = document.getElementById(id);
        if (element) {
            isClickScrolling = true;
            clearTimeout(clickScrollTimer);
            clickScrollTimer = setTimeout(() => { isClickScrolling = false; }, 900);

            const headerOffset = window.innerWidth <= 900 ? 70 : 25;
            const elementPosition = element.getBoundingClientRect().top + window.pageYOffset;
            const offsetPosition = Math.max(0, elementPosition - headerOffset);

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });

            if (window.history.replaceState) {
                history.replaceState(null, null, '#' + id);
            } else {
                window.location.hash = id;
            }

            const targetLink = findLinkBySection(id);
            if (targetLink) setActiveSidebarLink(targetLink);
            if (window.innerWidth <= 900 && typeof closeSidebar === 'function') closeSidebar();
        }
    }

    // Expose globally so inline scripts/calls use this enhanced version
    window.appScrollToSection = appScrollToSection;
    window.scrollToSection = appScrollToSection;
    window.setActiveSidebarLink = setActiveSidebarLink;

    function initSidebarClickHandlers() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        const navLinks = sidebar.querySelectorAll('.nav-link');
        const currentFile = getCurrentFilename();

        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href') || '';
                const dataSec = this.getAttribute('data-section');

                const hashIdx = href.indexOf('#');
                const hasHash = hashIdx !== -1;
                const linkFile = hasHash ? href.substring(0, hashIdx) : href.split('?')[0];
                const isSamePage = !linkFile || linkFile === currentFile || linkFile === '' || href.startsWith('#');

                if (dataSec === 'top' || (isSamePage && (href === currentFile || href.endsWith('#top')))) {
                    e.preventDefault();
                    appScrollToSection('top');
                    return;
                }

                if (isSamePage && (dataSec || hasHash)) {
                    const secId = dataSec || href.substring(hashIdx + 1);
                    const targetEl = document.getElementById(secId);
                    if (targetEl) {
                        e.preventDefault();
                        appScrollToSection(secId);
                        return;
                    }
                }

                // Normal page navigation: highlight immediately for instant feedback
                setActiveSidebarLink(this);
            });
        });
    }

    function initScrollSpy() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        const sectionLinks = sidebar.querySelectorAll('.nav-link[data-section]');
        if (sectionLinks.length === 0) return;

        const sectionIds = [];
        sectionLinks.forEach(link => {
            const sec = link.getAttribute('data-section');
            if (sec && sec !== 'top' && document.getElementById(sec)) {
                sectionIds.push(sec);
            }
        });

        if (sectionIds.length === 0) return;

        let ticking = false;
        window.addEventListener('scroll', function() {
            if (isClickScrolling) return;
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    const scrollPos = window.pageYOffset || document.documentElement.scrollTop;

                    if (scrollPos < 200) {
                        const topLink = sidebar.querySelector('.nav-link[data-section="top"]') ||
                                        sidebar.querySelector(`.nav-link[href="${getCurrentFilename()}"]`);
                        if (topLink) setActiveSidebarLink(topLink);
                        ticking = false;
                        return;
                    }

                    let activeSecId = null;
                    const triggerLine = window.innerHeight * 0.35;

                    for (let i = sectionIds.length - 1; i >= 0; i--) {
                        const secEl = document.getElementById(sectionIds[i]);
                        if (secEl) {
                            const rect = secEl.getBoundingClientRect();
                            if (rect.top <= triggerLine) {
                                activeSecId = sectionIds[i];
                                break;
                            }
                        }
                    }

                    if (activeSecId) {
                        const targetLink = findLinkBySection(activeSecId);
                        if (targetLink) setActiveSidebarLink(targetLink);
                    }

                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    function checkInitialHash() {
        const hash = window.location.hash ? window.location.hash.substring(1) : '';
        if (hash) {
            const targetLink = findLinkBySection(hash);
            if (targetLink) {
                setActiveSidebarLink(targetLink);
                setTimeout(() => {
                    appScrollToSection(hash);
                }, 180);
            }
        }
    }

    window.addEventListener('hashchange', function() {
        const hash = window.location.hash ? window.location.hash.substring(1) : '';
        if (hash) {
            const targetLink = findLinkBySection(hash);
            if (targetLink) setActiveSidebarLink(targetLink);
        } else {
            const topLink = document.querySelector('.sidebar .nav-link[data-section="top"]');
            if (topLink) setActiveSidebarLink(topLink);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initSidebarClickHandlers();
            initScrollSpy();
            checkInitialHash();
        });
    } else {
        initSidebarClickHandlers();
        initScrollSpy();
        checkInitialHash();
    }
})();
</script>
