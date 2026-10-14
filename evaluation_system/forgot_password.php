<?php
session_start();
include 'db_connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$error   = "";
$success = "";
$step    = 1; // 1 = enter email, 2 = enter OTP

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

// ── Handle: step 1 – Find account & send OTP ─────────────────────────
if (isset($_POST['find_account'])) {
    if (defined('SMTP_USERNAME') && (SMTP_USERNAME === 'your-email@gmail.com' || SMTP_PASSWORD === 'your-app-password')) {
        $error = "Email service is not configured. Please contact the system administrator.";
    } else {
        $email = trim($_POST['email']);

        $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user_row = $result->fetch_assoc();
            $role_val = $user_row['role'] ?? 'student';
            // Trigger OTP send via reusable logic (call send_otp.php logic inline)
            $phpmailer_dir = file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')
                ? __DIR__ . '/PHPMailer/src'
                : __DIR__ . '/../PHPMailer/src';

            require_once $phpmailer_dir . '/Exception.php';
            require_once $phpmailer_dir . '/PHPMailer.php';
            require_once $phpmailer_dir . '/SMTP.php';
            include_once 'email_config.php';


            // Rate limiting
            $rate_check = $conn->prepare("SELECT COUNT(*) FROM email_otp WHERE email = ? AND purpose = 'reset' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $rate_check->bind_param("s", $email);
            $rate_check->execute();
            $rate_count = $rate_check->get_result()->fetch_row()[0] ?? 0;

            if ($rate_count >= 3) {
                $error = "Too many OTP requests. Please wait 10 minutes before trying again.";
            } else {
                $otp_code  = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $purpose = 'reset';

                // Invalidate old OTPs
                $inv = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE email = ? AND purpose = 'reset' AND is_used = 0");
                $inv->bind_param("s", $email);
                $inv->execute();

                // Insert new OTP
                $ins = $conn->prepare("INSERT INTO email_otp (email, otp_code, purpose, expires_at) VALUES (?, ?, 'reset', ?)");
                $ins->bind_param("sss", $email, $otp_code, $expires_at);
                $ins->execute();

                // Send email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = gethostbyname(SMTP_HOST); 
                    $mail->SMTPAuth   = SMTP_AUTH;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_SECURE;
                    $mail->Port       = SMTP_PORT;
                    $mail->Timeout    = 10;

                    // LOCAL XAMPP FIX: Disable SSL verification which causes "Network Error" hangs
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        )
                    );

                    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
                    $mail->addAddress($email);
                    $mail->isHTML(true);

                    // Embed the logo
                    $role_lower = strtolower($role_val);
                    $use_split = in_array($role_lower, ['faculty', 'teacher', 'dean', 'admin', 'system admin']);

                    if ($use_split) {
                        $logo_file = 'mcnp-isap.png';
                        $logo_style = "width: 100px; height: 100px; border-radius: 15px; border: 3px solid rgba(255,255,255,0.2); margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);";
                        $alt_text = 'MCNP-ISAP Logo';
                    } else {
                        $logo_file = $cfg['logo'];
                        $logo_style = "width: 75px; height: 75px; border-radius: 50%; border: 3px solid rgba(255,255,255,0.2); margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);";
                        $alt_text = "{$cfg['short']} Logo";
                    }

                    $logo_path = __DIR__ . '/' . $logo_file;
                    if (file_exists($logo_path)) {
                        $mail->addEmbeddedImage($logo_path, 'sys_logo', $logo_file);
                        $logo_html = "<img src='cid:sys_logo' alt='{$alt_text}' style='{$logo_style}'>";
                    } else {
                        $logo_html = "<div style='font-size:45px;margin-bottom:10px;'>🔑</div>";
                    }

                    $mail->Subject = "Faculty Evaluation System - Password Reset Code: {$otp_code}";
                    $mail->Body = "
                    <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
                    <body style='margin:0;padding:0;background:#f0f4ff;font-family:Inter,Segoe UI,Arial,sans-serif;'>
                      <table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f4ff;padding:40px 20px;'>
                        <tr><td align='center'>
                          <table width='480' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(99,102,241,0.12);'>
                            <tr><td style='background:linear-gradient(135deg,#1e1e2f,#2a2a40);padding:40px 30px;text-align:center;border-bottom:4px solid #6366f1;'>
                              {$logo_html}
                              <h1 style='color:#ffffff;margin:0;font-size:24px;font-weight:800;letter-spacing:0.5px;'>Password Reset Code</h1>
                              <p style='color:#a5b4fc;margin:8px 0 0;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:1px;'>Faculty Evaluation System</p>
                            </td></tr>
                            <tr><td style='padding:40px;'>
                              <p style='color:#374151;font-size:15px;margin:0 0 24px;line-height:1.6;'>You requested to reset your password. Use the secure code below to continue.</p>
                              <div style='background:linear-gradient(135deg,#f8fafc,#f1f5f9);border:2px dashed #94a3b8;border-radius:12px;padding:24px;text-align:center;margin:0 0 28px;'>
                                <p style='color:#64748b;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;margin:0 0 10px;'>Your Reset Code</p>
                                <div style='font-size:46px;font-weight:800;letter-spacing:12px;color:#4f46e5;font-family:\"Courier New\",Courier,monospace;text-shadow: 2px 2px 4px rgba(79,70,229,0.15);'>{$otp_code}</div>
                              </div>
                              <div style='background:#fffbeb;border-left:4px solid #f59e0b;border-radius:8px;padding:16px;margin:0 0 24px;'>
                                <p style='color:#92400e;font-size:13.5px;margin:0;line-height:1.5;'><strong>⏱ Valid for 10 minutes only.</strong><br>Do not share this code with anyone.</p>
                              </div>
                              <p style='color:#94a3b8;font-size:12.5px;margin:0;line-height:1.5;'>If you did not request this, please ignore this email. Your account is safe.</p>
                            </td></tr>
                            <tr><td style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center;'>
                              <p style='color:#64748b;font-size:12px;margin:0;font-weight:600;'>Faculty Evaluation System</p>
                              <p style='color:#94a3b8;font-size:11px;margin:6px 0 0;'>This is an automated message. Please do not reply.</p>
                            </td></tr>
                          </table>
                        </td></tr>
                      </table>
                    </body></html>";
                    $mail->AltBody = "Your Password Reset Code: {$otp_code}\n\nValid for 10 minutes.";

                    $is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])
                        || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false
                        || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;

                    $mail_sent = false;
                    $mail_error = '';

                    try {
                        $mail->send();
                        $mail_sent = true;
                    } catch (Exception $sendEx) {
                        $mail_error = $mail->ErrorInfo;
                        if ($mail->Port != 2525 && (strpos(SMTP_HOST, 'brevo.com') !== false || strpos(SMTP_HOST, 'sendinblue') !== false)) {
                            try {
                                $mail->Port = 2525;
                                $mail->send();
                                $mail_sent = true;
                            } catch (Exception $retryEx) {
                                $mail_error = $mail->ErrorInfo;
                            }
                        }
                    }

                    if ($mail_sent) {
                        $_SESSION['reset_otp_email'] = $email;
                        $_SESSION['reset_otp_sent_at'] = time();
                        $step = 2;
                        $success = "A verification code has been sent to your email.";
                    } elseif ($is_localhost) {
                        $_SESSION['reset_otp_email'] = $email;
                        $_SESSION['reset_otp_sent_at'] = time();
                        $step = 2;
                        $success = "Dev Mode (Localhost): Reset OTP is {$otp_code}";
                    } else {
                        $error = "Could not send reset email. Error: " . $mail_error;
                    }

                } catch (Exception $e) {
                    $error = "Error preparing email: " . $e->getMessage();
                }
            }
        } else {
            // Security: don't reveal if email exists
            $step = 2; // show "check your email" page anyway
            $success = "If this email is registered, a reset code has been sent.";
            $_SESSION['reset_otp_email'] = $email;
            $_SESSION['reset_otp_sent_at'] = time();
        }
    }
}

