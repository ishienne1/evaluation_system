<?php
session_start();
include 'db_connect.php';

$survey_id = 0;
$survey = null;

// Determine survey to load by ID or by Type
if (isset($_GET['id'])) {
    $survey_id = (int)$_GET['id'];
} elseif (isset($_GET['type'])) {
    $type = $_GET['type'];
    $stmt = $conn->prepare("SELECT id FROM surveys WHERE target_audience = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $type);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $survey_id = $row['id'];
        }
    }
}

if ($survey_id === 0) {
    header("Location: index.php");
    exit();
}

// Fetch survey details
$survey_stmt = $conn->prepare("SELECT * FROM surveys WHERE id = ?");
$survey_stmt->bind_param("i", $survey_id);
$survey_stmt->execute();
$survey = $survey_stmt->get_result()->fetch_assoc();
 
if (!$survey) {
    die("Survey not found or is no longer active.");
}
 
// Fetch questions for this survey
$questions_stmt = $conn->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY order_num ASC");
$questions_stmt->bind_param("i", $survey_id);
$questions_stmt->execute();
$questions = $questions_stmt->get_result();
 
$submitted = false;
$page_title = $survey['title'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $responses = [];
    $questions->data_seek(0); // Reset pointer before looping again
    while ($q = $questions->fetch_assoc()) {
        $key = 'q_' . $q['id'];
        if (isset($_POST[$key]) && !empty($_POST[$key])) {
            $responses[$q['question_text']] = $_POST[$key];
        }
    }

    if (!empty($responses)) {
        $responses_json = json_encode($responses, JSON_PRETTY_PRINT);
        $user_id = $_SESSION['id'] ?? null; // Get user ID if logged in
        $respondent_type = $user_id ? 'registered_user' : 'anonymous';

        $stmt = $conn->prepare("INSERT INTO survey_responses (survey_id, user_id, respondent_type, responses) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $survey_id, $user_id, $respondent_type, $responses_json);
        $stmt->execute();
    }

    $submitted = true;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body eval-page" style="padding: 20px;">
    <div class="eval-container" style="max-width: 700px;">
        <?php if (!$submitted): ?>
            <div class="form-header">
                <div class="eval-logo-badge eval-logo-large" style="margin-bottom:15px;"><img src="Logo.png" alt="Logo"></div>
                <h2><?php echo htmlspecialchars($page_title); ?></h2>
                <p><?php echo htmlspecialchars($survey['description']); ?></p>
            </div>
            <form method="POST">
                <?php $questions->data_seek(0); while ($q = $questions->fetch_assoc()): ?>
                    <div class="question-box">
                        <label class="question-text"><?php echo htmlspecialchars($q['question_text']); ?></label>
                        <?php if ($q['type'] === 'text'): ?>
                            <input type="text" name="q_<?php echo $q['id']; ?>" class="input-group" <?php if($q['is_required']) echo 'required'; ?>>
                        <?php elseif ($q['type'] === 'textarea'): ?>
                            <textarea name="q_<?php echo $q['id']; ?>" rows="4" class="input-group" <?php if($q['is_required']) echo 'required'; ?>></textarea>
                        <?php elseif ($q['type'] === 'select'): ?>
                            <select name="q_<?php echo $q['id']; ?>" class="input-group" <?php if($q['is_required']) echo 'required'; ?>>
                                <option value="" disabled selected>-- Please select an option --</option>
                                <?php $options = json_decode($q['options']); ?>
                                <?php foreach ($options as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($q['type'] === 'radio'): ?>
                            <div class="rating-options" style="justify-content:center; margin-top:10px;">
                                <?php 
                                $options = json_decode($q['options']); 
                                $is_rating_scale = count($options) == 5 && $options[0] == '1' && $options[4] == '5';
                                ?>
                                <?php if ($is_rating_scale): ?><span class="scale-label">Lowest</span><?php endif; ?>
                                <?php foreach ($options as $opt): ?>
                                <label class="radio-label">
                                    <input type="radio" name="q_<?php echo $q['id']; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php if($q['is_required']) echo 'required'; ?>>
                                    <span><?php echo htmlspecialchars($opt); ?></span>
                                </label>
                                <?php endforeach; ?>
                                <?php if ($is_rating_scale): ?><span class="scale-label">Highest</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
                <button type="submit" class="login-btn" style="width:100%; padding:15px; font-size:18px;">Submit Survey</button>
            </form>
        <?php else: ?>
            <div class="success-screen">
                 <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                 <h2 style="color: var(--text-main); margin-bottom: 15px;">Thank You!</h2>
                 <p style="color: var(--text-muted); margin-bottom: 25px;">Your feedback has been successfully submitted.</p>
                 <a href="index.php" class="nav-link-btn" style="text-decoration:none; display:inline-block; width:auto; padding:12px 50px; background: var(--navbar-grad); color: white;">
                     <i class="fas fa-home"></i> Back to Home
                 </a>
            </div>
        <?php endif; ?>
    </div>
    <script>
        function toggleTheme() {
            const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('theme', t);
            updateThemeIcon(t);
        }
        function updateThemeIcon(t) {
            const i = document.querySelector('#themeBtn i');
            if(i) i.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const saved = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', saved);
        if(document.querySelector('#themeBtn i')) updateThemeIcon(saved);
    </script>
</body>
</html>