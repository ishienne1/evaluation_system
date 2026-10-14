<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// --- Flash Warning & Notification Handling ---
$flash_warning = $_SESSION['flash_warning'] ?? '';
unset($_SESSION['flash_warning']);

$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

if (isset($_GET['evaluated'])) {
    $flash_success = 'Thank you! Your evaluation for "' . htmlspecialchars($_GET['evaluated']) . '" was successfully submitted.';
}



// --- Check and Establish Session ---
if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
    header("Location: login.php?school=mcnp-isap&role=visitor");
    exit();
}

$is_registered_visitor = ($_SESSION['role'] === 'visitor');
$is_other_role = ($_SESSION['role'] !== 'visitor');
$other_role_name = $is_other_role ? ucfirst($_SESSION['role']) : '';
$visitor_id = (int)$_SESSION['id'];
$visitor_name = $_SESSION['full_name'] ?? 'Visitor';

// Synchronize and lock visitor classification directly from the users table (from registration)
if ($visitor_id > 0) {
    $v_stmt = $conn->prepare("SELECT visitor_category, full_name FROM users WHERE id = ?");
    if ($v_stmt) {
        $v_stmt->bind_param("i", $visitor_id);
        $v_stmt->execute();
        $v_res = $v_stmt->get_result()->fetch_assoc();
        if (!empty($v_res['visitor_category'])) {
            $_SESSION['visitor_category'] = $v_res['visitor_category'];
        }
        if (!empty($v_res['full_name'])) {
            $_SESSION['full_name'] = $v_res['full_name'];
            $visitor_name = $v_res['full_name'];
        }
    }
}
$visitor_category = $_SESSION['visitor_category'] ?? 'Campus Guest';

// --- Active Academic Period ---
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_period_id = $active_period['id'] ?? 0;
$period_label = $active_period ? ($active_period['semester'] . ' ' . $active_period['school_year']) : 'Academic Year 2026-2027';

$system_name = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetch_assoc()['setting_value'] ?? 'MCNP-ISAP';

// --- Visitor Campus Facilities & Services Configuration ---
$facility_services = [
    [
        'name' => 'General School', 
        'cat' => 'General Campus', 
        'group' => 'amenity', 
        'icon' => 'fa-school', 
        'color' => '#8b5cf6',
        'desc' => 'Overall campus cleanliness, grounds maintenance & institutional atmosphere'
    ],
    [
        'name' => 'Registrar Office', 
        'cat' => 'Office', 
        'group' => 'office', 
        'icon' => 'fa-file-invoice', 
        'color' => '#3b82f6',
        'desc' => 'Academic records, enrollment inquiries, documents & credential processing'
    ],
    [
        'name' => 'Finance Office', 
        'cat' => 'Office', 
        'group' => 'office', 
        'icon' => 'fa-money-bill-wave', 
        'color' => '#10b981',
        'desc' => 'Cashiers, assessment, payment queues & financial transaction courtesy'
    ],
    [
        'name' => 'Guidance Office', 
        'cat' => 'Office', 
        'group' => 'office', 
        'icon' => 'fa-hands-helping', 
        'color' => '#ec4899',
        'desc' => 'Visitor guidance, counseling, admissions inquiries & public assistance'
    ],
    [
        'name' => 'Security Unit', 
        'cat' => 'Office', 
        'group' => 'office', 
        'icon' => 'fa-shield-alt', 
        'color' => '#f59e0b',
        'desc' => 'Campus safety, entry gate hospitality, ID checks & parking assistance'
    ],
    [
        'name' => 'IT & WiFi Services', 
        'cat' => 'Office', 
        'group' => 'office', 
        'icon' => 'fa-wifi', 
        'color' => '#06b6d4',
        'desc' => 'Guest WiFi availability, network speeds, digital signage & technical support'
    ],
    [
        'name' => 'Canteen Services', 
        'cat' => 'Amenity', 
        'group' => 'amenity', 
        'icon' => 'fa-utensils', 
        'color' => '#f97316',
        'desc' => 'Food safety, cleanliness of dining tables, pricing & canteen staff courtesy'
    ],
    [
        'name' => 'School Clinic', 
        'cat' => 'Amenity', 
        'group' => 'amenity', 
        'icon' => 'fa-medkit', 
        'color' => '#ef4444',
        'desc' => 'First aid, emergency response readiness, health triage & clinic personnel'
    ],
    [
        'name' => 'School Library', 
        'cat' => 'Academic', 
        'group' => 'academic', 
        'icon' => 'fa-book-reader', 
        'color' => '#6366f1',
        'desc' => 'Quiet study areas, reference collections, visitor access & reading environment'
    ],
    [
        'name' => 'School Gym', 
        'cat' => 'Amenity', 
        'group' => 'amenity', 
        'icon' => 'fa-dumbbell', 
        'color' => '#14b8a6',
        'desc' => 'Indoor sports facilities, event bleachers, ventilation & recreational venues'
    ],
    [
        'name' => 'Classrooms', 
        'cat' => 'Academic', 
        'group' => 'academic', 
        'icon' => 'fa-door-open', 
        'color' => '#84cc16',
        'desc' => 'Acoustics, natural lighting, air conditioning, seating & cleanliness'
    ],
    [
        'name' => 'Science Lab', 
        'cat' => 'Academic', 
        'group' => 'academic', 
        'icon' => 'fa-flask', 
        'color' => '#a855f7',
        'desc' => 'Specialized laboratories, chemical safety, equipment condition & ventilation'
    ],
    [
        'name' => 'Computer Lab', 
        'cat' => 'Academic', 
        'group' => 'academic', 
        'icon' => 'fa-laptop-code', 
        'color' => '#3b82f6',
        'desc' => 'Workstation hardware, software applications, lab hygiene & assistance'
    ],
    [
        'name' => 'Comfort Rooms', 
        'cat' => 'Facility', 
        'group' => 'amenity', 
        'icon' => 'fa-restroom', 
        'color' => '#0ea5e9',
        'desc' => 'Restroom cleanliness, running water, soap/hand hygiene & odor control'
    ],
    [
        'name' => 'Campus Common Areas', 
        'cat' => 'Facility', 
        'group' => 'amenity', 
        'icon' => 'fa-users', 
        'color' => '#d946ef',
        'desc' => 'Corridors, outdoor waiting benches, landscaping, walkways & open plazas'
    ]
];

