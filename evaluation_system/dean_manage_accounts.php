<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'dean') {
    header("Location: index.php");
    exit();
}

$dept_id = $_SESSION['dept_id'];

// Get department name
$dept_stmt = $conn->prepare("SELECT dept_name, dept_alias FROM departments WHERE id = ?");
$dept_stmt->bind_param("i", $dept_id);
$dept_stmt->execute();
$dept_info = $dept_stmt->get_result()->fetch_assoc();
$dept_name = $dept_info['dept_name'] ?? 'Department';
$dept_alias = $dept_info['dept_alias'] ?? 'Dept';

$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;
$period_label = isset($active_period['semester']) ? $active_period['semester'] . " " . $active_period['school_year'] : "No Active Period";

// Handle Activate/Deactivate Faculty
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE users SET status='active' WHERE id=? AND role='faculty' AND dept_id=?");
    $stmt->bind_param("ii", $id, $dept_id);
    $stmt->execute();
    header("Location: dean_manage_accounts.php?msg=activated");
    exit();
}
if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    $stmt = $conn->prepare("UPDATE users SET status='inactive' WHERE id=? AND role='faculty' AND dept_id=?");
    $stmt->bind_param("ii", $id, $dept_id);
    $stmt->execute();
    header("Location: dean_manage_accounts.php?msg=deactivated");
    exit();
}
if (isset($_GET['reset_password'])) {
    $id = (int)$_GET['reset_password'];
    $new_pass = password_hash('123456', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expiry=NULL WHERE id=? AND role='faculty' AND dept_id=?");
    $stmt->bind_param("sii", $new_pass, $id, $dept_id);
    $stmt->execute();
    header("Location: dean_manage_accounts.php?msg=reset_password");
    exit();
}

// === Line Graph Data: Courses with student & evaluation counts per academic period ===
$all_periods_q = $conn->query("SELECT id, semester, school_year FROM academic_periods ORDER BY school_year ASC, semester ASC LIMIT 6");
$all_periods = [];
if ($all_periods_q) { $all_periods = $all_periods_q->fetch_all(MYSQLI_ASSOC); }

// Courses for this dept
$courses_q = $conn->prepare("SELECT id, course_alias, course_name FROM courses WHERE dept_id=? ORDER BY course_alias ASC");
$courses_q->bind_param("i", $dept_id);
$courses_q->execute();
$dept_courses = $courses_q->get_result()->fetch_all(MYSQLI_ASSOC);

// Build period labels
$period_labels = [];
foreach ($all_periods as $p) {
    $period_labels[] = $p['semester'] . ' ' . $p['school_year'];
}

// Build dataset per course
$course_datasets = [];
$colors = [
    ['rgba(99,102,241,0.9)', 'rgba(99,102,241,0.15)'],
    ['rgba(16,185,129,0.9)', 'rgba(16,185,129,0.15)'],
    ['rgba(245,158,11,0.9)', 'rgba(245,158,11,0.15)'],
    ['rgba(239,68,68,0.9)', 'rgba(239,68,68,0.15)'],
    ['rgba(132,90,223,0.9)', 'rgba(132,90,223,0.15)'],
    ['rgba(20,184,166,0.9)', 'rgba(20,184,166,0.15)'],
];
$ci = 0;
foreach ($dept_courses as $course) {
    $student_counts = [];
    $eval_counts = [];
    foreach ($all_periods as $p) {
        $sc_stmt = $conn->prepare("SELECT COUNT(DISTINCT u.id) as sc, COUNT(DISTINCT e.student_id) as ec FROM users u LEFT JOIN evaluations e ON u.id=e.student_id AND e.academic_period_id=? WHERE u.course_id=? AND u.role='student'");
        $sc_stmt->bind_param("ii", $p['id'], $course['id']);
        $sc_stmt->execute();
        $sc_row = $sc_stmt->get_result()->fetch_assoc();
        $student_counts[] = (int)($sc_row['sc'] ?? 0);
        $eval_counts[] = (int)($sc_row['ec'] ?? 0);
    }
    $color = $colors[$ci % count($colors)];
    $course_datasets[] = [
        'label' => $course['course_alias'] . ' - Students',
        'data' => $student_counts,
        'borderColor' => $color[0],
        'backgroundColor' => $color[1],
        'tension' => 0.4,
        'fill' => true,
        'pointRadius' => 5,
        'pointHoverRadius' => 7,
    ];
    $ci++;
}

// === Faculty in this department ===
$faculty_stmt = $conn->prepare("
    SELECT u.id, u.full_name, u.username, u.email, u.status, u.dept_id,
           AVG(e.rating) as avg_rating, COUNT(e.id) as eval_count
    FROM users u
    LEFT JOIN evaluations e ON u.id = e.faculty_id AND e.academic_period_id = ?
    WHERE u.role = 'faculty' AND u.dept_id = ?
    GROUP BY u.id
    ORDER BY u.full_name ASC
");
$faculty_stmt->bind_param("ii", $active_id, $dept_id);
$faculty_stmt->execute();
$faculty_list = $faculty_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Subjects per faculty
function getFacultySubjects($conn, $faculty_id, $dept_id) {
    $s_stmt = $conn->prepare("SELECT subject_code, subject_description, year_level, section FROM subjects WHERE faculty_id=? AND dept_id=?");
    $s_stmt->bind_param("ii", $faculty_id, $dept_id);
    $s_stmt->execute();
    return $s_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include 'theme_style.php'; ?>
    <title>Manage Accounts — Dean Portal</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">
<div style="display:flex; min-height:100vh; position:relative;">
    <?php $active_page_dean = 'dean_manage_accounts'; include 'dean_sidebar.php'; ?>

    <main class="main-content">

        <!-- Header Bar -->
        <div class="header-bar-modern">
            <div style="display:flex; align-items:center; gap:12px;">
                <div>
                    <h1 style="font-size:22px; font-weight:800; color:var(--text-main); margin-bottom:2px;">Manage Accounts</h1>
                    <p style="font-size:13px; color:var(--text-muted); margin:0;"><?php echo htmlspecialchars($dept_alias . ' — ' . $dept_name); ?></p>
                </div>
                <span style="display:inline-flex; align-items:center; gap:6px; background:rgba(132,90,223,0.08); border:1px solid rgba(132,90,223,0.2); border-radius:10px; padding:5px 12px; font-size:12px; font-weight:700; color:#845adf;">
                    <i class="fas fa-clipboard-list"></i> <?php echo $period_label; ?>
                </span>
            </div>
            <div style="position: relative; display: inline-block;">
                <div class="profile-widget-modern" id="profileWidget" style="cursor: pointer; user-select: none;">
                    <?php
                    $avatar_display = $_SESSION['avatar'] ?? 'uploads/avatars/default.png';
                    if (!file_exists($avatar_display)) $avatar_display = 'uploads/avatars/default.png';
                    ?>
                    <img src="<?php echo htmlspecialchars($avatar_display); ?>" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:2px solid var(--border-color);">
                    <div style="text-align: left; line-height: 1.15;">
                        <div style="font-size:12.5px; font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div style="font-size:10px; color:var(--text-muted); font-weight:600;">Dean / Dept Head</div>
                    </div>
                    <i class="fas fa-chevron-down" style="font-size:10px; color:var(--text-muted); margin-left: 2px;"></i>
                </div>

                <!-- Dean Profile Dropdown Menu -->
                <div id="profileDropdown" class="header-dropdown" style="display:none; position:absolute; top:calc(100% + 12px); right:0; width:220px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:9999; backdrop-filter:blur(15px); padding: 8px 0;">
                    <div style="padding: 10px 16px 12px; border-bottom: 1px solid var(--border-color);">
                        <div style="font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 3px;">Dean / Department Head</div>
                    </div>
                    <div style="padding: 6px 0;">
                        <a href="profile.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-user-circle" style="color:var(--accent); width:16px;"></i> My Profile
                        </a>
                        <a href="dean_manage_accounts.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-user-tie" style="color:#6366f1; width:16px;"></i> Manage Faculty
                        </a>
                        <a href="dean_manage_students.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-users" style="color:#10b981; width:16px;"></i> Manage Students
                        </a>
                        <a href="manage_subjects.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: var(--text-main); text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='var(--input-bg)'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-book-open" style="color:#f59e0b; width:16px;"></i> Manage Subjects
                        </a>
                    </div>
                    <div style="border-top: 1px solid var(--border-color); padding: 6px 0 2px;">
                        <?php if (!empty($_SESSION['original_admin_id'])): ?>
                            <a href="logout.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 700; color: #8b5cf6; text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='rgba(139,92,246,0.08)'" onmouseout="this.style.background='transparent'">
                                <i class="fas fa-user-secret" style="width:16px;"></i> Return to Admin
                            </a>
                        <?php endif; ?>
                        <a href="logout.php" style="display:flex; align-items:center; gap:10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: #ef4444; text-decoration: none; transition: 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.08)'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-sign-out-alt" style="width:16px;"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'activated'): ?>
                <div class="flash-success" style="margin:20px 30px 0;"><i class="fas fa-check-circle"></i> Faculty account activated.</div>
            <?php elseif ($_GET['msg'] === 'deactivated'): ?>
                <div class="flash-success" style="margin:20px 30px 0;"><i class="fas fa-check-circle"></i> Faculty account deactivated.</div>
            <?php elseif ($_GET['msg'] === 'reset_password'): ?>
                <div class="flash-success" style="margin:20px 30px 0;"><i class="fas fa-key"></i> Password has been reset to default (<strong>123456</strong>).</div>
            <?php endif; ?>
        <?php endif; ?>

        <div style="padding:30px;">

            <!-- Course Enrollment Line Graph -->
            <div class="card-modern" style="margin-bottom:28px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                    <div>
                        <h3 style="margin:0; font-size:17px; font-weight:800;"><i class="fas fa-chart-line" style="color:var(--accent); margin-right:8px;"></i> Department / Course Statistics</h3>
                        <p style="color:var(--text-muted); font-size:12px; margin:4px 0 0 0;">Student count per course across academic periods (line graph)</p>
                    </div>
                    <span class="category-badge" style="font-size:12px;"><?php echo count($dept_courses); ?> Courses</span>
                </div>
                <?php if (!empty($period_labels) && !empty($course_datasets)): ?>
                <div style="height:320px; width:100%; position:relative;">
                    <canvas id="courseLineChart"></canvas>
                </div>
                <?php else: ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">
                    <i class="fas fa-chart-line" style="font-size:36px; opacity:0.3; display:block; margin-bottom:10px;"></i>
                    No course or period data available yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Faculty / Instructors Table -->
            <div class="card-modern">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0; font-size:17px; font-weight:800;"><i class="fas fa-chalkboard-teacher" style="color:var(--accent); margin-right:8px;"></i> Faculty Accounts</h3>
                    <span class="category-badge" style="font-size:12px;"><?php echo count($faculty_list); ?> instructors</span>
                </div>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Employee ID</th>
                                <th>Email</th>
                                <th>Avg Rating</th>
                                <th>Evaluations</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($faculty_list)): ?>
                                <?php foreach ($faculty_list as $fac): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fac['full_name']); ?></strong>
                                        <?php
                                        $subjects = getFacultySubjects($conn, $fac['id'], $dept_id);
                                        if (!empty($subjects)):
                                        ?>
                                        <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:4px;">
                                            <?php foreach($subjects as $sub): ?>
                                            <span class="category-badge" style="font-size:10px; padding:2px 7px;"><?php echo htmlspecialchars($sub['subject_code']); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?php echo htmlspecialchars($fac['username']); ?></td>
                                    <td style="color:var(--text-muted); font-size:12px;"><?php echo htmlspecialchars($fac['email'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($fac['avg_rating']): ?>
                                        <span class="category-badge" style="background:rgba(245,158,11,0.1); color:#f59e0b; font-weight:bold;">
                                            <?php echo number_format($fac['avg_rating'], 2); ?> ★
                                        </span>
                                        <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:12px;">No ratings</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-muted);"><?php echo $fac['eval_count']; ?> evals</td>
                                    <td>
                                        <?php if ($fac['status'] === 'active'): ?>
                                        <a href="dean_manage_accounts.php?deactivate=<?php echo $fac['id']; ?>" 
                                           onclick="return confirm('Deactivate faculty account for <?php echo addslashes($fac['full_name']); ?>?')" 
                                           style="text-decoration:none;" 
                                           title="Click to Deactivate">
                                            <span class="category-badge" style="background:#d1fae5; color:#065f46; cursor:pointer; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                                <i class="fas fa-check-circle" style="font-size:10px; margin-right:3px;"></i>Active
                                            </span>
                                        </a>
                                        <?php else: ?>
                                        <a href="dean_manage_accounts.php?activate=<?php echo $fac['id']; ?>" 
                                           onclick="return confirm('Activate faculty account for <?php echo addslashes($fac['full_name']); ?>?')" 
                                           style="text-decoration:none;" 
                                           title="Click to Activate">
                                            <span class="category-badge" style="background:#fee2e2; color:#991b1b; cursor:pointer; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                                <i class="fas fa-ban" style="font-size:10px; margin-right:3px;"></i>Inactive
                                            </span>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if ($fac['status'] === 'active'): ?>
                                        <a href="dean_manage_accounts.php?deactivate=<?php echo $fac['id']; ?>" class="btn-action" style="background:#f59e0b; color:white;" title="Deactivate" onclick="return confirm('Deactivate this faculty account?')">
                                            <i class="fas fa-user-slash"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="dean_manage_accounts.php?activate=<?php echo $fac['id']; ?>" class="btn-action" style="background:#10b981; color:white;" title="Activate" onclick="return confirm('Activate this faculty account?')">
                                            <i class="fas fa-user-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="dean_manage_accounts.php?reset_password=<?php echo $fac['id']; ?>" class="btn-action" style="background:#3b82f6; color:white;" title="Reset Password to 123456" onclick="return confirm('Are you sure you want to reset password for <?php echo htmlspecialchars($fac['full_name']); ?> to 123456?')">
                                            <i class="fas fa-key"></i>
                                        </a>
                                        <button type="button" onclick="openSubjectModal(<?php echo htmlspecialchars(json_encode(['name' => $fac['full_name'], 'subjects' => getFacultySubjects($conn, $fac['id'], $dept_id)])); ?>)" class="btn-action" style="background:var(--purple-glow); color:var(--accent); border:1px solid rgba(132,90,223,0.2);" title="View Subjects">
                                            <i class="fas fa-book"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">
                                    <i class="fas fa-users" style="font-size:30px; display:block; margin-bottom:8px; opacity:0.3;"></i>
                                    No faculty accounts found for your department.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <footer>
            <i class="fas fa-university"></i> Dean Portal · <?php echo $_SESSION['username']; ?> · <?php echo $_SESSION['full_name']; ?>
        </footer>
    </main>
</div>

<!-- Subject List Modal -->
<div id="subjectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; padding:25px; width:90%; max-width:550px; color:var(--text-main); box-shadow:0 20px 50px rgba(0,0,0,0.3); max-height:80vh; overflow-y:auto;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 class="modal-title" style="margin:0;" id="subjectModalTitle"><i class="fas fa-book" style="color:var(--accent);"></i> Assigned Subjects</h3>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('subjectModal').style.display='none'" aria-label="Close modal">&times;</button>
        </div>
        <div id="subjectModalContent"></div>
    </div>
</div>

<script>
    // Line Chart
    <?php if (!empty($period_labels) && !empty($course_datasets)): ?>
    const ctx = document.getElementById('courseLineChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($period_labels); ?>,
                datasets: <?php echo json_encode($course_datasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { 
                        position: 'top',
                        labels: { 
                            color: 'var(--text-main)',
                            font: { size: 12, weight: '600' },
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'var(--card-bg)',
                        titleColor: 'var(--text-main)',
                        bodyColor: 'var(--text-muted)',
                        borderColor: 'var(--border-color)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { color: 'var(--text-muted)', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { color: 'var(--text-muted)', font: { size: 11 }, stepSize: 1 }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    function openSubjectModal(data) {
        document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-book" style="color:var(--accent);"></i> ' + data.name + ' — Subjects';
        let html = '';
        if (data.subjects && data.subjects.length > 0) {
            html = '<table class="table-modern"><thead><tr><th>Code</th><th>Description</th><th>Year</th><th>Section</th></tr></thead><tbody>';
            data.subjects.forEach(s => {
                html += `<tr>
                    <td><strong>${s.subject_code || ''}</strong></td>
                    <td>${s.subject_description || ''}</td>
                    <td><span class="category-badge">${s.year_level || 'N/A'}</span></td>
                    <td><span class="category-badge" style="background:#eef2ff;color:#4338ca;">${s.section || 'N/A'}</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html = '<p style="color:var(--text-muted); text-align:center; padding:20px;"><i class="fas fa-book-open"></i> No subjects assigned yet.</p>';
        }
        document.getElementById('subjectModalContent').innerHTML = html;
        document.getElementById('subjectModal').style.display = 'flex';
    }

    document.getElementById('subjectModal').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

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

    document.addEventListener('click', function(e) {
        let profileWidget = document.getElementById('profileWidget');
        let profileDropdown = document.getElementById('profileDropdown');
        if (profileWidget && profileDropdown) {
            if (profileWidget.contains(e.target)) {
                e.stopPropagation();
                profileDropdown.style.display = profileDropdown.style.display === 'none' ? 'block' : 'none';
            } else if (!profileDropdown.contains(e.target)) {
                profileDropdown.style.display = 'none';
            }
        }
    });
</script>
</body>
</html>
