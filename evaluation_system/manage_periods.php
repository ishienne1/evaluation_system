<?php
session_start();
include 'db_connect.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { 
    header("Location: index.php"); 
    exit(); 
}

// Handle Setting Active Period
if (isset($_GET['set_active'])) {
    $id = (int)$_GET['set_active'];
    $stmt_deactivate = $conn->prepare("UPDATE academic_periods SET is_active = 0");
    $stmt_deactivate->execute();
    $stmt_activate = $conn->prepare("UPDATE academic_periods SET is_active = 1 WHERE id = ?");
    $stmt_activate->bind_param("i", $id);
    $stmt_activate->execute();
    header("Location: manage_periods.php?msg=activated");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Prevent deleting the active period as a safeguard
    $stmt = $conn->prepare("DELETE FROM academic_periods WHERE id = ? AND is_active = 0");
    $stmt->bind_param("i", $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        header("Location: manage_periods.php?msg=deleted");
    } else {
        header("Location: manage_periods.php?error=delete_failed");
    }
    exit();
}

// Handle Add/Edit Period
if (isset($_POST['save_period'])) {
    $id = (int)$_POST['id'];
    $sy = trim($_POST['school_year']);
    $sem = trim($_POST['semester']);

    if ($id > 0) { // Update
        $stmt = $conn->prepare("UPDATE academic_periods SET school_year = ?, semester = ? WHERE id = ?");
        $stmt->bind_param("ssi", $sy, $sem, $id);
        $msg = 'updated';
    } else { // Insert
        $stmt = $conn->prepare("INSERT INTO academic_periods (school_year, semester, is_active) VALUES (?, ?, 0)");
        $stmt->bind_param("ss", $sy, $sem);
        $msg = 'added';
    }
    $stmt->execute();
    header("Location: manage_periods.php?msg=$msg");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Periods</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php $active_page = 'periods'; include 'admin_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap: wrap; gap: 10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-calendar-check" style="color:var(--accent);"></i> Academic Periods
                </h2>
                <button onclick="openAddPeriodModal()" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;"><i class="fas fa-plus"></i> Add Academic Period</button>
            </div>

            <!-- Add / Edit Period Modal -->
            <div id="periodModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:var(--card-bg); padding:30px; border-radius:12px; width:100%; max-width:450px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 class="modal-title" id="modalPeriodTitle" style="margin:0;"><i class="fas fa-calendar-plus"></i> Add Academic Period</h3>
                        <button type="button" class="modal-close-btn" onclick="document.getElementById('periodModal').style.display='none'" aria-label="Close modal">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="id" id="period_id" value="0">
                        <div class="input-group" style="margin-bottom:15px;">
                            <div class="input-label">School Year</div>
                            <input type="text" name="school_year" id="period_sy" placeholder="e.g. 2025-2026 or 2026" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        </div>
                        <div class="input-group" style="margin-bottom:20px;">
                            <div class="input-label">Semester</div>
                            <select name="semester" id="period_sem" required style="width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" onclick="document.getElementById('periodModal').style.display='none'" style="background:var(--text-muted); color:white; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                            <button type="submit" name="save_period" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-check"></i> Save Period</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Academic period added successfully.</div>
                <?php elseif ($_GET['msg'] === 'updated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Academic period updated.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Academic period has been deleted.</div>
                <?php elseif ($_GET['msg'] === 'activated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Active academic period updated.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> Could not delete period. It might currently be set as active.</div>
            <?php endif; ?>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-history"></i> Academic Period History</h3>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>School Year</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res = $conn->query("SELECT * FROM academic_periods ORDER BY school_year DESC, semester ASC");
                        if ($res && $res->num_rows > 0):
                            while($row = $res->fetch_assoc()):
                                $is_active = $row['is_active'] == 1;
                        ?> 
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['school_year']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['semester']); ?></td>
                            <td>
                                <?php if($is_active): ?>
                                    <span class="category-badge" style="background:#d1fae5; color:#065f46; font-weight:700;"><i class="fas fa-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="category-badge" style="background:#fee2e2; color:#991b1b;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" class="btn-action" style="background:#3b82f6; color:white; border:none; cursor:pointer;" onclick="openEditPeriodModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Edit Period"><i class="fas fa-edit"></i></button>
                                <?php if(!$is_active): ?>
                                    <a href="manage_periods.php?set_active=<?php echo htmlspecialchars($row['id']); ?>" class="btn-action" style="background:#10b981; color:white;" title="Set as Active Period"><i class="fas fa-check-circle"></i></a>
                                    <a href="manage_periods.php?delete=<?php echo htmlspecialchars($row['id']); ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this period? All associated classes and enrollments will also be permanently deleted.');" title="Delete Period"><i class="fas fa-trash-alt"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">
                                No academic periods found.
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
        function openAddPeriodModal() {
            document.getElementById('period_id').value = '0';
            document.getElementById('period_sy').value = '';
            document.getElementById('period_sem').value = '1st Semester';
            document.getElementById('modalPeriodTitle').innerHTML = '<i class="fas fa-calendar-plus"></i> Add Academic Period';
            document.getElementById('periodModal').style.display = 'flex';
        }
        function openEditPeriodModal(data) {
            document.getElementById('period_id').value = data.id;
            document.getElementById('period_sy').value = data.school_year;
            document.getElementById('period_sem').value = data.semester;
            document.getElementById('modalPeriodTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Academic Period';
            document.getElementById('periodModal').style.display = 'flex';
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
            updateThemeIcon(targetTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeBtn i');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'light');
        updateThemeIcon(localStorage.getItem('theme') || 'light');
    </script>
</body>
</html>