// --- Evaluated Targets for this visitor ---
$evaluated_targets = [];

// 1. Registered visitor records from database
if ($is_registered_visitor && $visitor_id && $active_period_id) {
    $vis_eval_chk = $conn->prepare("SELECT target_name, rating, feedback, created_at FROM evaluations WHERE student_id = ? AND academic_period_id = ? AND faculty_id IS NULL");
    if ($vis_eval_chk) {
        $vis_eval_chk->bind_param("ii", $visitor_id, $active_period_id);
        $vis_eval_chk->execute();
        $vis_eval_res = $vis_eval_chk->get_result();
        while ($r = $vis_eval_res->fetch_assoc()) {
            $evaluated_targets[$r['target_name']] = [
                'rating' => (float)$r['rating'],
                'feedback' => $r['feedback'],
                'created_at' => $r['created_at']
            ];
        }
    }
}

// 2. Merge session evaluations (for guest and immediate feedback in session)
if (!empty($_SESSION['visitor_evaluated']) && is_array($_SESSION['visitor_evaluated'])) {
    foreach ($_SESSION['visitor_evaluated'] as $tname => $tdata) {
        $evaluated_targets[$tname] = [
            'rating' => (float)($tdata['rating'] ?? 5.0),
            'feedback' => $tdata['feedback'] ?? '',
            'created_at' => $tdata['date'] ?? date('Y-m-d H:i:s')
        ];
    }
}

// --- Top Rated Campus Facilities ---
$top_fac_query = "
    SELECT target_name, AVG(rating) as avg_rating, COUNT(*) as feedback_count 
    FROM evaluations 
    WHERE faculty_id IS NULL 
    GROUP BY target_name 
    ORDER BY avg_rating DESC, feedback_count DESC 
    LIMIT 4
";
$top_facilities = $conn->query($top_fac_query);

