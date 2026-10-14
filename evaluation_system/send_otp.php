<?php
session_start();
include 'db_connect.php';
include 'email_config.php';

header('Content-Type: application/json');
set_time_limit(120); // Prevent PHP from killing the script if SMTP is slow

// ── Only accept POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$email   = trim($_POST['email']   ?? '');
$purpose = trim($_POST['purpose'] ?? 'register'); // 'register' | 'reset'
$role    = trim($_POST['role']    ?? '');


// ── Basic Validation ──────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit();
}

if (!in_array($purpose, ['register', 'reset'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid purpose.']);
    exit();
}

$school = trim($_POST['school'] ?? 'mcnp');
if (!in_array($school, ['mcnp', 'isap', 'mcnp-isap'])) $school = 'mcnp';

if ($purpose === 'register' && in_array($role, ['admin', 'system admin'])) {
    echo json_encode(['success' => false, 'message' => 'Administrator accounts cannot be self-registered.']);
    exit();
}

// ── Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$phpmailer_dir = file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')
    ? __DIR__ . '/PHPMailer/src'
    : __DIR__ . '/../PHPMailer/src';

require_once $phpmailer_dir . '/Exception.php';
require_once $phpmailer_dir . '/PHPMailer.php';
require_once $phpmailer_dir . '/SMTP.php';


$schools = [
    'mcnp' => [
        'short'   => 'MCNP',
        'logo'    => 'MCNP_Logo.png',
        'primary' => '#1a2f7a',
        'grad'    => 'linear-gradient(135deg,#0d1f66,#1a2f7a)',
        'shadow'  => 'rgba(26, 47, 122, 0.15)'
    ],
    'isap' => [
        'short'   => 'ISAP',
        'logo'    => 'ISAP_Logo.png',
        'primary' => '#7a0000',
        'grad'    => 'linear-gradient(135deg,#3d0000,#7a0000)',
        'shadow'  => 'rgba(122, 0, 0, 0.15)'
    ],
    'mcnp-isap' => [
        'short'   => 'MCNP-ISAP',
        'logo'    => 'MCNP_Logo.png',
        'primary' => '#1a2f7a',
        'grad'    => 'linear-gradient(135deg,#0d1f66,#7a0000)',
        'shadow'  => 'rgba(26, 47, 122, 0.15)'
    ],
];
$cfg = $schools[$school];

// ── Check email config ────────────────────────────────────────────────
if (!defined('SMTP_USERNAME') || SMTP_USERNAME === 'your-email@gmail.com' || SMTP_PASSWORD === 'your-app-password') {
    echo json_encode(['success' => false, 'message' => 'Email service is not configured. Please contact the system administrator.']);
    exit();
}

// ── For 'reset' purpose: check if email exists ────────────────────────
if ($purpose === 'reset') {
    $chk = $conn->prepare("SELECT id, role FROM users WHERE email = ? LIMIT 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    $res = $chk->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
        exit();
    }
    $user_row = $res->fetch_assoc();
    $role = $user_row['role'] ?? '';
}

// ── Rate limiting: max 3 OTPs per email per 10 minutes ───────────────
$rate_check = $conn->prepare("SELECT COUNT(*) FROM email_otp WHERE email = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$rate_check->bind_param("ss", $email, $purpose);
$rate_check->execute();
$rate_count = $rate_check->get_result()->fetch_row()[0] ?? 0;

if ($rate_count >= 3) {
    echo json_encode(['success' => false, 'message' => 'Too many OTP requests. Please wait 10 minutes before trying again.']);
    exit();
}

// ── Generate 6-digit OTP ──────────────────────────────────────────────
$otp_code  = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// ── Invalidate previous unused OTPs for same email+purpose ───────────
$invalidate = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE email = ? AND purpose = ? AND is_used = 0");
$invalidate->bind_param("ss", $email, $purpose);
$invalidate->execute();

// ── Store new OTP ─────────────────────────────────────────────────────
$insert = $conn->prepare("INSERT INTO email_otp (email, otp_code, purpose, expires_at) VALUES (?, ?, ?, ?)");
$insert->bind_param("ssss", $email, $otp_code, $purpose, $expires_at);
if (!$insert->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate OTP. Please try again.']);
    exit();
}

// ── Send Email via PHPMailer ──────────────────────────────────────────
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    // Force IPv4 resolution to prevent 40-second IPv6 timeout hangs on Windows XAMPP
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

    // Always use MCNP-ISAP split logo for OTP emails
    $logo_file = 'mcnp-isap.png';
    $logo_style = "width: 90px; height: auto; margin-bottom: 15px;";
    $alt_text = 'MCNP-ISAP Logo';

    $logo_path = __DIR__ . '/' . $logo_file;
    if (file_exists($logo_path)) {
        $mail->addEmbeddedImage($logo_path, 'sys_logo', $logo_file);
        $logo_html = "<img src='cid:sys_logo' alt='{$alt_text}' style='{$logo_style}'>";
    } else {
        $logo_html = "<div style='font-size:45px;margin-bottom:10px;'>🔐</div>";
    }

    $subject_label = ($purpose === 'reset') ? 'Password Reset' : 'Email Verification';
    $mail->Subject  = "Faculty Evaluation System - {$subject_label} Code: {$otp_code}";

    $action_text = ($purpose === 'reset')
        ? 'You requested to reset your password.'
        : 'You are registering an account.';

    $mail->Body = "
    <!DOCTYPE html>
    <html>
    <head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'><meta charset='UTF-8'></head>
    <body style='margin:0;padding:0;background:#f0f4ff;font-family:Inter,Segoe UI,Arial,sans-serif;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f4ff;padding:40px 20px;'>
        <tr><td align='center'>
          <table width='480' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.08);'>
            <!-- Header -->
            <tr>
              <td style='background:linear-gradient(135deg, #0b5394 0%, #8B0000 100%);padding:40px 30px;text-align:center;border-bottom:4px solid #f59e0b;'>
                {$logo_html}
                <h1 style='color:#ffffff;margin:0;font-size:24px;font-weight:800;letter-spacing:0.5px;'>{$subject_label} Code</h1>
                <p style='color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:1px;'>MCNP-ISAP Faculty Evaluation System</p>
              </td>
            </tr>
            <!-- Body -->
            <tr>
              <td style='padding:40px;'>
                <p style='color:#374151;font-size:15px;margin:0 0 24px;line-height:1.6;'>{$action_text} Use the secure code below to verify your identity.</p>
                <!-- OTP Box -->
                <div style='background:linear-gradient(135deg,#f8fafc,#f1f5f9);border:2px dashed #0b5394;border-radius:12px;padding:24px;text-align:center;margin:0 0 28px;'>
                  <p style='color:#64748b;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;margin:0 0 10px;'>Your Verification Code</p>
                  <div style='font-size:46px;font-weight:800;letter-spacing:12px;color:#8B0000;font-family:\"Courier New\",Courier,monospace;text-shadow: 2px 2px 4px rgba(139, 0, 0, 0.15);'>{$otp_code}</div>
                </div>
                <!-- Info -->
                <div style='background:#fffbeb;border-left:4px solid #f59e0b;border-radius:8px;padding:16px;margin:0 0 24px;'>
                  <p style='color:#92400e;font-size:13.5px;margin:0;line-height:1.5;'><strong>⏱ Valid for 10 minutes only.</strong><br>Do not share this code with anyone.</p>
                </div>
                <p style='color:#94a3b8;font-size:12.5px;margin:0;line-height:1.5;'>If you did not request this verification, please ignore this email. Your account is safe.</p>
              </td>
            </tr>
            <!-- Footer -->
            <tr>
              <td style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center;'>
                <p style='color:#64748b;font-size:12px;margin:0;font-weight:600;'>MCNP-ISAP Faculty Evaluation System</p>
                <p style='color:#94a3b8;font-size:11px;margin:6px 0 0;'>This is an automated message. Please do not reply.</p>
              </td>
            </tr>
          </table>
        </td></tr>
      </table>
    </body>
    </html>";

    $mail->AltBody = "Your {$subject_label} Code is: {$otp_code}\n\nThis code is valid for 10 minutes. Do not share it with anyone.";

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
        // Port 587 is blocked on DigitalOcean; attempt automatic fallback to port 2525
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
        echo json_encode(['success' => true, 'message' => "OTP sent to {$email}. Valid for 10 minutes."]);
    } elseif ($is_localhost) {
        // On localhost: Do not invalidate OTP so developer can test registration without blocking
        echo json_encode([
            'success' => true,
            'dev_mode' => true,
            'otp' => $otp_code,
            'message' => "Dev Mode (Localhost): OTP is {$otp_code}\n(Email not sent: {$mail_error})"
        ]);
    } else {
        // On production: Invalidate OTP on send failure
        $cln = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE email = ? AND otp_code = ? AND purpose = ?");
        if ($cln) {
            $cln->bind_param("sss", $email, $otp_code, $purpose);
            $cln->execute();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Could not send OTP email. Please check your email configuration or contact the administrator. Error: ' . $mail_error
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Mailer initialization error: ' . $e->getMessage()]);
}
?>
