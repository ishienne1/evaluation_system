<?php
session_start();
include 'db_connect.php';
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

// Fetch all surveys for the dropdown
$surveys = $conn->query("SELECT s.id, s.title, COUNT(sr.id) as response_count FROM surveys s LEFT JOIN survey_responses sr ON s.id = sr.survey_id GROUP BY s.id, s.title ORDER BY s.title ASC");

$selected_survey_id = $_GET['survey_id'] ?? null;
$responses = [];
$survey_title = "";

if ($selected_survey_id) {
    $stmt = $conn->prepare("SELECT title FROM surveys WHERE id = ?");
    $stmt->bind_param("i", $selected_survey_id);
    $stmt->execute();
    $survey_title = $stmt->get_result()->fetch_assoc()['title'] ?? 'Unknown Survey';

    $resp_stmt = $conn->prepare("SELECT sr.*, u.full_name FROM survey_responses sr LEFT JOIN users u ON sr.user_id = u.id WHERE sr.survey_id = ? ORDER BY sr.submitted_at DESC");
    $resp_stmt->bind_param("i", $selected_survey_id);
    $resp_stmt->execute();
    $responses = $resp_stmt->get_result();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Survey Responses</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php $active_page = 'surveys'; include 'admin_sidebar.php'; ?>
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 style="color:var(--text-main);"><i class="fas fa-poll" style="color:#0d2158;"></i> Survey Responses</h2>
        </div>

        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-filter"></i> Select a Survey to View Responses</h3>
            <form method="GET">
                <div style="display:flex; gap:15px; align-items:flex-end;">
                    <div class="input-group" style="flex-grow:1; margin:0;">
                        <select name="survey_id" required onchange="this.form.submit()">
                            <option value="" disabled <?php echo !$selected_survey_id ? 'selected' : ''; ?>>-- Select a Survey --</option>
                            <?php while($s = $surveys->fetch_assoc()): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo ($selected_survey_id == $s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['title']) . " ({$s['response_count']} responses)"; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($selected_survey_id): ?>
        <div class="card">
            <h3 style="margin-bottom:20px;"><i class="fas fa-list-ul"></i> Responses for: <?php echo htmlspecialchars($survey_title); ?></h3>
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Respondent</th>
                        <th>Date Submitted</th>
                        <th style="text-align:right;">Responses</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($responses->num_rows > 0): while($row = $responses->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['full_name'] ?? 'Anonymous'); ?></strong></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($row['submitted_at'])); ?></td>
                        <td style="text-align:right;">
                            <button class="btn-action" style="background:#eef2ff; color:#4338ca;" onclick='alert(`<?php echo json_encode(json_decode($row["responses"]), JSON_PRETTY_PRINT | JSON_HEX_APOS); ?>`)'>
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted);">No responses for this survey yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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