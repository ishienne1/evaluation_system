<?php
session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin'])) {
    header("Location: index.php");
    exit();
}
include 'db_connect.php';

$is_impersonating = isset($_SESSION['original_admin_id']);

// Handle System Settings Update
if (isset($_POST['update_settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $stmt->execute();
    }
    header("Location: system_settings.php?msg=settings_updated"); exit();
}

// Handle Logo Upload
if (isset($_POST['upload_logo']) && isset($_FILES['school_logo'])) {
    $file = $_FILES['school_logo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
            // Save as Logo.png (overwrite)
            if (move_uploaded_file($file['tmp_name'], 'Logo.png')) {
                header("Location: system_settings.php?msg=logo_updated"); exit();
            }
        }
    }
    header("Location: system_settings.php?msg=logo_error"); exit();
}

// Handle Theme Reset
if (isset($_POST['reset_theme'])) {
    $theme_keys = [
        'theme_primary', 'theme_accent', 'theme_background',
        'theme_dark_primary', 'theme_dark_accent', 'theme_dark_background',
        'theme_dark_card', 'theme_dark_text'
    ];
    $placeholders = implode(',', array_fill(0, count($theme_keys), '?'));
    $stmt = $conn->prepare("DELETE FROM system_settings WHERE setting_key IN ($placeholders)");
    if ($stmt) {
        $stmt->bind_param(str_repeat('s', count($theme_keys)), ...$theme_keys);
        $stmt->execute();
    }
    header("Location: system_settings.php?msg=theme_reset");
    exit();
}

// Handle Password Reset
if (isset($_POST['reset_password'])) {
    $user_id_to_reset = (int)$_POST['user_id'];
    $new_password_hashed = password_hash('123456', PASSWORD_DEFAULT);

    if ($user_id_to_reset != $_SESSION['id'] && $user_id_to_reset > 0) {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password_hashed, $user_id_to_reset);
        $stmt->execute();
        
        $log_stmt = $conn->prepare("INSERT INTO system_logs (level, message, context, user_id) VALUES ('warning', 'Admin reset password for a user.', ?, ?)");
        $log_context = "Admin ID: {$_SESSION['id']}, User ID: {$user_id_to_reset}";
        $log_stmt->bind_param("si", $log_context, $_SESSION['id']);
        $log_stmt->execute();
        header("Location: system_settings.php?msg=reset_ok"); exit();
    }
}

// Fetch settings
$max_rating_val = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'")->fetch_assoc()['setting_value'] ?? 5;
$system_name    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'Faculty Evaluation System';
$min_feedback   = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_feedback_length'")->fetch_assoc()['setting_value'] ?? 0;
$fac_visible    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'faculty_results_visible'")->fetch_assoc()['setting_value'] ?? 'hidden';

// Fetch Theme Settings
$theme_settings_q = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'theme_%'");
$theme_settings = [];
if ($theme_settings_q) { while($row = $theme_settings_q->fetch_assoc()){ $theme_settings[$row['setting_key']] = $row['setting_value']; } }
$theme_primary    = $theme_settings['theme_primary'] ?? '#283593';
$theme_accent     = $theme_settings['theme_accent'] ?? '#b71c1c';
$theme_background = $theme_settings['theme_background'] ?? '#f4f6f9';
$theme_dark_primary = $theme_settings['theme_dark_primary'] ?? '#1a237e';
$theme_dark_accent = $theme_settings['theme_dark_accent'] ?? '#b71c1c';
$theme_dark_background = $theme_settings['theme_dark_background'] ?? '#0b1121';
$theme_dark_card = $theme_settings['theme_dark_card'] ?? 'rgba(22, 27, 44, 0.5)';
$theme_dark_text = $theme_settings['theme_dark_text'] ?? '#f8fafc';

