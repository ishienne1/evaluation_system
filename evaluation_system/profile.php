<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// Redirect if not logged in
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['id'];
$is_own_profile = ($user_id === (int)$_SESSION['id']);
$error = '';
$success = '';

// Handle Avatar Removal
if (isset($_POST['remove_avatar']) && $is_own_profile) {
    $old_avatar_stmt = $conn->prepare("SELECT avatar FROM users WHERE id = ?");
    $old_avatar_stmt->bind_param("i", $user_id);
    $old_avatar_stmt->execute();
    $old_avatar_path = $old_avatar_stmt->get_result()->fetch_assoc()['avatar'];

    if ($old_avatar_path && file_exists($old_avatar_path)) {
        unlink($old_avatar_path);
    }

    $update_stmt = $conn->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
    $update_stmt->bind_param("i", $user_id);
    if ($update_stmt->execute()) {
        $_SESSION['avatar'] = null;
        $success = "Profile picture removed successfully!";
    } else { 
        $error = "Failed to remove profile picture."; 
    }
}

// Handle Avatar Upload
if (isset($_POST['update_avatar']) && isset($_FILES['avatar']) && $is_own_profile) {
    if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/avatars/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_info = pathinfo($_FILES['avatar']['name']);
        $file_ext = strtolower($file_info['extension']);
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($file_ext, $allowed_ext)) {
            if ($_FILES['avatar']['size'] <= 4000000) { // 4MB limit
                $old_avatar_stmt = $conn->prepare("SELECT avatar FROM users WHERE id = ?");
                $old_avatar_stmt->bind_param("i", $user_id);
                $old_avatar_stmt->execute();
                $old_avatar_path = $old_avatar_stmt->get_result()->fetch_assoc()['avatar'];
                if ($old_avatar_path && file_exists($old_avatar_path)) {
                    unlink($old_avatar_path);
                }

                $new_filename = $upload_dir . uniqid('avatar_', true) . '.' . $file_ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $new_filename)) {
                    $update_stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $new_filename, $user_id);
                    if ($update_stmt->execute()) {
                        $_SESSION['avatar'] = $new_filename;
                        $success = "Profile picture updated successfully!";
                    } else { 
                        $error = "Database update failed."; 
                    }
                } else { 
                    $error = "Failed to move uploaded file."; 
                }
            } else { 
                $error = "File is too large. Maximum size is 4MB."; 
            }
        } else { 
            $error = "Invalid file type. Only JPG, JPEG, PNG, GIF, and WEBP are allowed."; 
        }
    } else { 
        $error = "An error occurred during file upload."; 
    }
}

// Handle Profile Update
if (isset($_POST['update_profile']) && $is_own_profile) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);

    $check_stmt = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
    $check_stmt->bind_param("ssi", $email, $username, $user_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $error = "Email or Username is already taken by another account.";
    } else {
        $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, username = ? WHERE id = ?");
        $update_stmt->bind_param("sssi", $full_name, $email, $username, $user_id);
        if ($update_stmt->execute()) {
            $_SESSION['full_name'] = $full_name;
            $_SESSION['username'] = $username;
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile. Please try again.";
        }
    }
}

