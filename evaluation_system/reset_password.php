<?php
session_start();
include 'db_connect.php';

$error = "";

// ── School config ─────────────────────────────────────────────────────
$school = trim($_GET['school'] ?? $_POST['school'] ?? 'mcnp');
if (!in_array($school, ['mcnp', 'isap', 'mcnp-isap'])) $school = 'mcnp';

$schools = [
    'mcnp' => [
        'short'   => 'MCNP',
        'logo'    => 'MCNP_Logo.png',
        'accent'  => '#3b6bd6',
        'accent2' => '#1a2f7a',
        'glow'    => 'rgba(59, 107, 214, 0.4)',
    ],
    'isap' => [
        'short'   => 'ISAP',
        'logo'    => 'ISAP_Logo.png',
        'accent'  => '#c41230',
        'accent2' => '#7a0000',
        'glow'    => 'rgba(196, 18, 48, 0.4)',
    ],
    'mcnp-isap' => [
        'short'   => 'MCNP-ISAP',
        'logo'    => 'MCNP_Logo.png',
        'accent'  => '#3b6bd6',
        'accent2' => '#7a0000',
        'glow'    => 'rgba(59, 107, 214, 0.4)',
    ],
];
$cfg = $schools[$school];

// ── Validate: must have a verified OTP session ────────────────────────
$otp_email = $_SESSION['otp_verified_reset_email'] ?? null;
$otp_time  = $_SESSION['otp_verified_reset_time']  ?? 0;

if (!$otp_email || (time() - $otp_time) > 1800) {
    // OTP session missing or older than 30 minutes → back to forgot_password
    unset($_SESSION['otp_verified_reset_email'], $_SESSION['otp_verified_reset_time']);
    header("Location: forgot_password.php?error=session_expired");
    exit();
}

$email = $otp_email;