$pending_count = $conn->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include 'theme_style.php'; ?>
    <title>System Settings — <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div style="display:flex; min-height:100vh; position:relative;">

    <?php
    $active_page = 'system_settings';
    if ($_SESSION['role'] === 'admin') {
        include 'admin_sidebar.php';
    } else {
        include 'ict_sidebar.php';
    }
    ?>

    <!-- Main Content -->
    <main class="main-content" style="flex:1; padding:30px; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 style="color:var(--text-main); margin:0;"><i class="fas fa-cogs" style="color:var(--accent);"></i> System Settings & Configuration</h2>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'settings_updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> System settings updated successfully.</div>
            <?php elseif ($_GET['msg'] === 'theme_reset'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Accent color theme has been reset to default.</div>
            <?php elseif ($_GET['msg'] === 'reset_ok'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Password has been successfully reset to "password123".</div>
            <?php elseif ($_GET['msg'] === 'logo_updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> System logo updated successfully.</div>
            <?php elseif ($_GET['msg'] === 'logo_error'): ?>
                <div class="flash-success" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;"><i class="fas fa-exclamation-circle"></i> Error uploading logo. Make sure it is a valid PNG/JPG image.</div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- System Settings CSS Style Block -->
        <style>
            .config-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 18px;
                margin-bottom: 25px;
            }
            .config-card-title {
                margin: 0;
                font-size: 17px;
                font-weight: 800;
                color: var(--text-main);
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .config-card-subtitle {
                font-size: 13px;
                font-weight: 700;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.8px;
                margin-bottom: 18px;
                border-bottom: 1px dashed var(--border-color);
                padding-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .theme-swatch-container {
                background: rgba(0, 0, 0, 0.01);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                padding: 16px;
                height: 100%;
            }
            .color-picker-group {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 12px;
                border-radius: 8px;
                background: rgba(0, 0, 0, 0.02);
                margin-bottom: 8px;
                border: 1px solid transparent;
                transition: all 0.2s ease;
            }
            .color-picker-group:hover {
                background: rgba(0, 0, 0, 0.04);
                border-color: var(--border-color);
            }
            .color-picker-group label {
                font-size: 13px;
                font-weight: 600;
                color: var(--text-main);
            }
            .picker-wrapper {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .picker-wrapper input[type="color"] {
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
                width: 28px;
                height: 28px;
                border: none;
                border-radius: 50%;
                cursor: pointer;
                background: none;
                padding: 0;
            }
            .picker-wrapper input[type="color"]::-webkit-color-swatch-wrapper {
                padding: 0;
            }
            .picker-wrapper input[type="color"]::-webkit-color-swatch {
                border: 2px solid var(--border-color);
                border-radius: 50%;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            .picker-wrapper input[type="color"]::-moz-color-swatch {
                border: 2px solid var(--border-color);
                border-radius: 50%;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            .color-hex {
                font-family: 'Courier New', Courier, monospace;
                font-size: 12px;
                font-weight: 700;
                color: var(--text-muted);
                background: rgba(0, 0, 0, 0.04);
                padding: 3px 8px;
                border-radius: 6px;
                text-transform: uppercase;
                min-width: 75px;
                text-align: center;
                border: 1px solid var(--border-color);
            }
            .settings-input-group {
                margin-bottom: 18px;
            }
            .settings-input-group .input-label {
                font-size: 13px;
                font-weight: 600;
                color: var(--text-main);
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .settings-input-group input, 
            .settings-input-group select {
                width: 100%;
                padding: 12px 16px;
                border: 1.5px solid var(--border-color);
                border-radius: 10px;
                background: var(--input-bg);
                color: var(--text-main);
                font-weight: 600;
                outline: none;
                transition: all 0.3s ease;
            }
            .settings-input-group input:focus, 
            .settings-input-group select:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            }
            .quick-tool-card {
                background: rgba(0, 0, 0, 0.015);
                border: 1px dashed var(--border-color);
                border-radius: 12px;
                padding: 18px;
                margin-top: 20px;
            }
            .log-container-modern {
                max-height: 110px;
                overflow-y: auto;
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 10px 12px;
                background: rgba(0, 0, 0, 0.02);
            }
            .log-item-modern {
                font-size: 11.5px;
                color: var(--text-muted);
                border-bottom: 1px solid rgba(0, 0, 0, 0.03);
                padding: 6px 4px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                transition: all 0.2s ease;
            }
            .log-item-modern:last-child {
                border-bottom: none;
            }
            .log-item-modern:hover {
                background: rgba(0, 0, 0, 0.01);
                color: var(--text-main);
            }
        </style>

        <div class="card-modern" id="system-config-card" style="box-shadow: 0 10px 30px rgba(0,0,0,0.04); border-radius:16px;">
            <div class="config-card-header">
                <h3 class="config-card-title"><i class="fas fa-cogs" style="color:var(--accent);"></i> System Configurations</h3>
                <form method="POST" onsubmit="return confirm('Are you sure you want to reset all theme colors to their defaults?');" style="margin:0;">
                    <button type="submit" name="reset_theme" class="btn-action" style="background:var(--bg-color); color:var(--text-muted); border:1px solid var(--border-color); font-weight:700; border-radius:8px;">
                        <i class="fas fa-undo"></i> Reset Default Color Theme
                    </button>
                </form>
            </div>
            
            <div class="dashboard-grid-layout" style="grid-template-columns: 1fr 1fr; gap:30px; margin-bottom: 30px; margin-top: 20px;">
                <!-- Logo Upload Section -->
                <div>
                    <h4 class="config-card-subtitle"><i class="fas fa-image"></i> System Branding / Logo</h4>
                    <div class="quick-tool-card" style="margin-top: 0; display:flex; align-items:center; gap: 20px;">
                        <div style="width: 80px; height: 80px; background: white; padding: 5px; border-radius: 12px; border: 1px solid var(--border-color); flex-shrink:0;">
                            <img src="Logo.png?v=<?php echo time(); ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                        <div style="flex-grow:1;">
                            <form method="POST" enctype="multipart/form-data" action="system_settings.php" style="margin:0;">
                                <div class="settings-input-group" style="margin-bottom: 10px;">
                                    <div class="input-label" style="font-size:12px;">Upload New Logo (PNG/JPG)</div>
                                    <input type="file" name="school_logo" accept=".png, .jpg, .jpeg" required style="padding: 8px; font-size: 13px;">
                                </div>
                                <button type="submit" name="upload_logo" class="btn-action" style="background:var(--accent); color:white; border:none; padding:8px 15px; border-radius:8px; cursor:pointer; font-weight:600; font-size: 13px; width:100%;"><i class="fas fa-upload"></i> Upload Logo</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" action="system_settings.php">
                <div class="dashboard-grid-layout" style="grid-template-columns: 1fr 1fr; gap:30px;">
                    <div>
                        <h4 class="config-card-subtitle"><i class="fas fa-sliders-h"></i> QA Evaluation & Ratings Configuration</h4>
                        
                        <div class="settings-input-group">
                            <div class="input-label"><i class="fas fa-tag"></i> System Branding Name</div>
                            <input type="text" name="settings[system_name]" value="<?php echo htmlspecialchars($system_name); ?>" required>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom: 18px;">
                            <div class="settings-input-group" style="margin:0;">
                                <div class="input-label"><i class="fas fa-star"></i> Max Rating Scale</div>
                                <input type="number" name="settings[max_rating]" value="<?php echo intval($max_rating_val); ?>" min="2" max="10">
                            </div>
                            <div class="settings-input-group" style="margin:0;">
                                <div class="input-label"><i class="fas fa-comment"></i> Min Feedback Length</div>
                                <input type="number" name="settings[min_feedback_length]" value="<?php echo intval($min_feedback); ?>" min="0" max="500">
                            </div>
                        </div>
                        
                        <!-- Faculty Result Visibility Option -->
                        <div class="settings-input-group">
                            <div class="input-label"><i class="fas fa-eye"></i> Faculty Result Visibility</div>
                            <select name="settings[faculty_results_visible]">
                                <option value="hidden"  <?php echo ($fac_visible == 'hidden')  ? 'selected' : ''; ?>>Results are Hidden from Faculty Members</option>
                                <option value="visible" <?php echo ($fac_visible == 'visible') ? 'selected' : ''; ?>>Results are Visible to Faculty Members</option>
                            </select>
                        </div>

                        <!-- Quick Password Reset -->
                        <div class="quick-tool-card">
                            <h4 style="font-size: 13.5px; margin-top: 0; margin-bottom: 12px; color: var(--text-main); font-weight:700; display:flex; align-items:center; gap:6px;"><i class="fas fa-key" style="color:#ef4444;"></i> Quick Password Reset Tool</h4>
                            <div style="display:flex; gap:10px; align-items:flex-end;">
                                <div class="settings-input-group" style="flex-grow:1; margin:0;">
                                    <select id="reset_user_select">
                                        <option value="" disabled selected>-- Select a user --</option>
                                        <?php
                                        $all_users_stmt = $conn->prepare("SELECT id, full_name, role, username FROM users WHERE status = 'active' AND id != ? ORDER BY full_name ASC");
                                        $all_users_stmt->bind_param("i", $_SESSION['id']);
                                        $all_users_stmt->execute();
                                        $all_users_result = $all_users_stmt->get_result();
                                        while($user = $all_users_result->fetch_assoc()):
                                        ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name'] . " ({$user['role']} - {$user['username']})"); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <button type="button" class="login-btn" style="background:#ef4444; padding:12px 20px; width:auto; border-radius:10px; margin:0;" onclick="triggerPasswordReset()">
                                    Reset
                                </button>
                            </div>
                            <p style="font-size:11px; color:var(--text-muted); margin-top:6px; margin-bottom:0;">Sets selected user's password to: <strong>password123</strong></p>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="config-card-subtitle"><i class="fas fa-palette"></i> Live Color Palette Configurator</h4>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                            <div class="theme-swatch-container">
                                <h5 style="margin-top:0; margin-bottom:12px; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">LIGHT THEME COLORS</h5>
                                <div class="color-picker-group"><label>Primary</label><div class="picker-wrapper"><input type="color" name="settings[theme_primary]" value="<?php echo $theme_primary; ?>" data-css-var="--navbar-grad" data-theme-target="light"><span class="color-hex"><?php echo strtoupper($theme_primary); ?></span></div></div>
                                <div class="color-picker-group"><label>Accent</label><div class="picker-wrapper"><input type="color" name="settings[theme_accent]" value="<?php echo $theme_accent; ?>" data-css-var="--accent" data-theme-target="light"><span class="color-hex"><?php echo strtoupper($theme_accent); ?></span></div></div>
                                <div class="color-picker-group"><label>Background</label><div class="picker-wrapper"><input type="color" name="settings[theme_background]" value="<?php echo $theme_background; ?>" data-css-var="--bg-color" data-theme-target="light"><span class="color-hex"><?php echo strtoupper($theme_background); ?></span></div></div>
                            </div>
                            <div class="theme-swatch-container">
                                <h5 style="margin-top:0; margin-bottom:12px; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">DARK THEME COLORS</h5>
                                <div class="color-picker-group"><label>Primary</label><div class="picker-wrapper"><input type="color" name="settings[theme_dark_primary]" value="<?php echo $theme_dark_primary; ?>" data-css-var="--navbar-grad" data-theme-target="dark"><span class="color-hex"><?php echo strtoupper($theme_dark_primary); ?></span></div></div>
                                <div class="color-picker-group"><label>Accent</label><div class="picker-wrapper"><input type="color" name="settings[theme_dark_accent]" value="<?php echo $theme_dark_accent; ?>" data-css-var="--accent" data-theme-target="dark"><span class="color-hex"><?php echo strtoupper($theme_dark_accent); ?></span></div></div>
                                <div class="color-picker-group"><label>Background</label><div class="picker-wrapper"><input type="color" name="settings[theme_dark_background]" value="<?php echo $theme_dark_background; ?>" data-css-var="--bg-color" data-theme-target="dark"><span class="color-hex"><?php echo strtoupper($theme_dark_background); ?></span></div></div>
                                <div class="color-picker-group"><label>Card Bg</label><div class="picker-wrapper"><input type="color" name="settings[theme_dark_card]" value="<?php echo $theme_dark_card; ?>" data-css-var="--card-bg" data-theme-target="dark"><span class="color-hex"><?php echo strtoupper($theme_dark_card); ?></span></div></div>
                                <div class="color-picker-group"><label>Text Main</label><div class="picker-wrapper"><input type="color" name="settings[theme_dark_text]" value="<?php echo $theme_dark_text; ?>" data-css-var="--text-main" data-theme-target="dark"><span class="color-hex"><?php echo strtoupper($theme_dark_text); ?></span></div></div>
                            </div>
                        </div>
                        
                        <!-- Recent Activity Logs inside Settings -->
                        <div style="border-top:1px solid var(--border-color); padding-top:15px; margin-top:20px;">
                            <h5 style="font-size: 13px; margin-top:0; margin-bottom: 10px; color: var(--text-main); font-weight:700;"><i class="fas fa-history"></i> Recent System Activity Logs</h5>
                            <div class="log-container-modern">
                                <?php
                                $logs = $conn->query("SELECT * FROM system_logs ORDER BY timestamp DESC LIMIT 6");
                                if ($logs && $logs->num_rows > 0) {
                                    while($log = $logs->fetch_assoc()) {
                                        $log_color = $log['level'] == 'warning' ? '#f59e0b' : '#6b7280';
                                        echo "<div class='log-item-modern'>
                                                <span><i class='fas fa-exclamation-triangle' style='color:{$log_color}; margin-right:4px;'></i> " . htmlspecialchars($log['message']) . "</span>
                                                <span style='font-family:monospace; font-size:10px; font-weight:700;'>" . date('H:i', strtotime($log['timestamp'])) . "</span>
                                              </div>";
                                    }
                                } else { echo "<p style='font-size:11px; color:var(--text-muted); text-align:center; margin:10px 0;'>No system logs found.</p>"; }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" name="update_settings" class="login-btn" style="padding:14px; margin-top:24px; width:100%; border-radius:10px; font-size:16px;">
                    <i class="fas fa-save"></i> Save System & Theme Configurations
                </button>
            </form>
            
            <!-- Hidden form for Password Reset -->
            <form id="pw-reset-form" method="POST" action="system_settings.php" style="display:none;">
                <input type="hidden" name="user_id" id="reset_user_id">
                <input type="hidden" name="reset_password" value="1">
            </form>
        </div>

        <footer>
            <i class="fas fa-shield-alt"></i> Admin Panel &middot; <?php echo htmlspecialchars($_SESSION['full_name']); ?>
        </footer>
    </main>
</div>

<script>
    // System Password Reset helper
    function triggerPasswordReset() {
        const select = document.getElementById('reset_user_select');
        const userId = select.value;
        if (!userId) {
            alert('Please select a user first!');
            return;
        }
        if (confirm('Are you sure you want to reset this user\'s password to \'123456\'?')) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('pw-reset-form').submit();
        }
    }

    // Color Swatch Interactive Live Preview Helper
    document.querySelectorAll('.picker-wrapper input[type="color"]').forEach(picker => {
        picker.addEventListener('input', function(e) {
            const hexSpan = this.nextElementSibling;
            if (hexSpan) hexSpan.textContent = this.value.toUpperCase();
            
            // Check dynamic target theme matching page state
            const targetTheme = this.getAttribute('data-theme-target');
            const currentActiveTheme = document.documentElement.getAttribute('data-theme') || 'light';
            if (targetTheme === currentActiveTheme) {
                const cssVar = this.getAttribute('data-css-var');
                document.documentElement.style.setProperty(cssVar, this.value);
            }
        });
    });
</script>
</body>
</html>