// Fetch user data for display
$stmt = $conn->prepare(
    "SELECT u.*, d.dept_name, c.course_name 
     FROM users u
     LEFT JOIN departments d ON u.dept_id = d.id 
     LEFT JOIN courses c ON u.course_id = c.id 
     WHERE u.id = ?"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

if (!$user_data) {
    $user_id = $_SESSION['id'];
    $is_own_profile = true;
    $stmt = $conn->prepare(
        "SELECT u.*, d.dept_name, c.course_name 
         FROM users u
         LEFT JOIN departments d ON u.dept_id = d.id 
         LEFT JOIN courses c ON u.course_id = c.id 
         WHERE u.id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
}

$role = $_SESSION['role'];
$avatar_path = $user_data['avatar'] ?? 'uploads/avatars/default.png';
if (!file_exists($avatar_path)) {
    $avatar_path = 'uploads/avatars/default.png';
}

// Role-specific badge icons
$role_icons = [
    'admin' => 'fas fa-shield-alt',
    'system admin' => 'fas fa-terminal',
    'dean' => 'fas fa-user-graduate',
    'faculty' => 'fas fa-chalkboard-teacher',
    'student' => 'fas fa-user-graduate',
    'visitor' => 'fas fa-id-badge'
];
$current_role_icon = $role_icons[$user_data['role']] ?? 'fas fa-user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_own_profile ? 'My Profile' : 'User Profile — ' . htmlspecialchars($user_data['full_name']); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ====================================================
           MODERN PROFILE ENHANCEMENTS (Clean & Institutional)
           ==================================================== */
        .profile-hero-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(139, 92, 246, 0.05) 50%, var(--card-bg) 100%);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 26px 30px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
        }

        .profile-hero-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent);
        }

        .avatar-wrapper {
            position: relative;
            width: 116px;
            height: 116px;
            flex-shrink: 0;
            border-radius: 50%;
            border: 4px solid var(--card-bg);
            box-shadow: 0 0 0 2px var(--border-color), 0 8px 24px rgba(0, 0, 0, 0.12);
            cursor: pointer;
            overflow: visible;
        }

        .avatar-img-circle {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .avatar-overlay-btn {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.2s ease;
            font-size: 11px;
            font-weight: 600;
        }

        .avatar-wrapper:hover .avatar-overlay-btn {
            opacity: 1;
        }

        /* Deep-link highlight when arriving via the top-bar avatar (#change-photo) */
        .avatar-wrapper.avatar-attention {
            animation: avatarAttentionPulse 1.1s ease-out 3;
        }
        .avatar-wrapper.avatar-attention .avatar-overlay-btn {
            opacity: 1;
        }
        @keyframes avatarAttentionPulse {
            0%   { box-shadow: 0 0 0 2px var(--border-color), 0 0 0 0 rgba(37, 99, 235, 0.55); }
            70%  { box-shadow: 0 0 0 2px var(--accent), 0 0 0 16px rgba(37, 99, 235, 0); }
            100% { box-shadow: 0 0 0 2px var(--border-color), 0 0 0 0 rgba(37, 99, 235, 0); }
        }
        .swal-change-photo-img {
            border-radius: 50% !important;
            object-fit: cover;
            border: 3px solid var(--border-color);
        }

        /* Touch devices have no hover: keep a small camera hint on the avatar */
        @media (hover: none) {
            .avatar-wrapper .avatar-overlay-btn {
                inset: auto 0 0 auto;
                width: 34px;
                height: 34px;
                opacity: 1;
                background: var(--accent);
                border: 3px solid var(--card-bg);
                font-size: 13px;
            }
            .avatar-wrapper .avatar-overlay-btn span { display: none; }
            .avatar-wrapper .avatar-online-dot { display: none; }
        }

        .avatar-online-dot {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #10b981;
            border: 2.5px solid var(--card-bg);
            box-shadow: 0 0 0 1px rgba(0,0,0,0.1);
        }

        .profile-meta-chips {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            line-height: 1;
        }

        .meta-chip-role {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.25);
        }

        .meta-chip-status {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .meta-chip-info {
            background: var(--input-bg);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            font-weight: 500;
        }

        /* Profile Mini Stats Grid */
        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .profile-stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .profile-stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .stat-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .stat-blue { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
        .stat-emerald { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .stat-purple { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }

        .stat-details span {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            display: block;
            margin-bottom: 3px;
        }

        .stat-details strong {
            font-size: 15px;
            color: var(--text-main);
            display: block;
            word-break: break-all;
        }

        /* Grouped Form Layout */
        .profile-form-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 22px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .profile-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        }

        .form-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section-title {
            margin: 0;
            font-size: 17px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .input-group-styled {
            margin-bottom: 18px;
        }

        .input-label-styled {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 7px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .input-field-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-field-wrapper i.prefix-icon {
            position: absolute;
            left: 14px;
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
        }

        .input-field-wrapper input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 9px;
            font-size: 14.5px;
            color: var(--text-main);
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .input-field-wrapper input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .input-field-wrapper input:disabled {
            background: rgba(0, 0, 0, 0.03);
            color: var(--text-muted);
            cursor: not-allowed;
            border-style: dashed;
        }

        [data-theme="dark"] .input-field-wrapper input:disabled {
            background: rgba(255, 255, 255, 0.03);
        }

        .record-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 14px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 9px;
            margin-bottom: 12px;
        }

        .record-label {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .record-value {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-main);
            text-align: right;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="split-container">

    <?php
    // Include the correct sidebar based on user role
    $mobile_page_title = $is_own_profile ? 'My Profile' : 'User Profile';
    if ($role == 'admin') {
        $active_page = 'profile';
        include 'admin_sidebar.php';
    } elseif ($role == 'dean') {
        $active_page_dean = 'profile';
        include 'dean_sidebar.php';
    } elseif ($role == 'faculty') {
        $active_page_faculty = 'profile';
        include 'faculty_sidebar.php';
    } elseif ($role == 'student') {
        $active_page_student = 'profile';
        include 'student_sidebar.php';
    } elseif ($role == 'system admin') {
        $active_page = 'profile';
        include 'ict_sidebar.php';
    }
    ?>

    <main class="main-content">
        <?php if ($role === 'visitor'): ?>
        <div style="margin-bottom: 20px;">
            <a href="dashboard_visitor.php" class="nav-link-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-weight:700; background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:10px; padding:10px 18px;">
                <i class="fas fa-arrow-left"></i> Back to Visitor Portal
            </a>
        </div>
        <?php endif; ?>

        <?php if ($error): ?><div class="flash-error" style="margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="flash-success" style="margin-bottom:20px;"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>

        <!-- Hidden Form for Avatar Auto-Upload -->
        <?php if ($is_own_profile): ?>
        <form id="avatar-form" method="POST" enctype="multipart/form-data" style="display: none;">
            <input type="file" name="avatar" id="avatar-input" accept="image/*">
            <input type="hidden" name="update_avatar" value="1">
        </form>
        <?php endif; ?>

        <!-- 1. MODERN PROFILE HERO HEADER CARD -->
        <div class="profile-hero-card" id="change-photo">
            <!-- Left Side: Circular Avatar & Identity side-by-side -->
            <div style="display:flex; align-items:center; gap:22px; flex-wrap:wrap;">
                
                <!-- Avatar with Online Dot & Hover Overlay -->
                <div class="avatar-wrapper" <?php if ($is_own_profile): ?>onclick="document.getElementById('avatar-input').click();" title="Click to upload new photo"<?php endif; ?>>
                    <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Avatar" class="avatar-img-circle">
                    <?php if ($is_own_profile): ?>
                    <div class="avatar-overlay-btn">
                        <i class="fas fa-camera"></i>
                        <span>Change</span>
                    </div>
                    <?php endif; ?>
                    <span class="avatar-online-dot" title="Active Account"></span>
                </div>

                <!-- User Name, Role, and Key Meta Chips -->
                <div>
                    <h2 style="color:var(--text-main); margin:0 0 6px 0; font-size:26px; font-weight:700; letter-spacing:-0.4px;">
                        <?php echo htmlspecialchars($user_data['full_name']); ?>
                    </h2>
                    
                    <div class="profile-meta-chips">
                        <!-- Role Chip -->
                        <span class="meta-chip meta-chip-role">
                            <i class="<?php echo $current_role_icon; ?>"></i> <?php echo ucfirst($user_data['role']); ?>
                        </span>

                        <!-- Account Status Chip -->
                        <span class="meta-chip meta-chip-status">
                            <i class="fas fa-check-circle"></i> <?php echo ucfirst($user_data['status'] ?? 'Active'); ?>
                        </span>

                        <!-- Department / Course Chip -->
                        <?php if (!empty($user_data['dept_name'])): ?>
                            <span class="meta-chip meta-chip-info">
                                <i class="fas fa-university"></i> <?php echo htmlspecialchars($user_data['dept_name']); ?>
                            </span>
                        <?php elseif (!empty($user_data['course_name'])): ?>
                            <span class="meta-chip meta-chip-info">
                                <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($user_data['course_name']); ?>
                            </span>
                        <?php endif; ?>

                        <!-- Member Since Chip -->
                        <span class="meta-chip meta-chip-info">
                            <i class="far fa-calendar-alt"></i> Joined <?php echo !empty($user_data['created_at']) ? date('M Y', strtotime($user_data['created_at'])) : 'Verified'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Right Side: Essential Actions -->
            <?php if ($is_own_profile): ?>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <!-- Quick Change Photo Button -->
                <button type="button" onclick="document.getElementById('avatar-input').click();" class="nav-link-btn" style="padding:9px 16px; border-radius:9px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main); font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px;">
                    <i class="fas fa-camera" style="color:var(--accent);"></i> Change Photo
                </button>

                <!-- Remove Picture (If exists) -->
                <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="remove_avatar" class="nav-link-btn" style="padding:9px 15px; border-radius:9px; border:1px solid rgba(239,68,68,0.2); background:rgba(239,68,68,0.06); color:#ef4444; font-size:13px; font-weight:600; cursor:pointer;" onclick="return confirm('Are you sure you want to remove your profile picture?');">
                        <i class="fas fa-trash-alt"></i> Remove
                    </button>
                </form>
                <?php endif; ?>

                <?php if (!empty($_SESSION['original_admin_id'])): ?>
                    <a href="logout.php" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:9px; background:rgba(139,92,246,0.12); color:#8b5cf6; border:1px solid rgba(139,92,246,0.25); font-weight:700; font-size:13px; text-decoration:none;">
                        <i class="fas fa-user-secret"></i> Return to Admin
                    </a>
                <?php endif; ?>

                <a href="logout.php" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:9px; background:rgba(239,68,68,0.08); color:#ef4444; border:1px solid rgba(239,68,68,0.25); font-weight:700; font-size:13px; text-decoration:none;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- 2. MINI PROFILE METADATA CARDS -->
        <div class="profile-stats-grid">
            <!-- Account ID Box -->
            <div class="profile-stat-box">
                <div class="stat-icon-wrap stat-blue">
                    <i class="fas fa-id-badge"></i>
                </div>
                <div class="stat-details">
                    <span>Account ID</span>
                    <strong>#USR-<?php echo str_pad($user_data['id'], 4, '0', STR_PAD_LEFT); ?></strong>
                </div>
            </div>

            <!-- Email Box -->
            <div class="profile-stat-box">
                <div class="stat-icon-wrap stat-emerald">
                    <i class="fas fa-envelope-circle-check"></i>
                </div>
                <div class="stat-details">
                    <span>Official Email</span>
                    <strong><?php echo htmlspecialchars($user_data['email']); ?></strong>
                </div>
            </div>

            <!-- Session / Last Activity Box -->
            <div class="profile-stat-box">
                <div class="stat-icon-wrap stat-purple">
                    <i class="fas fa-history"></i>
                </div>
                <div class="stat-details">
                    <span>Last Login Session</span>
                    <strong><?php echo !empty($user_data['last_login']) ? date('M d, Y h:i A', strtotime($user_data['last_login'])) : 'Active Now'; ?></strong>
                </div>
            </div>
        </div>

        <!-- 3. PROFILE DETAILS & INSTITUTIONAL RECORDS -->
        <div class="profile-form-grid">
            
            <!-- Left Card: Editable Personal Details -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <h3 class="form-section-title">
                        <i class="fas fa-user-edit" style="color:var(--accent);"></i> Personal Information
                    </h3>
                    <span style="font-size:12px; color:var(--text-muted); background:var(--input-bg); padding:4px 10px; border-radius:20px; border:1px solid var(--border-color);">
                        Editable
                    </span>
                </div>

                <form method="POST">
                    <div class="input-group-styled">
                        <div class="input-label-styled">Full Name</div>
                        <div class="input-field-wrapper">
                            <i class="fas fa-user prefix-icon"></i>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" placeholder="Enter full name" required <?php echo !$is_own_profile ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <div class="input-group-styled">
                        <div class="input-label-styled">Email Address</div>
                        <div class="input-field-wrapper">
                            <i class="fas fa-envelope prefix-icon"></i>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" placeholder="Enter email address" required <?php echo !$is_own_profile ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <div class="input-group-styled">
                        <div class="input-label-styled">Username / ID Number</div>
                        <div class="input-field-wrapper">
                            <i class="fas fa-id-card prefix-icon"></i>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" placeholder="Enter username / ID" required <?php echo !$is_own_profile ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <?php if ($is_own_profile): ?>
                    <button type="submit" name="update_profile" class="login-btn" style="width:100%; margin-top:10px; padding:12px; font-size:14.5px; display:inline-flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Right Card: Institutional Records (Read-Only) -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <h3 class="form-section-title">
                        <i class="fas fa-university" style="color:var(--accent);"></i> Institutional Records
                    </h3>
                    <span style="font-size:12px; color:var(--text-muted); background:var(--input-bg); padding:4px 10px; border-radius:20px; border:1px solid var(--border-color);">
                        <i class="fas fa-lock" style="font-size:10px;"></i> Verified Record
                    </span>
                </div>

                <div class="record-row">
                    <div class="record-label">
                        <i class="fas fa-user-shield" style="color:var(--accent);"></i> System Role
                    </div>
                    <div class="record-value">
                        <?php echo ucfirst($user_data['role']); ?>
                    </div>
                </div>

                <div class="record-row">
                    <div class="record-label">
                        <i class="fas fa-university" style="color:var(--accent);"></i> Department
                    </div>
                    <div class="record-value">
                        <?php echo !empty($user_data['dept_name']) ? htmlspecialchars($user_data['dept_name']) : 'Not Assigned'; ?>
                    </div>
                </div>

                <div class="record-row">
                    <div class="record-label">
                        <i class="fas fa-graduation-cap" style="color:var(--accent);"></i> Program / Course
                    </div>
                    <div class="record-value">
                        <?php echo !empty($user_data['course_name']) ? htmlspecialchars($user_data['course_name']) : 'N/A'; ?>
                    </div>
                </div>

                <div class="record-row">
                    <div class="record-label">
                        <i class="far fa-calendar-check" style="color:var(--accent);"></i> Date Registered
                    </div>
                    <div class="record-value">
                        <?php echo !empty($user_data['created_at']) ? date('M d, Y', strtotime($user_data['created_at'])) : 'Standard User'; ?>
                    </div>
                </div>

                <div class="record-row">
                    <div class="record-label">
                        <i class="fas fa-shield-alt" style="color:var(--accent);"></i> Authentication
                    </div>
                    <div class="record-value" style="color:#10b981;">
                        <i class="fas fa-shield-check"></i> Standard Security
                    </div>
                </div>

                <div style="margin-top:16px; padding:12px 14px; background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.18); border-radius:9px; font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-info-circle" style="color:var(--accent); font-size:16px;"></i>
                    <span>Official academic records (Department and Course) are maintained and managed by the institution.</span>
                </div>
            </div>

        </div>

        <!-- 4. RECENT ACTIVITY CARD -->
        <div class="card">
            <h3 style="margin-bottom:20px; color:var(--text-main);"><i class="fas fa-history" style="color:var(--accent);"></i> Recent Activity</h3>
            <?php if ($user_data['role'] == 'student'): ?>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead><tr><th>Date</th><th>Target Evaluated</th><th>Rating Given</th></tr></thead>
                    <tbody>
                        <?php
                        $eval_query = $conn->prepare("SELECT e.created_at, e.rating, e.target_name, f.full_name AS teacher_name FROM evaluations e LEFT JOIN users f ON e.faculty_id = f.id WHERE e.student_id = ? ORDER BY e.created_at DESC LIMIT 5");
                        $eval_query->bind_param("i", $user_id);
                        $eval_query->execute();
                        $eval_result = $eval_query->get_result();
                        if ($eval_result->num_rows > 0) {
                            while ($row = $eval_result->fetch_assoc()) {
                                $target = $row['teacher_name'] ? htmlspecialchars($row['teacher_name']) : htmlspecialchars($row['target_name']);
                                echo "<tr><td>".date('M d, Y', strtotime($row['created_at']))."</td><td><strong>{$target}</strong></td><td>".number_format($row['rating'], 2)." ⭐</td></tr>";
                            }
                        } else { echo "<tr><td colspan='3' style='text-align:center; color:var(--text-muted);'>No recent evaluations found.</td></tr>"; }
                        ?>
                    </tbody>
                </table>
                </div>
            <?php elseif ($user_data['role'] == 'faculty'): ?>
                 <ul style="list-style: none; padding: 0;">
                    <?php
                    $comments_stmt = $conn->prepare("SELECT feedback, created_at FROM evaluations WHERE faculty_id = ? AND feedback IS NOT NULL AND feedback != '' ORDER BY created_at DESC LIMIT 5");
                    $comments_stmt->bind_param("i", $user_id);
                    $comments_stmt->execute();
                    $comments_result = $comments_stmt->get_result();
                    if ($comments_result->num_rows > 0) {
                        while($c = $comments_result->fetch_assoc()) {
                            echo "<li style='padding:12px; border-bottom:1px solid var(--border-color); font-size:14px; color: var(--text-main);'>
                                    <span style='color:var(--text-muted); font-size:12px; display:block; margin-bottom:4px;'>".date('M d, Y h:i A', strtotime($c['created_at']))."</span>
                                    \"" . htmlspecialchars($c['feedback']) . "\"
                                  </li>";
                        }
                    } else { echo "<li style='text-align:center; padding:20px; color:var(--text-muted);'>No recent comments found.</li>"; }
                    ?>
                </ul>
            <?php else: ?>
                <p style="text-align:center; color:var(--text-muted); padding:20px;">No recent public evaluations to display for this role.</p>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // One-click Avatar Auto-upload handler
    const avatarInput = document.getElementById('avatar-input');
    if (avatarInput) {
        avatarInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                document.getElementById('avatar-form').submit();
            }
        });
    }

    // Deep link from the mobile top-bar avatar: profile.php#change-photo
    (function () {
        if (window.location.hash !== '#change-photo') return;

        // Clear the hash so a refresh or the upload POST never re-triggers this prompt
        try {
            if (window.history && history.replaceState) {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        } catch (e) {}

        const input = document.getElementById('avatar-input');
        const wrap = document.querySelector('.avatar-wrapper');
        if (!input || !wrap) return; // not your own profile — nothing to change

        function start() {
            try { wrap.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { wrap.scrollIntoView(); }
            wrap.classList.add('avatar-attention');
            setTimeout(function () { wrap.classList.remove('avatar-attention'); }, 3500);

            if (typeof window.Swal === 'undefined') return; // highlight-only fallback

            const img = wrap.querySelector('img');
            Swal.fire({
                title: 'Change Profile Photo',
                html: '<span style="font-size:13.5px;">Choose a new photo from your device.<br><small style="opacity:.75;">JPG, PNG, GIF or WEBP · max 4MB</small></span>',
                imageUrl: img ? img.src : undefined,
                imageWidth: 96,
                imageHeight: 96,
                imageAlt: 'Current photo',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-camera"></i> Choose Photo',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#2563eb',
                reverseButtons: true,
                focusConfirm: true,
                customClass: { image: 'swal-change-photo-img' },
                didOpen: function () {
                    // Native listener keeps the file picker inside the user's tap gesture
                    const btn = Swal.getConfirmButton();
                    if (btn) btn.addEventListener('click', function () { input.click(); }, { once: true });
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    })();

    // Theme Switcher & Initializer
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
    
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    });
</script>
</body>
</html>