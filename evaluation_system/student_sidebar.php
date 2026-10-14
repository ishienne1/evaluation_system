<?php
require_once __DIR__ . '/theme_style.php';
$active_page_student = $active_page_student ?? '';
$is_impersonating = isset($_SESSION['original_admin_id']);

$user_school = $_SESSION['school'] ?? null;
if (!$user_school && isset($conn) && isset($_SESSION['id'])) {
    $u_stmt = $conn->prepare("SELECT school, dept_id FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['id']);
    $u_stmt->execute();
    $u_data = $u_stmt->get_result()->fetch_assoc();
    if ($u_data) {
        $user_school = !empty($u_data['school']) ? strtolower(trim($u_data['school'])) : (((int)$u_data['dept_id'] <= 4 && (int)$u_data['dept_id'] > 0) ? 'isap' : 'mcnp');
        $_SESSION['school'] = $user_school;
    }
}
if (!$user_school) {
    $user_school = 'isap';
}

$sidebar_logo = ($user_school === 'isap') ? 'ISAP_Logo.png' : 'MCNP_Logo.png';
$sidebar_title = ($user_school === 'isap') ? 'ISAP' : 'MCNP';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="notifications.js?v=<?php echo time(); ?>"></script>
<?php if ($is_impersonating): ?>
<div id="impersonationNotice" 
     data-name="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Student'); ?>" 
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
                <img src="<?php echo $sidebar_logo; ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span style="font-weight: 800; font-size: 1.18rem; letter-spacing: 0.3px; color: var(--text-main); line-height: 1.15;"><?php echo $sidebar_title; ?><br><small style="font-size:11px; font-weight:700; opacity:0.75; text-transform:uppercase; letter-spacing:0.6px; color: var(--text-muted);">Student Portal</small></span>
        </div>
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()" style="width: 36px; height: 36px; font-size: 15px; background: var(--toggle-bg) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); box-shadow: none; border-radius: 10px; display:flex; align-items:center; justify-content:center; cursor:pointer;" title="Toggle Theme">
            <i class="fas fa-moon"></i>
        </div>
    </div>
    
    <nav>
        <span class="sidebar-group-label">Main</span>
        <a href="dashboard_student.php" data-section="top" class="nav-link <?php echo ($active_page_student == 'dashboard') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>

        <span class="sidebar-group-label">Services & Evaluations</span>
        <a href="dashboard_student.php#classes-section" data-section="classes-section" class="nav-link <?php echo ($active_page_student == 'classes' || $active_page_student == 'classes-section') ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard-teacher"></i> Evaluate Instructors
        </a>
        <a href="dashboard_student.php#evaluation-section" data-section="evaluation-section" class="nav-link <?php echo ($active_page_student == 'facilities' || $active_page_student == 'evaluation-section') ? 'active' : ''; ?>">
            <i class="fas fa-university"></i> Evaluate Facilities
        </a>
        <span class="sidebar-group-label">Account</span>
        <a href="profile.php" class="nav-link <?php echo ($active_page_student == 'profile') ? 'active' : ''; ?>">
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
$mobile_page_title = $mobile_page_title ?? 'Student Portal';
$mobile_user_role = 'student';
$mobile_dashboard_url = 'dashboard_student.php';
include 'mobile_ui.php';
?>