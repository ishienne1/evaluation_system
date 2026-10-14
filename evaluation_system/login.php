<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// ── School config ─────────────────────────────────────────────────────
$school = trim($_GET['school'] ?? $_POST['school'] ?? '');
if (!in_array($school, ['mcnp', 'isap', 'mcnp-isap'])) {
    $school = 'mcnp-isap';
}


$mcnp_depts = ['CON', 'CORT', 'CMLS', 'COP', 'COPT'];
$isap_depts = ['CASTE', 'CBEM', 'CITE', 'COCJE'];
if ($school === 'mcnp-isap') {
    $filter = array_merge($mcnp_depts, $isap_depts);
} else {
    $filter = ($school === 'mcnp') ? $mcnp_depts : $isap_depts;
}

$schools = [
    'mcnp' => [
        'name'    => 'Medical Colleges of Northern Philippines',
        'short'   => 'MCNP',
        'logo'    => 'MCNP_Logo.png',
        'primary' => '#1a2f7a',
        'grad'    => 'linear-gradient(145deg,#0d1f66,#1a2f7a,#2552c4,#3b6bd6)',
        'shadow'  => '30,58,138',
        'tagline' => 'Deo Et Patria · Est. 1994',
        'portal'  => 'Student Portal',
        'feats'   => ['Student Evaluation Portal', 'Evaluate Instructors & Faculty', 'Confidential & Secure Submissions'],
    ],
    'isap' => [
        'name'    => 'International School of Asia and the Pacific',
        'short'   => 'ISAP',
        'logo'    => 'ISAP_Logo.png',
        'primary' => '#7a0000',
        'grad'    => 'linear-gradient(145deg,#3d0000,#7a0000,#b01020,#c41230)',
        'shadow'  => '139,0,0',
        'tagline' => 'Culture · Service · Excellence · 1997',
        'portal'  => 'Student Portal',
        'feats'   => ['Student Evaluation Portal', 'Diverse Academic Programs', 'Confidential & Secure Submissions'],
    ],
    'mcnp-isap' => [
        'name'    => 'MCNP-ISAP Joint Portal',
        'short'   => 'MCNP-ISAP',
        'logo'    => 'MCNP_Logo.png',
        'primary' => '#1a2f7a',
        'grad'    => 'linear-gradient(145deg,#0d1f66,#1a2f7a,#7a0000,#c41230)',
        'shadow'  => '30,58,138',
        'tagline' => 'Faculty, Deans, Administrators & Staff',
        'portal'  => 'Faculty & Staff Portal',
        'feats'   => ['Faculty Performance Reports', 'Dean Department Analytics', 'Admin & ICT System Controls'],
    ],
];
$c = $schools[$school];

// ── Departments & Courses ─────────────────────────────────────────────
$ph  = implode(',', array_fill(0, count($filter), '?'));
$tp  = str_repeat('s', count($filter));
$ds  = $conn->prepare("SELECT id,dept_alias,dept_name FROM departments WHERE dept_alias IN ($ph) ORDER BY dept_name");
$ds->bind_param($tp, ...$filter);
$ds->execute();
$depts = $ds->get_result()->fetch_all(MYSQLI_ASSOC);

$courses_map = [];
foreach ($depts as $d) {
    $cs = $conn->prepare("SELECT course_alias,course_name FROM courses WHERE dept_id=? ORDER BY course_name");
    $cs->bind_param("i", $d['id']);
    $cs->execute();
    $courses_map[$d['dept_alias']] = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── State ─────────────────────────────────────────────────────────────
$lerr = '';
$lok = '';
$rerr = '';
$requested_role = trim($_GET['role'] ?? $_POST['requested_role'] ?? '');
$panel = (isset($_GET['panel']) && $_GET['panel'] === 'register') ? 'register' : 'login';
$selected_login_role = $_POST['role'] ?? ($requested_role ?: '');
$selected_reg_role = $_POST['reg_role'] ?? ($requested_role ?: ($school === 'mcnp-isap' ? '' : 'student'));
if (isset($_GET['pending']))   $lok = "Account created! Pending administrator approval.";
if (isset($_GET['registered'])) $lok = "Registration successful! You can now log in.";
if (isset($_GET['msg']) && $_GET['msg'] === 'pw_reset_ok') $lok = "Password reset! You can now log in.";

// ── Login ─────────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    $em = trim($_POST['email'] ?? '');
    $pw = $_POST['password'] ?? '';
    $ro = trim($_POST['role'] ?? '');
    $st = $conn->prepare("SELECT id,username,password,full_name,role,visitor_category,dept_id,course_id,email,avatar,status,school FROM users WHERE (email=? OR username=?) AND role=?");
    $st->bind_param("sss", $em, $em, $ro);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        $chk_any = $conn->prepare("SELECT role FROM users WHERE email=? OR username=?");
        $chk_any->bind_param("ss", $em, $em);
        $chk_any->execute();
        $found_any = $chk_any->get_result()->fetch_assoc();
        if ($found_any && in_array($found_any['role'], ['faculty', 'dean', 'visitor', 'admin', 'system admin']) && $school !== 'mcnp-isap') {
            $lerr = "This account is for faculty/staff. Please sign in through the MCNP-ISAP portal.";
        } elseif ($found_any && $found_any['role'] === 'student' && $school === 'mcnp-isap') {
            $lerr = "Students must log in through their respective school portal (MCNP or ISAP).";
        } else {
            $lerr = "Invalid email/ID number or role.";
        }
    } elseif ($school !== 'mcnp-isap' && $row['school'] !== null && $row['school'] !== $school) {
        $lerr = "Account not registered under " . strtoupper($school) . ".";
    } elseif ($school === 'mcnp-isap' && $ro === 'student') {
        $lerr = "Students must log in through the MCNP or ISAP student portal.";
    } elseif ($school !== 'mcnp-isap' && $ro !== 'student') {
        $lerr = "Faculty and staff must log in through the MCNP-ISAP portal.";
    } elseif ($row['status'] === 'pending') $lerr = "Account pending approval.";
    elseif ($row['status'] !== 'active')  $lerr = "Account deactivated. Contact admin.";
    elseif (password_verify($pw, $row['password'])) {
        foreach (['id', 'username', 'full_name', 'role', 'visitor_category', 'dept_id', 'course_id', 'avatar', 'school'] as $k) $_SESSION[$k] = $row[$k] ?? null;
        $conn->query("UPDATE users SET last_login=NOW() WHERE id={$row['id']}");
        if ($row['role'] === 'admin')            header("Location: dashboard_admin.php");
        elseif ($row['role'] === 'system admin') header("Location: dashboard_ict.php");
        elseif ($row['role'] === 'dean')         header("Location: dashboard_dean.php");
        elseif ($row['role'] === 'student')      header("Location: dashboard_student.php");
        elseif ($row['role'] === 'faculty')      header("Location: dashboard_faculty.php");
        elseif ($row['role'] === 'visitor')      header("Location: dashboard_visitor.php");
        else {
            header("Location: index.php");
            exit();
        }
    } else $lerr = "Incorrect password.";
}

