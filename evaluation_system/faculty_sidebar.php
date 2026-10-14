<?php
require_once __DIR__ . '/theme_style.php';
$active_page_faculty = $active_page_faculty ?? '';
$is_impersonating = isset($_SESSION['original_admin_id']);
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="notifications.js?v=<?php echo time(); ?>"></script>
<?php if ($is_impersonating): ?>
<div id="impersonationNotice" 
     data-name="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Faculty'); ?>" 
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

    // Initialize icon class once the document is ready
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        updateThemeIcon(savedTheme);
    });
</script>
<aside class="sidebar">
    <div class="sidebar-brand-container">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="eval-logo-badge" style="width: 42px; height: 42px;">
                <img src="mcnp-isap.png" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span style="font-weight: 800; font-size: 1.18rem; letter-spacing: 0.3px; color: var(--text-main); line-height: 1.15;">MCNP-ISAP<br><small style="font-size:11px; font-weight:700; opacity:0.75; text-transform:uppercase; letter-spacing:0.6px; color: var(--text-muted);">Faculty Portal</small></span>
        </div>
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()" style="width: 36px; height: 36px; font-size: 15px; background: var(--toggle-bg) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); box-shadow: none; border-radius: 10px; display:flex; align-items:center; justify-content:center; cursor:pointer;" title="Toggle Theme">
            <i class="fas fa-moon"></i>
        </div>
    </div>
    
    <nav>
        <span class="sidebar-group-label">Main</span>
        <a href="dashboard_faculty.php" data-section="top" class="nav-link <?php echo ($active_page_faculty == 'dashboard') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>

        <span class="sidebar-group-label">Reports & Comments</span>
        <a href="dashboard_faculty.php#analytics" data-section="analytics" class="nav-link <?php echo ($active_page_faculty == 'analytics') ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i> Performance Analytics
        </a>
        <a href="dashboard_faculty.php#feedback" data-section="feedback" class="nav-link <?php echo ($active_page_faculty == 'feedback') ? 'active' : ''; ?>">
            <i class="fas fa-comments"></i> Student Feedback
        </a>

        <span class="sidebar-group-label">Class Management</span>
        <a href="faculty_enrollment.php" class="nav-link <?php echo ($active_page_faculty == 'enrollment') ? 'active' : ''; ?>">
            <i class="fas fa-user-graduate"></i> Subject Enrollment
        </a>

        <span class="sidebar-group-label">Institutional Evaluation</span>
        <a href="dashboard_faculty.php#dean-eval" data-section="dean-eval" class="nav-link <?php echo ($active_page_faculty == 'dean-eval' || $active_page_faculty == 'evaluate_dean') ? 'active' : ''; ?>">
            <i class="fas fa-user-tie"></i> Evaluate Dean
        </a>
        <a href="dashboard_faculty.php#facilities-section" data-section="facilities-section" class="nav-link <?php echo ($active_page_faculty == 'facilities' || $active_page_faculty == 'facilities-section') ? 'active' : ''; ?>">
            <i class="fas fa-university"></i> Evaluate Facilities
        </a>
        <span class="sidebar-group-label">Account</span>
        <a href="profile.php" class="nav-link <?php echo ($active_page_faculty == 'profile') ? 'active' : ''; ?>">
            <i class="fas fa-user-circle"></i> My Profile
        </a>
    </nav>

    <?php if ($is_impersonating): ?>
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
    if (action === 'open')   { if (s) s.classList.add('open');    if (o) o.classList.add('show'); }
    if (action === 'close')  { if (s) s.classList.remove('open'); if (o) o.classList.remove('show'); }
    if (action === 'toggle') { if (s) s.classList.toggle('open'); if (o) o.classList.toggle('show'); }
}
function toggleSidebar(e) { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('toggle'); }
function closeSidebar(e)  { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('close'); }
function openSidebar(e)   { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('open'); }
</script>
<?php
$mobile_page_title = $mobile_page_title ?? 'Faculty Portal';
$mobile_user_role = 'faculty';
$mobile_dashboard_url = 'dashboard_faculty.php';
include 'mobile_ui.php';
?>