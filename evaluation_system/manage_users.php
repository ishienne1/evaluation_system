<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin'])) {
    header("Location: index.php");
    exit();
}

$msg = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Prevent admin from deleting themselves
    if ($id != $_SESSION['id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header("Location: manage_users.php?msg=deleted");
        exit();
    }
}

// Handle Deactivate
if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    // Prevent admin from deactivating themselves
    if ($id != $_SESSION['id']) {
        $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header("Location: manage_users.php?msg=deactivated");
        exit();
    }
}

// Handle Activate
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_users.php?msg=activated");
    exit();
}

// Handle Reset Password
if (isset($_GET['reset_password'])) {
    $id = (int)$_GET['reset_password'];
    if ($id != $_SESSION['id']) {
        $new_pass = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
        $stmt->bind_param("si", $new_pass, $id);
        $stmt->execute();
        header("Location: manage_users.php?msg=reset_password");
        exit();
    }
}

// Handle Edit User
if (isset($_POST['edit_user'])) {
    $edit_id   = (int)$_POST['edit_id'];
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role      = $_POST['role'];
    $status    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $dept_id   = ($role == 'dean') ? (int)$_POST['dept_id'] : null;

    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, dept_id = ?, status = ? WHERE id = ?");
    $stmt->bind_param("ssssisi", $username, $email, $full_name, $role, $dept_id, $status, $edit_id);
    if ($stmt->execute()) {
        header("Location: manage_users.php?msg=updated");
        exit();
    }
}

// Handle Add User
if (isset($_POST['add_user'])) {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $full_name  = trim($_POST['full_name']);
    $role       = $_POST['role'];
    $dept_id    = ($role == 'dean') ? (int)$_POST['dept_id'] : null;
    $password   = password_hash('123456', PASSWORD_DEFAULT);

    // Check duplicate username/email
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $msg = 'error_exists';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO users (username, password, role, full_name, email, dept_id, status)
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param("sssssi", $username, $password, $role, $full_name, $email, $dept_id);
        $stmt->execute();
        header("Location: manage_users.php?msg=added");
        exit();
    }
}

// Filter parameters
$filter_role    = isset($_GET['role']) && $_GET['role'] !== '' ? $_GET['role'] : null;
$filter_dept_id = isset($_GET['dept_id']) && $_GET['dept_id'] !== '' ? (int)$_GET['dept_id'] : null;
$filter_status  = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;

// Build Query
$where = ["u.role IN ('admin', 'system admin', 'dean', 'visitor')", "u.status != 'pending'"];
if ($filter_role) {
    $where[] = "u.role = '" . $conn->real_escape_string($filter_role) . "'";
}
if ($filter_dept_id) {
    $where[] = "u.dept_id = " . (int)$filter_dept_id;
}
if ($filter_status) {
    $where[] = "u.status = '" . $conn->real_escape_string($filter_status) . "'";
}

$count_sql = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where);
$total_count = $conn->query($count_sql)->fetch_row()[0];

$sql = "SELECT u.*, d.dept_alias, d.dept_name 
        FROM users u 
        LEFT JOIN departments d ON u.dept_id = d.id 
        WHERE " . implode(' AND ', $where) . " 
        ORDER BY u.role, u.full_name ASC";
