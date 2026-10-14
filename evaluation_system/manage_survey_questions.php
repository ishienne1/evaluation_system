<?php
session_start();
include 'db_connect.php';
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$survey_id = (int)$_GET['survey_id'];
if ($survey_id === 0) { header("Location: manage_surveys.php"); exit(); }

$survey = $conn->query("SELECT title FROM surveys WHERE id = $survey_id")->fetch_assoc();
if (!$survey) { header("Location: manage_surveys.php"); exit(); }

// Handle Add/Edit
if (isset($_POST['save_question'])) {
    $id = (int)$_POST['id'];
    $text = trim($_POST['question_text']);
    $type = $_POST['type'];
    $options = ($type == 'select' || $type == 'radio') ? json_encode(array_map('trim', explode(',', $_POST['options']))) : null;
    $order = (int)$_POST['order_num'];
    $required = isset($_POST['is_required']) ? 1 : 0;

    if ($id > 0) { // Update
        $stmt = $conn->prepare("UPDATE survey_questions SET question_text=?, type=?, options=?, order_num=?, is_required=? WHERE id=? AND survey_id=?");
        $stmt->bind_param("sssiiii", $text, $type, $options, $order, $required, $id, $survey_id);
        $msg = 'updated';
    } else { // Insert
        $stmt = $conn->prepare("INSERT INTO survey_questions (survey_id, question_text, type, options, order_num, is_required) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssii", $survey_id, $text, $type, $options, $order, $required);
        $msg = 'added';
    }
    if ($stmt->execute()) {
        header("Location: manage_survey_questions.php?survey_id=$survey_id&msg=$msg");
        exit();
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM survey_questions WHERE id = ? AND survey_id = ?");
    $stmt->bind_param("ii", $id, $survey_id);
    $stmt->execute();
    header("Location: manage_survey_questions.php?survey_id=$survey_id&msg=deleted");
    exit();
}

// Fetch for editing
$edit_q = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM survey_questions WHERE id = ? AND survey_id = ?");
    $stmt->bind_param("ii", $id, $survey_id);
    $stmt->execute();
    $edit_q = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Survey Questions</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php $active_page = 'surveys'; include 'admin_sidebar.php'; ?>
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <div>
                <h2 style="color:var(--text-main);"><i class="fas fa-tasks" style="color:#0d2158;"></i> Manage Questions</h2>
                <p style="color:var(--text-muted); font-size:13px;">For Survey: <strong><?php echo htmlspecialchars($survey['title']); ?></strong></p>
            </div>
            <a href="manage_surveys.php" class="nav-link-btn" style="background:var(--text-muted); color:white;">← Back to Surveys</a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'added'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Question added.</div><?php endif; ?>
            <?php if ($_GET['msg'] === 'updated'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Question updated.</div><?php endif; ?>
            <?php if ($_GET['msg'] === 'deleted'): ?><div class="flash-success"><i class="fas fa-check-circle"></i> Question deleted.</div><?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-plus-circle"></i> <?php echo $edit_q ? 'Edit' : 'Add New'; ?> Question</h3>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $edit_q['id'] ?? 0; ?>">
                <div class="input-group">
                    <div class="input-label">Question Text</div>
                    <input type="text" name="question_text" value="<?php echo htmlspecialchars($edit_q['question_text'] ?? ''); ?>" required>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="input-group">
                        <div class="input-label">Question Type</div>
                        <select name="type">
                            <option value="text" <?php echo (isset($edit_q) && $edit_q['type'] == 'text') ? 'selected' : ''; ?>>Text Field</option>
                            <option value="textarea" <?php echo (isset($edit_q) && $edit_q['type'] == 'textarea') ? 'selected' : ''; ?>>Text Area</option>
                            <option value="select" <?php echo (isset($edit_q) && $edit_q['type'] == 'select') ? 'selected' : ''; ?>>Dropdown (Select)</option>
                            <option value="radio" <?php echo (isset($edit_q) && $edit_q['type'] == 'radio') ? 'selected' : ''; ?>>Radio Buttons</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <div class="input-label">Order Number</div>
                        <input type="number" name="order_num" value="<?php echo $edit_q['order_num'] ?? 0; ?>" required>
                    </div>
                </div>
                <div class="input-group">
                    <div class="input-label">Options (for Dropdown/Radio, comma-separated)</div>
                    <input type="text" name="options" value="<?php echo isset($edit_q['options']) ? htmlspecialchars(implode(',', json_decode($edit_q['options']))) : ''; ?>" placeholder="e.g. Option 1,Option 2,Option 3">
                </div>
                <div class="input-group" style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="is_required" id="is_required" value="1" <?php echo (isset($edit_q) && $edit_q['is_required']) ? 'checked' : ''; ?> style="width:auto;">
                    <label for="is_required" style="margin:0; font-size:14px;">This question is required</label>
                </div>
                <button type="submit" name="save_question" class="login-btn" style="width:auto; padding:12px 30px;"><?php echo $edit_q ? 'Update' : 'Save'; ?> Question</button>
                <?php if ($edit_q): ?>
                    <a href="manage_survey_questions.php?survey_id=<?php echo $survey_id; ?>" class="nav-link-btn" style="display:inline-block; width:auto; margin-left:10px; background:var(--text-muted); color:white;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-list"></i> Question List</h3>
            <table class="table-modern">
                <thead>
                    <tr><th>Order</th><th>Question</th><th>Type</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php
                    $questions = $conn->query("SELECT * FROM survey_questions WHERE survey_id = $survey_id ORDER BY order_num ASC");
                    while ($row = $questions->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo $row['order_num']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['question_text']); ?></strong> <?php echo $row['is_required'] ? '<span style="color:#ef4444;">*</span>' : ''; ?></td>
                        <td><span class="category-badge"><?php echo ucfirst($row['type']); ?></span></td>
                        <td>
                            <a href="manage_survey_questions.php?survey_id=<?php echo $survey_id; ?>&edit=<?php echo $row['id']; ?>" class="btn-action" style="background:#eef2ff; color:#4338ca;"><i class="fas fa-edit"></i></a>
                            <a href="manage_survey_questions.php?survey_id=<?php echo $survey_id; ?>&delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this question?')"><i class="fas fa-trash-alt"></i></a>
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