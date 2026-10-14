<?php 
session_start();
// This file is obsolete and has been replaced by the more comprehensive evaluation_form.php.
if (isset($_SESSION['role']) && $_SESSION['role'] === 'visitor') {
    header("Location: dashboard_visitor.php");
    exit();
}
header("Location: dashboard_student.php");
exit();
?>