// --- Active Public / Visitor Surveys ---
$visitor_surveys = $conn->query("SELECT * FROM surveys WHERE target_audience IN ('visitor', 'public', 'all') ORDER BY id DESC LIMIT 3");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <?php include 'theme_style.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Portal · Campus Facilities &amp; Services Evaluation — <?php echo htmlspecialchars($system_name); ?></title>
    
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --vis-font-head: 'Outfit', sans-serif;
            --vis-font-body: 'Inter', sans-serif;
            --vis-primary: #7c3aed;
            --vis-primary-glow: rgba(124, 58, 237, 0.25);
            --vis-emerald: #10b981;
            --vis-emerald-glow: rgba(16, 185, 129, 0.2);
            --vis-amber: #f59e0b;
            --vis-blue: #3b82f6;
        }

        body {
            font-family: var(--vis-font-body);
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Ambient glowing backdrop elements */
        .ambient-glow {
            position: fixed;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.15;
            pointer-events: none;
            z-index: 0;
        }
        .glow-1 { top: -100px; right: -100px; background: radial-gradient(circle, #7c3aed, #3b82f6); }
        .glow-2 { bottom: -150px; left: -150px; background: radial-gradient(circle, #10b981, #06b6d4); }
        [data-theme="dark"] .ambient-glow { opacity: 0.22; }

        /* Top Navigation Header */
        .vis-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }
        [data-theme="dark"] .vis-header {
            background: rgba(15, 23, 42, 0.85);
        }

        .vis-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
        }
        .vis-logos {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .vis-logo-img {
            width: 36px;
            height: 36px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.08));
        }
        .vis-brand-text {
            display: flex;
            flex-direction: column;
        }
        .vis-brand-title {
            font-family: var(--vis-font-head);
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .vis-portal-badge {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.25));
            color: #10b981;
            border: 1px solid rgba(16,185,129,0.3);
        }
        .vis-brand-sub {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .vis-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .vis-user-chip {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 6px 14px;
            border-radius: 30px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
            cursor: default;
            user-select: none;
        }
        .vis-user-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--vis-primary), #3b82f6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
        }
        .vis-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .vis-icon-btn:hover {
            border-color: var(--vis-primary);
            color: var(--vis-primary);
            transform: translateY(-1px);
        }

        .vis-exit-btn {
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .vis-exit-btn:hover {
            background: var(--input-bg);
            border-color: var(--border-color);
            transform: translateY(-1px);
        }

        /* Container Layout */
        .vis-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 24px 60px;
            position: relative;
            z-index: 1;
        }

        /* Flash Message Banners */
        .vis-alert-banner {
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            animation: slideDown 0.3s ease;
        }
        .vis-alert-warning {
            background: rgba(245, 158, 11, 0.12);
            border: 1.5px solid rgba(245, 158, 11, 0.4);
            color: #d97706;
        }
        [data-theme="dark"] .vis-alert-warning {
            color: #fbbf24;
        }
        .vis-alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1.5px solid rgba(16, 185, 129, 0.4);
            color: #059669;
        }
        [data-theme="dark"] .vis-alert-success {
            color: #34d399;
        }
        .vis-alert-info {
            background: rgba(59, 130, 246, 0.12);
            border: 1.5px solid rgba(59, 130, 246, 0.4);
            color: #2563eb;
        }
        [data-theme="dark"] .vis-alert-info {
            color: #60a5fa;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Hero Welcome Section - Exclusive for Visitor */
        .vis-hero {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.09) 0%, rgba(16, 185, 129, 0.08) 50%, rgba(59, 130, 246, 0.06) 100%);
            border: 1.5px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            margin-bottom: 30px;
        }
        .vis-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #7c3aed, #3b82f6, #10b981);
        }

        .vis-hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(124, 58, 237, 0.12);
            color: var(--vis-primary);
            border: 1px solid rgba(124, 58, 237, 0.25);
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 16px;
        }
        .vis-hero-title {
            font-family: var(--vis-font-head);
            font-size: 32px;
            font-weight: 900;
            line-height: 1.18;
            margin: 0 0 12px 0;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }
        .vis-hero-title span {
            background: linear-gradient(135deg, #7c3aed, #2563eb);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .vis-hero-desc {
            font-size: 14.5px;
            color: var(--text-muted);
            max-width: 760px;
            line-height: 1.6;
            margin: 0 0 24px 0;
        }

        /* Locked Persona Classification Badge */
        .vis-locked-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-top: 4px;
        }
        .vis-locked-label {
            font-size: 11.5px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .vis-locked-val {
            font-size: 12.5px;
            font-weight: 800;
            color: var(--vis-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Key Metrics Grid */
        .vis-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 32px;
        }
        @media (max-width: 992px) {
            .vis-stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .vis-stats-grid { grid-template-columns: 1fr; }
        }

        .vis-stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .vis-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            border-color: var(--vis-primary);
        }
        .vis-stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .vis-stat-label {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
        }
        .vis-stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .vis-stat-val {
            font-family: var(--vis-font-head);
            font-size: 30px;
            font-weight: 900;
            color: var(--text-main);
            margin: 0;
            line-height: 1;
        }
        .vis-stat-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .vis-progress-track {
            width: 100%;
            height: 7px;
            background: rgba(0,0,0,0.06);
            border-radius: 8px;
            margin-top: 10px;
            overflow: hidden;
        }
        [data-theme="dark"] .vis-progress-track {
            background: rgba(255,255,255,0.08);
        }
        .vis-progress-fill {
            height: 100%;
            border-radius: 8px;
            background: linear-gradient(90deg, var(--vis-primary), var(--vis-emerald));
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Facility Evaluation Core Section */
        .vis-eval-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
        }

        .vis-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .vis-section-title-wrap h2 {
            font-family: var(--vis-font-head);
            font-size: 22px;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .vis-section-title-wrap p {
            margin: 4px 0 0 0;
            font-size: 13.5px;
            color: var(--text-muted);
        }

        /* Search & Filter Toolbar */
        .vis-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border-color);
        }

        .vis-filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .vis-tab-pill {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .vis-tab-pill:hover {
            border-color: var(--vis-primary);
            color: var(--text-main);
            transform: translateY(-1px);
        }
        .vis-tab-pill.active {
            background: var(--vis-primary);
            border-color: var(--vis-primary);
            color: white;
            box-shadow: 0 4px 12px var(--vis-primary-glow);
        }

        .vis-search-box {
            position: relative;
            min-width: 280px;
            flex-grow: 1;
            max-width: 360px;
        }
        .vis-search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
        }
        .vis-search-input {
            width: 100%;
            padding: 9px 16px 9px 38px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-main);
            font-size: 13px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .vis-search-input:focus {
            border-color: var(--vis-primary);
            box-shadow: 0 0 0 3px var(--vis-primary-glow);
        }

        /* Facility Cards Grid */
        .vis-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .vis-card {
            background: var(--card-bg);
            border: 1.5px solid var(--border-color);
            border-radius: 20px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            cursor: pointer;
            transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }
        .vis-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--card-accent, var(--vis-primary));
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .vis-card:hover {
            transform: translateY(-4px);
            border-color: var(--card-accent, var(--vis-primary));
            box-shadow: 0 12px 28px rgba(0,0,0,0.06);
        }
        .vis-card:hover::after {
            opacity: 1;
        }

        .vis-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .vis-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            transition: transform 0.25s ease;
        }
        .vis-card:hover .vis-card-icon {
            transform: scale(1.08) rotate(3deg);
        }

        .vis-card-badge {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 9px;
            border-radius: 12px;
            background: var(--input-bg);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }

        .vis-card-title {
            font-family: var(--vis-font-head);
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 6px 0;
            line-height: 1.25;
        }
        .vis-card-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
            margin: 0 0 18px 0;
            min-height: 38px;
        }

        .vis-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .vis-status-pill {
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .vis-status-done {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .vis-status-pending {
            background: var(--input-bg);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }

        .vis-action-cta {
            font-size: 12.5px;
            font-weight: 800;
            color: var(--vis-primary);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: gap 0.2s ease;
        }
        .vis-card:hover .vis-action-cta {
            gap: 8px;
        }

        /* Two Column Lower Section */
        .vis-lower-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 24px;
        }
        @media (max-width: 900px) {
            .vis-lower-grid { grid-template-columns: 1fr; }
        }

        .vis-section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 28px;
        }
        .vis-card-heading {
            font-family: var(--vis-font-head);
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .vis-card-subheading {
            font-size: 12.5px;
            color: var(--text-muted);
            margin: 0 0 20px 0;
        }

        .vis-history-item {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }
        .vis-history-item:hover {
            border-color: var(--vis-primary);
            transform: translateX(3px);
        }

        /* Modal Overlay for Identity Customization */
        .vis-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .vis-modal.active {
            display: flex;
        }
        .vis-modal-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            width: 100%;
            max-width: 480px;
            padding: 32px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            animation: modalPop 0.25s ease;
            position: relative;
        }
        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .vis-modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: transparent;
            border: none;
            font-size: 18px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .vis-modal-close:hover { color: var(--text-main); }
    </style>
