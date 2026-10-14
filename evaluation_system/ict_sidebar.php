<?php
/**
 * ICT Admin Sidebar — shared include for all ICT admin pages.
 * Usage: include 'ict_sidebar.php';
 * Set $active_page before including, e.g. $active_page = 'ict_dashboard';
 * Valid values: ict_dashboard, system_settings, users, results
 */
require_once __DIR__ . '/theme_style.php';
$active_page = $active_page ?? '';

$pending_count = 0;
if (isset($conn)) {
    $pending_res = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    if ($pending_res) {
        $pending_count = (int)$pending_res->fetch_row()[0];
    }
}

function ict_sidebar_link($href, $icon, $label, $current_page, $key) {
    $is_active = ($current_page === $key) ? ' active' : '';
    echo "<a href=\"{$href}\" class=\"nav-link{$is_active}\"><i class=\"fas fa-{$icon}\"></i> {$label}</a>";
}
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="notifications.js?v=<?php echo time(); ?>"></script>
<?php if (!empty($_SESSION['original_admin_id'])): ?>
<div id="impersonationNotice" 
     data-name="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ICT Admin'); ?>" 
     data-url="logout.php" 
     style="display:none;"></div>
<?php endif; ?>
<script>
    (function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    })();

    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', targetTheme);
        localStorage.setItem('theme', targetTheme);
        updateThemeIcon(targetTheme);
    }

    function updateThemeIcon(theme) {
        const icon = document.querySelector('#themeBtn i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        updateThemeIcon(savedTheme);
    });
</script>
<aside class="sidebar" style="--sidebar-accent: #0891b2;">

    <!-- Brand -->
    <div class="sidebar-brand-container">
        <div style="display:flex; align-items:center; gap:12px;">
            <div class="eval-logo-badge" style="width:42px; height:42px; flex-shrink:0;">
                <img src="mcnp-isap.png" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span style="font-weight:800; font-size:1.18rem; letter-spacing:0.3px; color: var(--text-main); line-height: 1.15;">MCNP-ISAP<br><small style="font-size:11px; font-weight:700; opacity:0.75; text-transform:uppercase; letter-spacing:0.6px; color: var(--text-muted);">ICT Admin Portal</small></span>
        </div>
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()"
             style="width:36px; height:36px; font-size:15px; background: var(--toggle-bg) !important;
                    color: var(--text-main) !important; border: 1px solid var(--border-color); box-shadow:none; flex-shrink:0; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer;"
             title="Toggle Theme">
            <i class="fas fa-moon"></i>
        </div>
    </div>

    <!-- ICT Role Badge -->
    <div style="padding: 8px 16px 12px;">
        <div style="background: linear-gradient(135deg, rgba(8,145,178,0.15), rgba(6,182,212,0.1)); border: 1px solid rgba(8,145,178,0.25); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-shield-alt" style="color: #0891b2; font-size: 13px;"></i>
            <div>
                <div style="font-size: 10px; font-weight: 700; color: #0891b2; text-transform: uppercase; letter-spacing: 0.5px;">ICT Administrator</div>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 1px;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ICT Admin'); ?></div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav>
        <span class="sidebar-group-label">Main</span>
        <?php ict_sidebar_link('dashboard_ict.php', 'desktop', 'ICT Dashboard', $active_page, 'ict_dashboard'); ?>

        <span class="sidebar-group-label">Account Management</span>
        <?php ict_sidebar_link('manage_users.php', 'user-shield', 'System Users', $active_page, 'users'); ?>

        <span class="sidebar-group-label">System</span>
        <?php ict_sidebar_link('system_settings.php', 'cog', 'System Settings', $active_page, 'system_settings'); ?>
        <?php ict_sidebar_link('dashboard_ict.php?section=audit', 'history', 'Audit Trail', $active_page, 'audit'); ?>

        <span class="sidebar-group-label">Account</span>
        <?php ict_sidebar_link('profile.php', 'user-circle', 'My Profile', $active_page, 'profile'); ?>
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
    if (action === 'open')   { if (s) s.classList.add('open');    if (o) o.classList.add('show'); }
    if (action === 'close')  { if (s) s.classList.remove('open'); if (o) o.classList.remove('show'); }
    if (action === 'toggle') { if (s) s.classList.toggle('open'); if (o) o.classList.toggle('show'); }
}
function toggleSidebar(e) { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('toggle'); }
function closeSidebar(e)  { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('close'); }
function openSidebar(e)   { if (e && e.stopPropagation) e.stopPropagation(); _sidebarDOM('open'); }
</script>
<?php
$mobile_page_title = $mobile_page_title ?? 'ICT Admin';
$mobile_user_role = 'system admin';
$mobile_dashboard_url = 'dashboard_ict.php';
include 'mobile_ui.php';
?>

