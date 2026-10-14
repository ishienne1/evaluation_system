<?php
session_start();
include 'db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$email   = trim($_POST['email']   ?? '');
$otp     = trim($_POST['otp']     ?? '');
$purpose = trim($_POST['purpose'] ?? 'register');

// ── Validate ─────────────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($otp) || !in_array($purpose, ['register', 'reset'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit();
}

// ── Look up the latest valid OTP ──────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, otp_code, expires_at 
    FROM email_otp 
    WHERE email = ? AND purpose = ? AND is_used = 0 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->bind_param("ss", $email, $purpose);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No active OTP found. Please request a new code.']);
    exit();
}

// ── Check expiry ──────────────────────────────────────────────────────
if (strtotime($row['expires_at']) <= time()) {
    // Mark as used/expired
    $exp = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE id = ?");
    $exp->bind_param("i", $row['id']);
    $exp->execute();
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit();
}

// ── Match OTP ─────────────────────────────────────────────────────────
if ($row['otp_code'] !== $otp) {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP code. Please try again.']);
    exit();
}

// ── Mark OTP as used ──────────────────────────────────────────────────
$mark = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE id = ?");
$mark->bind_param("i", $row['id']);
$mark->execute();

// ── Store verified status in session ─────────────────────────────────
if ($purpose === 'register') {
    $_SESSION['otp_verified_register_email'] = $email;
    $_SESSION['otp_verified_register_time']  = time();
} elseif ($purpose === 'reset') {
    $_SESSION['otp_verified_reset_email'] = $email;
    $_SESSION['otp_verified_reset_time']  = time();
}

echo json_encode(['success' => true, 'message' => 'OTP verified successfully!']);
?>
