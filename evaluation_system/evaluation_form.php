<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// If user is a faculty member, redirect directly to Dean evaluation portal
if (isset($_SESSION['role']) && $_SESSION['role'] === 'faculty') {
    $dean_param = isset($_GET['dean_id']) ? ('?dean_id=' . (int)$_GET['dean_id']) : '';
    header("Location: evaluate_dean.php$dean_param");
    exit();
}

// Strict check: Visitors are only authorized to evaluate facilities, not faculty
if (isset($_SESSION['role']) && $_SESSION['role'] === 'visitor') {
    $_SESSION['flash_warning'] = "Visitors are only authorized to evaluate campus facilities and services.";
    header("Location: dashboard_visitor.php");
    exit();
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['student', 'dean'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'];
$user_id = (int)$_SESSION['id'];
$is_dean = ($user_role === 'dean');
$back_url = $is_dean ? 'dashboard_dean.php#instructors-eval' : 'dashboard_student.php#classes-section';
$dash_url = $is_dean ? 'dashboard_dean.php' : 'dashboard_student.php';

$eval_type = $_GET['type'] ?? 'faculty';
$target_name = $_GET['target'] ?? 'Faculty';
$category_filter = $_GET['cat'] ?? 'Teaching';

// Get IDs from URL for pre-selection
$get_faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$get_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$questions_stmt = $conn->prepare("SELECT * FROM evaluation_questions WHERE category IN ('Teaching', 'Professionalism', 'Assessment', 'Support', 'General') ORDER BY id ASC");
$questions_stmt->execute();
$questions = $questions_stmt->get_result();

// Get active period to ensure evaluation is linked correctly
$active_period = $conn->query("SELECT id FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? null;

// Get dynamic max rating
$max_rating_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_rating'");
$max_rating = (int)($max_rating_res->fetch_assoc()['setting_value'] ?? 5);

// Get dynamic system name and feedback length requirement
$system_name = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'MCNP-ISAP';
$min_feedback = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_feedback_length'")->fetch_assoc()['setting_value'] ?? 0);

$submitted = false;
$submitted_faculty_name = "";
$submitted_subject_code = "";

$student_course_alias = '';
$student_course_name = '';

if ($is_dean) {
    // Dean context
    $dean_dept_id = (int)($_SESSION['dept_id'] ?? 0);
    $dept_q = $conn->prepare("SELECT dept_name, dept_alias FROM departments WHERE id = ?");
    $dept_q->bind_param("i", $dean_dept_id);
    $dept_q->execute();
    $dept_row = $dept_q->get_result()->fetch_assoc();
    $student_course_alias = $dept_row['dept_alias'] ?? 'Dean Evaluation';
    $student_course_name = $dept_row['dept_name'] ?? 'Department Head Review';

    // Fetch classes for Dean's department
    $dean_classes_stmt = $conn->prepare(
        "SELECT 
            c.id as class_id, f.id as faculty_id, f.full_name as faculty_name,
            s.id as subject_id, s.subject_code, s.subject_description,
            (SELECT COUNT(*) FROM evaluations WHERE student_id = ? AND faculty_id = f.id AND subject_id = s.id AND academic_period_id = ?) as is_evaluated
        FROM classes c
        JOIN users f ON c.faculty_id = f.id
        JOIN subjects s ON c.subject_id = s.id
        WHERE c.period_id = ? AND (f.dept_id = ? OR s.dept_id = ?)
        ORDER BY f.full_name ASC, s.subject_code ASC"
    );
    $dean_classes_stmt->bind_param("iiiii", $user_id, $active_id, $active_id, $dean_dept_id, $dean_dept_id);
    $dean_classes_stmt->execute();
    $enrolled_classes = $dean_classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    // Student context
    $student_id = $user_id;
    $st_query = $conn->prepare("
        SELECT u.course_id, u.dept_id, u.school, c.course_alias, c.course_name 
        FROM users u 
        LEFT JOIN courses c ON u.course_id = c.id 
        WHERE u.id = ?
    ");
    $st_query->bind_param("i", $student_id);
    $st_query->execute();
    $st_data = $st_query->get_result()->fetch_assoc();

    $student_course_id = $st_data['course_id'] ?? ($_SESSION['course_id'] ?? null);
    $student_dept_id   = $st_data['dept_id'] ?? ($_SESSION['dept_id'] ?? null);
    $student_course_alias = $st_data['course_alias'] ?? '';
    $student_course_name  = $st_data['course_name'] ?? '';

    // 1. Fetch student's enrolled classes for the active period
    $my_classes_stmt = $conn->prepare(
        "SELECT 
            c.id as class_id, f.id as faculty_id, f.full_name as faculty_name,
            s.id as subject_id, s.subject_code, s.subject_description,
            (SELECT COUNT(*) FROM evaluations WHERE student_id = ? AND faculty_id = f.id AND subject_id = s.id AND academic_period_id = ?) as is_evaluated
        FROM classes c
        JOIN users f ON c.faculty_id = f.id
        JOIN subjects s ON c.subject_id = s.id
        WHERE c.period_id = ? 
          AND (
              (
                  c.id IN (SELECT class_id FROM enrollments WHERE student_id = ?)
                  AND (? = 0 OR s.course_id = ? OR s.course_id IS NULL)
              )
              OR (
                  NOT EXISTS (SELECT 1 FROM enrollments WHERE student_id = ?)
                  AND (
                      (? > 0 AND s.course_id = ?)
                      OR (? = 0 AND s.course_id IS NULL AND s.dept_id = ?)
                  )
              )
          )
        ORDER BY s.subject_code ASC"
    );
    $my_classes_stmt->bind_param(
        "iiiiiiiiiii",
        $student_id, $active_id, $active_id,
        $student_id, $student_course_id, $student_course_id,
        $student_id,
        $student_course_id, $student_course_id,
        $student_course_id, $student_dept_id
    );
    $my_classes_stmt->execute();
    $enrolled_classes = $my_classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Auto-resolve to first unevaluated class if not specified in URL
if ($get_faculty_id <= 0 || $get_subject_id <= 0) {
    foreach ($enrolled_classes as $ec) {
        if (!$ec['is_evaluated']) {
            $get_faculty_id = (int)$ec['faculty_id'];
            $get_subject_id = (int)$ec['subject_id'];
            break;
        }
    }
}

// If still empty, fall back to first class if available
if (($get_faculty_id <= 0 || $get_subject_id <= 0) && !empty($enrolled_classes)) {
    $get_faculty_id = (int)$enrolled_classes[0]['faculty_id'];
    $get_subject_id = (int)$enrolled_classes[0]['subject_id'];
}

// If no valid class found at all, redirect back
if ($get_faculty_id <= 0 || $get_subject_id <= 0) {
    header("Location: $back_url");
    exit();
}

// Check existing evaluation for this active period
$existing_eval_id = null;
$existing_ratings = [];
$existing_feedback = '';
$existing_score = 0;

if ($active_id) {
    $ev_chk = $conn->prepare("SELECT id, feedback, rating FROM evaluations WHERE student_id = ? AND faculty_id = ? AND subject_id = ? AND academic_period_id = ?");
    $ev_chk->bind_param("iiii", $user_id, $get_faculty_id, $get_subject_id, $active_id);
    $ev_chk->execute();
    $ev_res = $ev_chk->get_result();
    if ($ev_row = $ev_res->fetch_assoc()) {
        $existing_eval_id = (int)$ev_row['id'];
        $existing_feedback = $ev_row['feedback'] ?? '';
        $existing_score = (float)($ev_row['rating'] ?? 0);

        if (!$is_dean) {
            // Students are limited to 1 evaluation per class
            header("Location: dashboard_student.php?msg=already_evaluated");
            exit();
        }

        // Deans can view or update their evaluation: load existing question responses
        $resp_q = $conn->prepare("SELECT question_id, rating FROM evaluation_responses WHERE evaluation_id = ?");
        $resp_q->bind_param("i", $existing_eval_id);
        $resp_q->execute();
        $r_res = $resp_q->get_result();
        while($rr = $r_res->fetch_assoc()) {
            $existing_ratings[(int)$rr['question_id']] = (int)$rr['rating'];
        }
    }
}

// Fetch exact details of the locked Faculty
$fac_query = $conn->prepare("SELECT full_name, username FROM users WHERE id = ? AND role = 'faculty'");
$fac_query->bind_param("i", $get_faculty_id);
$fac_query->execute();
$locked_fac_row = $fac_query->get_result()->fetch_assoc();
$locked_faculty_name = $locked_fac_row['full_name'] ?? 'Assigned Faculty';
$locked_faculty_empid = $locked_fac_row['username'] ?? '';

// Fetch exact details of the locked Subject
$sub_query = $conn->prepare("SELECT subject_code, subject_description FROM subjects WHERE id = ?");
$sub_query->bind_param("i", $get_subject_id);
$sub_query->execute();
$locked_sub_row = $sub_query->get_result()->fetch_assoc();
$locked_subject_code = $locked_sub_row['subject_code'] ?? 'Subject';
$locked_subject_description = $locked_sub_row['subject_description'] ?? '';

// Include sentiment engine for success screen preview
include_once 'process_sentiment.php';
include_once 'validate_feedback.php';
$submitted_sentiment = '';
$submitted_avg_rating = 0;

if (isset($_POST['submit_eval'])) {
    $faculty_id = (int)($_POST['faculty_id'] ?? $get_faculty_id);
    $subject_id = (int)($_POST['subject_id'] ?? $get_subject_id);
    $general_feedback = trim($_POST['general_feedback'] ?? '');
    
    // Strict validation: Reject random characters, keyboard mash, symbols, or emoji spam
    $feedback_val = validateFeedbackComment($general_feedback, $min_feedback);
    if (!$feedback_val['valid']) {
        $err_msg = urlencode($feedback_val['message']);
        header("Location: evaluation_form.php?faculty_id=$faculty_id&subject_id=$subject_id&err=invalid_feedback&msg=$err_msg");
        exit();
    }
    
    // Names for the success screen
    $submitted_faculty_name = $locked_faculty_name;
    $submitted_subject_code = $locked_subject_code . ' - ' . $locked_subject_description;

    $total_rating = array_sum($_POST['rating']);
    $count = count($_POST['rating']);
    $avg_rating = $count > 0 ? $total_rating / $count : 0;
    $submitted_avg_rating = $avg_rating;

    if ($existing_eval_id && $is_dean) {
        // Update existing Dean evaluation
        $stmt = $conn->prepare("UPDATE evaluations SET target_name = ?, feedback = ?, rating = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("ssdi", $target_name, $general_feedback, $avg_rating, $existing_eval_id);
        $stmt->execute();
        $eval_id = $existing_eval_id;

        // Replace question responses
        $conn->query("DELETE FROM evaluation_responses WHERE evaluation_id = $eval_id");
        foreach ($_POST['rating'] as $question_id => $rating) {
            $stmt2 = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
            $stmt2->bind_param("iii", $eval_id, $question_id, $rating);
            $stmt2->execute();
        }
    } else {
        // Insert new evaluation
        $stmt = $conn->prepare("INSERT INTO evaluations (student_id, faculty_id, subject_id, academic_period_id, target_name, feedback, rating) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiisd", $user_id, $faculty_id, $subject_id, $active_id, $target_name, $general_feedback, $avg_rating);
        $stmt->execute();
        $eval_id = $conn->insert_id;
        
        foreach ($_POST['rating'] as $question_id => $rating) {
            $stmt2 = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
            $stmt2->bind_param("iii", $eval_id, $question_id, $rating);
            $stmt2->execute();
        }
    }
    
    // Analyze sentiment for success screen preview
    $submitted_sentiment = analyzeSentiment($general_feedback, $avg_rating);
    $submitted = true;
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Evaluation Form</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="validate_feedback.js?v=<?php echo filemtime('validate_feedback.js'); ?>"></script>
    <style>
        .eval-container {
            max-width: 880px;
            margin: 40px auto;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 44px 48px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            position: relative;
            z-index: 2;
        }
        [data-theme="dark"] .eval-container {
            background: var(--card-bg);
        }
        .question-box {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            padding: 24px 26px;
            border-radius: 14px;
            margin-bottom: 22px;
            border: 1px solid var(--border-color);
            transition: all 0.3s;
        }
        [data-theme="dark"] .question-box {
            background: rgba(30, 41, 59, 0.5);
        }
        .question-text {
            font-size: 15.5px;
            line-height: 1.5;
            font-weight: 600;
            color: var(--text-main);
        }
        .rating-scale { display: flex; justify-content: space-between; align-items: center; padding: 0 10px; margin-top: 18px; }
        .rating-options { display: flex; gap: 20px; justify-content: center; flex: 1; }
        .scale-label { font-size: 13.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .radio-label { display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 5px; border-radius: 8px; transition: all 0.3s; }
        .radio-label input[type="radio"] { display: none; }
        .radio-label span {
            width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--input-bg); border: 2px solid var(--border-color);
            font-size: 17px; font-weight: 700; color: var(--text-muted); transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1);
        }
        .radio-label:hover span { border-color: var(--accent); color: var(--accent); background: var(--purple-glow); }
        .radio-label input[type="radio"]:checked + span {
            background: var(--accent); color: white; border-color: var(--accent);
            transform: scale(1.15); box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }
        .form-header h2 {
            color: var(--text-main);
            font-size: 30px;
            font-weight: 800;
        }
        .form-header p {
            color: var(--text-muted);
            font-size: 15px;
        }
        .section-divider {
            border: none;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--accent), transparent);
            margin: 25px 0;
        }
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--navbar-grad);
            width: 0%;
            transition: width 0.3s;
            border-radius: 3px;
        }
        /* Success Screen Styles */
        .success-screen {
            text-align: center;
            padding: 40px 0;
            animation: slideInUp 0.6s ease-out;
        }
        .success-icon {
            font-size: 80px;
            color: #43e97b;
            margin-bottom: 20px;
            display: inline-block;
            animation: bounceIn 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            70% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        .sentiment-result {
            margin-top: 15px;
            padding: 12px 25px;
            border-radius: 30px;
            display: inline-block;
        }
        /* Mobile & Tablet responsive for eval form */
        .eval-info-card {
            background: var(--bg-color);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
            box-sizing: border-box;
        }
        .eval-locked-card {
            width: 100%;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 14.5px;
            font-weight: 600;
            background: var(--input-bg);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            box-sizing: border-box;
            cursor: default;
            user-select: none;
        }
        .eval-locked-text {
            min-width: 0;
            flex: 1;
            overflow-wrap: break-word;
            word-break: break-word;
            line-height: 1.4;
        }
        .eval-locked-badge {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            background: rgba(0,0,0,0.05);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            white-space: nowrap;
        }
        [data-theme="dark"] .eval-locked-badge {
            background: rgba(255,255,255,0.08);
        }

        /* Tablet & iPad responsive (768px - 1024px) */
        @media (max-width: 900px) {
            body.eval-page { padding: 18px 14px !important; }
            .eval-container { padding: 28px 22px !important; margin: 16px auto !important; border-radius: 16px !important; width: 100% !important; }
            .form-header h2 { font-size: 24px; }
        }
        @media (max-width: 768px) {
            .eval-select-grid { grid-template-columns: 1fr !important; gap: 14px !important; }
            .eval-info-card { padding: 16px 14px !important; }
        }
        /* Mobile responsive for iPhone & Android (< 600px) */
        @media (max-width: 600px) {
            body.eval-page { padding: 10px 8px !important; }
            .eval-container { padding: 18px 14px !important; margin: 8px auto !important; border-radius: 14px !important; width: 100% !important; }
            .form-header { margin-bottom: 20px; padding-bottom: 14px; }
            .form-header h2 { font-size: 20px; }
            .question-box { padding: 16px 14px !important; margin-bottom: 16px !important; }
            .question-text { font-size: 14px !important; }
            
            .rating-scale { flex-direction: column; gap: 10px; position: relative; padding-bottom: 22px; padding-left: 0; padding-right: 0; margin-top: 14px; }
            .rating-options { gap: 4px; justify-content: space-between; width: 100%; }
            .radio-label { padding: 2px; flex: 1; display: flex; justify-content: center; }
            .radio-label span { width: 40px; height: 40px; font-size: 14px; }
            
            .scale-label { position: absolute; bottom: 0; font-size: 10.5px; opacity: 0.75; }
            .scale-label-low { left: 4px; }
            .scale-label-high { right: 4px; }

            .confidentiality-card { padding: 14px 12px !important; gap: 12px !important; align-items: flex-start !important; }
            .confidentiality-card h4 { font-size: 14px !important; }
            .confidentiality-card p { font-size: 12px !important; line-height: 1.4 !important; }

            .eval-floating-theme { top: 12px !important; right: 12px !important; }
            #feedbackText { font-size: 16px !important; } /* Prevents iOS Safari auto-zoom */
        }
        @media (max-width: 360px) {
            .radio-label span { width: 34px; height: 34px; font-size: 13px; }
        }
    </style>
</head>
<body class="dashboard-body eval-page">

    <!-- Floating Theme Toggle -->
    <div class="eval-floating-theme" style="position: absolute; top: 25px; right: 30px; z-index: 1000;">
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()">
            <i class="fas fa-moon"></i>
        </div>
    </div>

    <div class="eval-container">
        <?php if (!$submitted): ?>

        <div class="form-header">
            <div style="display:flex; justify-content:center; margin-bottom:15px;">
                <div class="eval-logo-badge eval-logo-large">
                    <img src="Logo.png" alt="Logo">
                </div>
            </div>
            <?php if ($is_dean): ?>
            <h2><i class="fas fa-clipboard-check" style="color:var(--accent);"></i> Dean Faculty Evaluation</h2>
            <p style="margin-bottom: 20px;">Institutional Quality Assurance & Faculty Evaluation for <strong><?php echo htmlspecialchars($student_course_name); ?></strong>.</p>
            <?php else: ?>
            <h2>📋 Evaluation Form</h2>
            <p style="margin-bottom: 20px;">Your feedback is <strong>anonymous</strong> and helps <?php echo htmlspecialchars($system_name); ?> maintain institutional excellence.</p>
            <?php endif; ?>
        </div>

        <?php if ($existing_eval_id && $is_dean): ?>
        <div style="background: rgba(132, 90, 223, 0.09); border: 1.5px solid rgba(132, 90, 223, 0.35); border-radius: 14px; padding: 14px 18px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; gap: 12px; color: var(--text-main);">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:38px; height:38px; border-radius:10px; background:rgba(132, 90, 223, 0.2); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:18px; flex-shrink:0;">
                    <i class="fas fa-edit"></i>
                </div>
                <div>
                    <strong style="color: var(--accent); font-size:14.5px;">Dean Evaluation Update Mode</strong>
                    <div style="font-size: 13px; color: var(--text-muted); margin-top:2px;">
                        You have previously evaluated this faculty member for this subject (Previous rating: <strong><?php echo number_format($existing_score, 2); ?> ★</strong>). Your previous responses are pre-filled below and submitting will update your official evaluation.
                    </div>
                </div>
            </div>
            <span class="category-badge" style="background:#10b981; color:white; font-size:11px; padding:5px 10px; flex-shrink:0;">
                <i class="fas fa-check-circle"></i> Evaluated
            </span>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['err']) && $_GET['err'] === 'invalid_feedback'): ?>
        <div style="background: rgba(239, 68, 68, 0.1); border: 1.5px solid #ef4444; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; color: #ef4444;">
            <i class="fas fa-exclamation-triangle" style="font-size: 20px; flex-shrink: 0;"></i>
            <div style="font-size: 13.5px; font-weight: 600; line-height: 1.4;">
                <strong>Invalid Comments / Feedback:</strong> <?php echo htmlspecialchars($_GET['msg'] ?? 'Comments cannot consist of random characters (e.g. "easbhdjdvbafakhfals"), keyboard mash, excessive symbols, or emojis only. Please provide constructive feedback in English or Filipino.'); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Confidentiality Disclaimer Banner -->
        <div class="confidentiality-card" style="background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(99,102,241,0.05)); border: 1px solid rgba(37,99,235,0.25); border-radius: 14px; padding: 16px 20px; margin-bottom: 25px; display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(37,99,235,0.15); display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--accent); flex-shrink: 0;">
                <i class="fas fa-user-shield"></i>
            </div>
            <div style="flex: 1;">
                <h4 style="margin: 0 0 4px; font-size: 15px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-lock" style="color: var(--accent); font-size: 13px;"></i> <?php echo $is_dean ? 'Department Leadership Review' : 'Disclaimer: All Evaluation is Confidential'; ?>
                </h4>
                <p style="margin: 0; font-size: 13px; line-height: 1.45; color: var(--text-muted);">
                    <?php if ($is_dean): ?>
                        As Dean / Department Head, your evaluation provides critical administrative oversight for faculty promotion, retention, and quality assurance.
                    <?php else: ?>
                        All individual responses, ratings, and feedback in this evaluation are strictly confidential, anonymized, and protected under institutional quality assurance protocols. Your honest feedback directly contributes to faculty development and academic excellence.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="progress-bar">
            <div class="progress-fill" id="progressFill"></div>
        </div>

        <form method="POST" id="evalForm">

            <!-- STEP 1: Ratings -->
            <div id="step-1">
                <div style="text-align: left; margin-bottom: 15px; font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <span style="background: var(--accent); color: white; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">1</span> Step 1: Performance Ratings
                </div>
                
                <?php if($eval_type == 'faculty'): ?>
                <div class="eval-info-card">
                    <?php if (!empty($student_course_alias)): ?>
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border-color); font-size:13px;">
                        <span style="color:var(--text-muted); min-width:0;"><?php echo $is_dean ? 'Department:' : 'Program:'; ?> <strong style="color:var(--text-main); overflow-wrap:break-word;"><?php echo htmlspecialchars($student_course_alias . ' - ' . $student_course_name); ?></strong></span>
                        <span style="background:var(--purple-glow); color:var(--accent); padding:3px 10px; border-radius:6px; font-weight:700; font-size:11px; flex-shrink:0;"><i class="fas <?php echo $is_dean ? 'fa-user-tie' : 'fa-graduation-cap'; ?>"></i> <?php echo $is_dean ? 'Dean Review' : htmlspecialchars($student_course_alias) . ' Faculty'; ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;" class="eval-select-grid">
                        <div style="min-width:0;">
                            <label style="font-weight:600; display:block; margin-bottom:6px; font-size:14px; color:var(--text-main);">
                                <i class="fas fa-user-tie" style="color:var(--accent); margin-right:4px;"></i> Faculty Instructor
                            </label>
                            <!-- Fixed Locked Faculty Card (Responsive) -->
                            <div class="eval-locked-card">
                                <span class="eval-locked-text"><?php echo htmlspecialchars($locked_faculty_name); ?></span>
                                <span class="eval-locked-badge">
                                    <i class="fas fa-lock" style="font-size:10px;"></i> <?php echo !empty($locked_faculty_empid) ? htmlspecialchars($locked_faculty_empid) : 'Fixed'; ?>
                                </span>
                            </div>
                            <input type="hidden" name="faculty_id" value="<?php echo (int)$get_faculty_id; ?>">
                        </div>
                        <div style="min-width:0;">
                            <label style="font-weight:600; display:block; margin-bottom:6px; font-size:14px; color:var(--text-main);">
                                <i class="fas fa-book" style="color:var(--accent); margin-right:4px;"></i> Subject
                            </label>
                            <!-- Fixed Locked Subject Card (Responsive) -->
                            <div class="eval-locked-card">
                                <div class="eval-locked-text" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span class="category-badge" style="margin:0; flex-shrink:0;"><?php echo htmlspecialchars($locked_subject_code); ?></span> 
                                    <span style="min-width:0; overflow-wrap:break-word; word-break:break-word; font-size:13.5px;"><?php echo htmlspecialchars($locked_subject_description); ?></span>
                                </div>
                                <span class="eval-locked-badge">
                                    <i class="fas fa-lock" style="font-size:10px;"></i> Fixed
                                </span>
                            </div>
                            <input type="hidden" name="subject_id" value="<?php echo (int)$get_subject_id; ?>">
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <div class="card" style="background: linear-gradient(135deg, #2563eb, #4f46e5); color:white; margin-bottom: 25px; text-align: center;">
                        <h3>Evaluating: <?php echo htmlspecialchars($target_name); ?></h3>
                    </div>
                <?php endif; ?>

                <?php 
                $q_count = 1;
                while($q = $questions->fetch_assoc()): 
                ?>
                <div class="question-box" data-question="<?php echo $q_count; ?>">
                    <div class="question-text">
                        <span style="color:var(--accent); font-weight:bold;"><?php echo $q_count; ?>.</span> 
                        <?php echo $q['question_text']; ?>
                        <span style="color:#ef4444; font-size:14px;">*</span>
                    </div>
                    
                    <div class="rating-scale">
                        <span class="scale-label scale-label-low">Lowest</span>
                        <div class="rating-options">
                            <?php for($i = 1; $i <= $max_rating; $i++): 
                                $is_checked = isset($existing_ratings[$q['id']]) && ((int)$existing_ratings[$q['id']] === $i);
                            ?>
                            <label class="radio-label">
                                <input type="radio" name="rating[<?php echo $q['id']; ?>]" value="<?php echo $i; ?>" <?php echo $is_checked ? 'checked' : ''; ?> required>
                                <span><?php echo $i; ?></span>
                            </label>
                            <?php endfor; ?>
                        </div>
                        <span class="scale-label scale-label-high">Highest</span>
                    </div>
                </div>
                <?php $q_count++; endwhile; ?>

                <button type="button" onclick="goToStep(2)" style="width:100%; padding:15px; background:var(--accent); color:white; border:none; border-radius:10px; font-size:18px; font-weight:600; cursor:pointer; margin-top:10px;">
                    Next: Comments <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <!-- STEP 2: Feedback & Submit -->
            <div id="step-2" style="display: none;">
                <div style="text-align: left; margin-bottom: 15px; font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <span style="background: var(--accent); color: white; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">2</span> Step 2: General Feedback
                </div>
                
                <div style="background: var(--bg-color); padding: 20px; border-radius: 10px; border: 1px solid var(--border-color);">
                    <label style="font-weight:600; display:block; margin-bottom:10px; font-size:16px; color:var(--text-main);">
                        <i class="fas fa-comment-dots" style="color:var(--accent);"></i> Final Feedback & Suggestions
                    </label>
                    <p style="color:var(--text-muted); font-size:13px; margin-bottom:10px;">
                        <?php echo $is_dean ? 'Provide constructive administrative feedback, developmental recommendations, or commendations.' : 'Your feedback helps us improve teaching quality.'; ?>
                        <?php if($min_feedback > 0): ?>
                            <span style="color: #ef4444;">(Minimum <?php echo $min_feedback; ?> characters required)</span>
                        <?php endif; ?>
                    </p>
                    <textarea name="general_feedback" id="feedbackText" rows="6" <?php echo $min_feedback > 0 ? "minlength='$min_feedback'" : ""; ?> required style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; font-size:14px; resize:vertical; background: var(--input-bg); color: var(--text-main);" placeholder="<?php echo $is_dean ? 'Write your official supervisory notes, feedback, and recommendations...' : 'Write your comments, suggestions, or constructive feedback here...'; ?>"><?php echo htmlspecialchars($existing_feedback); ?></textarea>
                    <div id="charCounter" style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:12px;">
                        <span style="color:var(--text-muted);"><span id="charCount"><?php echo strlen($existing_feedback); ?></span> characters<?php if($min_feedback > 0): ?> / <span style="color:var(--accent); font-weight:600;"><?php echo $min_feedback; ?> minimum</span><?php endif; ?></span>
                        <span id="charStatus" style="font-weight:600; color:var(--text-muted);"></span>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" onclick="goToStep(1)" style="flex: 1; padding: 15px; background: #94a3b8; color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button type="button" onclick="confirmSubmitEval()" style="flex: 2; padding: 15px; background: linear-gradient(135deg, #2563eb, #4f46e5); color: white; border: none; border-radius: 10px; font-size: 18px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);">
                        <?php echo ($existing_eval_id && $is_dean) ? 'Update Evaluation' : 'Submit Evaluation'; ?> <i class="fas fa-check-circle"></i>
                    </button>
                </div>
            </div>

            <div style="text-align:center; margin-top:15px;">
                <a href="<?php echo htmlspecialchars($dash_url); ?>" style="color:var(--text-muted); text-decoration:none; font-size:14px;">
                    <i class="fas fa-arrow-left"></i> <?php echo $is_dean ? 'Back to Dean Dashboard' : 'Back to Dashboard'; ?>
                </a>
            </div>
        </form>
        <?php else: ?>

        <div class="success-screen">
             <div class="success-icon">
                 <i class="fas fa-check-circle"></i>
             </div>
             <h2 style="color: var(--text-main); margin-bottom: 15px;">Evaluation Submitted Successfully!</h2>
             <p style="color: var(--text-muted); margin-bottom: 15px;">Thank you for your evaluation of <strong><?php echo htmlspecialchars($submitted_faculty_name); ?></strong> on <strong><?php echo htmlspecialchars($submitted_subject_code); ?></strong>.</p>
             <p style="color: var(--text-muted); margin-bottom: 25px;">Your response has been securely recorded and integrated into the institutional evaluation system.</p>
             
             <?php if ((isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'system admin', 'dean'])) && !empty($submitted_sentiment)): ?>
             <div class="sentiment-result" style="background: <?php echo $submitted_sentiment === 'Positive' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border: 1px solid <?php echo $submitted_sentiment === 'Positive' ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)'; ?>; padding: 14px 28px; border-radius: 12px; display: inline-block; margin-bottom: 10px;">
                 <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px; font-weight: 600;">Sentiment Analysis Result</div>
                 <div style="font-size: 18px; font-weight: 800; color: <?php echo $submitted_sentiment === 'Positive' ? '#10b981' : '#ef4444'; ?>; display: flex; align-items: center; gap: 8px; justify-content: center;">
                     <i class="fas <?php echo $submitted_sentiment === 'Positive' ? 'fa-smile' : 'fa-frown'; ?>"></i>
                     <?php echo $submitted_sentiment; ?>
                 </div>
             </div>
             <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 20px;">Feedback sentiment: <strong><?php echo strtolower($submitted_sentiment); ?></strong>. Score: <strong><?php echo number_format($submitted_avg_rating, 2); ?> ★</strong></p>
             <?php else: ?>
             <div style="background: rgba(37, 99, 235, 0.08); border: 1px solid rgba(37, 99, 235, 0.2); padding: 12px 24px; border-radius: 12px; display: inline-flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                 <i class="fas fa-shield-alt" style="color: var(--accent); font-size: 18px;"></i>
                 <span style="font-size: 13.5px; color: var(--text-muted); font-weight: 500;">
                     Your evaluation has been anonymously submitted and securely saved for administrative review.
                 </span>
             </div>
             <?php endif; ?>
             
             <div style="margin-top: 40px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                 <a href="<?php echo htmlspecialchars($back_url); ?>" class="nav-link-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; width:auto; padding:12px 32px; background: var(--navbar-grad); color: white;">
                     <i class="fas fa-list-check"></i> <?php echo $is_dean ? 'Return to Instructors Evaluation' : 'Return to My Classes'; ?>
                 </a>
                 <a href="<?php echo htmlspecialchars($dash_url); ?>" class="nav-link-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; width:auto; padding:12px 32px; background: var(--text-muted); color: white;">
                     <i class="fas fa-home"></i> <?php echo $is_dean ? 'Go to Dean Dashboard' : 'Finish & Go to Dashboard'; ?>
                 </a>
             </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function goToStep(step) {
            if (step === 2) {
                // Validate Step 1
                const step1 = document.getElementById('step-1');
                const inputs = step1.querySelectorAll('input[type="radio"]:checked');
                const totalQ = step1.querySelectorAll('.question-box').length;
                
                if (inputs.length < totalQ) {
                    alert("Please answer all questions before proceeding to comments.");
                    return;
                }
                
                document.getElementById('step-1').style.display = 'none';
                document.getElementById('step-2').style.display = 'block';
            } else {
                document.getElementById('step-1').style.display = 'block';
                document.getElementById('step-2').style.display = 'none';
            }
            window.scrollTo(0, 0);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Progress bar logic
            const form = document.getElementById('evalForm');
            const progressFill = document.getElementById('progressFill');
            const questions = document.querySelectorAll('.question-box');
            const totalQuestions = questions.length;

            form.addEventListener('change', function() {
                let answered = 0;
                questions.forEach(q => {
                    const radios = q.querySelectorAll('input[type="radio"]');
                    let checked = false;
                    radios.forEach(r => {
                        if (r.checked) checked = true;
                    });
                    if (checked) answered++;
                });
                
                const percent = (answered / totalQuestions) * 100;
                progressFill.style.width = percent + '%';

                if (percent < 50) {
                    progressFill.style.background = '#ef4444'; // Red for incomplete/low progress
                } else if (percent < 80) {
                    progressFill.style.background = '#f59e0b'; // Amber for mid progress
                } else {
                    progressFill.style.background = '#10b981'; // Green for near completion
                }
            });

            // Real-time character & content validator for feedback textarea
            const feedbackText = document.getElementById('feedbackText');
            const charCount = document.getElementById('charCount');
            const charStatus = document.getElementById('charStatus');
            const minLen = Math.max(<?php echo (int)$min_feedback; ?>, 10);

            if (feedbackText && charCount) {
                function updateFeedbackValidationUI() {
                    const text = feedbackText.value.trim();
                    const rawLen = feedbackText.value.length;
                    charCount.textContent = rawLen;

                    if (rawLen === 0) {
                        charStatus.innerHTML = '';
                        feedbackText.style.borderColor = 'var(--border-color)';
                        feedbackText.style.boxShadow = 'none';
                        return;
                    }

                    const val = validateFeedbackCommentJS(text, minLen);
                    if (!val.valid) {
                        if (val.reason === 'too_short') {
                            charStatus.innerHTML = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-pen"></i> ${val.shortMessage}</span>`;
                            feedbackText.style.borderColor = 'var(--border-color)';
                            feedbackText.style.boxShadow = 'none';
                        } else {
                            charStatus.innerHTML = `<span style="color:#ef4444; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> ${val.shortMessage}</span>`;
                            feedbackText.style.borderColor = '#ef4444';
                            feedbackText.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.15)';
                        }
                    } else {
                        charStatus.innerHTML = `<span style="color:#10b981; font-weight:700;"><i class="fas fa-check-circle"></i> ${val.shortMessage}</span>`;
                        feedbackText.style.borderColor = '#10b981';
                        feedbackText.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.15)';
                    }
                }

                feedbackText.addEventListener('input', updateFeedbackValidationUI);
                feedbackText.addEventListener('change', updateFeedbackValidationUI);
            }
        });

        // SweetAlert2 confirmation before submission with strict validation
        function confirmSubmitEval() {
            const form = document.getElementById('evalForm');
            const feedbackText = document.getElementById('feedbackText');
            const minLen = Math.max(<?php echo (int)$min_feedback; ?>, 10);
            const rawComment = feedbackText.value.trim();
            
            // Comprehensive feedback check (symbols, emojis, keyboard mash, and random words)
            const val = validateFeedbackCommentJS(rawComment, minLen);
            if (!val.valid) {
                Swal.fire({
                    icon: 'warning',
                    title: val.title,
                    html: `<p style="font-size:14.5px; color:var(--text-main); margin-bottom:12px; line-height:1.5;">${val.message}</p>
                           <div style="font-size:12.5px; color:#b91c1c; background:#fee2e2; border:1px solid #fca5a5; padding:12px 14px; border-radius:10px; text-align:left; line-height:1.45;">
                               <strong><i class="fas fa-shield-alt"></i> Quality Guidelines:</strong><br>
                               Comments cannot consist of random characters (e.g. <code>easbhdjdvbafakhfals</code>), keyboard mashing, repeated symbols, or emojis only. Please provide constructive and meaningful feedback.
                           </div>`,
                    confirmButtonColor: 'var(--accent)',
                    confirmButtonText: '<i class="fas fa-pen"></i> Revise Comments'
                });
                feedbackText.focus();
                return;
            }

            Swal.fire({
                title: 'Submit Evaluation?',
                html: '<p style="color:#6b7280; font-size:14px;">Are you sure you want to submit your evaluation? This action cannot be undone.</p><p style="color:#6b7280; font-size:12px; margin-top:8px;">Your feedback will be recorded anonymously for administrative review.</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check-circle"></i> Yes, Submit',
                cancelButtonText: 'Review Again',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#94a3b8',
                reverseButtons: true,
            }).then((result) => {
                if (result.isConfirmed) {
                    // Add the submit_eval hidden input and submit
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'submit_eval';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                    form.submit();
                }
            });
        }

        // Theme toggle logic
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
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="notifications.js?v=<?php echo time(); ?>"></script>
    <?php if (!empty($_SESSION['original_admin_id'])): ?>
    <div id="impersonationNotice" 
         data-name="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Student'); ?>" 
         data-url="logout.php" 
         style="display:none;"></div>
    <?php endif; ?>

</body>
</html>