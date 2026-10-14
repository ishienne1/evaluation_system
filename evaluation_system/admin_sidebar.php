<?php

/**
 * Admin Sidebar — shared include for all admin pages.
 * Usage: include 'admin_sidebar.php';
 * Set $active_page before including, e.g. $active_page = 'dashboard';
 * Valid values: dashboard, periods, categories, questions, results,
 *               students, users, pending_approvals, classes, profile
 */
require_once __DIR__ . '/theme_style.php';

$active_page = $active_page ?? '';
$user_pages = ['students', 'users', 'pending_approvals', 'faculties'];
$is_user_management_active = in_array($active_page, $user_pages);

$pending_count = 0;
if (isset($conn)) {
    $pending_res = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    if ($pending_res) {
        $pending_count = (int)$pending_res->fetch_row()[0];
    }
}

if (!function_exists('sidebar_link')) {
    function sidebar_link($href, $icon, $label, $current_page, $key)
    {
        $is_active = ($current_page === $key) ? ' active' : '';
        echo "<a href=\"{$href}\" class=\"nav-link{$is_active}\"><i class=\"fas fa-{$icon}\"></i> <span>{$label}</span></a>";
    }
}

if (!function_exists('sidebar_dropdown_link')) {
    function sidebar_dropdown_link($href, $icon, $label, $current_page, $key)
    {
        $is_active = ($current_page === $key) ? ' active' : '';
        echo "<a href=\"{$href}\" class=\"nav-link{$is_active}\"><i class=\"fas fa-{$icon}\"></i> <span>{$label}</span></a>";
    }
}
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="notifications.js?v=<?php echo time(); ?>"></script>
<?php if (!empty($_SESSION['original_admin_id'])): ?>
    <div id="impersonationNotice"
        data-name="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>"
        data-url="logout.php"
        style="display:none;"></div>
<?php endif; ?>
<script>
    // Immediately set theme on page load to prevent flashing
    (function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    })();

    // Global theme toggle function
    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', targetTheme);
        localStorage.setItem('theme', targetTheme);
        updateThemeIcon(targetTheme);
    }

    // Update moon/sun icon in theme toggle button
    function updateThemeIcon(theme) {
        const icon = document.querySelector('#themeBtn i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    // Toggle dropdown submenu smoothly with chevron rotation
    function toggleSidebarDropdown(id, headerEl) {
        const el = document.getElementById(id);
        if (!el) return;
        const isHidden = (window.getComputedStyle(el).display === 'none');
        el.style.display = isHidden ? 'block' : 'none';
        if (headerEl) {
            if (isHidden) {
                headerEl.classList.add('is-open');
            } else {
                headerEl.classList.remove('is-open');
            }
        }
    }

    // Initialize icon class once the document is ready
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        updateThemeIcon(savedTheme);
    });