$users = $conn->query($sql);
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Accounts Management — System Users &amp; Administrators</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php
        $active_page = 'users';
        if ($_SESSION['role'] === 'system admin') {
            include 'ict_sidebar.php';
        } else {
            include 'admin_sidebar.php';
        }
        ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap: wrap; gap: 10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-users-cog" style="color:var(--accent);"></i> User Accounts Management
                </h2>
                <div style="display:flex; gap: 10px;">
                    <button onclick="document.getElementById('addUserModal').style.display='flex'" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-user-plus"></i> Add User Account</button>
                </div>
            </div>

            <!-- Add User Modal -->
            <div id="addUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-plus"></i> Add New System User</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('addUserModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Full Name</div>
                            <input type="text" name="full_name" placeholder="e.g. John Doe" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">ID Number</div>
                            <input type="text" name="username" placeholder="e.g. EMP-2021-123" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Email Address</div>
                            <input type="email" name="email" placeholder="e.g. sysadmin@mcnp.edu.ph" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Role</div>
                            <select name="role" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="admin">Admin (QA Officer)</option>
                                <option value="system admin">ICT Admin</option>
                                <option value="dean">Dean</option>
                            </select>
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Department (for Deans)</div>
                            <select name="dept_id" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">N/A for Admins</option>
                                <?php
                                $depts = $conn->query("SELECT * FROM departments ORDER BY dept_alias ASC");
                                while ($d = $depts->fetch_assoc()) echo "<option value='{$d['id']}'>".htmlspecialchars($d['dept_alias'])." - ".htmlspecialchars($d['dept_name'])."</option>";
                                ?>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1; font-size:12px; color:var(--text-muted);">
                            <i class="fas fa-info-circle" style="color:var(--accent);"></i> Default password will automatically be set to <strong>123456</strong>.
                        </div>
                        <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" onclick="document.getElementById('addUserModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="add_user" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-check"></i> Add User</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit User Modal -->
            <div id="editUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" style="margin:0;"><i class="fas fa-user-edit"></i> Edit System User</h3>
                        <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <input type="hidden" name="edit_id" id="edit_user_id">
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Full Name</div>
                            <input type="text" name="full_name" id="edit_full_name" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">ID Number</div>
                            <input type="text" name="username" id="edit_username" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Email Address</div>
                            <input type="email" name="email" id="edit_email" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Role</div>
                            <select name="role" id="edit_role" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="admin">Admin (QA Officer)</option>
                                <option value="system admin">ICT Admin</option>
                                <option value="dean">Dean</option>
                                <option value="visitor">Visitor</option>
                            </select>
                        </div>
                        <div class="input-group" style="margin:0;">
                            <div class="input-label">Department (for Deans)</div>
                            <select name="dept_id" id="edit_dept_id" style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="">N/A for Admins</option>
                                <?php
                                $depts = $conn->query("SELECT * FROM departments ORDER BY dept_alias ASC");
                                while ($d = $depts->fetch_assoc()) echo "<option value='{$d['id']}'>".htmlspecialchars($d['dept_alias'])." - ".htmlspecialchars($d['dept_name'])."</option>";
                                ?>
                            </select>
                        </div>
                        <div class="input-group" style="grid-column:1 / -1; margin:0;">
                            <div class="input-label">Account Status</div>
                            <select name="status" id="edit_status" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main); font-weight:700;">
                                <option value="active" style="color:#059669; font-weight:700;">Active (Can Login & Access Portal)</option>
                                <option value="inactive" style="color:#dc2626; font-weight:700;">Inactive (Deactivated / Blocked from Login)</option>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" onclick="closeEditModal()" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="edit_user" style="background:#3b82f6; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> User account created. Default password: <strong>123456</strong></div>
                <?php elseif ($_GET['msg'] === 'updated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> User details updated successfully.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> User account deleted.</div>
                <?php elseif ($_GET['msg'] === 'activated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> User account activated.</div>
                <?php elseif ($_GET['msg'] === 'deactivated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> User account deactivated.</div>
                <?php elseif ($_GET['msg'] === 'reset_password'): ?>
                    <div class="flash-success"><i class="fas fa-key"></i> Password has been reset to default (<strong>123456</strong>).</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($msg === 'error_exists'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> ID Number or Email already exists.</div>
            <?php endif; ?>

            <!-- Filter Card -->
            <div class="card">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter User Accounts</h3>
                <form method="GET" id="userFilterForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end;">
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Role</div>
                        <select name="role" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo ($filter_role === 'admin') ? 'selected' : ''; ?>>Admin (QA Officer)</option>
                            <option value="system admin" <?php echo ($filter_role === 'system admin') ? 'selected' : ''; ?>>ICT Admin</option>
                            <option value="dean" <?php echo ($filter_role === 'dean') ? 'selected' : ''; ?>>Dean</option>
                            <option value="visitor" <?php echo ($filter_role === 'visitor') ? 'selected' : ''; ?>>Visitor</option>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Department</div>
                        <select name="dept_id" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Departments</option>
                            <?php
                            $depts = $conn->query("SELECT * FROM departments ORDER BY dept_alias ASC");
                            while ($d = $depts->fetch_assoc()) {
                                $sel = ($filter_dept_id === (int)$d['id']) ? 'selected' : '';
                                echo "<option value='{$d['id']}' {$sel}>".htmlspecialchars($d['dept_alias'])." - ".htmlspecialchars($d['dept_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <div class="input-label">Filter by Status</div>
                        <select name="status" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <?php if ($filter_role || $filter_dept_id || $filter_status): ?>
                            <a href="manage_users.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; display:inline-block; border-radius:8px;">Reset Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- User Accounts List Card -->
            <div class="card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-users-cog"></i> User Accounts List</h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php echo $total_count; ?> total accounts
                    </span>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th style="width: 120px;">ID Number</th>
                            <th>Full Name</th>
                            <th style="text-align:center; width: 110px;">Role</th>
                            <th style="text-align:center; width: 90px;">Department</th>
                            <th style="width: 180px;">Email</th>
                            <th style="text-align:center; width: 85px;">Status</th>
                            <th style="text-align:right; width: 145px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($users && $users->num_rows > 0):
                            while ($row = $users->fetch_assoc()):
                                $role_name = $row['role'] === 'system admin' ? 'ICT Admin' : ($row['role'] === 'admin' ? 'Admin' : ucwords($row['role']));
                                $role_badge_bg = '#eef2ff';
                                $role_badge_color = '#4338ca';
                                if ($row['role'] === 'system admin') {
                                    $role_badge_bg = 'rgba(8, 145, 178, 0.12)';
                                    $role_badge_color = '#0891b2';
                                } elseif ($row['role'] === 'dean') {
                                    $role_badge_bg = 'rgba(124, 58, 237, 0.12)';
                                    $role_badge_color = '#7c3aed';
                                }
                        ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars($row['username']); ?></td>
                            <td style="white-space:nowrap;"><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                            <td style="text-align:center; white-space:nowrap;"><span class="category-badge" style="background:<?php echo $role_badge_bg; ?>; color:<?php echo $role_badge_color; ?>; font-weight:700;"><?php echo $role_name; ?></span></td>
                            <td style="text-align:center; white-space:nowrap;"><span class="category-badge"><?php echo htmlspecialchars($row['dept_alias'] ?? 'N/A'); ?></span></td>
                            <td class="cell-email" title="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                            <td style="text-align:center; white-space:nowrap;">
                                <?php if ($row['id'] != $_SESSION['id']): ?>
                                    <?php if ($row['status'] == 'active'): ?>
                                        <a href="manage_users.php?deactivate=<?php echo $row['id']; ?>" 
                                           onclick="return confirm('Deactivate account for <?php echo addslashes($row['full_name']); ?>? They will be locked out from logging in.')" 
                                           style="text-decoration:none;" 
                                           title="Status: Active — Click to Deactivate">
                                            <span class="category-badge" style="background:#d1fae5; color:#065f46; cursor:pointer; font-weight:700; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:20px;">
                                                <i class="fas fa-check-circle"></i> Active
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <a href="manage_users.php?activate=<?php echo $row['id']; ?>" 
                                           onclick="return confirm('Activate account for <?php echo addslashes($row['full_name']); ?>? They will be allowed to log in.')" 
                                           style="text-decoration:none;" 
                                           title="Status: Inactive — Click to Activate">
                                            <span class="category-badge" style="background:#fee2e2; color:#991b1b; cursor:pointer; font-weight:700; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:20px;">
                                                <i class="fas fa-ban"></i> Inactive
                                            </span>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="category-badge" style="background:#d1fae5; color:#065f46; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:20px;" title="Current Logged-in Account">
                                        <i class="fas fa-user-shield"></i> Active (You)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" class="btn-action" style="background:#3b82f6; color:white; border:none; cursor:pointer;" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Edit User"><i class="fas fa-edit"></i></button>
                                <?php if ($row['id'] != $_SESSION['id']): // Can't delete/deactivate self ?>
                                    <?php if ($row['status'] == 'active'): ?>
                                        <a href="manage_users.php?deactivate=<?php echo $row['id']; ?>" class="btn-action" style="background:#f59e0b; color:white;" title="Deactivate Account" onclick="return confirm('Deactivate this user account?')"><i class="fas fa-user-slash"></i></a>
                                    <?php else: ?>
                                        <a href="manage_users.php?activate=<?php echo $row['id']; ?>" class="btn-action" style="background:#10b981; color:white;" title="Activate Account" onclick="return confirm('Activate this user account?')"><i class="fas fa-user-check"></i></a>
                                    <?php endif; ?>
                                    <a href="manage_users.php?reset_password=<?php echo $row['id']; ?>" class="btn-action" style="background:#6366f1; color:white;" title="Reset Password to 123456" onclick="return confirm('Are you sure you want to reset the password for <?php echo htmlspecialchars($row['full_name']); ?> to 123456?')"><i class="fas fa-key"></i></a>
                                    <a href="manage_users.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this user?')"><i class="fas fa-trash-alt"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size:30px; display:block; margin-bottom:8px; opacity:0.4;"></i>
                                No system users found matching the filter criteria.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function openEditModal(userData) {
            document.getElementById('edit_user_id').value = userData.id;
            document.getElementById('edit_full_name').value = userData.full_name;
            document.getElementById('edit_username').value = userData.username;
            document.getElementById('edit_email').value = userData.email;
            document.getElementById('edit_role').value = userData.role;
            document.getElementById('edit_dept_id').value = userData.dept_id || '';
            document.getElementById('edit_status').value = userData.status || 'active';
            document.getElementById('editUserModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }

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