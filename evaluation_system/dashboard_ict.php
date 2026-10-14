<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'system admin') {
    header("Location: index.php"); exit();
}

$full_name = $_SESSION['full_name'] ?? 'ICT Admin';

// ── Stats ─────────────────────────────────────────────────────────────
$total_users    = $conn->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetch_row()[0] ?? 0;
$total_students = $conn->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetch_row()[0] ?? 0;
$total_faculty  = $conn->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND status='active'")->fetch_row()[0] ?? 0;
$total_admins   = $conn->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','system admin') AND status='active'")->fetch_row()[0] ?? 0;
$pending_count  = $conn->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetch_row()[0] ?? 0;
$total_evals    = $conn->query("SELECT COUNT(*) FROM evaluations")->fetch_row()[0] ?? 0;
$system_name    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_name'")->fetch_assoc()['setting_value'] ?? 'Faculty Evaluation System';
$eval_status    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='evaluation_status'")->fetch_assoc()['setting_value'] ?? 'closed';
$active_period  = $conn->query("SELECT * FROM academic_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();

// ── Audit Logs ────────────────────────────────────────────────────────
$show_audit = isset($_GET['section']) && $_GET['section'] === 'audit';

// Check if system_logs table exists
$logs_table_check = $conn->query("SHOW TABLES LIKE 'system_logs'");
$has_logs_table = $logs_table_check && $logs_table_check->num_rows > 0;

$audit_logs = [];
if ($has_logs_table) {
    $log_res = $conn->query("SELECT * FROM system_logs ORDER BY timestamp DESC LIMIT 50");
    if ($log_res) $audit_logs = $log_res->fetch_all(MYSQLI_ASSOC);
}

// ── User activity per role ─────────────────────────────────────────────
$role_dist = $conn->query("SELECT role, COUNT(*) as cnt FROM users WHERE status='active' GROUP BY role");
$role_data = [];
if ($role_dist) {
    while ($r = $role_dist->fetch_assoc()) $role_data[$r['role']] = (int)$r['cnt'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ICT Admin Dashboard — <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── ICT-specific accent colors ── */
:root { --ict-primary: #0891b2; --ict-secondary: #06b6d4; --ict-dark: #0e7490; }

.ict-stat-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 22px 24px;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    backdrop-filter: blur(12px);
}
.ict-stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
.ict-stat-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
}
.ict-stat-card.cyan::before  { background: linear-gradient(90deg, #0891b2, #06b6d4); }
.ict-stat-card.blue::before  { background: linear-gradient(90deg, #2563eb, #3b82f6); }
.ict-stat-card.green::before { background: linear-gradient(90deg, #059669, #10b981); }
.ict-stat-card.amber::before { background: linear-gradient(90deg, #d97706, #f59e0b); }
.ict-stat-card.red::before   { background: linear-gradient(90deg, #dc2626, #ef4444); }
.ict-stat-card.purple::before{ background: linear-gradient(90deg, #7c3aed, #8b5cf6); }

.ict-stat-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; margin-bottom: 14px;
}
.ict-stat-number { font-size: 2rem; font-weight: 800; color: var(--text-main); line-height: 1; }
.ict-stat-label  { font-size: 12px; color: var(--text-muted); font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

.quick-action-btn {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: 12px;
    background: var(--card-bg); border: 1px solid var(--border-color);
    text-decoration: none; color: var(--text-main);
    transition: all 0.2s; font-weight: 600; font-size: 14px;
    backdrop-filter: blur(10px);
}
.quick-action-btn:hover { transform: translateX(4px); box-shadow: 0 4px 16px rgba(0,0,0,0.1); border-color: var(--ict-primary); }
.quick-action-btn .qa-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}

.audit-row {
    display: grid; grid-template-columns: 140px 1fr 140px;
    align-items: center; gap: 16px;
    padding: 12px 16px; border-bottom: 1px solid var(--border-color);
    font-size: 13px; transition: background 0.2s;
}
.audit-row:hover { background: rgba(8,145,178,0.04); }
.audit-row:last-child { border-bottom: none; }

.status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.status-pill.open   { background: #d1fae5; color: #065f46; }
.status-pill.closed { background: #fee2e2; color: #991b1b; }

.section-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px;
}
.section-title {
    font-size: 15px; font-weight: 700; color: var(--text-main);
    display: flex; align-items: center; gap: 8px;
}

.header-ict {
    background: linear-gradient(135deg, #0891b2, #0e7490);
    border-radius: 16px; padding: 24px 28px;
    color: white; margin-bottom: 28px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 8px 32px rgba(8,145,178,0.3);
}
.ict-main-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 992px) {
    .ict-main-grid { grid-template-columns: 1fr !important; }
}
@media (max-width: 768px) {
    .header-ict { flex-direction: column; text-align: center; gap: 15px; padding: 18px 16px; }
    .header-ict > div:last-child { text-align: center !important; }
    .audit-row { grid-template-columns: 1fr !important; gap: 6px; }
}
</style>
</head>
<body class="dashboard-body">

<div style="display:flex; min-height:100vh; position:relative;">

    <?php $active_page = $show_audit ? 'audit' : 'ict_dashboard'; $mobile_page_title = 'ICT Dashboard'; include 'ict_sidebar.php'; ?>

    <main class="main-content" style="flex:1; padding:30px; overflow-y:auto;">

        <!-- Header Bar -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">

            <div>
                <h1 style="font-size:1.5rem; font-weight:800; color:var(--text-main);">
                    <i class="fas fa-desktop" style="color:#0891b2;"></i>
                    ICT Admin Dashboard
                </h1>
                <p style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                    Welcome back, <strong><?php echo htmlspecialchars($full_name); ?></strong> &mdash;
                    <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div style="display:flex; gap:12px; align-items:center;">
                <a href="?section=audit" class="btn-action" style="background: linear-gradient(135deg,#0891b2,#0e7490); color:white; text-decoration:none; padding:9px 14px; border-radius:10px; font-size:12.5px; font-weight:700; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-history"></i> Audit Trail
                </a>
                <a href="system_settings.php" class="btn-action" style="background: linear-gradient(135deg,#7c3aed,#6d28d9); color:white; text-decoration:none; padding:9px 14px; border-radius:10px; font-size:12.5px; font-weight:700; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-cog"></i> Settings
                </a>

                <div style="position: relative; display: inline-block;">
                    <div class="profile-widget-modern" id="profileWidget" style="cursor: pointer; user-select: none;">
                        <?php
                        $avatar_path = $_SESSION['avatar'] ?? 'uploads/avatars/default.png';
                        if (!file_exists($avatar_path)) $avatar_path = 'uploads/avatars/default.png';
                        ?>
                        <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Avatar" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:2px solid var(--border-color);">
                        <div style="text-align:left; line-height:1.15;">
                            <div style="font-weight:700; font-size:12.5px; color:var(--text-main);"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size:10px; color:var(--text-muted); font-weight:600;">ICT Admin</div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:10px; color:var(--text-muted); margin-left: 2px;"></i>
                    </div>

                    <!-- ICT Profile Dropdown Menu -->
                    <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                        <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 11px; color:#0891b2; font-weight: 600; margin-top: 3px;">ICT Administrator</div>
                        </div>
                        <div style="padding: 6px 0;">
                            <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                            </a>
                            <a href="manage_users.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-shield" style="color:#0891b2; width:16px;"></i> System Users
                            </a>
                            <a href="system_settings.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-cog" style="color:#7c3aed; width:16px;"></i> System Settings
                            </a>
                            <a href="?section=audit" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-history" style="color:#d97706; width:16px;"></i> View Audit Trail
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

        <!-- Hero Banner -->
        <div class="header-ict">
            <div>
                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:1px; opacity:0.75; margin-bottom:6px;">
                    <i class="fas fa-shield-alt"></i> ICT Administration
                </div>
                <div style="font-size:1.4rem; font-weight:800; line-height:1.2;">
                    System Status Overview
                </div>
                <div style="font-size:13px; opacity:0.85; margin-top:6px;">
                    Evaluation System:
                    <span class="status-pill <?php echo $eval_status; ?>" style="background:rgba(255,255,255,0.2); color:white; border:1px solid rgba(255,255,255,0.3);">
                        <i class="fas fa-<?php echo $eval_status==='open' ? 'lock-open' : 'lock'; ?>"></i>
                        <?php echo ucfirst($eval_status); ?>
                    </span>
                    &nbsp;|&nbsp; Active Period:
                    <strong><?php echo $active_period ? htmlspecialchars($active_period['semester'].' '.$active_period['school_year']) : 'None'; ?></strong>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:2.5rem; font-weight:900; line-height:1;"><?php echo $total_users; ?></div>
                <div style="font-size:12px; opacity:0.8; margin-top:2px;">Active System Users</div>
            </div>
        </div>

        <!-- Stat Cards -->
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; margin-bottom:28px;">

            <div class="ict-stat-card cyan">
                <div class="ict-stat-icon" style="background:rgba(8,145,178,0.12); color:#0891b2;">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="ict-stat-number"><?php echo number_format($total_students); ?></div>
                <div class="ict-stat-label">Students</div>
            </div>

            <div class="ict-stat-card blue">
                <div class="ict-stat-icon" style="background:rgba(37,99,235,0.12); color:#2563eb;">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="ict-stat-number"><?php echo number_format($total_faculty); ?></div>
                <div class="ict-stat-label">Faculty</div>
            </div>

            <div class="ict-stat-card purple">
                <div class="ict-stat-icon" style="background:rgba(124,58,237,0.12); color:#7c3aed;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ict-stat-number"><?php echo number_format($total_admins); ?></div>
                <div class="ict-stat-label">Admin Users</div>
            </div>

            <div class="ict-stat-card amber">
                <div class="ict-stat-icon" style="background:rgba(217,119,6,0.12); color:#d97706;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="ict-stat-number"><?php echo number_format($pending_count); ?></div>
                <div class="ict-stat-label">Pending Approvals</div>
            </div>

            <div class="ict-stat-card green">
                <div class="ict-stat-icon" style="background:rgba(5,150,105,0.12); color:#059669;">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="ict-stat-number"><?php echo number_format($total_evals); ?></div>
                <div class="ict-stat-label">Total Evaluations</div>
            </div>

            <div class="ict-stat-card <?php echo $eval_status==='open' ? 'green' : 'red'; ?>">
                <div class="ict-stat-icon" style="background:rgba(<?php echo $eval_status==='open'?'5,150,105':'220,38,38'; ?>,0.12); color:<?php echo $eval_status==='open'?'#059669':'#dc2626'; ?>;">
                    <i class="fas fa-<?php echo $eval_status==='open'?'lock-open':'lock'; ?>"></i>
                </div>
                <div class="ict-stat-number" style="font-size:1.4rem;"><?php echo ucfirst($eval_status); ?></div>
                <div class="ict-stat-label">Evaluation Status</div>
            </div>

        </div>

        <!-- Main Grid: Chart + Quick Actions -->
        <div class="ict-main-grid">

            <!-- User Distribution Chart -->
            <div class="card" style="padding:24px;">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-chart-donut" style="color:#0891b2;"></i>
                        User Distribution
                    </div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; height:240px;">
                    <canvas id="userDistChart"></canvas>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin-top:16px;">
                    <?php
                    $colors = ['student'=>'#0891b2','faculty'=>'#2563eb','admin'=>'#7c3aed','system admin'=>'#d97706','dean'=>'#059669'];
                    foreach ($role_data as $role => $cnt) {
                        $c = $colors[$role] ?? '#94a3b8';
                        echo "<div style='display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);'>
                            <div style='width:10px;height:10px;border-radius:3px;background:{$c};'></div>
                            <span style='font-weight:600;'>".ucwords($role)."</span>: <strong style='color:var(--text-main);'>{$cnt}</strong>
                        </div>";
                    }
                    ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card" style="padding:24px;">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-bolt" style="color:#0891b2;"></i>
                        Quick Actions
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <a href="manage_users.php" class="quick-action-btn">
                        <div class="qa-icon" style="background:rgba(124,58,237,0.12); color:#7c3aed;">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        Manage System Users
                    </a>
                    <a href="system_settings.php" class="quick-action-btn">
                        <div class="qa-icon" style="background:rgba(8,145,178,0.12); color:#0891b2;">
                            <i class="fas fa-cog"></i>
                        </div>
                        System Settings
                    </a>
                    <a href="?section=audit" class="quick-action-btn">
                        <div class="qa-icon" style="background:rgba(217,119,6,0.12); color:#d97706;">
                            <i class="fas fa-history"></i>
                        </div>
                        View Audit Trail
                    </a>
                    <a href="profile.php" class="quick-action-btn">
                        <div class="qa-icon" style="background:rgba(37,99,235,0.12); color:#2563eb;">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        My Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Audit Trail Section -->
        <?php if ($show_audit): ?>
        <div class="card" style="padding:24px; margin-bottom:24px;">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-history" style="color:#0891b2;"></i>
                    Audit Trail — System Activity Log
                </div>
                <a href="dashboard_ict.php" style="font-size:12px; color:var(--text-muted); text-decoration:none; display:flex; align-items:center; gap:4px;">
                    <i class="fas fa-times"></i> Close
                </a>
            </div>
            <?php if ($has_logs_table && count($audit_logs) > 0): ?>
            <div class="table-responsive">
                <div style="min-width:600px;">
                    <div class="audit-row" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); border-bottom: 2px solid var(--border-color);">
                        <div>Timestamp</div>
                        <div>Action / Message</div>
                        <div>User</div>
                    </div>
                    <?php foreach ($audit_logs as $log): ?>
                    <div class="audit-row">
                        <div style="color:var(--text-muted); font-size:12px;">
                            <i class="fas fa-clock" style="margin-right:4px;"></i>
                            <?php echo date('M j, Y H:i', strtotime($log['timestamp'] ?? $log['created_at'] ?? '')); ?>
                        </div>
                        <div style="color:var(--text-main);"><?php echo htmlspecialchars($log['message'] ?? $log['action'] ?? 'N/A'); ?></div>
                        <div>
                            <span style="font-size:11px; background:rgba(8,145,178,0.1); color:#0891b2; padding:3px 8px; border-radius:8px; font-weight:600;">
                                <?php echo htmlspecialchars($log['user'] ?? $log['performed_by'] ?? 'System'); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <i class="fas fa-history" style="font-size:36px; opacity:0.3; display:block; margin-bottom:10px;"></i>
                <div style="font-size:14px;">No audit logs available yet.</div>
                <div style="font-size:12px; margin-top:4px;">Activity will be logged here once the system is used.</div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Recent System Users -->
        <div class="card" style="padding:24px;">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-users" style="color:#0891b2;"></i>
                    Recent System Users
                </div>
                <a href="manage_users.php" style="font-size:12px; color:#0891b2; font-weight:700; text-decoration:none;">View All →</a>
            </div>
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>ID Number</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sys_users = $conn->query("SELECT full_name, username, role, status, last_login FROM users WHERE role IN ('admin','system admin','dean') ORDER BY last_login DESC LIMIT 8");
                    while ($u = $sys_users->fetch_assoc()):
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($u['full_name']); ?></strong></td>
                        <td style="color:var(--text-muted); font-size:13px;"><?php echo htmlspecialchars($u['username']); ?></td>
                        <td>
                            <?php
                            $role_label = $u['role'] === 'system admin' ? 'ICT Admin' : ucwords($u['role']);
                            $role_color = $u['role'] === 'system admin' ? '#0891b2' : ($u['role'] === 'admin' ? '#7c3aed' : '#059669');
                            $role_bg    = $u['role'] === 'system admin' ? 'rgba(8,145,178,0.1)' : ($u['role'] === 'admin' ? 'rgba(124,58,237,0.1)' : 'rgba(5,150,105,0.1)');
                            ?>
                            <span style="background:<?php echo $role_bg; ?>; color:<?php echo $role_color; ?>; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700;">
                                <?php echo $role_label; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="category-badge" style="background:#d1fae5; color:#065f46;">Active</span>
                            <?php else: ?>
                                <span class="category-badge" style="background:#fee2e2; color:#991b1b;"><?php echo ucfirst($u['status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted); font-size:12px;">
                            <?php echo $u['last_login'] ? date('M j, Y H:i', strtotime($u['last_login'])) : '<em>Never</em>'; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<script>


// Theme
const saved = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', saved);

// User Distribution Chart
const roleData = <?php echo json_encode(array_values($role_data)); ?>;
const roleLabels = <?php echo json_encode(array_map('ucwords', array_keys($role_data))); ?>;
const roleColors = ['#0891b2','#2563eb','#7c3aed','#d97706','#059669','#ef4444','#94a3b8'];

if (document.getElementById('userDistChart') && roleData.length > 0) {
    new Chart(document.getElementById('userDistChart'), {
        type: 'doughnut',
        data: {
            labels: roleLabels,
            datasets: [{
                data: roleData,
                backgroundColor: roleColors.slice(0, roleData.length),
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            cutout: '68%'
        }
    });
}

document.addEventListener('click', function(e) {
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