</script>
<aside class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand-container">
        <div style="display:flex; align-items:center; gap:12px;">
            <div class="eval-logo-badge" style="width:42px; height:42px; flex-shrink:0;">
                <img src="mcnp-isap.png" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span style="font-weight:800; font-size:1.18rem; letter-spacing:0.3px; color: var(--text-main); line-height: 1.15;">MCNP-ISAP<br><small style="font-size:11px; font-weight:700; opacity:0.85; text-transform:uppercase; letter-spacing:0.6px; color: var(--accent);">QA Portal</small></span>
        </div>
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()"
            style="width:36px; height:36px; font-size:15px; background: var(--toggle-bg) !important;
                    color: var(--text-main) !important; border: 1px solid var(--border-color); box-shadow:none; flex-shrink:0; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer;"
            title="Toggle Theme">
            <i class="fas fa-moon"></i>
        </div>
    </div>

    <!-- Navigation -->
    <nav>
        <span class="sidebar-group-label">Main</span>
        <?php sidebar_link('dashboard_admin.php',  'tachometer-alt',    'Dashboard',           $active_page, 'dashboard'); ?>

        <span class="sidebar-group-label">User Management</span>
        <?php
        $pending_badge = $pending_count > 0 ? " <span style='background:#ef4444; color:white; border-radius:10px; padding: 2px 7px; font-size: 10.5px; font-weight: 800; margin-left: auto;'>$pending_count</span>" : "";
        sidebar_link('pending_approvals.php',  'user-clock',          'Pending Approvals' . $pending_badge, $active_page, 'pending_approvals');
        sidebar_link('manage_instructors.php', 'chalkboard-teacher',  'Instructors / Faculty',              $active_page, 'faculties');
        sidebar_link('manage_students.php',    'user-graduate',       'Students',                           $active_page, 'students');
        sidebar_link('manage_users.php',       'users-cog',           'User Accounts',                      $active_page, 'users');
        ?>

        <span class="sidebar-group-label">Evaluation Criteria</span>
        <?php sidebar_link('manage_courses.php',   'graduation-cap',     'Manage Courses',      $active_page, 'courses'); ?>
        <?php sidebar_link('manage_classes.php',   'chalkboard-teacher', 'Class Management',    $active_page, 'classes'); ?>
        <?php sidebar_link('manage_periods.php',   'calendar-check',    'Academic Periods',    $active_page, 'periods'); ?>
        <?php sidebar_link('manage_categories.php', 'tags',              'Manage Categories',   $active_page, 'categories'); ?>
        <?php sidebar_link('manage_questions.php', 'tasks',             'Manage Questions', $active_page, 'questions'); ?>
        <?php sidebar_link('view_results.php',     'chart-pie',         'Evaluation Reports',  $active_page, 'results'); ?>
        <?php sidebar_link('print_center.php',     'print',             'Print Center',        $active_page, 'print_center'); ?>
        <?php sidebar_link('import_evaluations.php', 'file-import',       'Import Evaluations',  $active_page, 'import_evaluations'); ?>

        <span class="sidebar-group-label">System & Configuration</span>
        <?php sidebar_link('system_settings.php',   'cog',               'System Settings & Ratings', $active_page, 'system_settings'); ?>
        <?php sidebar_link('profile.php',           'user-circle',      'My Profile',                $active_page, 'profile'); ?>
    </nav>

    <?php if (!empty($_SESSION['original_admin_id'])): ?>
    <!-- User Impersonation Return Block -->
    <div style="padding: 12px 16px; border-top: 1px solid var(--border-color); margin-top: auto;">
        <a href="logout.php" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; background: rgba(139, 92, 246, 0.12); border: 1px solid rgba(139, 92, 246, 0.25); border-radius: 8px; text-decoration: none; transition: all 0.2s; height: 38px;" title="Return to Admin">
            <i class="fas fa-user-secret" style="color:#8b5cf6;"></i>
            <span style="color:#8b5cf6; font-size:12px; font-weight:700;">Return to Admin</span>
        </a>
    </div>
    <?php endif; ?>
</aside>
<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" onclick="closeSidebar(event)"></div>
<!-- Floating Mobile Hamburger Button -->
<button class="mobile-hamburger-btn" onclick="toggleSidebar(event)" aria-label="Open Menu"><i class="fas fa-bars"></i></button>
<script>
    /* Sidebar bootstrap — mobile_ui.php will override these with the full implementation */
    function _sidebarDOM(action) {
        const s = document.querySelector('.sidebar');
        const o = document.querySelector('.sidebar-overlay');
        if (action === 'open') {
            if (s) s.classList.add('open');
            if (o) o.classList.add('show');
        }
        if (action === 'close') {
            if (s) s.classList.remove('open');
            if (o) o.classList.remove('show');
        }
        if (action === 'toggle') {
            if (s) s.classList.toggle('open');
            if (o) o.classList.toggle('show');
        }
    }

    function toggleSidebar(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        _sidebarDOM('toggle');
    }

    function closeSidebar(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        _sidebarDOM('close');
    }

    function openSidebar(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        _sidebarDOM('open');
    }
</script>
<?php
$mobile_page_title = $mobile_page_title ?? 'Admin Portal';
$mobile_user_role = 'admin';
$mobile_dashboard_url = 'dashboard_admin.php';
include 'mobile_ui.php';
?>