// ── Handle: step 2 – Verify OTP ───────────────────────────────────────
if (isset($_POST['verify_reset_otp'])) {
    $step = 2;
    $email = $_SESSION['reset_otp_email'] ?? '';
    $otp   = implode('', array_map(fn($i) => trim($_POST["rotp{$i}"] ?? ''), range(0, 5)));

    if (empty($email) || strlen($otp) < 6) {
        $error = "Invalid request. Please start again.";
        $step  = 1;
    } else {
        $stmt = $conn->prepare("SELECT id, otp_code, expires_at FROM email_otp WHERE email = ? AND purpose = 'reset' AND is_used = 0 ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            $error = "No active OTP found. Please request a new code.";
        } elseif (strtotime($row['expires_at']) <= time()) {
            $exp = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE id = ?");
            $exp->bind_param("i", $row['id']);
            $exp->execute();
            $error = "OTP has expired. Please request a new one.";
        } elseif ($row['otp_code'] !== $otp) {
            $error = "Invalid OTP code. Please try again.";
        } else {
            // Mark used
            $mark = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE id = ?");
            $mark->bind_param("i", $row['id']);
            $mark->execute();

            // Set session for reset_password.php
            $_SESSION['otp_verified_reset_email'] = $email;
            $_SESSION['otp_verified_reset_time']  = time();
            unset($_SESSION['reset_otp_email'], $_SESSION['reset_otp_sent_at']);

            header("Location: reset_password.php?school={$school}");
            exit();
        }
    }
}

