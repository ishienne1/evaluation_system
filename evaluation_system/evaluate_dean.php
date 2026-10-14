<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';
include_once 'process_sentiment.php';
include_once 'validate_feedback.php';

// Strict check: Visitors are only authorized to evaluate facilities, not deans
if (isset($_SESSION['role']) && $_SESSION['role'] === 'visitor') {
    $_SESSION['flash_warning'] = "Visitors are only authorized to evaluate campus facilities and services.";
    header("Location: dashboard_visitor.php");
    exit();
}

// Authentication: only faculty (or admin for inspection)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['faculty', 'admin', 'system admin'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'];
$faculty_id = (int)$_SESSION['id'];
$faculty_name = $_SESSION['full_name'] ?? 'Faculty Member';
$faculty_dept_id = (int)($_SESSION['dept_id'] ?? 0);

// Active Academic Period
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? 0;
$period_label = $active_period ? ($active_period['semester'] . ' ' . $active_period['school_year']) : 'Academic Year 2026-2027';

if (!$active_id) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <?php include 'theme_style.php'; ?>
        <title>Dean Evaluation Unavailable</title>
        <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    </head>
    <body class="dashboard-body" style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
        <div class="card-modern" style="max-width:500px; text-align:center; padding:40px;">
            <i class="fas fa-calendar-times" style="font-size:48px; color:#f59e0b; margin-bottom:15px; display:inline-block;"></i>
            <h2>No Active Academic Period</h2>
            <p style="color:var(--text-muted); margin-bottom:25px;">Dean evaluations are only available when an academic semester is actively open.</p>
            <a href="dashboard_faculty.php" class="login-btn" style="text-decoration:none; display:inline-block;">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Fetch all available Deans in the institution
