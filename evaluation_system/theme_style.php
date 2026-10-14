<?php
if (defined('THEME_STYLE_LOADED')) {
    return;
}
define('THEME_STYLE_LOADED', true);

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { include_once 'db_connect.php'; }

if (!function_exists('hex2rgba')) {
    function hex2rgba($color, $opacity = 1.0) {
        if(empty($color) || $color[0] !== '#') return 'rgba(0,0,0,'.$opacity.')';
        $color = substr($color, 1);
        if (strlen($color) == 6) $hex = [$color[0].$color[1], $color[2].$color[3], $color[4].$color[5]];
        elseif (strlen($color) == 3) $hex = [$color[0].$color[0], $color[1].$color[1], $color[2].$color[2]];
        else return 'rgba(0,0,0,'.$opacity.')';
        $rgb = array_map('hexdec', $hex);
        return 'rgba('.implode(",", $rgb).','.$opacity.')';
    }
}

$settings_query = (isset($conn) && $conn instanceof mysqli) ? @$conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'theme_%'") : false;
$theme = [];
if ($settings_query) { while($row = $settings_query->fetch_assoc()){ $theme[$row['setting_key']] = $row['setting_value']; } }

// Light Theme Glass
$light_primary    = htmlspecialchars($theme['theme_primary'] ?? '#3b82f6');
$light_accent     = htmlspecialchars($theme['theme_accent'] ?? '#8b5cf6');
$light_background = htmlspecialchars($theme['theme_background'] ?? '#f4f6fa');
$light_card       = 'rgba(255, 255, 255, 0.94)';
$light_text       = '#0f172a';

$l_grad_1 = hex2rgba($light_primary, 0.14);
$l_grad_2 = hex2rgba($light_accent, 0.12);
$l_grad_3 = hex2rgba('#3b82f6', 0.07);

// Dark Theme Glass
$dark_primary    = htmlspecialchars($theme['theme_dark_primary'] ?? '#3b82f6');
$dark_accent     = htmlspecialchars($theme['theme_dark_accent'] ?? '#8b5cf6');
$dark_background = htmlspecialchars($theme['theme_dark_background'] ?? '#0b0f19');
$dark_card       = htmlspecialchars($theme['theme_dark_card'] ?? 'rgba(20, 27, 48, 0.88)');
$dark_text       = htmlspecialchars($theme['theme_dark_text'] ?? '#ffffff');

$d_grad_1 = hex2rgba($dark_primary, 0.24);
$d_grad_2 = hex2rgba($dark_accent, 0.20);
$d_grad_3 = hex2rgba('#3b82f6', 0.14);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap">
<script>
    (function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    })();
</script>
<style id="dynamic-theme-styles">
:root {
    --bg-color: <?php echo $light_background; ?>;
    --card-bg: <?php echo $light_card; ?>;
    --text-main: <?php echo $light_text; ?>;
    --text-muted: #475569;
    --border-color: rgba(0, 0, 0, 0.09);
    --input-bg: rgba(255, 255, 255, 0.96);
    --navbar-grad: linear-gradient(135deg, <?php echo $light_primary; ?>, <?php echo $light_accent; ?>);
    --accent: <?php echo $light_primary; ?>;
    --sidebar-bg: rgba(255, 255, 255, 0.96);
    --sidebar-active-bg: <?php echo hex2rgba($light_primary, 0.12); ?>;
    --sidebar-active-text: <?php echo $light_primary; ?>;
    --sidebar-text: #1e293b;
    --toggle-bg: rgba(0, 0, 0, 0.06);
    --toggle-icon: <?php echo $light_primary; ?>;
    --purple-glow: <?php echo hex2rgba($light_primary, 0.22); ?>;
    --home-grad: radial-gradient(circle at 10% 15%, <?php echo $l_grad_1; ?> 0%, transparent 48%), radial-gradient(circle at 90% 85%, <?php echo $l_grad_2; ?> 0%, transparent 48%), radial-gradient(circle at 50% 50%, <?php echo $l_grad_3; ?> 0%, transparent 60%), linear-gradient(180deg, #f8fafc 0%, #edf2f7 100%);
}
[data-theme="dark"] {
    --bg-color: <?php echo $dark_background; ?>;
    --card-bg: <?php echo $dark_card; ?>;
    --text-main: <?php echo $dark_text; ?>;
    --text-muted: #94a3b8;
    --border-color: rgba(255, 255, 255, 0.12);
    --input-bg: rgba(15, 23, 42, 0.75);
    --navbar-grad: linear-gradient(135deg, <?php echo $dark_primary; ?>, <?php echo $dark_accent; ?>);
    --accent: <?php echo $dark_primary; ?>;
    --sidebar-bg: rgba(15, 23, 42, 0.92);
    --sidebar-active-bg: <?php echo hex2rgba($dark_primary, 0.24); ?>;
    --sidebar-active-text: #ffffff;
    --sidebar-text: #e2e8f0;
    --toggle-bg: rgba(255, 255, 255, 0.08);
    --toggle-icon: <?php echo $dark_primary; ?>;
    --purple-glow: <?php echo hex2rgba($dark_primary, 0.28); ?>;
    --home-grad: radial-gradient(circle at 10% 15%, <?php echo $d_grad_1; ?> 0%, transparent 50%), radial-gradient(circle at 90% 85%, <?php echo $d_grad_2; ?> 0%, transparent 50%), radial-gradient(circle at 50% 50%, <?php echo $d_grad_3; ?> 0%, transparent 60%), linear-gradient(180deg, #0b0f19 0%, #070a12 100%);
}
</style>