// ── Handle password reset form submit ─────────────────────────────────
if (isset($_POST['reset_password'])) {
    $new_pass     = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if ($new_pass !== $confirm_pass) {
        $error = "Passwords do not match!";
    } elseif (strlen($new_pass) < 8 || strlen($new_pass) > 12) {
        $error = "Password must be 8 to 12 characters long.";
    } else {
        // Get user by email
        $find = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $find->bind_param("s", $email);
        $find->execute();
        $user = $find->get_result()->fetch_assoc();

        if (!$user) {
            $error = "Account not found. Please contact the administrator.";
        } else {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
            $stmt->bind_param("si", $hashed_pass, $user['id']);

            if ($stmt->execute()) {
                // Clear OTP session after successful reset
                unset($_SESSION['otp_verified_reset_email'], $_SESSION['otp_verified_reset_time']);
                header("Location: login.php?school={$school}&msg=pw_reset_ok");
                exit();
            } else {
                $error = "Something went wrong. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Faculty Evaluation System</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --school-accent: <?= $cfg['accent'] ?>;
            --school-accent2: <?= $cfg['accent2'] ?>;
            --school-glow: <?= $cfg['glow'] ?>;
        }
        .auth-page {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
        }
        .step-dot {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700;
            border: 2px solid var(--border-color);
            background: var(--card-bg); color: var(--text-muted);
            transition: all 0.35s; position: relative; z-index: 1;
        }
        .step-dot.done   { border-color: #10b981; background: #10b981; color: #fff; }
        .step-dot.active { border-color: var(--school-accent); background: var(--school-accent); color: #fff; box-shadow: 0 0 0 4px var(--school-glow); }
        .step-line { flex: 1; height: 2px; background: var(--border-color); max-width: 60px; transition: background 0.35s; }
        .step-line.done { background: #10b981; }

        /* Strength bar */
        .strength-bar-track {
            height: 4px; background: var(--border-color); border-radius: 4px; margin-top: 6px; overflow: hidden;
        }
        .strength-bar-fill {
            height: 100%; width: 0; border-radius: 4px; transition: width 0.4s, background 0.4s;
        }
        .strength-label { font-size: 11px; font-weight: 600; margin-top: 4px; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="notifications.js?v=<?php echo time(); ?>"></script>
</head>
<body class="mesh-bg">

    <div class="auth-page" style="position: relative; z-index: 2;">
        <div style="position: absolute; top: 25px; right: 30px; z-index: 1000;">
            <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()" style="background: var(--toggle-bg); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease;">
                <i class="fas fa-moon" style="color: var(--text-main);"></i>
            </div>
        </div>

        <div class="auth-card glass-card stagger-1" style="border-top: 5px solid var(--school-accent); max-width: 440px; width: 100%;">

            <!-- Logo & Title -->
            <div style="text-align: center; margin-bottom: 20px;">
                <div class="eval-logo-badge eval-logo-large stagger-2">
                    <img src="<?= htmlspecialchars($cfg['logo']) ?>" alt="Logo">
                </div>
                <h2 class="stagger-2" style="font-weight: 800; font-size: 22px; color: var(--text-main); margin-bottom: 4px;">Set New Password</h2>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0;">
                    Resetting password for: <strong style="color: var(--text-main);"><?= htmlspecialchars($email) ?></strong>
                </p>
            </div>

            <!-- Step Indicator (Step 3 = active) -->
            <div class="step-indicator">
                <div class="step-dot done"><i class="fas fa-check"></i></div>
                <div class="step-line done"></div>
                <div class="step-dot done"><i class="fas fa-check"></i></div>
                <div class="step-line done"></div>
                <div class="step-dot active">3</div>
            </div>

            <?php if($error != ""): ?>
                <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="stagger-3" onsubmit="return validatePasswords()">
                <input type="hidden" name="school" value="<?= htmlspecialchars($school) ?>">
                <div class="input-group glass-input-group" style="margin-bottom: 15px;">
                    <div class="input-label"><i class="fas fa-key"></i> <span>New Password</span></div>
                    <div class="password-wrapper">
                        <input type="password" name="new_password" id="new_pass" placeholder="Enter new password" required minlength="8" maxlength="12" oninput="checkStrength(this.value)" style="border-radius: 12px; font-weight: 500;">
                        <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('new_pass', this)" style="color: var(--text-muted);"></i>
                    </div>
                    <div class="strength-bar-track"><div class="strength-bar-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel" style="color: var(--text-muted);"></div>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; font-weight: 500;">Must be 8 to 12 characters long.</div>
                </div>

                <div class="input-group glass-input-group" style="margin-bottom: 20px;">
                    <div class="input-label"><i class="fas fa-key"></i> <span>Confirm New Password</span></div>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="conf_pass" placeholder="Confirm new password" required minlength="8" maxlength="12" oninput="checkMatch()" style="border-radius: 12px; font-weight: 500;">
                        <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('conf_pass', this)" style="color: var(--text-muted);"></i>
                    </div>
                    <div class="strength-label" id="matchLabel" style="margin-top: 4px;"></div>
                </div>

                <button type="submit" name="reset_password" class="login-btn" style="background: linear-gradient(135deg, var(--school-accent), var(--school-accent2)); border-radius: 12px; padding: 14px; font-weight: 700; box-shadow: 0 4px 15px var(--school-glow);">
                    <i class="fas fa-lock"></i> Update Password
                </button>
            </form>

            <div class="footer-links" style="margin-top: 20px; font-size: 13px; text-align: center;">
                <a href="login.php?school=<?= htmlspecialchars($school) ?>" style="color: var(--text-muted); text-decoration: none;">← Back to Login</a>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(id, icon) {
            const input = document.getElementById(id);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.className = isPassword ? 'fas fa-eye-slash toggle-password' : 'fas fa-eye toggle-password';
        }

        function checkStrength(val) {
            const fill  = document.getElementById('strengthFill');
            const label = document.getElementById('strengthLabel');
            const len   = val.length;
            let score   = 0;
            if (len >= 8)  score++;
            if (len >= 10) score++;
            if (/[A-Z]/.test(val))   score++;
            if (/[0-9]/.test(val))   score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const levels = [
                { pct: 0,   color: 'transparent', text: '' },
                { pct: 20,  color: '#ef4444',      text: 'Weak' },
                { pct: 40,  color: '#f97316',      text: 'Fair' },
                { pct: 65,  color: '#eab308',      text: 'Good' },
                { pct: 85,  color: '#22c55e',      text: 'Strong' },
                { pct: 100, color: '#10b981',      text: 'Very Strong' },
            ];
            const lv = levels[Math.min(score, 5)];
            fill.style.width    = lv.pct + '%';
            fill.style.background = lv.color;
            label.textContent   = lv.text;
            label.style.color   = lv.color;
        }

        function checkMatch() {
            const p1  = document.getElementById('new_pass').value;
            const p2  = document.getElementById('conf_pass').value;
            const lbl = document.getElementById('matchLabel');
            if (!p2) { lbl.textContent = ''; return; }
            if (p1 === p2) {
                lbl.textContent = '✓ Passwords match';
                lbl.style.color = '#10b981';
            } else {
                lbl.textContent = '✕ Passwords do not match';
                lbl.style.color = '#ef4444';
            }
        }

        function validatePasswords() {
            const p1 = document.getElementById('new_pass').value;
            const p2 = document.getElementById('conf_pass').value;
            if (p1 !== p2) { alert('Passwords do not match!'); return false; }
            if (p1.length < 8 || p1.length > 12) { alert('Password must be 8 to 12 characters.'); return false; }
            return true;
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme  = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
            updateThemeIcon(targetTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeBtn i');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>
</body>
</html>