$all_deans_stmt = $conn->query("
    SELECT u.id, u.full_name, u.username, u.avatar, u.dept_id,
           d.dept_name, d.dept_alias
    FROM users u
    LEFT JOIN departments d ON u.dept_id = d.id
    WHERE u.role = 'dean' AND u.status = 'active'
    ORDER BY d.dept_alias ASC, u.full_name ASC
");
$all_deans = $all_deans_stmt ? $all_deans_stmt->fetch_all(MYSQLI_ASSOC) : [];

// Resolve target Dean to evaluate:
// 1. Check URL param ?dean_id=
// 2. Otherwise default to faculty's department Dean
// 3. Otherwise first Dean available
$target_dean_id = isset($_GET['dean_id']) ? (int)$_GET['dean_id'] : 0;
$target_dean = null;

if ($target_dean_id > 0) {
    foreach ($all_deans as $d) {
        if ((int)$d['id'] === $target_dean_id) {
            $target_dean = $d;
            break;
        }
    }
}

if (!$target_dean && $faculty_dept_id > 0) {
    foreach ($all_deans as $d) {
        if ((int)$d['dept_id'] === $faculty_dept_id) {
            $target_dean = $d;
            $target_dean_id = (int)$d['id'];
            break;
        }
    }
}

if (!$target_dean && !empty($all_deans)) {
    $target_dean = $all_deans[0];
    $target_dean_id = (int)$target_dean['id'];
}

if (!$target_dean) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <?php include 'theme_style.php'; ?>
        <title>Dean Not Found</title>
        <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    </head>
    <body class="dashboard-body" style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
        <div class="card-modern" style="max-width:500px; text-align:center; padding:40px;">
            <i class="fas fa-user-slash" style="font-size:48px; color:#ef4444; margin-bottom:15px; display:inline-block;"></i>
            <h2>Dean Profile Not Found</h2>
            <p style="color:var(--text-muted); margin-bottom:25px;">No active Dean was found for your department. Please contact the administrator.</p>
            <a href="dashboard_faculty.php" class="login-btn" style="text-decoration:none; display:inline-block;">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Fetch Dean Questions
$questions_stmt = $conn->prepare("SELECT id, question_text, category FROM evaluation_questions WHERE category = 'Dean' ORDER BY id ASC");
$questions_stmt->execute();
$questions = $questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fallback if empty
if (empty($questions)) {
    $fallback_stmt = $conn->query("SELECT id, question_text, category FROM evaluation_questions WHERE category IN ('Support', 'Professionalism') ORDER BY id ASC LIMIT 8");
    $questions = $fallback_stmt ? $fallback_stmt->fetch_all(MYSQLI_ASSOC) : [];
}

// Check if faculty has already evaluated this Dean for active period
$existing_eval = null;
$existing_ratings = [];
$chk_eval = $conn->prepare("SELECT id, rating, feedback, created_at FROM evaluations WHERE student_id = ? AND faculty_id = ? AND target_name = 'Dean' AND academic_period_id = ?");
$chk_eval->bind_param("iii", $faculty_id, $target_dean_id, $active_id);
$chk_eval->execute();
$existing_eval = $chk_eval->get_result()->fetch_assoc();

if ($existing_eval) {
    $existing_eval_id = (int)$existing_eval['id'];
    $resp_q = $conn->prepare("SELECT question_id, rating FROM evaluation_responses WHERE evaluation_id = ?");
    $resp_q->bind_param("i", $existing_eval_id);
    $resp_q->execute();
    $r_res = $resp_q->get_result();
    while ($rr = $r_res->fetch_assoc()) {
        $existing_ratings[(int)$rr['question_id']] = (int)$rr['rating'];
    }
}

// Settings
$max_rating_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'");
$max_rating = (int)($max_rating_res->fetch_assoc()['setting_value'] ?? 5);
$min_feedback = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_feedback_length'")->fetch_assoc()['setting_value'] ?? 10);
$system_name = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'MCNP-ISAP';

$scale_labels = [
    1 => ['label' => 'Poor', 'color' => '#ef4444', 'desc' => 'Needs significant improvement'],
    2 => ['label' => 'Fair', 'color' => '#f97316', 'desc' => 'Below standard expectations'],
    3 => ['label' => 'Satisfactory', 'color' => '#eab308', 'desc' => 'Meets standard expectations'],
    4 => ['label' => 'Very Satisfactory', 'color' => '#3b82f6', 'desc' => 'Exceeds standard expectations'],
    5 => ['label' => 'Outstanding', 'color' => '#10b981', 'desc' => 'Exceptional academic leadership']
];

$submitted = false;
$submitted_avg_rating = 0;
$submitted_sentiment = '';
$err_msg = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dean_eval'])) {
    $ratings = $_POST['rating'] ?? [];
    $feedback = trim($_POST['general_feedback'] ?? '');

    // Validate ratings count
    $expected_count = count($questions);
    if (count($ratings) < $expected_count) {
        $err_msg = "Please answer all " . $expected_count . " evaluation questions before submitting.";
    } else {
        // Validate feedback comment
        $feedback_val = validateFeedbackComment($feedback, $min_feedback);
        if (!$feedback_val['valid']) {
            $err_msg = $feedback_val['message'];
        } else {
            // Compute average rating
            $total = 0;
            $count = 0;
            foreach ($ratings as $qid => $val) {
                $total += (int)$val;
                $count++;
            }
            $avg_rating = $count > 0 ? round($total / $count, 2) : 0;
            $submitted_avg_rating = $avg_rating;

            if ($existing_eval) {
                // Update existing evaluation
                $eval_id = (int)$existing_eval['id'];
                $upd_stmt = $conn->prepare("UPDATE evaluations SET rating = ?, feedback = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd_stmt->bind_param("dsi", $avg_rating, $feedback, $eval_id);
                $upd_stmt->execute();

                // Replace question responses
                $conn->query("DELETE FROM evaluation_responses WHERE evaluation_id = $eval_id");
                foreach ($ratings as $qid => $val) {
                    $ins_r = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                    $qid_int = (int)$qid;
                    $val_int = (int)$val;
                    $ins_r->bind_param("iii", $eval_id, $qid_int, $val_int);
                    $ins_r->execute();
                }
            } else {
                // Insert new evaluation
                $target_name = 'Dean';
                $ins_stmt = $conn->prepare("
                    INSERT INTO evaluations (student_id, faculty_id, subject_id, academic_period_id, target_name, feedback, rating)
                    VALUES (?, ?, NULL, ?, ?, ?, ?)
                ");
                $ins_stmt->bind_param("iiissd", $faculty_id, $target_dean_id, $active_id, $target_name, $feedback, $avg_rating);
                $ins_stmt->execute();
                $eval_id = $conn->insert_id;

                foreach ($ratings as $qid => $val) {
                    $ins_r = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                    $qid_int = (int)$qid;
                    $val_int = (int)$val;
                    $ins_r->bind_param("iii", $eval_id, $qid_int, $val_int);
                    $ins_r->execute();
                }
            }

            $submitted_sentiment = analyzeSentiment($feedback, $avg_rating);
            $submitted = true;
        }
    }
}

// Avatar resolution
$dean_avatar = $target_dean['avatar'] ?? 'uploads/avatars/default.png';
if (!file_exists($dean_avatar)) {
    $dean_avatar = 'uploads/avatars/default.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluate Dean — <?php echo htmlspecialchars($target_dean['full_name']); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="validate_feedback.js?v=<?php echo filemtime('validate_feedback.js'); ?>"></script>
    <style>
        .dean-eval-container {
            max-width: 900px;
            width: 100%;
            margin: 35px auto 60px auto;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 12px 45px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            position: relative;
            box-sizing: border-box;
            overflow-x: hidden;
        }
        @media (max-width: 900px) {
            .dean-eval-container { padding: 26px 20px; margin: 16px auto 40px auto; border-radius: 16px; width: 100%; }
            .dean-hero-card { padding: 18px 16px; }
        }
        @media (max-width: 600px) {
            .dean-eval-container { padding: 16px 12px; margin: 8px auto 30px auto; border-radius: 14px; }
            .question-card { padding: 16px 14px; margin-bottom: 14px; }
            .dean-hero-card { padding: 14px 12px; gap: 14px; }
            .dean-avatar-lg { width: 58px; height: 58px; }
            textarea { font-size: 16px !important; }
        }
        .dean-hero-card {
            background: linear-gradient(135deg, rgba(132, 90, 223, 0.08), rgba(99, 102, 241, 0.04));
            border: 1px solid rgba(132, 90, 223, 0.2);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .dean-profile-info {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .dean-avatar-lg {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            box-shadow: 0 6px 18px rgba(132, 90, 223, 0.25);
        }
        .question-card {
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px 24px;
            margin-bottom: 20px;
            transition: all 0.25s ease;
        }
        [data-theme="dark"] .question-card {
            background: rgba(30, 41, 59, 0.45);
        }
        .question-card:hover {
            border-color: rgba(132, 90, 223, 0.4);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.04);
        }
        .question-card.answered {
            border-left: 4px solid #10b981;
        }
        .rating-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-top: 18px;
        }
        @media (max-width: 680px) {
            .rating-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }
        .rating-btn-label {
            display: block;
            position: relative;
            cursor: pointer;
            user-select: none;
        }
        .rating-btn-label input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        .rating-btn-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 14px 8px;
            border-radius: 12px;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-align: center;
        }
        .rating-btn-score {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .rating-btn-text {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
            line-height: 1.2;
        }
        .rating-btn-label:hover .rating-btn-inner {
            border-color: var(--accent);
            transform: translateY(-2px);
            background: var(--purple-glow);
        }
        .rating-btn-label:hover .rating-btn-score,
        .rating-btn-label:hover .rating-btn-text {
            color: var(--accent);
        }
        .rating-btn-label input[type="radio"]:checked + .rating-btn-inner {
            background: var(--accent);
            border-color: var(--accent);
            box-shadow: 0 4px 14px rgba(132, 90, 223, 0.35);
            transform: scale(1.03);
        }
        .rating-btn-label input[type="radio"]:checked + .rating-btn-inner .rating-btn-score,
        .rating-btn-label input[type="radio"]:checked + .rating-btn-inner .rating-btn-text {
            color: #ffffff !important;
        }
        .progress-pill {
            background: rgba(132, 90, 223, 0.12);
            color: var(--accent);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Top Navigation Header -->
    <header class="top-nav" style="border-bottom: 1px solid var(--border-color); background: var(--header-bg); backdrop-filter: blur(10px); padding: 14px 28px; position: sticky; top: 0; z-index: 100;">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <a href="dashboard_faculty.php" style="display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-size: 13.5px; font-weight: 600; padding: 7px 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <i class="fas fa-arrow-left"></i> Faculty Dashboard
                </a>
                <span style="font-size: 16px; font-weight: 800; color: var(--text-main);">
                    <i class="fas fa-user-tie" style="color: var(--accent); margin-right: 6px;"></i> Dean Leadership Appraisal
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="progress-pill">
                    <i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($period_label); ?>
                </span>
            </div>
        </div>
    </header>

    <div class="dean-eval-container">

        <?php if ($submitted): ?>
            <!-- Success Screen -->
            <div style="text-align: center; padding: 40px 20px;">
                <div style="width: 80px; height: 80px; background: rgba(16, 185, 129, 0.15); color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 38px; margin: 0 auto 24px auto;">
                    <i class="fas fa-check"></i>
                </div>
                <h2 style="font-size: 26px; font-weight: 800; margin-bottom: 8px; color: var(--text-main);">
                    Dean Evaluation Submitted Successfully!
                </h2>
                <p style="color: var(--text-muted); font-size: 14.5px; max-width: 540px; margin: 0 auto 28px auto; line-height: 1.6;">
                    Thank you, <strong><?php echo htmlspecialchars($faculty_name); ?></strong>. Your leadership evaluation and constructive feedback for <strong><?php echo htmlspecialchars($target_dean['full_name']); ?></strong> have been securely recorded.
                </p>

                <div style="display: inline-flex; flex-direction: column; align-items: center; background: rgba(132, 90, 223, 0.06); border: 1px solid rgba(132, 90, 223, 0.2); border-radius: 16px; padding: 22px 36px; margin-bottom: 30px;">
                    <span style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Your Computed Rating</span>
                    <div style="font-size: 42px; font-weight: 900; color: #f59e0b; margin: 6px 0;">
                        <?php echo number_format($submitted_avg_rating, 2); ?> <span style="font-size: 22px;">★</span>
                    </div>
                    <?php
                    $sentiment_color = '#10b981';
                    $sentiment_label = 'Positive Sentiment';
                    if (strtolower($submitted_sentiment) === 'negative') {
                        $sentiment_color = '#ef4444';
                        $sentiment_label = 'Constructive / Needs Improvement';
                    } elseif (strtolower($submitted_sentiment) === 'neutral') {
                        $sentiment_color = '#64748b';
                        $sentiment_label = 'Neutral Assessment';
                    }
                    ?>
                    <span style="background: <?php echo $sentiment_color; ?>15; color: <?php echo $sentiment_color; ?>; font-weight: 800; font-size: 12px; padding: 4px 12px; border-radius: 12px;">
                        <i class="fas fa-heart"></i> <?php echo $sentiment_label; ?>
                    </span>
                </div>

                <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                    <a href="dashboard_faculty.php#dean-eval" class="login-btn" style="width: auto; padding: 12px 28px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 700;">
                        <i class="fas fa-arrow-left"></i> Return to Faculty Dashboard
                    </a>
                </div>
            </div>

        <?php else: ?>

            <?php if (!empty($err_msg)): ?>
                <div style="background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444; border-radius: 8px; padding: 14px 18px; margin-bottom: 25px; color: #ef4444; font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-exclamation-circle" style="font-size: 18px;"></i>
                    <span><?php echo htmlspecialchars($err_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($existing_eval): ?>
                <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 12px; padding: 14px 18px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle" style="color: #3b82f6; font-size: 18px;"></i>
                        <div>
                            <strong style="font-size: 13.5px; color: var(--text-main);">Existing Evaluation Found</strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                You evaluated Dean <?php echo htmlspecialchars($target_dean['full_name']); ?> on <?php echo date('M d, Y', strtotime($existing_eval['created_at'])); ?> (Rating: <strong><?php echo number_format($existing_eval['rating'], 2); ?> ★</strong>). You can update your assessment below.
                            </div>
                        </div>
                    </div>
                    <span style="background: rgba(16, 185, 129, 0.15); color: #059669; font-size: 12px; font-weight: 800; padding: 4px 12px; border-radius: 12px;">
                        <i class="fas fa-check"></i> Previously Evaluated
                    </span>
                </div>
            <?php endif; ?>

            <!-- Dean Hero Card -->
            <div class="dean-hero-card">
                <div class="dean-profile-info">
                    <img src="<?php echo htmlspecialchars($dean_avatar); ?>" alt="Dean" class="dean-avatar-lg">
                    <div>
                        <div style="font-size: 11.5px; font-weight: 800; text-transform: uppercase; color: var(--accent); letter-spacing: 0.6px; margin-bottom: 4px;">
                            College Dean & Academic Head
                        </div>
                        <h2 style="font-size: 22px; font-weight: 800; margin: 0 0 4px 0; color: var(--text-main);">
                            <?php echo htmlspecialchars($target_dean['full_name']); ?>
                        </h2>
                        <div style="font-size: 13.5px; color: var(--text-muted); font-weight: 600;">
                            <?php echo htmlspecialchars($target_dean['dept_alias'] ?? 'Department'); ?> — <?php echo htmlspecialchars($target_dean['dept_name'] ?? 'College'); ?>
                        </div>
                    </div>
                </div>

                <!-- Dean Selector Dropdown (if faculty wants to switch Dean) -->
                <?php if (count($all_deans) > 1): ?>
                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                    <label for="deanSelect" style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">
                        Switch College Dean:
                    </label>
                    <select id="deanSelect" onchange="if(this.value) window.location.href='evaluate_dean.php?dean_id='+this.value;" style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); font-weight: 600; font-size: 13px;">
                        <?php foreach ($all_deans as $d_opt): ?>
                            <option value="<?php echo $d_opt['id']; ?>" <?php echo ((int)$d_opt['id'] === $target_dean_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d_opt['dept_alias'] . ' — ' . $d_opt['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- Instructions Banner -->
            <div style="background: rgba(132, 90, 223, 0.05); border: 1px solid rgba(132, 90, 223, 0.15); border-radius: 12px; padding: 16px 20px; margin-bottom: 30px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-shield-alt" style="font-size: 24px; color: var(--accent);"></i>
                    <div>
                        <h4 style="margin: 0 0 2px 0; font-size: 14px; font-weight: 800; color: var(--text-main);">
                            Confidential 360° Academic Leadership Review
                        </h4>
                        <p style="margin: 0; font-size: 12.5px; color: var(--text-muted);">
                            Rate your College Dean objectively across key administrative, leadership, and support dimensions. All individual responses are held in strict institutional confidence.
                        </p>
                    </div>
                </div>
                <div id="liveQuestionCounter" class="progress-pill">
                    <i class="fas fa-check-circle"></i> <span id="answeredCount">0</span> / <?php echo count($questions); ?> Answered
                </div>
            </div>

            <!-- Evaluation Form -->
            <form id="deanEvalForm" method="POST" action="evaluate_dean.php?dean_id=<?php echo $target_dean_id; ?>">
                
                <?php foreach ($questions as $idx => $q): 
                    $qid = (int)$q['id'];
                    $selected_val = $existing_ratings[$qid] ?? 0;
                ?>
                <div class="question-card <?php echo $selected_val > 0 ? 'answered' : ''; ?>" id="qcard_<?php echo $qid; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 14px;">
                        <span style="width: 28px; height: 28px; border-radius: 50%; background: var(--accent); color: white; display: flex; align-items: center; justify-content: center; font-size: 12.5px; font-weight: 800; flex-shrink: 0; margin-top: 2px;">
                            <?php echo $idx + 1; ?>
                        </span>
                        <div style="flex: 1;">
                            <div style="font-size: 15.5px; font-weight: 700; line-height: 1.5; color: var(--text-main);">
                                <?php echo htmlspecialchars($q['question_text']); ?>
                            </div>
                            <span style="display: inline-block; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-top: 4px;">
                                Dimension: Leadership & Governance
                            </span>
                        </div>
                    </div>

                    <!-- 1-5 Rating Scale Buttons -->
                    <div class="rating-grid">
                        <?php for ($val = 1; $val <= $max_rating; $val++): 
                            $meta = $scale_labels[$val] ?? ['label' => "Rating $val", 'color' => '#845adf', 'desc' => ''];
                            $is_checked = ($selected_val === $val);
                        ?>
                        <label class="rating-btn-label">
                            <input type="radio" 
                                   name="rating[<?php echo $qid; ?>]" 
                                   value="<?php echo $val; ?>" 
                                   <?php echo $is_checked ? 'checked' : ''; ?>
                                   onchange="onRatingSelected(<?php echo $qid; ?>)"
                                   required>
                            <div class="rating-btn-inner">
                                <span class="rating-btn-score"><?php echo $val; ?></span>
                                <span class="rating-btn-text"><?php echo $meta['label']; ?></span>
                            </div>
                        </label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- General Feedback Section -->
                <div class="card-modern" style="margin-top: 30px; padding: 28px; background: rgba(255, 255, 255, 0.6); border: 1px solid var(--border-color);">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                        <i class="fas fa-comment-dots" style="color: var(--accent); font-size: 20px;"></i>
                        <h3 style="margin: 0; font-size: 16.5px; font-weight: 800; color: var(--text-main);">
                            Qualitative Remarks & Constructive Recommendations
                        </h3>
                    </div>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 0 0 16px 0;">
                        Provide thoughtful, professional feedback regarding the Dean's leadership, support for faculty, and college administration. (Minimum <?php echo $min_feedback; ?> characters required).
                    </p>

                    <div style="position: relative;">
                        <textarea name="general_feedback" 
                                  id="general_feedback" 
                                  rows="5" 
                                  placeholder="Type your constructive comments, observations, and recommendations for Dean <?php echo htmlspecialchars($target_dean['full_name']); ?>..." 
                                  required
                                  style="width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); font-family: inherit; font-size: 14px; line-height: 1.6; resize: vertical; box-sizing: border-box;"><?php echo htmlspecialchars($existing_eval['feedback'] ?? ''); ?></textarea>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                            <span id="feedbackCharCount" style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                                0 / <?php echo $min_feedback; ?> minimum characters
                            </span>
                            <span id="feedbackValidationMsg" style="font-size: 12px; font-weight: 700;"></span>
                        </div>
                    </div>
                </div>

                <!-- Submit Action Buttons -->
                <div style="display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 35px; flex-wrap: wrap;">
                    <a href="dashboard_faculty.php#dean-eval" style="padding: 12px 22px; border-radius: 10px; background: rgba(0, 0, 0, 0.05); color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 13.5px; border: 1px solid var(--border-color);">
                        Cancel
                    </a>
                    <button type="submit" name="submit_dean_eval" id="submitBtn" class="login-btn" style="width: auto; padding: 12px 32px; font-size: 14px; font-weight: 800; box-shadow: 0 6px 18px rgba(132, 90, 223, 0.35); display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-paper-plane"></i> <?php echo $existing_eval ? 'Update Evaluation' : 'Submit Dean Evaluation'; ?>
                    </button>
                </div>

            </form>
        <?php endif; ?>

    </div>

    <script>
        const totalQuestions = <?php echo count($questions); ?>;
        const minFeedbackLength = <?php echo $min_feedback; ?>;

        function updateAnsweredCount() {
            let answered = 0;
            const radios = document.querySelectorAll('input[type="radio"]:checked');
            answered = radios.length;
            
            const counterEl = document.getElementById('answeredCount');
            if (counterEl) {
                counterEl.innerText = answered;
            }
            const pillEl = document.getElementById('liveQuestionCounter');
            if (pillEl) {
                if (answered === totalQuestions) {
                    pillEl.style.background = 'rgba(16, 185, 129, 0.15)';
                    pillEl.style.color = '#10b981';
                } else {
                    pillEl.style.background = 'rgba(132, 90, 223, 0.12)';
                    pillEl.style.color = 'var(--accent)';
                }
            }
        }

        function onRatingSelected(qid) {
            const card = document.getElementById('qcard_' + qid);
            if (card) {
                card.classList.add('answered');
            }
            updateAnsweredCount();
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateAnsweredCount();

            const feedbackInput = document.getElementById('general_feedback');
            const countLabel = document.getElementById('feedbackCharCount');
            const valMsg = document.getElementById('feedbackValidationMsg');

            if (feedbackInput) {
                function validateLive() {
                    const text = feedbackInput.value.trim();
                    const len = text.length;

                    if (countLabel) {
                        countLabel.innerText = `${len} characters (minimum ${minFeedbackLength})`;
                        if (len >= minFeedbackLength) {
                            countLabel.style.color = '#10b981';
                        } else {
                            countLabel.style.color = 'var(--text-muted)';
                        }
                    }

                    // Check using window.validateFeedbackComment if loaded
                    if (typeof window.validateFeedbackComment === 'function' && len > 0) {
                        const check = window.validateFeedbackComment(text, minFeedbackLength);
                        if (!check.valid) {
                            valMsg.innerText = check.message;
                            valMsg.style.color = '#ef4444';
                        } else {
                            valMsg.innerText = '✓ Valid constructive remarks';
                            valMsg.style.color = '#10b981';
                        }
                    } else if (valMsg) {
                        valMsg.innerText = '';
                    }
                }

                feedbackInput.addEventListener('input', validateLive);
                validateLive();
            }

            // Form Submit confirmation
            const form = document.getElementById('deanEvalForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const radios = document.querySelectorAll('input[type="radio"]:checked');
                    if (radios.length < totalQuestions) {
                        e.preventDefault();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Incomplete Evaluation',
                                text: `Please provide a rating for all ${totalQuestions} questions before submitting.`
                            });
                        } else {
                            alert(`Please provide a rating for all ${totalQuestions} questions before submitting.`);
                        }
                        return false;
                    }

                    const fbText = feedbackInput ? feedbackInput.value.trim() : '';
                    if (typeof window.validateFeedbackComment === 'function') {
                        const check = window.validateFeedbackComment(fbText, minFeedbackLength);
                        if (!check.valid) {
                            e.preventDefault();
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Invalid Feedback',
                                    text: check.message
                                });
                            } else {
                                alert(check.message);
                            }
                            return false;
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
