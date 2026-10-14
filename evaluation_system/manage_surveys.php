<?php
session_start();
include 'db_connect.php';
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$msg = '';

// Handle Add/Edit
if (isset($_POST['save_survey'])) {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $audience = $_POST['target_audience'];

    if ($id > 0) { // Update
        $stmt = $conn->prepare("UPDATE surveys SET title = ?, description = ?, target_audience = ? WHERE id = ?");
        $stmt->bind_param("sssi", $title, $description, $audience, $id);
        $msg = 'updated';
    } else { // Insert
        $stmt = $conn->prepare("INSERT INTO surveys (title, description, target_audience, created_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $title, $description, $audience, $_SESSION['id']);
        $msg = 'added';
    }
    if ($stmt->execute()) {
        header("Location: manage_surveys.php?msg=$msg");
        exit();
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM surveys WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_surveys.php?msg=deleted");
    exit();
}

// Fetch for editing
$edit_survey = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM surveys WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_survey = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Surveys</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php $active_page = 'surveys'; include 'admin_sidebar.php'; ?>
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 style="color:var(--text-main);"><i class="fas fa-poll-h" style="color:#0d2158;"></i> Manage Surveys</h2>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'added'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Survey created successfully.</div><?php endif; ?>
            <?php if ($_GET['msg'] === 'updated'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Survey updated successfully.</div><?php endif; ?>
            <?php if ($_GET['msg'] === 'deleted'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Survey deleted.</div><?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-plus-circle"></i> <?php echo $edit_survey ? 'Edit' : 'Create New'; ?> Survey</h3>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $edit_survey['id'] ?? 0; ?>">
                <div class="input-group">
                    <div class="input-label">Survey Title</div>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($edit_survey['title'] ?? ''); ?>" required>
                </div>
                <div class="input-group">
                    <div class="input-label">Description</div>
                    <textarea name="description" rows="3"><?php echo htmlspecialchars($edit_survey['description'] ?? ''); ?></textarea>
                </div>
                <div class="input-group">
                    <div class="input-label">Target Audience</div>
                    <select name="target_audience" required>
                        <option value="incoming" <?php echo (isset($edit_survey) && $edit_survey['target_audience'] == 'incoming') ? 'selected' : ''; ?>>Incoming Students</option>
                        <option value="alumni" <?php echo (isset($edit_survey) && $edit_survey['target_audience'] == 'alumni') ? 'selected' : ''; ?>>Alumni / Guests</option>
                        <option value="continuing" <?php echo (isset($edit_survey) && $edit_survey['target_audience'] == 'continuing') ? 'selected' : ''; ?>>Continuing Students</option>
                    </select>
                </div>
                <button type="submit" name="save_survey" class="login-btn" style="width:auto; padding:12px 30px;"><?php echo $edit_survey ? 'Update' : 'Save'; ?> Survey</button>
                <?php if ($edit_survey): ?>
                    <a href="manage_surveys.php" class="nav-link-btn" style="display:inline-block; width:auto; margin-left:10px; background:var(--text-muted); color:white;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-list"></i> Survey List</h3>
            <table class="table-modern">
                <thead>
                    <tr><th>Title</th><th>Target Audience</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php
                    $surveys = $conn->query("SELECT * FROM surveys ORDER BY created_at DESC");
                    while ($row = $surveys->fetch_assoc()):
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['title']); ?></strong><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($row['description']); ?></small></td>
                        <td><span class="category-badge"><?php echo ucfirst($row['target_audience']); ?></span></td>
                        <td>
                            <a href="manage_survey_questions.php?survey_id=<?php echo $row['id']; ?>" class="btn-action btn-action-text" style="background:#10b981; color:white;"><i class="fas fa-tasks"></i> Manage Questions</a>
                            <a href="view_survey_analytics.php?survey_id=<?php echo $row['id']; ?>" class="btn-action btn-action-text" style="background:#3b82f6; color:white;"><i class="fas fa-chart-pie"></i> Analytics</a>
                            <a href="manage_surveys.php?edit=<?php echo $row['id']; ?>" class="btn-action" style="background:#eef2ff; color:#4338ca;"><i class="fas fa-edit"></i></a>
                            <a href="manage_surveys.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this survey and all its questions/responses?')"><i class="fas fa-trash-alt"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script>
    function toggleTheme() {
        const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('theme', t);
    }
</script>
</body>
</html>