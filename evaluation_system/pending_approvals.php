<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

$msg = '';

// Handle Approve
if (isset($_GET['approve'])) {
    $id = (int)$_GET['approve'];
    
    // Get user details first for logging
    $get_user = $conn->prepare("SELECT full_name, role FROM users WHERE id = ? AND status = 'pending'");
    $get_user->bind_param("i", $id);
    $get_user->execute();
    $u_data = $get_user->get_result()->fetch_assoc();
    
    if ($u_data) {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Log the approval event
            $log_message = "User registration approved: " . $u_data['full_name'];
            $log_context = "Approved User ID: {$id}, Role: {$u_data['role']}";
            $log_stmt = $conn->prepare("INSERT INTO system_logs (level, message, context, user_id) VALUES ('info', ?, ?, ?)");
            $admin_id = $_SESSION['id'];
            $log_stmt->bind_param("ssi", $log_message, $log_context, $admin_id);
            $log_stmt->execute();
            
            header("Location: pending_approvals.php?msg=approved");
            exit();
        }
    }
}

// Handle Reject (Delete)
if (isset($_GET['reject'])) {
    $id = (int)$_GET['reject'];
    
    // Get user details first for logging
    $get_user = $conn->prepare("SELECT full_name, role FROM users WHERE id = ? AND status = 'pending'");
    $get_user->bind_param("i", $id);
    $get_user->execute();
    $u_data = $get_user->get_result()->fetch_assoc();
    
    if ($u_data) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Log the rejection event
            $log_message = "User registration rejected and deleted: " . $u_data['full_name'];
            $log_context = "Rejected User ID: {$id}, Role: {$u_data['role']}";
            $log_stmt = $conn->prepare("INSERT INTO system_logs (level, message, context, user_id) VALUES ('info', ?, ?, ?)");
            $admin_id = $_SESSION['id'];
            $log_stmt->bind_param("ssi", $log_message, $log_context, $admin_id);
            $log_stmt->execute();
            
            header("Location: pending_approvals.php?msg=rejected");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    
    <!-- Sidebar -->
    <?php $active_page = 'pending_approvals'; include 'admin_sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 style="color:var(--text-main); margin:0;">
                <i class="fas fa-clock" style="color:#ef4444;"></i> Pending Approvals
            </h2>
            <span style="color:var(--text-muted); font-size:14px;">
                <i class="fas fa-clock"></i> <?php echo date('l, F j, Y'); ?>
            </span>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'approved'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> User account approved and activated successfully.</div>
            <?php elseif ($_GET['msg'] === 'rejected'): ?>
                <div class="flash-success" style="background:#fef2f2; color:#b91c1c; border:1px solid #fee2e2;"><i class="fas fa-times-circle"></i> User registration rejected and account deleted.</div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Pending Registrations List -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;"><i class="fas fa-list" style="color:#0d2158;"></i> Pending Accounts Awaiting Action</h3>
                <span class="category-badge" style="font-size:13px; background:#fef3c7; color:#d97706;">
                    <?php echo $pending_count; ?> pending
                </span>
            </div>
            <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Username / ID</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Email</th>
                        <th>Department / Course</th>
                        <th>Registered Date</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $pending_users_query = "
                        SELECT u.*, d.dept_alias, c.course_alias 
                        FROM users u 
                        LEFT JOIN departments d ON u.dept_id = d.id 
                        LEFT JOIN courses c ON u.course_id = c.id 
                        WHERE u.status = 'pending' 
                        ORDER BY u.created_at DESC
                    ";
                    $pending_users = $conn->query($pending_users_query);
                    if ($pending_users->num_rows === 0):
                    ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
                            <i class="fas fa-check-circle" style="font-size:40px; color:#10b981; display:block; margin-bottom:12px; opacity:0.7;"></i>
                            No pending approvals. All registrations are processed!
                        </td>
                    </tr>
                    <?php else: while ($row = $pending_users->fetch_assoc()): ?>
                    <tr>
                        <td style="font-family:monospace; font-size:13px; white-space:nowrap;"><?php echo htmlspecialchars($row['username']); ?></td>
                        <td style="white-space:nowrap;"><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                        <td>
                            <?php 
                            $role_color = '#3b82f6';
                            $role_bg = '#eff6ff';
                            if ($row['role'] == 'student') {
                                $role_color = '#10b981';
                                $role_bg = '#ecfdf5';
                            } elseif ($row['role'] == 'faculty') {
                                $role_color = '#8b5cf6';
                                $role_bg = '#f5f3ff';
                            } elseif ($row['role'] == 'dean') {
                                $role_color = '#f59e0b';
                                $role_bg = '#fffbeb';
                            }
                            ?>
                            <span class="category-badge" style="background:<?php echo $role_bg; ?>; color:<?php echo $role_color; ?>;">
                                <?php echo ucfirst($row['role'] == 'faculty' ? 'Teacher' : $row['role']); ?>
                            </span>
                        </td>
                        <td class="cell-email" style="color:var(--text-muted); font-size:13px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($row['email']); ?>"><?php echo htmlspecialchars($row['email']); ?></td>
                        <td>
                            <?php 
                            $dept_part = htmlspecialchars($row['dept_alias'] ?? '');
                            $course_part = htmlspecialchars($row['course_alias'] ?? '');
                            if ($dept_part && $course_part) {
                                echo "<span class='category-badge'>{$dept_part} &middot; {$course_part}</span>";
                            } elseif ($dept_part) {
                                echo "<span class='category-badge'>{$dept_part}</span>";
                            } elseif ($course_part) {
                                echo "<span class='category-badge'>{$course_part}</span>";
                            } else {
                                echo "<span style='color:var(--text-muted); font-size:12px;'>N/A</span>";
                            }
                            ?>
                        </td>
                        <td style="font-size:12px; color:var(--text-muted); white-space:nowrap;"><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="pending_approvals.php?approve=<?php echo $row['id']; ?>" 
                               class="btn-action" 
                               style="background:#10b981; color:white;" 
                               title="Approve & Activate Registration" 
                               onclick="return confirm('Approve registration for <?php echo htmlspecialchars($row['full_name']); ?>?')">
                                <i class="fas fa-check"></i>
                            </a>
                            <a href="pending_approvals.php?reject=<?php echo $row['id']; ?>" 
                               class="btn-action" 
                               style="background:#ef4444; color:white;" 
                               title="Reject & Delete Registration" 
                               onclick="return confirm('Are you sure you want to REJECT and DELETE the registration for <?php echo htmlspecialchars($row['full_name']); ?>?')">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleTheme() {
        const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('theme', t);
        updateThemeIcon(t);
    }
    function updateThemeIcon(t) {
        const icon = document.querySelector('#themeBtn i');
        if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeIcon(saved);
</script>
</body>
</html>
