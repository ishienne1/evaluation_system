<?php
session_start();
include 'db_connect.php';

if (isset($_SESSION['original_admin_id'])) {
    $original_admin_id = $_SESSION['original_admin_id'];
    $original_admin_school = $_SESSION['original_admin_school'] ?? null;

    $admin_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role IN ('admin', 'system admin')");
    $admin_stmt->bind_param("i", $original_admin_id);
    $admin_stmt->execute();
    $admin_user = $admin_stmt->get_result()->fetch_assoc();

    if ($admin_user) {
        session_unset(); // Clear the impersonated session
        $_SESSION['id'] = $admin_user['id'];
        $_SESSION['role'] = $admin_user['role'];
        $_SESSION['full_name'] = $admin_user['full_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['dept_id'] = $admin_user['dept_id'] ?? null;
        $_SESSION['school'] = $original_admin_school ?? $admin_user['school'] ?? 'mcnp';
        $_SESSION['avatar'] = $admin_user['avatar'];
        $redirect = ($admin_user['role'] === 'system admin') ? 'dashboard_ict.php' : 'dashboard_admin.php';
        header("Location: " . $redirect);
        exit();
    }
}

session_destroy();
header("Location: index.php");
exit();