// ── If session says we're in step 2 already ───────────────────────────
if (isset($_SESSION['reset_otp_email']) && !isset($_POST['find_account'])) {
    $step = 2;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Faculty Evaluation System</title>
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

        /* ── Step Indicator ───────────────────── */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 28px;
        }
        .step-dot {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            border: 2px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-muted);
            transition: all 0.35s;
            position: relative;
            z-index: 1;
        }
        .step-dot.active {
            border-color: var(--school-accent);
            background: var(--school-accent);
            color: #fff;
            box-shadow: 0 0 0 4px var(--school-glow);
        }
        .step-dot.done {
            border-color: #10b981;
            background: #10b981;
            color: #fff;
        }
        .step-line {
            flex: 1;
            height: 2px;
            background: var(--border-color);
            max-width: 60px;
            transition: background 0.35s;
        }
        .step-line.done { background: #10b981; }

        /* ── OTP Digit Inputs ─────────────────── */
        .otp-digits-row {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0 16px;
        }
        .otp-digit-input {
            width: 50px;
            height: 58px;
            text-align: center;
            font-size: 24px;
            font-weight: 800;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--card-bg);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.1s;
        }
        .otp-digit-input:focus {
            border-color: var(--school-accent);
            box-shadow: 0 0 0 3px var(--school-glow);
            transform: scale(1.05);
        }
        .otp-digit-input.filled { border-color: var(--school-accent); }

        .otp-timer {
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 16px;
        }
        .otp-timer span { font-weight: 700; color: var(--school-accent); }
        .otp-timer.urgent span { color: #ef4444; animation: pulse 1s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        @keyframes shake  { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)} 40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)} }
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
                <h2 class="stagger-2" style="font-weight: 800; font-size: 22px; color: var(--text-main); margin-bottom: 4px;">Forgot Password?</h2>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0;">
                    <?php echo $step === 1 ? "Enter your email to receive a verification code." : "Enter the 6-digit code sent to your email."; ?>
                </p>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>" id="stepDot1">
                    <?= $step > 1 ? '<i class="fas fa-check"></i>' : '1' ?>
                </div>
                <div class="step-line <?= $step > 1 ? 'done' : '' ?>" id="stepLine1"></div>
                <div class="step-dot <?= $step >= 2 ? 'active' : '' ?>" id="stepDot2">2</div>
                <div class="step-line" id="stepLine2"></div>
                <div class="step-dot" id="stepDot3">3</div>
            </div>

            <!-- Alerts -->
            <?php if($error != ""): ?>
                <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success != ""): ?>
                <div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- ── STEP 1: Enter Email ───────────────────────────────── -->
            <?php if ($step === 1): ?>
            <form method="POST" class="stagger-3">
                <input type="hidden" name="school" value="<?= htmlspecialchars($school) ?>">
                <div class="input-group glass-input-group" style="margin-bottom: 20px;">
                    <div class="input-label">
                        <i class="fas fa-envelope"></i>
                        <span>Email Address</span>
                    </div>
                    <input type="email" name="email" placeholder="Enter your registered email" required style="border-radius: 12px; font-weight: 500;">
                </div>

                <button type="submit" name="find_account" class="login-btn" style="background: linear-gradient(135deg, var(--school-accent), var(--school-accent2)); border-radius: 12px; padding: 14px; font-weight: 700; box-shadow: 0 4px 15px var(--school-glow);">
                    <i class="fas fa-paper-plane"></i> Send Verification Code
                </button>
            </form>
            <?php endif; ?>

            <!-- ── STEP 2: Enter OTP ─────────────────────────────────── -->
            <?php if ($step === 2): ?>
            <form method="POST" id="otpForm" class="stagger-3" onsubmit="return collectOtp()">
                <div style="text-align: center; margin-bottom: 8px;">
                    <p style="font-size: 13px; color: var(--text-muted); margin: 0;">Code sent to: <strong style="color: var(--text-main);"><?= htmlspecialchars($_SESSION['reset_otp_email'] ?? '') ?></strong></p>
                </div>

                <!-- Hidden inputs for OTP digits (filled by JS) -->
                <input type="hidden" name="rotp0" id="h0">
                <input type="hidden" name="rotp1" id="h1">
                <input type="hidden" name="rotp2" id="h2">
                <input type="hidden" name="rotp3" id="h3">
                <input type="hidden" name="rotp4" id="h4">
                <input type="hidden" name="rotp5" id="h5">

                <!-- Digit Inputs -->
                <div class="otp-digits-row" id="otpDigitsRow">
                    <?php for($i=0;$i<6;$i++): ?>
                    <input class="otp-digit-input" maxlength="1" type="text" inputmode="numeric" pattern="[0-9]" id="rd<?= $i ?>">
                    <?php endfor; ?>
                </div>

                <!-- Timer -->
                <div class="otp-timer" id="otpTimer">Code expires in: <span id="otpCountdown">10:00</span></div>

                <button type="submit" name="verify_reset_otp" class="login-btn" style="background: linear-gradient(135deg, var(--school-accent), var(--school-accent2)); border-radius: 12px; padding: 14px; font-weight: 700; box-shadow: 0 4px 15px var(--school-glow); margin-bottom: 12px;">
                    <i class="fas fa-check-circle"></i> Verify Code
                </button>

                <button type="button" onclick="resendOtp(this)" class="login-btn" style="background: transparent; border: 1.5px solid var(--border-color); color: var(--text-muted); border-radius: 12px; padding: 12px; font-weight: 600; box-shadow: none;">
                    <i class="fas fa-redo"></i> Resend Code
                </button>
            </form>

            <script>
                // ── Init timer from session ───────────────────────────
                let sentAt = <?= (int)($_SESSION['reset_otp_sent_at'] ?? time()) ?>;
                let elapsed = Math.floor(Date.now() / 1000) - sentAt;
                let secsLeft = Math.max(0, 600 - elapsed);
                const timerEl    = document.getElementById('otpTimer');
                const countdownEl = document.getElementById('otpCountdown');

                function startTimer() {
                    const iv = setInterval(() => {
                        if (secsLeft <= 0) {
                            clearInterval(iv);
                            timerEl.innerHTML = '<span style="color:#ef4444;font-weight:700;">⏰ Code expired. Please resend.</span>';
                            document.querySelector('button[name="verify_reset_otp"]').disabled = true;
                            return;
                        }
                        secsLeft--;
                        const m = String(Math.floor(secsLeft/60)).padStart(2,'0');
                        const s = String(secsLeft%60).padStart(2,'0');
                        countdownEl.textContent = m+':'+s;
                        if (secsLeft <= 60) timerEl.classList.add('urgent');
                    }, 1000);
                }
                startTimer();

                // ── Digit input behaviour ─────────────────────────────
                for (let i = 0; i < 6; i++) {
                    const inp = document.getElementById('rd' + i);
                    inp.addEventListener('input', e => {
                        const v = e.target.value.replace(/\D/g,'');
                        e.target.value = v.slice(0,1);
                        if (v) { inp.classList.add('filled'); if(i<5) document.getElementById('rd'+(i+1)).focus(); }
                        else inp.classList.remove('filled');
                    });
                    inp.addEventListener('keydown', e => {
                        if (e.key === 'Backspace' && !inp.value && i > 0) document.getElementById('rd'+(i-1)).focus();
                    });
                    inp.addEventListener('paste', e => {
                        e.preventDefault();
                        const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
                        for (let j=0;j<p.length;j++) {
                            const t = document.getElementById('rd'+(i+j));
                            if(t){t.value=p[j];t.classList.add('filled');}
                        }
                        const nx = document.getElementById('rd'+Math.min(i+p.length,5));
                        if(nx) nx.focus();
                    });
                }
                document.getElementById('rd0').focus();

                // ── Collect OTP into hidden fields before submit ───────
                function collectOtp() {
                    for (let i=0;i<6;i++) {
                        const v = document.getElementById('rd'+i).value;
                        if (!v) { alert('Please enter all 6 digits.'); return false; }
                        document.getElementById('h'+i).value = v;
                    }
                    return true;
                }

                // ── Resend via AJAX ───────────────────────────────────
                async function resendOtp(btn) {
                    const email = '<?= addslashes(htmlspecialchars($_SESSION['reset_otp_email'] ?? '')) ?>';
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
                    try {
                        const fd = new FormData();
                        fd.append('email', email);
                        fd.append('purpose', 'reset');
                        const res  = await fetch('send_otp.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            secsLeft = 600;
                            timerEl.classList.remove('urgent');
                            timerEl.innerHTML = 'Code expires in: <span id="otpCountdown">10:00</span>';
                            document.querySelector('button[name="verify_reset_otp"]').disabled = false;
                            startTimer();
                            alert('New OTP sent! Check your email.');
                        } else {
                            alert(data.message);
                        }
                    } catch(e) { alert('Network error. Try again.'); }
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
                    }, 30000);
                }
            </script>
            <?php endif; ?>

            <div class="footer-links" style="margin-top: 20px; font-size: 13px; text-align: center;">
                <?php if ($step === 2): ?>
                    <a href="forgot_password.php?school=<?= htmlspecialchars($school) ?>" style="color: var(--text-muted); text-decoration: none;">← Use a different email</a>
                <?php else: ?>
                    <a href="login.php?school=<?= htmlspecialchars($school) ?>" style="color: var(--school-accent); font-weight: 600; text-decoration: none;">← Back to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
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