</head>
<body>

    <!-- Ambient Floating Orbs -->
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>

    <!-- Top Navigation Header -->
    <header class="vis-header">
        <a href="index.php" class="vis-brand" title="MCNP-ISAP Institutional Quality Assurance">
            <div class="vis-logos">
                <img src="MCNP_Logo.png" alt="MCNP" class="vis-logo-img" onerror="this.src='Logo.png'">
                <img src="ISAP_Logo.png" alt="ISAP" class="vis-logo-img" onerror="this.src='Logo.png'">
            </div>
            <div class="vis-brand-text">
                <div class="vis-brand-title">
                    MCNP-ISAP <span class="vis-portal-badge"><i class="fas fa-building-user"></i> Visitor Portal</span>
                </div>
                <div class="vis-brand-sub">Campus Facilities &amp; Services Evaluation</div>
            </div>
        </a>

        <div class="vis-nav-actions">
            <!-- Visitor Persona / Name Chip (Display Only) -->
            <div class="vis-user-chip" title="Registered Visitor">
                <div class="vis-user-avatar">
                    <i class="fas <?php echo $is_registered_visitor ? 'fa-user-check' : 'fa-user'; ?>"></i>
                </div>
                <div>
                    <span style="display:block; line-height: 1.1; font-weight:700;"><?php echo htmlspecialchars($visitor_name); ?></span>
                    <span style="font-size: 10px; font-weight: 700; color: var(--vis-primary); text-transform: uppercase;">
                        <?php echo htmlspecialchars($visitor_category); ?>
                    </span>
                </div>
            </div>

            <!-- Light/Dark Mode Switcher -->
            <button type="button" class="vis-icon-btn" id="themeBtn" onclick="toggleTheme()" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>

            <?php if ($is_registered_visitor): ?>
                <a href="profile.php" class="vis-exit-btn" title="Manage Account Profile">
                    <i class="fas fa-user-cog" style="color:var(--vis-primary);"></i> Profile
                </a>
                <a href="logout.php" class="vis-exit-btn" style="color: #ef4444;" title="Sign out of visitor account">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            <?php else: ?>
                <a href="login.php?school=mcnp-isap" class="vis-exit-btn" title="Sign in with registered visitor account">
                    <i class="fas fa-sign-in-alt" style="color:var(--vis-primary);"></i> Visitor Sign In
                </a>
                <a href="index.php" class="vis-exit-btn">
                    <i class="fas fa-home"></i> Home
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="vis-container">

        <!-- Flash Warning Notice (e.g. if visitor attempted to access faculty/dean eval) -->
        <?php if (!empty($flash_warning)): ?>
            <div class="vis-alert-banner vis-alert-warning">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-shield-halved" style="font-size:22px;"></i>
                    <div>
                        <strong style="font-size:14px; display:block;">Visitor Access Boundary</strong>
                        <span style="font-size:13px;"><?php echo htmlspecialchars($flash_warning); ?></span>
                    </div>
                </div>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; color:inherit; cursor:pointer; font-size:16px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Flash Success Notice (e.g. after submission) -->
        <?php if (!empty($flash_success)): ?>
            <div class="vis-alert-banner vis-alert-success">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-check-circle" style="font-size:22px;"></i>
                    <div>
                        <strong style="font-size:14px; display:block;">Feedback Recorded Successfully</strong>
                        <span style="font-size:13px;"><?php echo htmlspecialchars($flash_success); ?></span>
                    </div>
                </div>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; color:inherit; cursor:pointer; font-size:16px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Notice if signed in under student/staff role -->
        <?php if ($is_other_role): ?>
            <div class="vis-alert-banner vis-alert-info">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-user-tag" style="font-size:22px;"></i>
                    <div>
                        <strong style="font-size:14px; display:block;">Logged in as <?php echo htmlspecialchars($other_role_name); ?></strong>
                        <span style="font-size:13px;">You can evaluate campus facilities here, or return to your role-specific dashboard.</span>
                    </div>
                </div>
                <a href="<?php echo ($_SESSION['role'] === 'student' ? 'dashboard_student.php' : ($_SESSION['role'] === 'faculty' ? 'dashboard_faculty.php' : ($_SESSION['role'] === 'dean' ? 'dashboard_dean.php' : 'dashboard_admin.php'))); ?>" class="vis-persona-btn active" style="text-decoration:none; padding:6px 14px;">
                    Return to <?php echo htmlspecialchars($other_role_name); ?> Portal <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <!-- Hero Section Designed Exclusively for Visitors -->
        <section class="vis-hero">
            <div class="vis-hero-tag">
                <i class="fas fa-hand-sparkles"></i> MCNP-ISAP Institutional Quality Assurance · Visitor Edition
            </div>
            <h1 class="vis-hero-title">
                Campus Facilities &amp; <span>Services Evaluation</span>
            </h1>
            <p class="vis-hero-desc">
                Welcome, campus visitors, parents, and community guests! Your first-hand impressions guide our institutional maintenance and hospitality standards. Please share your confidential feedback on our administrative offices, dining, sanitation, safety, and campus environment.
            </p>

            <!-- Assigned Visitor Classification (Locked from registration) -->
            <div class="vis-locked-badge">
                <span class="vis-locked-label"><i class="fas fa-id-badge"></i> Visitor Classification:</span>
                <span class="vis-locked-val"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($visitor_category); ?></span>
            </div>
        </section>

        <!-- Campus Facilities Evaluation Section (Core Purpose) -->
        <section class="vis-eval-section" id="facilities-section">
            
            <div class="vis-section-head">
                <div class="vis-section-title-wrap">
                    <h2><i class="fas fa-building" style="color:var(--vis-primary);"></i> Campus Facilities &amp; Services</h2>
                    <p>Select any facility or office below to provide your rating, comments, and suggestions.</p>
                </div>
                <div style="font-size:12.5px; font-weight:700; color:var(--text-muted); background:var(--input-bg); border:1px solid var(--border-color); padding:6px 14px; border-radius:20px;">
                    <i class="fas fa-shield-alt" style="color:var(--vis-primary);"></i> Protected &amp; Confidential
                </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div class="vis-toolbar">
                <div class="vis-filter-tabs">
                    <button type="button" class="vis-tab-pill active" onclick="filterFacilities('all', this)">
                        <i class="fas fa-th-large"></i> All Facilities (<?php echo count($facility_services); ?>)
                    </button>
                    <button type="button" class="vis-tab-pill" onclick="filterFacilities('office', this)">
                        <i class="fas fa-briefcase"></i> Offices (5)
                    </button>
                    <button type="button" class="vis-tab-pill" onclick="filterFacilities('amenity', this)">
                        <i class="fas fa-coffee"></i> Amenities &amp; Dining (6)
                    </button>
                    <button type="button" class="vis-tab-pill" onclick="filterFacilities('academic', this)">
                        <i class="fas fa-book"></i> Academic &amp; Labs (4)
                    </button>
                </div>

                <div class="vis-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="facilitySearchInput" class="vis-search-input" placeholder="Search facility (e.g. Clinic, WiFi, Library)..." oninput="liveSearchFacilities(this.value)">
                </div>
            </div>

            <!-- Facility Cards Grid -->
            <div class="vis-cards-grid" id="facilityCardsContainer">
                <?php 
                foreach ($facility_services as $fac): 
                    $is_done = isset($evaluated_targets[$fac['name']]);
                    $given_rating = $is_done ? $evaluated_targets[$fac['name']]['rating'] : 0;
                    $eval_time = $is_done ? $evaluated_targets[$fac['name']]['created_at'] : null;
                ?>
                <div class="vis-card fac-item" 
                     data-group="<?php echo $fac['group']; ?>" 
                     data-name="<?php echo strtolower($fac['name']); ?>"
                     data-cat="<?php echo strtolower($fac['cat']); ?>"
                     data-desc="<?php echo strtolower($fac['desc']); ?>"
                     style="--card-accent: <?php echo $fac['color']; ?>;"
                     onclick="window.location.href='evaluate_facility.php?target=<?php echo urlencode($fac['name']); ?>&cat=<?php echo urlencode($fac['cat']); ?>'">
                    
                    <div>
                        <div class="vis-card-header">
                            <div class="vis-card-icon" style="background: linear-gradient(135deg, <?php echo $fac['color']; ?>, <?php echo $fac['color']; ?>dd); box-shadow: 0 4px 14px <?php echo $fac['color']; ?>44;">
                                <i class="fas <?php echo $fac['icon']; ?>"></i>
                            </div>
                            <span class="vis-card-badge"><?php echo htmlspecialchars($fac['cat']); ?></span>
                        </div>

                        <h3 class="vis-card-title"><?php echo htmlspecialchars($fac['name']); ?></h3>
                        <p class="vis-card-desc"><?php echo htmlspecialchars($fac['desc']); ?></p>
                    </div>

                    <div class="vis-card-footer">
                        <?php if ($is_done): ?>
                            <span class="vis-status-pill vis-status-done">
                                <i class="fas fa-check-circle"></i> Evaluated <?php if ($given_rating > 0) echo '· ' . number_format($given_rating, 1) . ' ★'; ?>
                            </span>
                            <span class="vis-action-cta" style="color: #10b981;">
                                Update <i class="fas fa-arrow-right"></i>
                            </span>
                        <?php else: ?>
                            <span class="vis-status-pill vis-status-pending">
                                <i class="fas fa-circle-notch"></i> Needs Rating
                            </span>
                            <span class="vis-action-cta">
                                Evaluate <i class="fas fa-arrow-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Empty Search Results Placeholder -->
            <div id="noResultsMsg" style="display:none; text-align:center; padding:50px 20px;">
                <i class="fas fa-magnifying-glass" style="font-size:36px; color:var(--text-muted); opacity:0.4; margin-bottom:12px; display:block;"></i>
                <div style="font-size:16px; font-weight:800; color:var(--text-main);">No matching campus facilities found</div>
                <p style="font-size:13px; color:var(--text-muted); margin-top:4px;">Try searching with a different keyword like "Clinic", "Office", or "Lab".</p>
                <button type="button" class="vis-tab-pill" style="margin-top:14px;" onclick="resetSearch()">Clear Search</button>
            </div>

        </section>

        <!-- Lower Two-Column Section: History & Institutional Quality Transparency -->
        <div class="vis-lower-grid">
            
            <!-- LEFT COLUMN: Visitor Feedback History -->
            <div class="vis-section-card">
                <h3 class="vis-card-heading">
                    <i class="fas fa-history" style="color:var(--vis-primary);"></i> My Evaluated Facilities
                </h3>
                <p class="vis-card-subheading">
                    Campus facility evaluations submitted from your current visit.
                </p>

                <?php if (!empty($evaluated_targets)): ?>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach($evaluated_targets as $tname => $tinfo): ?>
                            <div class="vis-history-item">
                                <div>
                                    <div style="font-weight: 800; font-size: 14.5px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-circle-check" style="color: #10b981; font-size: 14px;"></i>
                                        <?php echo htmlspecialchars($tname); ?>
                                    </div>
                                    <?php if (!empty($tinfo['feedback'])): ?>
                                        <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 4px; font-style: italic;">
                                            "<?php echo htmlspecialchars(mb_strimwidth($tinfo['feedback'], 0, 80, '...')); ?>"
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">
                                        <?php echo date('M d, Y · h:i A', strtotime($tinfo['created_at'])); ?>
                                    </div>
                                </div>
                                <div style="text-align: right; flex-shrink: 0;">
                                    <span style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; font-weight: 800; font-size: 13px; padding: 4px 10px; border-radius: 20px; display: inline-block;">
                                        <?php echo number_format($tinfo['rating'], 1); ?> ★
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; background: var(--input-bg); border-radius: 16px; border: 1.5px dashed var(--border-color);">
                        <i class="fas fa-clipboard-list" style="font-size: 36px; color: var(--text-muted); opacity: 0.35; margin-bottom: 12px; display: block;"></i>
                        <div style="font-weight: 800; font-size: 15px; color: var(--text-main);">No facility feedback recorded yet</div>
                        <p style="font-size: 13px; color: var(--text-muted); margin: 6px auto 0; max-width: 320px; line-height: 1.5;">
                            Click on any of the 15 campus facilities above to share your satisfaction rating and observations!
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Active Public Surveys for Visitors -->
                <?php if ($visitor_surveys && $visitor_surveys->num_rows > 0): ?>
                    <div style="margin-top: 28px; border-top: 1px solid var(--border-color); padding-top: 22px;">
                        <h4 style="font-family:var(--vis-font-head); font-size: 15px; font-weight: 800; color: var(--text-main); margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-poll-h" style="color: var(--vis-primary);"></i> Open Institutional Surveys
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php while($sv = $visitor_surveys->fetch_assoc()): ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--input-bg);">
                                    <div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: var(--text-main);"><?php echo htmlspecialchars($sv['title']); ?></div>
                                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;"><?php echo htmlspecialchars(mb_strimwidth($sv['description'] ?? '', 0, 60, '...')); ?></div>
                                    </div>
                                    <a href="public_survey.php?id=<?php echo $sv['id']; ?>" class="vis-persona-btn active" style="text-decoration:none; padding: 6px 14px; font-size: 11.5px;">Take Survey</a>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: Top Rated Facilities & Confidentiality -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                
                <!-- Ranked Facilities -->
                <div class="vis-section-card">
                    <h3 class="vis-card-heading">
                        <i class="fas fa-award" style="color: #f59e0b;"></i> Top-Rated Campus Facilities
                    </h3>
                    <p class="vis-card-subheading">
                        Highest satisfaction ratings based on visitor and campus reviews.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php 
                        $medals = ['🥇', '🥈', '🥉', '⭐'];
                        $rank = 0;
                        if ($top_facilities && $top_facilities->num_rows > 0):
                            while($tf = $top_facilities->fetch_assoc()): 
                                $medal = $medals[$rank++] ?? '⭐';
                        ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="font-size: 20px;"><?php echo $medal; ?></span>
                                    <div>
                                        <div style="font-size: 14px; font-weight: 800; color: var(--text-main);"><?php echo htmlspecialchars($tf['target_name']); ?></div>
                                        <div style="font-size: 11.5px; color: var(--text-muted);"><?php echo $tf['feedback_count']; ?> evaluations submitted</div>
                                    </div>
                                </div>
                                <span style="font-size: 13px; color: #10b981; background: rgba(16, 185, 129, 0.12); font-weight: 800; padding: 4px 10px; border-radius: 12px;">
                                    <?php echo number_format($tf['avg_rating'], 2); ?> ★
                                </span>
                            </div>
                        <?php endwhile; else: ?>
                            <p style="font-size: 13px; color: var(--text-muted); text-align: center; padding: 20px;">No facility rankings compiled yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quality Assurance & Confidentiality Guarantee -->
                <div class="vis-section-card" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(124, 58, 237, 0.06) 100%); border-color: rgba(59, 130, 246, 0.25);">
                    <div style="display: flex; gap: 16px; align-items: flex-start;">
                        <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div>
                            <h4 style="font-family:var(--vis-font-head); margin: 0; font-size: 15px; font-weight: 800; color: var(--text-main);">
                                100% Confidential Quality Assurance
                            </h4>
                            <p style="margin: 6px 0 0; font-size: 12.5px; color: var(--text-muted); line-height: 1.55;">
                                Visitor evaluations are compiled directly by the Institutional Quality Assurance (QA) office. Your constructive observations directly inform facility maintenance, equipment procurement, and administrative staff development.
                            </p>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <footer style="margin-top: 60px; text-align: center; font-size: 12px; color: var(--text-muted); border-top: 1px solid var(--border-color); padding: 32px 0;">
            <p style="margin: 0;">MCNP-ISAP Institutional Quality Assurance &amp; Visitor Feedback · Medical Colleges of Northern Philippines &amp; International School of Asia and the Pacific</p>
        </footer>

    </div>



    <!-- Scripts for Interactivity -->
    <script>
        // Category filtering
        let currentGroupFilter = 'all';

        function filterFacilities(group, btn) {
            currentGroupFilter = group;
            document.querySelectorAll('.vis-tab-pill').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            applyFilters();
        }

        // Live search filtering
        function liveSearchFacilities(keyword) {
            applyFilters();
        }

        function resetSearch() {
            const searchInput = document.getElementById('facilitySearchInput');
            if (searchInput) searchInput.value = '';
            filterFacilities('all', document.querySelector('.vis-tab-pill'));
        }

        function applyFilters() {
            const query = (document.getElementById('facilitySearchInput').value || '').trim().toLowerCase();
            const cards = document.querySelectorAll('.fac-item');
            let visibleCount = 0;

            cards.forEach(card => {
                const group = card.getAttribute('data-group');
                const name = card.getAttribute('data-name');
                const cat = card.getAttribute('data-cat');
                const desc = card.getAttribute('data-desc');

                const matchesGroup = (currentGroupFilter === 'all' || group === currentGroupFilter);
                const matchesQuery = !query || name.includes(query) || cat.includes(query) || desc.includes(query);

                if (matchesGroup && matchesQuery) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const noResults = document.getElementById('noResultsMsg');
            if (noResults) {
                noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }



        // Theme Switcher
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
            updateThemeIcon(targetTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeBtn i');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
    </script>
</body>
</html>