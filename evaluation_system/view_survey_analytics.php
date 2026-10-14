<?php
session_start();
include 'db_connect.php';
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$survey_id = (int)$_GET['survey_id'];
if ($survey_id === 0) { header("Location: manage_surveys.php"); exit(); }

// Fetch survey and questions
$survey = $conn->query("SELECT * FROM surveys WHERE id = $survey_id")->fetch_assoc();
$questions_res = $conn->query("SELECT * FROM survey_questions WHERE survey_id = $survey_id ORDER BY order_num ASC");
$questions = [];
while ($q = $questions_res->fetch_assoc()) {
    $questions[$q['id']] = $q;
}

// Fetch and process responses
$responses_res = $conn->query("SELECT responses FROM survey_responses WHERE survey_id = $survey_id");
$total_responses = $responses_res->num_rows;
$aggregated_answers = [];

// Initialize aggregation array
foreach ($questions as $qid => $q) {
    if ($q['type'] == 'select' || $q['type'] == 'radio') {
        $options = json_decode($q['options'], true);
        if (is_array($options)) {
            $aggregated_answers[$qid] = array_fill_keys($options, 0);
        }
    } else {
        $aggregated_answers[$qid] = [];
    }
}

// Aggregate answers
while ($row = $responses_res->fetch_assoc()) {
    $response_data = json_decode($row['responses'], true);
    if (is_array($response_data)) {
        foreach ($questions as $qid => $q) {
            $question_text = $q['question_text'];
            if (isset($response_data[$question_text])) {
                $answer = $response_data[$question_text];
                if (($q['type'] == 'select' || $q['type'] == 'radio') && isset($aggregated_answers[$qid])) {
                    if (isset($aggregated_answers[$qid][$answer])) {
                        $aggregated_answers[$qid][$answer]++;
                    }
                } elseif (isset($aggregated_answers[$qid])) {
                    $aggregated_answers[$qid][] = $answer;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php include 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Analytics - <?php echo htmlspecialchars($survey['title'] ?? ''); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php $active_page = 'surveys'; include 'admin_sidebar.php'; ?>
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <div>
                <h2 style="color:var(--text-main);"><i class="fas fa-chart-pie" style="color:var(--accent);"></i> Survey Analytics</h2>
                <p style="color:var(--text-muted); font-size:13px;">For Survey: <strong><?php echo htmlspecialchars($survey['title']); ?></strong></p>
            </div>
            <a href="manage_surveys.php" class="nav-link-btn" style="background:var(--accent); color:white;">← Back to Surveys</a>
        </div>

        <div class="card" style="text-align:center;">
            <h3 style="color:var(--text-muted); font-size:16px;">Total Responses</h3>
            <h1 style="font-size:48px; color:var(--accent); margin:10px 0; font-weight:800;"><?php echo $total_responses; ?></h1>
        </div>

        <?php foreach ($questions as $qid => $q): ?>
            <div class="card">
                <h3 style="margin-bottom:20px; color:var(--text-main);"><?php echo htmlspecialchars($q['question_text']); ?></h3>
                <?php if (($q['type'] == 'select' || $q['type'] == 'radio') && $total_responses > 0): ?>
                    <div style="height: 250px; width: 100%;"><canvas id="chart-<?php echo $qid; ?>"></canvas></div>
                <?php elseif ($total_responses > 0): // Text or Textarea ?>
                    <ul style="max-height: 250px; overflow-y: auto; list-style: none; padding: 0;">
                        <?php foreach ($aggregated_answers[$qid] as $answer): ?>
                            <li style="padding:10px; border-bottom:1px solid var(--border-color); font-size:14px; color: var(--text-main);">"<?php echo htmlspecialchars($answer); ?>"</li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align:center; color:var(--text-muted);">No responses to analyze for this question.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </main>
</div>
<script>
    <?php foreach ($questions as $qid => $q): ?>
        <?php if (($q['type'] == 'select' || $q['type'] == 'radio') && $total_responses > 0): ?>
            const ctx<?php echo $qid; ?> = document.getElementById('chart-<?php echo $qid; ?>');
            if (ctx<?php echo $qid; ?>) {
                new Chart(ctx<?php echo $qid; ?>, {
                    type: 'bar',
                    data: { labels: <?php echo json_encode(array_keys($aggregated_answers[$qid])); ?>, datasets: [{ label: 'Response Count', data: <?php echo json_encode(array_values($aggregated_answers[$qid])); ?>, backgroundColor: 'rgba(59, 130, 246, 0.8)' }] },
                    options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
                });
            }
        <?php endif; ?>
    <?php endforeach; ?>
    function toggleTheme(){const t=document.documentElement.getAttribute("data-theme")==="dark"?"light":"dark";document.documentElement.setAttribute("data-theme",t),localStorage.setItem("theme",t)}
</script>
</body>
</html>