// ── Register ──────────────────────────────────────────────────────────
if (isset($_POST['register'])) {
    $panel = 'register';
    $un = trim($_POST['reg_username'] ?? '');
    $pw = $_POST['reg_password'] ?? '';
    $cf = $_POST['reg_confirm_password'] ?? '';
    $fn = trim($_POST['reg_first_name'] ?? '');
    $mn = trim($_POST['reg_middle_name'] ?? '');
    $ln = trim($_POST['reg_last_name'] ?? '');
    $em = trim($_POST['reg_email'] ?? '');
    $da = $_POST['reg_department'] ?? '';
    $ca = $_POST['reg_course'] ?? '';
    $ro = trim($_POST['reg_role'] ?? ($school === 'mcnp-isap' ? '' : 'student'));
    $rs = $_POST['school'] ?? $school;

    $allowed_reg_roles = ($requested_role === 'visitor')
        ? ['visitor']
        : (($school === 'mcnp-isap') ? ['faculty', 'dean'] : ['student']);

    if (!in_array($ro, $allowed_reg_roles)) {
        if (in_array($ro, ['admin', 'system admin'])) {
            $rerr = "Administrator accounts cannot be self-registered.";
        } elseif ($ro === 'visitor') {
            $rerr = "Visitors must register through the Campus Visitor Portal.";
        } else {
            $rerr = ($school === 'mcnp-isap')
                ? "Students must register through the MCNP or ISAP student portal."
                : "Only student registration is permitted here. Faculty and deans must register under MCNP-ISAP.";
        }
    }

    $ove = $_SESSION['otp_verified_register_email'] ?? null;
    $ovt = $_SESSION['otp_verified_register_time'] ?? 0;

    if (empty($rerr)) {
        if ($ro === 'visitor') {
            // Bypass email and OTP for visitor
            $em = null;
            if (empty($un)) $rerr = "Please provide a Username.";
            elseif ($pw !== $cf)       $rerr = "Passwords do not match!";
            elseif (strlen($pw) < 6)   $rerr = "Password must be at least 6 characters.";
        } else {
            if ($ove !== $em)          $rerr = "Email not verified via OTP.";
            elseif (time() - $ovt > 1800) {
                unset($_SESSION['otp_verified_register_email'], $_SESSION['otp_verified_register_time']);
                $rerr = "OTP expired. Verify email again.";
            } elseif ($pw !== $cf)       $rerr = "Passwords do not match!";
            elseif (strlen($pw) < 6)   $rerr = "Password must be at least 6 characters.";
            elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) $rerr = "Invalid email format.";
        }
    }

    if (empty($rerr)) {
        $full = trim($fn . ($mn ? " $mn" : "") . " $ln");
        $hp = password_hash($pw, PASSWORD_DEFAULT);
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? OR (email IS NOT NULL AND email=? AND email != '')");
        $chk->bind_param("ss", $un, $em);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $rerr = "Username or Email already exists!";
        } else {
            $did = $cid = null;
            if ($da) {
                $dq = $conn->prepare("SELECT id FROM departments WHERE dept_alias=? LIMIT 1");
                $dq->bind_param("s", $da);
                $dq->execute();
                $did = $dq->get_result()->fetch_assoc()['id'] ?? null;
            }
            if ($ca && $did) {
                $cq = $conn->prepare("SELECT id FROM courses WHERE course_alias=? AND dept_id=? LIMIT 1");
                $cq->bind_param("si", $ca, $did);
                $cq->execute();
                $cid = $cq->get_result()->fetch_assoc()['id'] ?? null;
            }

            // Determine school affiliation for database storage
            $target_school = null;
            if ($school === 'mcnp') {
                $target_school = 'mcnp';
            } elseif ($school === 'isap') {
                $target_school = 'isap';
            } elseif ($school === 'mcnp-isap') {
                if (in_array($da, $mcnp_depts)) {
                    $target_school = 'mcnp';
                } elseif (in_array($da, $isap_depts)) {
                    $target_school = 'isap';
                } elseif ($ro === 'visitor') {
                    $target_school = 'mcnp';
                } else {
                    $target_school = null; // admin / system admin
                }
            }

            $vc = ($ro === 'visitor') ? trim($_POST['visitor_category'] ?? 'Campus Guest') : null;
            $initial_status = ($ro === 'visitor') ? 'active' : 'pending';
            $ins = $conn->prepare("INSERT INTO users (username,password,role,visitor_category,full_name,first_name,middle_name,last_name,email,dept_id,course_id,status,school) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sssssssssiiss", $un, $hp, $ro, $vc, $full, $fn, $mn, $ln, $em, $did, $cid, $initial_status, $target_school);
            if ($ins->execute()) {
                unset($_SESSION['otp_verified_register_email'], $_SESSION['otp_verified_register_time']);
                $redirect_param = ($ro === 'visitor') ? 'registered=1&role=visitor' : 'pending=1';
                header("Location: login.php?school={$school}&{$redirect_param}");
                exit();
            } else $rerr = "Registration error: " . $conn->error;
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
    <title><?php echo $c['short']; ?> — Login & Register</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        html,
        body {
            font-family: 'Poppins', sans-serif
        }

        /* ── Page background ──────────────────────────── */
        body {
            height: 100vh;
            height: 100dvh;
            min-height: 100vh;
            background: var(--home-grad);
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 0;
            position: relative;
            overflow-x: hidden;
            overflow-y: hidden;
        }

        [data-theme="dark"] body {
            background: var(--home-grad)
        }

        /* BG orbs */
        .bg-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(110px);
            pointer-events: none;
            z-index: 0;
            animation: orbPop 10s ease-in-out infinite alternate
        }

        .bg-orb-a {
            width: 550px;
            height: 550px;
            background: rgba(<?php echo $c['shadow']; ?>, .14);
            top: -100px;
            left: -100px
        }

        .bg-orb-b {
            width: 450px;
            height: 450px;
            background: rgba(<?php echo $c['shadow']; ?>, .10);
            bottom: -80px;
            right: -80px;
            animation-delay: 4s
        }

        @keyframes orbPop {
            0% {
                transform: scale(1)
            }

            100% {
                transform: scale(1.2)
            }
        }

        /* ── Top bar ─────────────────────────────────── */
        .top-bar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 32px;
            background: rgba(255, 255, 255, .82);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(0, 0, 0, .08);
            flex-shrink: 0;
            width: 100%;
        }

        [data-theme="dark"] .top-bar {
            background: rgba(13, 17, 32, .88);
            border-color: rgba(255, 255, 255, .08)
        }

        .top-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none
        }

        .top-logo {
            height: 30px;
            width: 30px;
            object-fit: contain
        }

        .top-name {
            font-weight: 700;
            font-size: 13px;
            color: var(--text-main)
        }

        .top-right {
            display: flex;
            align-items: center;
            gap: 12px
        }

        .back-lnk {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color .2s
        }

        .back-lnk:hover {
            color: var(--s-primary)
        }

        .th-btn {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--toggle-bg);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 13px;
            transition: all .3s
        }

        /* ── The main card ──────────────────────────── */
        .mobile-auth-header {
            display: none;
        }

        .card {
            position: relative;
            z-index: 1;
            width: min(980px, 94vw);
            height: min(580px, calc(100vh - 66px));
            max-height: calc(100vh - 66px);
            margin: auto;
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 16px 50px rgba(0, 0, 0, .12), 0 4px 20px rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        [data-theme="dark"] .card {
            background: #141828
        }

        /* ── Form panels — each occupies one half ──── */
        .f-panel {
            position: absolute;
            top: 0;
            height: 100%;
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: clamp(12px, 1.8vh, 20px) clamp(22px, 2.8vw, 38px);
            overflow-y: auto;
            background: transparent;
        }

        .f-panel::-webkit-scrollbar {
            width: 3px;
            height: 0
        }

        .f-panel::-webkit-scrollbar:horizontal {
            display: none !important;
            height: 0 !important;
            width: 0 !important
        }

        .f-panel::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, .35);
            border-radius: 3px
        }

        .p-login {
            left: 0;
            opacity: 1;
            visibility: visible;
            z-index: 2;
            transition: opacity 0s .33s, visibility 0s .33s
        }

        .p-reg {
            right: 0;
            opacity: 0;
            visibility: hidden;
            z-index: 2;
            transition: opacity 0s .33s, visibility 0s .33s
        }

        .card.sr .p-login {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0s .33s, visibility 0s .33s
        }

        .card.sr .p-reg {
            opacity: 1;
            visibility: visible;
            transition: opacity 0s .33s, visibility 0s .33s
        }

        /* ── THE BLOB ───────────────────────────────── */
        .blob {
            position: absolute;
            top: 0;
            right: 0;
            width: 50%;
            height: 100%;
            z-index: 100;
            background: <?php echo $c['grad']; ?>;
            border-radius: 60% 0 0 60% / 50% 0 0 50%;
            overflow: hidden;
            transition: transform .65s cubic-bezier(.68, -.05, .32, 1.05),
                border-radius .65s cubic-bezier(.68, -.05, .32, 1.05);
        }

        /* Blob slides to left when show-register */
        .card.sr .blob {
            transform: translateX(-100%);
            border-radius: 0 60% 60% 0 / 0 50% 50% 0;
        }

        /* Blob inner — 200% wide so we can slide content */
        .blob-inner {
            width: 200%;
            height: 100%;
            display: flex;
            transform: translateX(-50%);
            transition: transform .65s cubic-bezier(.68, -.05, .32, 1.05);
        }

        .card.sr .blob-inner {
            transform: translateX(0)
        }

        /* Each blob-half is 50% of blob-inner = actual blob width */
        .blob-half {
            width: 50%;
            height: 100%;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(18px, 2.5vh, 28px) clamp(16px, 2.2vw, 26px);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* decorative circles inside blob */
        .blob-half::before {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
            top: -70px;
            right: -50px
        }

        .blob-half::after {
            content: '';
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .05);
            bottom: -35px;
            left: -20px
        }

        /* Blob logo */
        .blob-logo-wrap {
            position: relative;
            margin-bottom: 8px;
            z-index: 1
        }

        .blob-logo-wrap::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 1.5px solid rgba(255, 255, 255, .25);
            animation: ringPulse 3.5s ease-in-out infinite;
        }

        .blob-logo-wrap::after {
            content: '';
            position: absolute;
            inset: -16px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .1);
            animation: ringPulse 3.5s ease-in-out infinite .9s;
        }

        @keyframes ringPulse {

            0%,
            100% {
                opacity: .4;
                transform: scale(1)
            }

            50% {
                opacity: .8;
                transform: scale(1.05)
            }
        }

        .blob-logo {
            width: clamp(50px, 6.2vh, 66px);
            height: clamp(50px, 6.2vh, 66px);
            object-fit: contain;
            border-radius: 50%;
            filter: drop-shadow(0 4px 14px rgba(0, 0, 0, .35));
            animation: floatLogo 5s ease-in-out infinite;
        }

        @keyframes floatLogo {

            0%,
            100% {
                transform: translateY(0)
            }

            50% {
                transform: translateY(-6px)
            }
        }

        .blob-school-tag {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 16px;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .3);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .8px;
            color: rgba(255, 255, 255, .95);
            margin-bottom: 8px;
            z-index: 1;
            position: relative;
        }

        .blob-title {
            font-size: clamp(1.2rem, 1.55vw, 1.5rem);
            font-weight: 900;
            color: #fff;
            margin-bottom: 3px;
            z-index: 1;
            position: relative;
            line-height: 1.25
        }

        .blob-sub {
            font-size: 11.5px;
            color: rgba(255, 255, 255, .82);
            line-height: 1.4;
            margin-bottom: clamp(10px, 1.5vh, 16px);
            z-index: 1;
            position: relative
        }

        .blob-feats {
            list-style: none;
            text-align: left;
            margin-bottom: clamp(10px, 1.5vh, 16px);
            z-index: 1;
            position: relative;
            width: 100%;
            max-width: 320px
        }

        .blob-feats li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            color: rgba(255, 255, 255, .88);
            padding: 3.5px 0;
            border-bottom: 1px solid rgba(255, 255, 255, .08)
        }

        .blob-feats li i {
            font-size: 9px;
            color: rgba(255, 255, 255, .6)
        }

        .blob-switch-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 22px;
            border-radius: 22px;
            border: 2px solid rgba(255, 255, 255, .7);
            background: transparent;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: .3px;
            font-family: 'Poppins', sans-serif;
            transition: all .3s;
            z-index: 1;
            position: relative;
        }

        .blob-switch-btn:hover {
            background: rgba(255, 255, 255, .18);
            border-color: #fff;
            transform: translateY(-1px)
        }

        /* ── FORM STYLES ──────────────────────────── */
        .f-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(<?php echo $c['shadow']; ?>, .08);
            border: 1px solid rgba(<?php echo $c['shadow']; ?>, .2);
            border-radius: 16px;
            padding: 3px 10px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .5px;
            margin-bottom: 5px;
            color: <?php echo $c['primary']; ?>;
            width: fit-content;
        }

        [data-theme="dark"] .f-badge {
            color: <?php echo ($school === 'mcnp') ? '#5b85e8' : '#e05a6a'; ?>
        }

        .f-title {
            font-size: clamp(1.2rem, 1.5vw, 1.5rem);
            font-weight: 900;
            color: var(--text-main);
            margin-bottom: 2px;
            line-height: 1.2
        }

        .f-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-bottom: clamp(5px, 0.9vh, 10px);
            line-height: 1.35
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 600;
            margin-bottom: 6px
        }

        .a-err {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca
        }

        .a-ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0
        }

        [data-theme="dark"] .a-err {
            background: rgba(239, 68, 68, .12);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, .28)
        }

        [data-theme="dark"] .a-ok {
            background: rgba(16, 185, 129, .12);
            color: #6ee7b7;
            border-color: rgba(16, 185, 129, .28)
        }

        /* Field */
        .field {
            margin-bottom: clamp(4px, 0.7vh, 7px)
        }

        .field label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 2.5px
        }

        .inp-wrap {
            position: relative
        }

        .inp-wrap .ico {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 12px;
            pointer-events: none
        }

        .field input,
        .field select {
            width: 100%;
            padding: 7px 30px 7px 11px;
            background: var(--input-bg);
            color: var(--text-main);
            border: 1.5px solid var(--border-color);
            border-radius: 9px;
            font-size: 12.5px;
            font-family: 'Inter', sans-serif;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: <?php echo $c['primary']; ?>;
            box-shadow: 0 0 0 3px rgba(<?php echo $c['shadow']; ?>, .14);
        }

        .field input.bare,
        .field select.bare {
            padding-right: 11px
        }

        .pw-toggle {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            font-size: 12px;
            background: none;
            border: none;
            padding: 0
        }

        .g2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .g3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 7px
        }

        .g3 .field label,
        .g3 .field input {
            font-size: 10px
        }

        /* OTP */
        .otp-row {
            display: flex;
            gap: 7px;
            align-items: flex-end
        }

        .otp-row .field {
            flex: 1;
            margin: 0
        }

        .send-otp-btn {
            flex-shrink: 0;
            padding: 7px 13px;
            border-radius: 9px;
            border: none;
            background: <?php echo $c['grad']; ?>;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 2px 8px rgba(<?php echo $c['shadow']; ?>, .3);
            transition: opacity .2s;
            white-space: nowrap;
        }

        .send-otp-btn:hover {
            opacity: .85
        }

        .verify-row {
            display: none;
            gap: 7px;
            align-items: flex-end;
            margin-top: 5px
        }

        .verify-row.on {
            display: flex
        }

        .verify-btn {
            flex-shrink: 0;
            padding: 7px 13px;
            border-radius: 9px;
            border: none;
            background: #10b981;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: opacity .2s
        }

        .verify-btn:hover {
            opacity: .85
        }

        .otp-ok {
            display: none;
            align-items: center;
            gap: 5px;
            color: #059669;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 9px;
            background: rgba(16, 185, 129, .08);
            border: 1px solid rgba(16, 185, 129, .2);
            border-radius: 7px;
            margin-top: 4px
        }

        .otp-ok.on {
            display: flex
        }

        .otp-timer {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px
        }

        /* Divider */
        .dvdr {
            display: flex;
            align-items: center;
            gap: 7px;
            margin: 5px 0
        }

        .dvdr span {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-muted);
            white-space: nowrap;
            letter-spacing: .5px;
            text-transform: uppercase
        }

        .dvdr::before,
        .dvdr::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color)
        }

        /* Submit */
        .sub-btn {
            width: 100%;
            padding: 9.5px;
            border: none;
            border-radius: 20px;
            background: <?php echo $c['grad']; ?>;
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 5px;
            box-shadow: 0 3px 14px rgba(<?php echo $c['shadow']; ?>, .3);
            transition: all .3s;
            font-family: 'Poppins', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .sub-btn:hover {
            opacity: .88;
            transform: translateY(-1px);
            box-shadow: 0 5px 18px rgba(<?php echo $c['shadow']; ?>, .38)
        }

        .sub-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none
        }

        .bot-lnk {
            text-align: center;
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 7px
        }

        .bot-lnk a {
            color: <?php echo $c['primary']; ?>;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none
        }

        [data-theme="dark"] .bot-lnk a {
            color: <?php echo ($school === 'mcnp') ? '#5b85e8' : '#e05a6a'; ?>
        }

        .bot-lnk a:hover {
            opacity: .75
        }

        .forgot-lnk {
            display: block;
            text-align: center;
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 5px;
            text-decoration: none
        }

        .forgot-lnk:hover {
            color: <?php echo $c['primary']; ?>
        }

        .note {
            text-align: center;
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px
        }

        /* ── MOBILE FULL SCREEN LAYOUT (<= 900px) ─────────────────────────── */
        @media(max-width:900px) {
            body {
                height: auto;
                min-height: 100vh;
                min-height: 100dvh;
                overflow-y: auto;
                overflow-x: hidden;
                padding: 0;
                background: var(--home-grad);
                display: flex;
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
            }

            .top-bar {
                position: sticky;
                top: 0;
                left: 0;
                right: 0;
                z-index: 500;
                padding: 10px 16px;
                width: 100%;
                background: rgba(255, 255, 255, 0.88);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-bottom: 1px solid var(--border-color);
            }

            [data-theme="dark"] .top-bar {
                background: rgba(13, 17, 32, 0.92);
            }

            .top-name {
                font-size: 12px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 180px;
            }

            .card {
                width: 100% !important;
                max-width: 100% !important;
                min-height: calc(100dvh - 54px) !important;
                height: auto !important;
                max-height: none !important;
                margin: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                display: flex !important;
                flex-direction: column !important;
                background: transparent !important;
                border: none !important;
                overflow: visible !important;
                position: relative !important;
            }

            /* Hide the desktop sliding blob on mobile */
            .blob {
                display: none !important;
            }

            /* Mobile Header & Switcher */
            .mobile-auth-header {
                display: flex !important;
                flex-direction: column;
                align-items: center;
                padding: 22px 18px 6px;
                width: 100%;
                max-width: 480px;
                margin: 0 auto;
                gap: 12px;
                box-sizing: border-box;
            }

            .m-brand-badge {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 6px 14px;
                border-radius: 20px;
                background: rgba(<?php echo $c['shadow']; ?>, 0.1);
                border: 1px solid rgba(<?php echo $c['shadow']; ?>, 0.22);
                color: <?php echo $c['primary']; ?>;
                font-size: 11px;
                font-weight: 750;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                box-shadow: 0 2px 8px rgba(<?php echo $c['shadow']; ?>, 0.08);
            }

            [data-theme="dark"] .m-brand-badge {
                color: <?php echo ($school === 'mcnp') ? '#7ba5f5' : '#f87171'; ?>;
                background: rgba(<?php echo $c['shadow']; ?>, 0.22);
                border-color: rgba(255, 255, 255, 0.15);
            }

            .m-auth-switcher {
                display: flex;
                width: 100%;
                background: var(--toggle-bg);
                border: 1px solid var(--border-color);
                border-radius: 14px;
                padding: 4px;
                gap: 5px;
                box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.06);
                box-sizing: border-box;
            }

            .m-auth-tab {
                flex: 1;
                padding: 10px 14px;
                border: none;
                border-radius: 10px;
                background: transparent;
                color: var(--text-muted);
                font-size: 13px;
                font-weight: 700;
                font-family: 'Poppins', sans-serif;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                transition: all 0.25s ease;
            }

            .m-auth-tab.active {
                background: <?php echo $c['grad']; ?>;
                color: #ffffff !important;
                box-shadow: 0 4px 14px rgba(<?php echo $c['shadow']; ?>, 0.35);
            }

            /* Full Screen Form Panels */
            .f-panel {
                position: relative !important;
                top: auto !important;
                left: auto !important;
                right: auto !important;
                width: 100% !important;
                max-width: 480px !important;
                margin: 0 auto !important;
                height: auto !important;
                flex: 1;
                min-height: auto;
                padding: 16px 18px 40px !important;
                overflow-y: visible !important;
                background: transparent !important;
                box-sizing: border-box !important;
            }

            /* Hide redundant f-badge inside forms on mobile since mobile-auth-header shows it */
            .f-panel .f-badge {
                display: none !important;
            }

            .p-login {
                display: flex !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .p-reg {
                display: none !important;
                opacity: 0 !important;
                visibility: hidden !important;
            }

            .card.sr .p-login {
                display: none !important;
                opacity: 0 !important;
                visibility: hidden !important;
            }

            .card.sr .p-reg {
                display: flex !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .f-title {
                font-size: 1.45rem;
                margin-bottom: 4px;
            }

            .f-sub {
                font-size: 12px;
                margin-bottom: 16px;
            }

            /* Form Inputs — spacious & comfortable on mobile */
            .field {
                margin-bottom: 10px;
            }

            .field label {
                font-size: 11px;
                margin-bottom: 4px;
            }

            .field input,
            .field select {
                padding: 11px 34px 11px 13px;
                font-size: 14px;
                border-radius: 11px;
                min-height: 44px;
            }

            .field input.bare,
            .field select.bare {
                padding-right: 13px;
            }

            .inp-wrap .ico,
            .pw-toggle {
                font-size: 14px;
                right: 13px;
            }

            .sub-btn {
                padding: 13px;
                font-size: 14px;
                border-radius: 14px;
                min-height: 46px;
                margin-top: 10px;
            }

            .g2,
            .g3 {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .otp-row {
                gap: 8px;
            }

            .send-otp-btn,
            .verify-btn {
                min-height: 44px;
                padding: 10px 14px;
                font-size: 12px;
                border-radius: 11px;
            }

            .bot-lnk {
                font-size: 12.5px;
                margin-top: 16px;
                padding: 8px 0;
            }

            .forgot-lnk {
                font-size: 12px;
                margin-top: 8px;
                padding: 6px 0;
            }
        }

        @media(max-height:560px) and (min-width:901px) {
            body {
                height: auto;
                min-height: 100vh;
                overflow-y: auto
            }

            .card {
                height: auto;
                min-height: 500px;
                max-height: none;
                margin: 12px auto
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="notifications.js?v=<?php echo time(); ?>"></script>
</head>

<body>
    <div class="bg-orb bg-orb-a"></div>
    <div class="bg-orb bg-orb-b"></div>

    <!-- ── Top bar ── -->
    <header class="top-bar">
        <a class="top-brand" href="index.php">
            <?php if ($school === 'mcnp-isap'): ?>
                <div style="display:flex;align-items:center;gap:6px;">
                    <img src="MCNP_Logo.png" class="top-logo" alt="MCNP" style="width:32px;height:32px;object-fit:contain;" onerror="this.src='Logo.png'">
                    <img src="ISAP_Logo.png" class="top-logo" alt="ISAP" style="width:32px;height:32px;object-fit:contain;" onerror="this.src='Logo.png'">
                </div>
                <span class="top-name">MCNP-ISAP Staff &amp; Faculty Portal</span>
            <?php else: ?>
                <img src="<?php echo $c['logo']; ?>" class="top-logo" alt="<?php echo $c['short']; ?>" onerror="this.src='Logo.png'">
                <span class="top-name"><?php echo $c['short']; ?> Student Portal</span>
            <?php endif; ?>
        </a>
        <div class="top-right">
            <a href="index.php" class="back-lnk"><i class="fas fa-arrow-left"></i> Change School</a>
            <div class="th-btn" id="thBtn" onclick="toggleTheme()"><i class="fas fa-moon"></i></div>
        </div>
    </header>

    <!-- ══ CARD ══════════════════════════════════════════════ -->
    <div class="card <?php echo $panel === 'register' ? 'sr' : ''; ?>" id="card">

        <!-- ── Mobile Auth Header & Switcher (Shown on <=900px) ── -->
        <div class="mobile-auth-header">
            <div class="m-brand-badge">
                <i class="fas fa-university"></i> <?php echo $c['portal'] ?? ($c['short'] . ' Portal'); ?>
            </div>
            <div class="m-auth-switcher">
                <button type="button" class="m-auth-tab <?php echo $panel !== 'register' ? 'active' : ''; ?>" id="mTabLogin" onclick="goLogin()">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
                <button type="button" class="m-auth-tab <?php echo $panel === 'register' ? 'active' : ''; ?>" id="mTabReg" onclick="goReg()">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </div>
        </div>

        <!-- ── Login Form (left half) ── -->
        <div class="f-panel p-login">
            <div class="f-badge"><i class="fas fa-university"></i> <?php echo ($requested_role === 'visitor') ? 'Campus Visitor Portal' : ($c['portal'] ?? ($c['short'] . ' Portal')); ?></div>
            <div class="f-title"><?php echo ($requested_role === 'visitor') ? 'Visitor Sign In' : 'Sign In'; ?></div>
            <div class="f-sub"><?php echo ($requested_role === 'visitor') ? 'Access your visitor account to evaluate facilities' : ('Access your ' . $c['short'] . ' evaluation account'); ?></div>

            <?php if ($lerr): ?><div class="alert a-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($lerr); ?></div><?php endif; ?>
            <?php if ($lok):  ?><div class="alert a-ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($lok); ?></div><?php endif; ?>

            <form method="POST">
                <input type="hidden" name="school" value="<?php echo htmlspecialchars($school); ?>">
                <input type="hidden" name="requested_role" value="<?php echo htmlspecialchars($requested_role); ?>">
                <input type="hidden" name="login" value="1">
                <div class="field">
                    <label>Email Address or ID Number</label>
                    <div class="inp-wrap">
                        <input type="text" name="email" placeholder="your@email.com or ID number" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        <i class="fas fa-user ico"></i>
                    </div>
                </div>
                <div class="field">
                    <label>Password</label>
                    <div class="inp-wrap">
                        <input type="password" name="password" id="lPw" placeholder="Enter your password" required>
                        <button type="button" class="pw-toggle" onclick="tPw('lPw',this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Role</label>
                    <div class="inp-wrap">
                        <select name="role" required class="bare">
                            <?php if ($requested_role === 'visitor'): ?>
                                <option value="visitor" selected>Visitor</option>
                            <?php elseif ($school === 'mcnp-isap'): ?>
                                <option value="" disabled <?php echo empty($selected_login_role) ? 'selected' : ''; ?>>Select your role</option>
                                <option value="faculty" <?php echo ($selected_login_role === 'faculty') ? 'selected' : ''; ?>>Faculty/Teacher</option>
                                <option value="dean" <?php echo ($selected_login_role === 'dean') ? 'selected' : ''; ?>>Dean</option>
                                <option value="admin" <?php echo ($selected_login_role === 'admin') ? 'selected' : ''; ?>>Admin / QA Officer</option>
                                <option value="system admin" <?php echo ($selected_login_role === 'system admin') ? 'selected' : ''; ?>>ICT Admin</option>
                            <?php else: ?>
                                <option value="student" selected>Student</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="sub-btn"><i class="fas fa-sign-in-alt"></i> Sign In</button>
            </form>
            <a href="forgot_password.php?school=<?php echo $school; ?>" class="forgot-lnk"><i class="fas fa-key"></i> Forgot password?</a>
            <div class="bot-lnk" style="margin-top:10px">No account? <a onclick="goReg()">Register here</a></div>
        </div>

        <!-- ── Register Form (right half) ── -->
        <div class="f-panel p-reg">
            <div class="f-badge"><i class="fas fa-user-plus"></i> <?php echo ($requested_role === 'visitor') ? 'Visitor Registration' : 'New Account'; ?></div>
            <div class="f-title"><?php echo ($requested_role === 'visitor') ? 'Visitor Sign Up' : 'Create Account'; ?></div>
            <div class="f-sub"><?php echo ($requested_role === 'visitor') ? 'Register your guest evaluation account' : ('Register for ' . $c['short'] . ' — takes just a minute'); ?></div>

            <?php if ($rerr): ?><div class="alert a-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($rerr); ?></div><?php endif; ?>

            <form method="POST" id="regForm">
                <input type="hidden" name="school" value="<?php echo htmlspecialchars($school); ?>">
                <input type="hidden" name="requested_role" value="<?php echo htmlspecialchars($requested_role); ?>">
                <input type="hidden" name="register" value="1">

                <!-- Email + OTP -->
                <div class="field" id="emailOTPSection">
                    <label>Email Address <span style="color:#ef4444">*</span></label>
                    <div class="otp-row">
                        <div class="field" style="margin:0">
                            <div class="inp-wrap">
                                <input type="email" name="reg_email" id="reg_email" placeholder="your@email.com" required
                                    value="<?php echo htmlspecialchars($_POST['reg_email'] ?? ''); ?>"
                                    onchange="otpOk=false;updBtn()">
                                <i class="fas fa-envelope ico"></i>
                            </div>
                        </div>
                        <button type="button" class="send-otp-btn" id="sotpBtn" onclick="sendOTP()">
                            <i class="fas fa-paper-plane"></i> Send OTP
                        </button>
                    </div>
                    <div class="verify-row" id="vRow">
                        <div class="field" style="margin:0">
                            <div class="inp-wrap">
                                <input type="text" id="otp_inp" maxlength="6" placeholder="6-digit OTP"
                                    style="letter-spacing:4px;text-align:center;font-size:16px">
                                <i class="fas fa-shield-alt ico"></i>
                            </div>
                        </div>
                        <button type="button" class="verify-btn" onclick="verifyOTP()"><i class="fas fa-check"></i> Verify</button>
                    </div>
                    <div class="otp-ok" id="otpOkMsg"><i class="fas fa-check-circle"></i> Email verified!</div>
                    <div class="otp-timer" id="otpTimer"></div>
                </div>

                <div class="dvdr"><span>Personal Details</span></div>

                <!-- Name -->
                <div class="g3">
                    <div class="field"><label>First Name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="reg_first_name" class="bare" placeholder="Juan" required value="<?php echo htmlspecialchars($_POST['reg_first_name'] ?? ''); ?>">
                    </div>
                    <div class="field"><label>Middle Name</label>
                        <input type="text" name="reg_middle_name" class="bare" placeholder="(Opt.)" value="<?php echo htmlspecialchars($_POST['reg_middle_name'] ?? ''); ?>">
                    </div>
                    <div class="field"><label>Last Name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="reg_last_name" class="bare" placeholder="Dela Cruz" required value="<?php echo htmlspecialchars($_POST['reg_last_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="g2">
                    <div class="field"><label id="idLabel">ID Number <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><input type="text" name="reg_username" id="regUsernameInput" placeholder="e.g. 2023-10408" required value="<?php echo htmlspecialchars($_POST['reg_username'] ?? ''); ?>"><i class="fas fa-id-card ico"></i></div>
                    </div>
                    <div class="field"><label>Register as <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><select name="reg_role" id="rRole" class="bare" required onchange="updateRoleFields()">
                                <?php if ($requested_role === 'visitor'): ?>
                                    <option value="visitor" selected>Visitor</option>
                                <?php elseif ($school === 'mcnp-isap'): ?>
                                    <option value="" disabled <?php echo empty($selected_reg_role) ? 'selected' : ''; ?>>-- Select Role --</option>
                                    <option value="faculty" <?php echo ($selected_reg_role === 'faculty') ? 'selected' : ''; ?>>Faculty/Teacher</option>
                                    <option value="dean" <?php echo ($selected_reg_role === 'dean') ? 'selected' : ''; ?>>Dean</option>
                                <?php else: ?>
                                    <option value="student" selected>Student</option>
                                <?php endif; ?>
                            </select></div>
                    </div>
                </div>

                <div class="field" id="visitorCatRow" style="<?php echo ($selected_reg_role === 'visitor') ? '' : 'display:none;'; ?>">
                    <label>Visitor Classification <span style="color:#ef4444">*</span></label>
                    <div class="inp-wrap">
                        <select name="visitor_category" id="visitorCatSel" class="bare">
                            <option value="Campus Guest" <?php echo (($_POST['visitor_category'] ?? '') === 'Campus Guest') ? 'selected' : ''; ?>>Campus Guest</option>
                            <option value="Parent / Guardian" <?php echo (($_POST['visitor_category'] ?? '') === 'Parent / Guardian') ? 'selected' : ''; ?>>Parent / Guardian</option>
                            <option value="Alumnus / Alumna" <?php echo (($_POST['visitor_category'] ?? '') === 'Alumnus / Alumna') ? 'selected' : ''; ?>>Alumnus / Alumna</option>
                            <option value="Accreditor / Inspector" <?php echo (($_POST['visitor_category'] ?? '') === 'Accreditor / Inspector') ? 'selected' : ''; ?>>Accreditor / Inspector</option>
                            <option value="Community Member" <?php echo (($_POST['visitor_category'] ?? '') === 'Community Member') ? 'selected' : ''; ?>>Community Member</option>
                        </select>
                        <i class="fas fa-id-badge ico"></i>
                    </div>
                </div>

                <div class="g2" id="deptCourseRow">
                    <div class="field" id="deptCol"><label>Department <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><select name="reg_department" id="dSel" class="bare" required onchange="filterC(this.value)">
                                <option value="">-- Department --</option>
                                <?php foreach ($depts as $d): ?><option value="<?php echo $d['dept_alias']; ?>" <?php echo (($_POST['reg_department'] ?? '') === $d['dept_alias']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_alias'] . ' — ' . $d['dept_name']); ?></option><?php endforeach; ?>
                            </select></div>
                    </div>
                    <div class="field" id="courseCol"><label>Course / Program <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><select name="reg_course" id="cSel" class="bare" required>
                                <option value="">-- Select Dept First --</option>
                            </select></div>
                    </div>
                </div>

                <div class="dvdr"><span>Set Password</span></div>

                <div class="g2">
                    <div class="field"><label>Password <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><input type="password" name="reg_password" id="rPw" placeholder="Min. 6 chars" required><button type="button" class="pw-toggle" onclick="tPw('rPw',this)"><i class="fas fa-eye"></i></button></div>
                    </div>
                    <div class="field"><label>Confirm Password <span style="color:#ef4444">*</span></label>
                        <div class="inp-wrap"><input type="password" name="reg_confirm_password" id="rPw2" placeholder="Repeat" required><button type="button" class="pw-toggle" onclick="tPw('rPw2',this)"><i class="fas fa-eye"></i></button></div>
                    </div>
                </div>

                <button type="submit" class="sub-btn" id="regBtn" disabled><i class="fas fa-user-plus"></i> Create Account</button>
            </form>
            <div class="note" id="regNote"><i class="fas fa-info-circle"></i> <span id="regNoteText">Account requires admin approval before login.</span></div>
            <div class="bot-lnk">Have an account? <a onclick="goLogin()">Sign in here</a></div>
        </div>

        <!-- ══ THE BLOB ══════════════════════════════════════ -->
        <div class="blob" id="blob">
            <div class="blob-inner">

                <!-- Left blob-half: shown when show-reg (Welcome Back) -->
                <div class="blob-half bh-left">
                    <div class="blob-school-tag"><?php echo $c['short']; ?></div>
                    <div class="blob-logo-wrap">
                        <?php if ($school === 'mcnp-isap'): ?>
                            <div style="display:flex;gap:12px;align-items:center;justify-content:center;">
                                <img src="MCNP_Logo.png" class="blob-logo" alt="MCNP" style="width:55px;height:55px;object-fit:contain;" onerror="this.src='Logo.png'">
                                <img src="ISAP_Logo.png" class="blob-logo" alt="ISAP" style="width:55px;height:55px;object-fit:contain;" onerror="this.src='Logo.png'">
                            </div>
                        <?php else: ?>
                            <img src="<?php echo $c['logo']; ?>" class="blob-logo" alt="Logo" onerror="this.src='Logo.png'">
                        <?php endif; ?>
                    </div>
                    <div class="blob-title">Welcome Back!</div>
                    <div class="blob-sub">Already have an account?<br>Log in with your personal info</div>
                    <button class="blob-switch-btn" onclick="goLogin()"><i class="fas fa-sign-in-alt"></i> Sign In</button>
                </div>

                <!-- Right blob-half: shown by default (Hello Friend) -->
                <div class="blob-half bh-right">
                    <div class="blob-school-tag"><?php echo $c['short']; ?></div>
                    <div class="blob-logo-wrap">
                        <?php if ($school === 'mcnp-isap'): ?>
                            <div style="display:flex;gap:12px;align-items:center;justify-content:center;">
                                <img src="MCNP_Logo.png" class="blob-logo" alt="MCNP" style="width:55px;height:55px;object-fit:contain;" onerror="this.src='Logo.png'">
                                <img src="ISAP_Logo.png" class="blob-logo" alt="ISAP" style="width:55px;height:55px;object-fit:contain;" onerror="this.src='Logo.png'">
                            </div>
                        <?php else: ?>
                            <img src="<?php echo $c['logo']; ?>" class="blob-logo" alt="Logo" onerror="this.src='Logo.png'">
                        <?php endif; ?>
                    </div>
                    <div class="blob-title">Hello, Friend!</div>
                    <div class="blob-sub">Enter your personal details<br>and start your journey with us</div>
                    <ul class="blob-feats">
                        <?php foreach ($c['feats'] as $f): ?><li><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($f); ?></li><?php endforeach; ?>
                    </ul>
                    <button class="blob-switch-btn" onclick="goReg()"><i class="fas fa-user-plus"></i> Sign Up</button>
                </div>
            </div>
        </div>

    </div><!-- /card -->

    <script>
        // ── Courses ─────────────────────────────────────────────────────────
        const COURSES = <?php echo json_encode($courses_map); ?>;
        const PRE_D = <?php echo json_encode($_POST['reg_department'] ?? ''); ?>;
        const PRE_C = <?php echo json_encode($_POST['reg_course'] ?? ''); ?>;

        function filterC(alias) {
            const sel = document.getElementById('cSel');
            if (!sel) return;
            sel.innerHTML = '<option value="">-- Select Course --</option>';
            if (!alias || !COURSES[alias]) return;
            COURSES[alias].forEach(c => {
                const o = document.createElement('option');
                o.value = c.course_alias;
                o.textContent = c.course_alias + ' — ' + c.course_name;
                if (c.course_alias === PRE_C) o.selected = true;
                sel.appendChild(o);
            });
        }
        if (PRE_D) {
            document.getElementById('dSel').value = PRE_D;
            filterC(PRE_D);
        }

        // ── Role Dynamic Fields ──────────────────────────────────────────────
        function updateRoleFields() {
            const rRoleElem = document.getElementById('rRole');
            if (!rRoleElem) return;
            const role = rRoleElem.value;
            const row = document.getElementById('deptCourseRow');
            const deptCol = document.getElementById('deptCol');
            const courseCol = document.getElementById('courseCol');
            const dSel = document.getElementById('dSel');
            const cSel = document.getElementById('cSel');

            if (role === 'student') {
                if (row) row.style.display = '';
                if (deptCol) {
                    deptCol.style.display = 'block';
                    deptCol.style.gridColumn = 'auto';
                }
                if (courseCol) courseCol.style.display = 'block';
                if (dSel) dSel.required = true;
                if (cSel) cSel.required = true;
            } else if (role === 'faculty' || role === 'dean') {
                if (row) row.style.display = '';
                if (deptCol) {
                    deptCol.style.display = 'block';
                    deptCol.style.gridColumn = '1 / -1';
                }
                if (courseCol) courseCol.style.display = 'none';
                if (dSel) dSel.required = true;
                if (cSel) {
                    cSel.required = false;
                    cSel.value = '';
                }
            } else if (role === 'visitor' || role === 'admin' || role === 'system admin') {
                if (row) row.style.display = 'none';
                if (dSel) {
                    dSel.required = false;
                    dSel.value = '';
                }
                if (cSel) {
                    cSel.required = false;
                    cSel.value = '';
                }
            }

            const visitorCatRow = document.getElementById('visitorCatRow');
            const visitorCatSel = document.getElementById('visitorCatSel');
            if (role === 'visitor') {
                if (visitorCatRow) visitorCatRow.style.display = 'block';
                if (visitorCatSel) visitorCatSel.required = true;
            } else {
                if (visitorCatRow) visitorCatRow.style.display = 'none';
                if (visitorCatSel) visitorCatSel.required = false;
            }

            const regNoteText = document.getElementById('regNoteText');
            if (regNoteText) {
                if (role === 'visitor') {
                    regNoteText.textContent = 'Visitor accounts are activated immediately for campus facility evaluation.';
                } else {
                    regNoteText.textContent = 'Account requires admin approval before login.';
                }
            }

            const emailSection = document.getElementById('emailOTPSection');
            const emailInput = document.getElementById('reg_email');
            if (role === 'visitor') {
                if (emailSection) emailSection.style.display = 'none';
                if (emailInput) emailInput.required = false;
                otpOk = true;
                updBtn();
            } else {
                if (emailSection) {
                    const wasHidden = (emailSection.style.display === 'none');
                    emailSection.style.display = 'block';
                    if (wasHidden) {
                        otpOk = false;
                        updBtn();
                    }
                }
                if (emailInput) emailInput.required = true;
            }

            // Dynamic ID Label and Placeholder
            const idLabel = document.getElementById('idLabel');
            const idInput = document.getElementById('regUsernameInput');
            if (idLabel && idInput) {
                if (role === 'student') {
                    idLabel.innerHTML = 'School ID Number <span style="color:#ef4444">*</span>';
                    idInput.placeholder = 'e.g. 2023-10408';
                } else if (role === 'faculty' || role === 'dean') {
                    idLabel.innerHTML = 'Employee ID <span style="color:#ef4444">*</span>';
                    idInput.placeholder = 'e.g. EMP-2021-123';
                } else if (role === 'visitor') {
                    idLabel.innerHTML = 'Desired Username <span style="color:#ef4444">*</span>';
                    idInput.placeholder = 'e.g. juan_visitor';
                } else if (role === 'admin' || role === 'system admin') {
                    idLabel.innerHTML = 'Admin Username / Employee ID <span style="color:#ef4444">*</span>';
                    idInput.placeholder = 'e.g. admin_jdelacruz';
                } else {
                    idLabel.innerHTML = 'ID Number <span style="color:#ef4444">*</span>';
                    idInput.placeholder = 'e.g. 2023-10408';
                }
            }
        }
        document.addEventListener('DOMContentLoaded', updateRoleFields);

        // ── Sliding toggle ───────────────────────────────────────────────────
        const card = document.getElementById('card');

        function updateMobileTabs(isReg) {
            const tabLogin = document.getElementById('mTabLogin');
            const tabReg = document.getElementById('mTabReg');
            if (tabLogin && tabReg) {
                if (isReg) {
                    tabLogin.classList.remove('active');
                    tabReg.classList.add('active');
                } else {
                    tabLogin.classList.add('active');
                    tabReg.classList.remove('active');
                }
            }
        }

        function goReg() {
            card.classList.add('sr');
            updateMobileTabs(true);
            if (window.location.hash !== '#register' && history.replaceState) {
                history.replaceState(null, '', '#register');
            }
        }

        function goLogin() {
            card.classList.remove('sr');
            updateMobileTabs(false);
            if (window.location.hash === '#register' && history.replaceState) {
                history.replaceState(null, '', '#login');
            }
        }

        function syncPanelWithUrl() {
            const isReg = (window.location.hash === '#register' || new URLSearchParams(window.location.search).get('panel') === 'register');
            if (isReg) {
                card.classList.add('sr');
                updateMobileTabs(true);
            } else {
                card.classList.remove('sr');
                updateMobileTabs(false);
            }
        }
        window.addEventListener('hashchange', syncPanelWithUrl);
        document.addEventListener('DOMContentLoaded', syncPanelWithUrl);

        // ── Theme ────────────────────────────────────────────────────────────
        function toggleTheme() {
            const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('theme', t);
            applyIcon(t);
        }

        function applyIcon(t) {
            const i = document.querySelector('#thBtn i');
            if (i) i.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const saved = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', saved);
        applyIcon(saved);

        // ── Password toggle ──────────────────────────────────────────────────
        function tPw(id, btn) {
            const el = document.getElementById(id);
            el.type = el.type === 'password' ? 'text' : 'password';
            btn.querySelector('i').className = el.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        }

        // ── OTP ──────────────────────────────────────────────────────────────
        let otpOk = false,
            otpTmr = null;

        function updBtn() {
            document.getElementById('regBtn').disabled = !otpOk;
        }

        async function sendOTP() {
            const em = document.getElementById('reg_email').value.trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
                alert('Please enter a valid email first.');
                return;
            }
            const role = document.getElementById('rRole').value;
            if (!role) {
                alert('Please select your Role first (under Personal Details) before sending the OTP.');
                return;
            }

            const btn = document.getElementById('sotpBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
            try {
                const fd = new FormData();
                fd.append('email', em);
                fd.append('purpose', 'register');
                const sch = document.querySelector('input[name="school"]').value;
                fd.append('school', sch);
                fd.append('role', role);
                const r = await fetch('send_otp.php', {
                    method: 'POST',
                    body: fd
                });
                const text = await r.text();
                let d;
                try {
                    d = JSON.parse(text);
                } catch (jsonErr) {
                    console.error('Server returned non-JSON:', text);
                    throw new Error('Server returned an unexpected response. Please try again.');
                }
                if (d.success) {
                    document.getElementById('vRow').classList.add('on');
                    document.getElementById('otpOkMsg').classList.remove('on');
                    otpOk = false;
                    updBtn();
                    startTimer(120);
                    btn.innerHTML = '<i class="fas fa-redo"></i> Resend';
                    if (d.otp) {
                        const otpInput = document.getElementById('otp_inp');
                        if (otpInput) otpInput.value = d.otp;
                    }
                    alert(d.message || 'OTP sent successfully! Please check your email.');
                } else {
                    alert(d.message || 'Failed to send OTP.');
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP';
                }
            } catch (err) {
                console.error('sendOTP error:', err);
                alert(err.message || 'Network error.');
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP';
            }
            btn.disabled = false;
        }

        async function verifyOTP() {
            const em = document.getElementById('reg_email').value.trim();
            const ot = document.getElementById('otp_inp').value.trim();
            if (ot.length < 4) {
                alert('Please enter the OTP from your email.');
                return;
            }
            try {
                const fd = new FormData();
                fd.append('email', em);
                fd.append('otp', ot);
                fd.append('purpose', 'register');
                const r = await fetch('verify_otp.php', {
                    method: 'POST',
                    body: fd
                });
                const text = await r.text();
                let d;
                try {
                    d = JSON.parse(text);
                } catch (jsonErr) {
                    console.error('Server returned non-JSON:', text);
                    throw new Error('Server returned an unexpected response. Please try again.');
                }
                if (d.success) {
                    otpOk = true;
                    updBtn();
                    document.getElementById('vRow').classList.remove('on');
                    document.getElementById('otpOkMsg').classList.add('on');
                    clearInterval(otpTmr);
                    document.getElementById('otpTimer').textContent = '';
                    alert('Email verified successfully!');
                } else alert(d.message || 'Incorrect OTP.');
            } catch (err) {
                console.error('verifyOTP error:', err);
                alert(err.message || 'Network error.');
            }
        }

        function startTimer(s) {
            clearInterval(otpTmr);
            const el = document.getElementById('otpTimer');
            let r = s;
            el.style.color = '';
            el.textContent = `OTP expires in ${r}s`;
            otpTmr = setInterval(() => {
                r--;
                if (r <= 0) {
                    clearInterval(otpTmr);
                    el.textContent = 'OTP expired. Request a new one.';
                    el.style.color = '#ef4444';
                } else el.textContent = `OTP expires in ${r}s`;
            }, 1000);
        }
    </script>
</body>

</html>