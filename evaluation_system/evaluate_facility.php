<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// Allow guest visitors or registered users (student, faculty, dean, visitor)
if (!isset($_SESSION['role'])) {
    // Initialize guest visitor session
    $_SESSION['role'] = 'visitor';
    $_SESSION['full_name'] = 'Guest Visitor';
    $chk_v = $conn->query("SELECT id FROM users WHERE username = 'guest_visitor' LIMIT 1");
    if ($v_row = $chk_v->fetch_assoc()) {
        $_SESSION['id'] = (int)$v_row['id'];
    } else {
        $p_hash = password_hash('guest_visitor_pwd', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO users (username, password, role, full_name, first_name, last_name, school, status) VALUES ('guest_visitor', '$p_hash', 'visitor', 'Guest Visitor', 'Guest', 'Visitor', 'isap', 'active')");
        $_SESSION['id'] = (int)$conn->insert_id;
    }
}

$user_role = $_SESSION['role'];
$user_id = (int)$_SESSION['id'];

// Determine dynamic return URL and portal details based on role
if ($user_role === 'faculty') {
    $return_url = 'dashboard_faculty.php#facilities-section';
    $portal_name = 'Faculty Portal';
} elseif ($user_role === 'dean') {
    $return_url = 'dashboard_dean.php#facilities-section';
    $portal_name = 'Dean Portal';
} elseif ($user_role === 'visitor') {
    $return_url = 'dashboard_visitor.php#facilities-section';
    $portal_name = 'Visitor Portal';
} else {
    $return_url = 'dashboard_student.php#facilities-tab';
    $portal_name = 'Student Portal';
}

$target_name = trim($_GET['target'] ?? 'General School');
$category_filter = trim($_GET['cat'] ?? 'School');

// Fetch the 3 standard evaluation dimensions
$sat_questions = $conn->query("SELECT * FROM evaluation_questions WHERE category = 'Satisfaction' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$srv_questions = $conn->query("SELECT * FROM evaluation_questions WHERE category = 'Service' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$loy_questions = $conn->query("SELECT * FROM evaluation_questions WHERE category = 'Loyalty' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Fallbacks if empty
if (empty($sat_questions)) {
    $sat_questions = $conn->query("SELECT * FROM evaluation_questions WHERE category IN ('Facilities', 'School') ORDER BY id ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
}
if (empty($srv_questions)) {
    $srv_questions = $conn->query("SELECT * FROM evaluation_questions WHERE category IN ('Support', 'IT-Services') ORDER BY id ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
}

// Active academic period
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = $active_period['id'] ?? null;
$period_label = $active_period ? ($active_period['semester'] . ' ' . $active_period['school_year']) : 'Academic Year 2026-2027';

// Check if already evaluated for this target in active period
$already_eval = false;
if ($active_id && $user_id && $user_role !== 'visitor') {
    $chk_stmt = $conn->prepare("SELECT id FROM evaluations WHERE student_id = ? AND target_name = ? AND academic_period_id = ? AND faculty_id IS NULL");
    $chk_stmt->bind_param("isi", $user_id, $target_name, $active_id);
    $chk_stmt->execute();
    $already_eval = $chk_stmt->get_result()->num_rows > 0;
}

$system_name = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'MCNP-ISAP';
$min_feedback = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_feedback_length'")->fetch_assoc()['setting_value'] ?? 0);

// Resolve school logo
$user_school = $_SESSION['school'] ?? null;
if (!$user_school && $user_role === 'student') {
    $u_stmt = $conn->prepare("SELECT school, dept_id FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_data = $u_stmt->get_result()->fetch_assoc();
    if ($u_data && !empty($u_data['school'])) {
        $user_school = strtolower(trim($u_data['school']));
    }
}
if ($user_school === 'isap') {
    $form_logo = 'ISAP_Logo.png';
} elseif ($user_school === 'mcnp') {
    $form_logo = 'MCNP_Logo.png';
} else {
    $form_logo = 'mcnp-isap.png';
}

include_once 'process_sentiment.php';
$submitted_sentiment = '';
$submitted_avg_rating = 0;
$submitted_nps = 0;
$submitted_mps = 0;
$submitted_promoters_pct = 0;
$submitted_passives_pct = 0;
$submitted_detractors_pct = 0;
$submitted_sat_avg = 0;
$submitted_srv_avg = 0;
$submitted_loy_avg = 0;

$submitted = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_eval']) || isset($_POST['rating']))) {
    if ($already_eval) {
        $submitted = true;
    } else {
        $general_feedback = trim($_POST['general_feedback'] ?? '');
        $ratings = $_POST['rating'] ?? [];

        // Strict validation: Reject empty, solo characters, symbols, emojis, and keyboard mash
        require_once 'validate_feedback.php';
        $feedback_val = validateFeedbackComment($general_feedback, $min_feedback);
        if (!$feedback_val['valid']) {
            $err_msg = $feedback_val['message'];
        } else {
        $q_map = [];
        $all_q_ids = array_keys($ratings);
        if (!empty($all_q_ids)) {
            $ids_str = implode(',', array_map('intval', $all_q_ids));
            $q_res = $conn->query("SELECT id, category FROM evaluation_questions WHERE id IN ($ids_str)");
            if ($q_res) {
                while ($qr = $q_res->fetch_assoc()) {
                    $q_map[(int)$qr['id']] = $qr['category'];
                }
            }
        }

        $sat_vals = [];
        $srv_vals = [];
        $loy_vals = [];
        $promoters = 0;
        $passives = 0;
        $detractors = 0;

        foreach ($ratings as $qid => $val) {
            $qid = (int)$qid;
            $r_val = (int)$val;
            $cat = $q_map[$qid] ?? 'Satisfaction';

            if ($cat === 'Satisfaction') {
                $sat_vals[] = $r_val;
            } elseif ($cat === 'Service') {
                $srv_vals[] = $r_val;
            } elseif ($cat === 'Loyalty') {
                $loy_vals[] = $r_val;
                if ($r_val >= 9) $promoters++;
                elseif ($r_val >= 7) $passives++;
                else $detractors++;
            } else {
                $sat_vals[] = $r_val;
            }
        }

        $avg_sat = !empty($sat_vals) ? (array_sum($sat_vals) / count($sat_vals)) : 5.0;
        $avg_srv = !empty($srv_vals) ? (array_sum($srv_vals) / count($srv_vals)) : 5.0;
        $avg_loy = !empty($loy_vals) ? (array_sum($loy_vals) / count($loy_vals)) : 10.0;

        $total_loy = count($loy_vals);
        $nps_score = $total_loy > 0 ? round((($promoters - $detractors) / $total_loy) * 100) : 0;
        $mps_score = round($avg_loy, 2);

        // Standard 5-point composite rating for evaluations.rating
        $avg_rating = round(($avg_sat + $avg_srv + ($avg_loy / 2)) / 3, 2);

        $submitted_avg_rating = $avg_rating;
        $submitted_nps = $nps_score;
        $submitted_mps = $mps_score;
        $submitted_sat_avg = round($avg_sat, 2);
        $submitted_srv_avg = round($avg_srv, 2);
        $submitted_loy_avg = round($avg_loy, 2);
        $submitted_promoters_pct = $total_loy > 0 ? round(($promoters / $total_loy) * 100) : 0;
        $submitted_passives_pct  = $total_loy > 0 ? round(($passives / $total_loy) * 100) : 0;
        $submitted_detractors_pct = $total_loy > 0 ? round(($detractors / $total_loy) * 100) : 0;

        $stmt = $conn->prepare("INSERT INTO evaluations (student_id, academic_period_id, target_name, feedback, rating) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissd", $user_id, $active_id, $target_name, $general_feedback, $avg_rating);
        $stmt->execute();
        $eval_id = $conn->insert_id;

        if (!empty($ratings)) {
            $stmt2 = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
            foreach ($ratings as $question_id => $rating) {
                $q_id = (int)$question_id;
                $r_val = (int)$rating;
                $stmt2->bind_param("iii", $eval_id, $q_id, $r_val);
                $stmt2->execute();
            }
        }

        $submitted_sentiment = analyzeSentiment($general_feedback, $avg_rating);

        // Track evaluated target in session for instant visitor dashboard updates
        if (!isset($_SESSION['visitor_evaluated'])) {
            $_SESSION['visitor_evaluated'] = [];
        }
        $_SESSION['visitor_evaluated'][$target_name] = [
            'rating' => (float)$avg_rating,
            'feedback' => $general_feedback,
            'sentiment' => $submitted_sentiment,
            'date' => date('Y-m-d H:i:s')
        ];

        $submitted = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facility & Services Evaluation - <?php echo htmlspecialchars($target_name); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="validate_feedback.js?v=<?php echo filemtime('validate_feedback.js'); ?>"></script>
    <style>
        .eval-container {
            max-width: 920px;
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
        [data-theme="dark"] .eval-container { background: var(--card-bg); }

        /* Confidentiality Disclaimer Banner */
        .confidentiality-card {
            background: rgba(59, 130, 246, 0.08);
            border: 1.5px solid #3b82f6;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .confidentiality-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .confidentiality-content h4 {
            margin: 0;
            font-size: 15.5px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.3px;
        }
        .confidentiality-content p {
            margin: 4px 0 0;
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.45;
        }

        /* Section Banners */
        .dimension-header {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-left: 5px solid var(--accent);
            border-radius: 12px;
            padding: 14px 20px;
            margin: 32px 0 18px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .dimension-header.srv { border-left-color: #3b82f6; }
        .dimension-header.loy { border-left-color: #10b981; }

        .dimension-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dimension-subtitle {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 3px;
        }
        .dimension-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 20px;
            background: var(--purple-glow);
            color: var(--accent);
        }
        .dimension-badge.srv { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
        .dimension-badge.loy { background: rgba(16, 185, 129, 0.12); color: #10b981; }

        .question-box {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(8px);
            padding: 22px 24px;
            border-radius: 14px;
            margin-bottom: 18px;
            border: 1px solid var(--border-color);
            transition: all 0.25s ease;
        }
        .question-box:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 14px var(--purple-glow);
        }
        [data-theme="dark"] .question-box { background: rgba(30, 41, 59, 0.45); }

        .question-text {
            font-weight: 650;
            color: var(--text-main);
            margin-bottom: 14px;
            font-size: 15px;
            line-height: 1.5;
        }

        /* 5-Point Rating Scale */
        .rating-scale-5 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 6px;
            margin-top: 12px;
        }
        .rating-options-5 {
            display: flex;
            gap: 18px;
            justify-content: center;
            flex: 1;
        }
        .scale-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .radio-label-5 {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            padding: 4px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .radio-label-5 input[type="radio"] { display: none; }
        .radio-label-5 span {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            font-size: 17px;
            font-weight: 800;
            color: var(--text-muted);
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .radio-label-5:hover span {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--purple-glow);
        }
        .radio-label-5 input[type="radio"]:checked + span {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: scale(1.15);
            box-shadow: 0 6px 16px rgba(132, 90, 223, 0.35);
        }

        /* 10-Point Rating Scale (Loyalty / NPS / MPS) */
        .scale-10-wrapper {
            margin-top: 14px;
        }
        .scale-10-grid {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            gap: 8px;
        }
        .scale-10-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
        }
        .scale-10-item input[type="radio"] { display: none; }
        .scale-10-item span {
            width: 100%;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            font-size: 16px;
            font-weight: 800;
            color: var(--text-main);
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        /* Detractors (1-6) */
        .scale-10-item.detractor:hover span {
            border-color: #ef4444;
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }
        .scale-10-item.detractor input:checked + span {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-color: #dc2626;
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
        }
        /* Passives (7-8) */
        .scale-10-item.passive:hover span {
            border-color: #f59e0b;
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }
        .scale-10-item.passive input:checked + span {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border-color: #d97706;
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
        }
        /* Promoters (9-10) */
        .scale-10-item.promoter:hover span {
            border-color: #10b981;
            color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        .scale-10-item.promoter input:checked + span {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-color: #059669;
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }

        .scale-10-legend {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .nps-legend-bar {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
            padding: 8px 12px;
            background: rgba(0,0,0,0.03);
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
        }
        .nps-tag-detractor { color: #ef4444; display: inline-flex; align-items: center; gap: 4px; }
        .nps-tag-passive   { color: #f59e0b; display: inline-flex; align-items: center; gap: 4px; }
        .nps-tag-promoter  { color: #10b981; display: inline-flex; align-items: center; gap: 4px; }

        .form-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--border-color);
        }
        .form-header h2 {
            color: var(--text-main);
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .form-header p {
            color: var(--text-muted);
            font-size: 14.5px;
        }

        .success-screen {
            text-align: center;
            padding: 30px 10px;
            animation: slideInUp 0.5s ease-out;
        }
        .success-icon {
            font-size: 75px;
            color: #10b981;
            margin-bottom: 16px;
            display: inline-block;
        }

        .metrics-grid-success {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 25px 0;
            text-align: center;
        }
        .metric-card-success {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 12px;
        }
        .metric-val {
            font-size: 24px;
            font-weight: 900;
            color: var(--accent);
            margin-top: 4px;
        }
        .metric-lbl {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .eval-container { padding: 24px 16px; margin: 15px auto; }
            .form-header h2 { font-size: 22px; }
            .metrics-grid-success { grid-template-columns: repeat(2, 1fr); }
            .scale-10-grid { grid-template-columns: repeat(5, 1fr); gap: 6px; }
            .scale-10-item span { height: 40px; font-size: 14px; }
            .rating-options-5 { gap: 8px; }
            .radio-label-5 span { width: 40px; height: 40px; font-size: 14px; }

            /* Mobile: buttons on their own full row, end labels underneath (no clipping) */
            .question-box { padding: 16px 14px; }
            .rating-scale-5 {
                flex-wrap: wrap;
                justify-content: space-between;
                padding: 0;
                row-gap: 6px;
            }
            .rating-scale-5 .rating-options-5 {
                order: -1;
                flex: 1 1 100%;
                justify-content: space-between;
                gap: 4px;
                min-width: 0;
            }
            .rating-scale-5 .scale-label {
                flex: 1 1 50%;
                font-size: 10.5px;
                line-height: 1.3;
                min-width: 0;
            }
            .rating-scale-5 .scale-label:last-child { text-align: right; }
            .radio-label-5 { padding: 2px; }
            .scale-10-legend { font-size: 10.5px; gap: 8px; }
            .nps-legend-bar { flex-wrap: wrap; gap: 8px 14px; }
            .dimension-header { padding: 12px 14px; }
            .dimension-title { font-size: 14.5px; align-items: flex-start; }
            .confidentiality-card { padding: 14px; gap: 12px; align-items: flex-start; }
        }
        @media (max-width: 600px) {
            body.eval-page { padding: 10px 8px !important; }
            .eval-container { padding: 18px 14px !important; margin: 8px auto !important; width: 100% !important; border-radius: 14px !important; }
            .eval-floating-theme { top: 12px !important; right: 12px !important; }
            textarea { font-size: 16px !important; }
        }
        @media (max-width: 360px) {
            .radio-label-5 span { width: 36px; height: 36px; font-size: 13px; }
            .scale-10-item span { height: 36px; font-size: 13px; }
        }
    </style>
</head>
<body class="dashboard-body eval-page">

    <div class="eval-floating-theme" style="position: absolute; top: 25px; right: 30px; z-index: 1000;">
        <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()" title="Toggle Theme" style="cursor:pointer; width:38px; height:38px; display:flex; align-items:center; justify-content:center; background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px;">
            <i class="fas fa-moon"></i>
        </div>
    </div>

    <div class="eval-container">

        <?php if ($already_eval && !$submitted): ?>

        <div class="success-screen">
             <div class="success-icon"><i class="fas fa-check-circle" style="color: #3b82f6;"></i></div>
             <h2 style="color: var(--text-main); margin-bottom: 12px;">Evaluation Already Recorded</h2>
             <p style="color: var(--text-muted); margin-bottom: 25px;">You have already submitted an official evaluation for <strong><?php echo htmlspecialchars($target_name); ?></strong> for the current academic period.</p>
             
             <div style="margin-top: 30px;">
                 <a href="<?php echo htmlspecialchars($return_url); ?>" class="nav-link-btn" style="text-decoration:none; display:inline-block; width:auto; padding:12px 40px; background: var(--accent); color: white; border-radius: 8px; font-weight: 700;">
                     <i class="fas fa-arrow-left"></i> Return to Dashboard
                 </a>
             </div>
        </div>

        <?php elseif (!$submitted): ?>

        <!-- Form Header -->
        <div class="form-header">
            <div style="display:flex; justify-content:center; margin-bottom:15px;">
                <div class="eval-logo-badge eval-logo-large"><img src="<?php echo htmlspecialchars($form_logo); ?>" alt="Logo"></div>
            </div>
            <h2>Facility & Campus Services Evaluation</h2>
            <p style="margin-bottom: 10px; font-size: 15.5px; color: var(--text-main);">
                Evaluating Target: <strong style="color: var(--accent); font-weight: 800;"><?php echo htmlspecialchars($target_name); ?></strong>
            </p>
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                <span class="category-badge" style="background: var(--purple-glow); color: var(--accent); font-weight: 700; font-size: 11px;">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category_filter); ?>
                </span>
                <span class="category-badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-weight: 700; font-size: 11px;">
                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($portal_name); ?>
                </span>
                <span class="category-badge" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; font-weight: 700; font-size: 11px;">
                    <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($period_label); ?>
                </span>
            </div>
        </div>

        <!-- 1. Confidentiality Disclaimer Banner -->
        <div class="confidentiality-card">
            <div class="confidentiality-icon"><i class="fas fa-user-shield"></i></div>
            <div class="confidentiality-content">
                <h4>Disclaimer: All Evaluation is Confidential</h4>
                <p>All individual responses and ratings collected in this survey are strictly confidential and protected by institutional quality assurance protocols. Your honest feedback drives continuous service enhancement.</p>
            </div>
        </div>

        <?php if (!empty($err_msg)): ?>
        <div style="background: rgba(239, 68, 68, 0.1); border: 1.5px solid #ef4444; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; color: #ef4444;">
            <i class="fas fa-exclamation-triangle" style="font-size: 20px; flex-shrink: 0;"></i>
            <div style="font-size: 13.5px; font-weight: 600; line-height: 1.4;">
                <strong>Invalid Feedback:</strong> <?php echo htmlspecialchars($err_msg); ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="evalForm">

            <!-- PART 1: 5 Questions - Satisfaction (1-5 Likert scale) -->
            <div class="dimension-header">
                <div>
                    <div class="dimension-title">
                        <i class="fas fa-smile" style="color:var(--accent);"></i> PART 1: CLIENT & INSTITUTIONAL SATISFACTION
                    </div>
                    <div class="dimension-subtitle">
                        Please rate your overall level of satisfaction on a scale from 1 (Very Dissatisfied) to 5 (Very Satisfied).
                    </div>
                </div>
                <span class="dimension-badge">5-Point Likert Scale</span>
            </div>

            <?php 
            $q_idx = 1;
            foreach ($sat_questions as $q): 
            ?>
            <div class="question-box">
                <div class="question-text">
                    <span style="color:var(--accent); font-weight:800;"><?php echo $q_idx++; ?>.</span> 
                    <?php echo htmlspecialchars($q['question_text']); ?>
                    <span style="color:#ef4444; font-size:14px;">*</span>
                </div>
                <div class="rating-scale-5">
                    <span class="scale-label">1 - Very Dissatisfied</span>
                    <div class="rating-options-5">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <label class="radio-label-5" title="Score <?php echo $i; ?>">
                            <input type="radio" name="rating[<?php echo $q['id']; ?>]" value="<?php echo $i; ?>" required>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                    <span class="scale-label">5 - Very Satisfied</span>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- PART 2: 5 Questions - Service Quality (1-5 Likert scale) -->
            <div class="dimension-header srv">
                <div>
                    <div class="dimension-title">
                        <i class="fas fa-concierge-bell" style="color:#3b82f6;"></i> PART 2: SERVICE QUALITY & PERFORMANCE
                    </div>
                    <div class="dimension-subtitle">
                        Please evaluate the standard and execution of services on a scale from 1 (Poor) to 5 (Excellent).
                    </div>
                </div>
                <span class="dimension-badge srv">5-Point Likert Scale</span>
            </div>

            <?php foreach ($srv_questions as $q): ?>
            <div class="question-box">
                <div class="question-text">
                    <span style="color:#3b82f6; font-weight:800;"><?php echo $q_idx++; ?>.</span> 
                    <?php echo htmlspecialchars($q['question_text']); ?>
                    <span style="color:#ef4444; font-size:14px;">*</span>
                </div>
                <div class="rating-scale-5">
                    <span class="scale-label">1 - Poor</span>
                    <div class="rating-options-5">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <label class="radio-label-5" title="Score <?php echo $i; ?>">
                            <input type="radio" name="rating[<?php echo $q['id']; ?>]" value="<?php echo $i; ?>" required>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                    <span class="scale-label">5 - Excellent</span>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- PART 3: 5 Questions - Loyalty & Net Promoter Score (10-point Likert scale) -->
            <div class="dimension-header loy">
                <div>
                    <div class="dimension-title">
                        <i class="fas fa-heart" style="color:#10b981;"></i> PART 3: INSTITUTIONAL LOYALTY & NET PROMOTER SCORE (MPS)
                    </div>
                    <div class="dimension-subtitle">
                        On a 10-point scale from 1 (Highly Unlikely) to 10 (Extremely Likely), evaluate your institutional loyalty and likelihood of recommendation.
                    </div>
                </div>
                <span class="dimension-badge loy">10-Point Likert Scale</span>
            </div>

            <?php foreach ($loy_questions as $q): ?>
            <div class="question-box">
                <div class="question-text">
                    <span style="color:#10b981; font-weight:800;"><?php echo $q_idx++; ?>.</span> 
                    <?php echo htmlspecialchars($q['question_text']); ?>
                    <span style="color:#ef4444; font-size:14px;">*</span>
                </div>

                <div class="scale-10-wrapper">
                    <div class="scale-10-grid">
                        <?php for($i = 1; $i <= 10; $i++): 
                            $tier_class = ($i <= 6) ? 'detractor' : (($i <= 8) ? 'passive' : 'promoter');
                        ?>
                        <label class="scale-10-item <?php echo $tier_class; ?>" title="Rating <?php echo $i; ?> of 10">
                            <input type="radio" name="rating[<?php echo $q['id']; ?>]" value="<?php echo $i; ?>" required>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>

                    <div class="scale-10-legend">
                        <span><i class="fas fa-arrow-left"></i> 1 = Highly Unlikely</span>
                        <span>10 = Extremely Likely <i class="fas fa-arrow-right"></i></span>
                    </div>

                    <div class="nps-legend-bar">
                        <span class="nps-tag-detractor"><i class="fas fa-circle"></i> 1–6 Detractor</span>
                        <span class="nps-tag-passive"><i class="fas fa-circle"></i> 7–8 Passive</span>
                        <span class="nps-tag-promoter"><i class="fas fa-circle"></i> 9–10 Promoter</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Comments & Suggestions -->
            <div class="question-box" style="margin-top:28px;">
                <label style="font-weight:700; display:block; margin-bottom:8px; font-size:16px; color:var(--text-main);">
                    <i class="fas fa-comment-dots" style="color:var(--accent);"></i> Comments and Suggestions for Improvement
                </label>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:12px; line-height:1.45;">
                    Your constructive feedback helps us elevate campus standards and service delivery.
                    <?php if($min_feedback > 0): ?>
                        <span style="color: #ef4444; font-weight:600;">(Minimum <?php echo $min_feedback; ?> characters required)</span>
                    <?php endif; ?>
                </p>
                <textarea name="general_feedback" id="feedbackText" rows="5" <?php echo $min_feedback > 0 ? "minlength='$min_feedback'" : ""; ?> required style="width:100%; padding:14px; border:1px solid var(--border-color); border-radius:10px; font-size:14px; resize:vertical; background: var(--input-bg); color: var(--text-main); font-family: inherit;" placeholder="Write your constructive comments, observations, or suggestions here..."></textarea>
                <div id="charCounter" style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; font-size:12px;">
                    <span style="color:var(--text-muted);"><span id="charCount">0</span> characters<?php if($min_feedback > 0): ?> / <span style="color:var(--accent); font-weight:700;"><?php echo $min_feedback; ?> minimum</span><?php endif; ?></span>
                    <span id="charStatus" style="font-weight:700; color:var(--text-muted);"></span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px; margin-top: 25px;">
                <a href="<?php echo htmlspecialchars($return_url); ?>" style="flex: 1; padding: 14px; background: #64748b; color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; text-decoration:none; text-align:center; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button type="button" onclick="confirmSubmitFacility()" style="flex: 2; padding: 14px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; box-shadow: 0 4px 15px rgba(132, 90, 223, 0.25); display:flex; align-items:center; justify-content:center; gap:8px;">
                    Submit Evaluation <i class="fas fa-check-circle"></i>
                </button>
            </div>
        </form>

        <?php else: ?>

        <!-- Submission Success Screen -->
        <div class="success-screen">
             <div class="success-icon"><i class="fas fa-check-circle"></i></div>
             <h2 style="color: var(--text-main); margin-bottom: 10px;">Evaluation Submitted Successfully</h2>
             <p style="color: var(--text-muted); margin-bottom: 20px; font-size:15px;">
                 Thank you for evaluating <strong><?php echo htmlspecialchars($target_name); ?></strong>. Your feedback has been securely registered.
             </p>
             
             <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'system admin']) && !empty($submitted_sentiment)): ?>
             <div style="background: <?php echo $submitted_sentiment === 'Positive' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border: 1px solid <?php echo $submitted_sentiment === 'Positive' ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)'; ?>; padding: 14px 28px; border-radius: 12px; display: inline-block; margin-bottom: 15px;">
                 <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 4px; font-weight: 700; text-transform:uppercase;">AI Sentiment Analysis Result (Admin View)</div>
                 <div style="font-size: 18px; font-weight: 800; color: <?php echo $submitted_sentiment === 'Positive' ? '#10b981' : '#ef4444'; ?>; display: flex; align-items: center; gap: 8px; justify-content: center;">
                     <i class="fas <?php echo $submitted_sentiment === 'Positive' ? 'fa-smile' : 'fa-frown'; ?>"></i>
                     <?php echo $submitted_sentiment; ?> Feedback
                 </div>
             </div>
             <?php else: ?>
             <div style="background: rgba(37, 99, 235, 0.08); border: 1px solid rgba(37, 99, 235, 0.2); padding: 12px 24px; border-radius: 12px; display: inline-flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                 <i class="fas fa-shield-alt" style="color: var(--accent); font-size: 18px;"></i>
                 <span style="font-size: 13.5px; color: var(--text-muted); font-weight: 500;">
                     Your facility evaluation has been anonymously registered for administrative review.
                 </span>
             </div>
             <?php endif; ?>
             
             <div style="margin-top: 30px;">
                 <a href="<?php echo htmlspecialchars($return_url); ?>" class="nav-link-btn" style="text-decoration:none; display:inline-block; width:auto; padding:13px 45px; background: var(--accent); color: white; border-radius: 8px; font-weight: 700;">
                     <i class="fas fa-home"></i> Return to Dashboard
                 </a>
             </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Character & content validation counter
        document.addEventListener('DOMContentLoaded', function() {
            const feedbackText = document.getElementById('feedbackText');
            const charCount = document.getElementById('charCount');
            const charStatus = document.getElementById('charStatus');
            const minLen = Math.max(<?php echo (int)$min_feedback; ?>, 10);

            if (feedbackText && charCount) {
                function updateFacilityFeedbackUI() {
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

                feedbackText.addEventListener('input', updateFacilityFeedbackUI);
                feedbackText.addEventListener('change', updateFacilityFeedbackUI);
            }
        });

        // SweetAlert2 confirmation before submission
        function confirmSubmitFacility() {
            const form = document.getElementById('evalForm');
            const feedbackText = document.getElementById('feedbackText');
            const minLen = Math.max(<?php echo (int)$min_feedback; ?>, 10);
            
            // Check all radio questions answered
            const requiredRadioNames = new Set();
            form.querySelectorAll('input[type="radio"][required]').forEach(r => requiredRadioNames.add(r.name));
            for (let name of requiredRadioNames) {
                const checked = form.querySelector('input[name="' + name + '"]:checked');
                if (!checked) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Incomplete Evaluation',
                        text: 'Please answer all questions across Satisfaction, Service, and Loyalty before submitting.',
                        confirmButtonColor: '#8b5cf6',
                    });
                    return;
                }
            }

            const rawComment = feedbackText.value.trim();
            const val = validateFeedbackCommentJS(rawComment, minLen);
            if (!val.valid) {
                Swal.fire({
                    icon: 'warning',
                    title: val.title,
                    html: `<p style="font-size:14.5px; color:var(--text-main); margin-bottom:12px; line-height:1.5;">${val.message}</p>
                           <div style="font-size:12.5px; color:#b91c1c; background:#fee2e2; border:1px solid #fca5a5; padding:12px 14px; border-radius:10px; text-align:left; line-height:1.45;">
                               <strong><i class="fas fa-shield-alt"></i> Quality Guidelines:</strong><br>
                               Comments cannot consist of random characters (e.g. <code>easbhdjdvbafakhfals</code>), keyboard mashing, repeated symbols, or emojis only. Please write constructive and meaningful feedback.
                           </div>`,
                    confirmButtonColor: '#8b5cf6',
                    confirmButtonText: '<i class="fas fa-pen"></i> Revise Comments'
                });
                feedbackText.focus();
                return;
            }

            Swal.fire({
                title: 'Submit Evaluation?',
                html: '<p style="color:#64748b; font-size:14px;">Are you sure you want to submit your evaluation for <strong><?php echo htmlspecialchars($target_name); ?></strong>? All responses are strictly confidential.</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check-circle"></i> Yes, Submit Evaluation',
                cancelButtonText: 'Review Questions',
                confirmButtonColor: '#8b5cf6',
                cancelButtonColor: '#94a3b8',
                reverseButtons: true,
            }).then((result) => {
                if (result.isConfirmed) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'submit_eval';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                    form.submit();
                }
            });
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
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>