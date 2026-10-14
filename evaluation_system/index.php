<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db_connect.php';
/** @var mysqli $conn */
$system_name_result = (isset($conn) && $conn instanceof mysqli)
    ? $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")
    : false;
$system_name = $system_name_result ? ($system_name_result->fetch_assoc()['setting_value'] ?? 'Faculty Evaluation System') : 'Faculty Evaluation System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'theme_style.php'; ?>
    <title>Welcome — <?php echo htmlspecialchars($system_name); ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #070e26 0%, #0d1f66 30%, #1a2f7a 48%, #520713 74%, #7a0000 90%, #990c24 100%) !important;
            background-attachment: fixed !important;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
            color: #ffffff;
        }

        /* ── MCNP-ISAP Signature Ambient Shapes & Curved Blobs ──────────────── */
        .bg-decorative-shapes {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        /* Giant circular curve sweeping from top-left, matching login blob aesthetic */
        .bg-shape-curve-top {
            position: absolute;
            width: clamp(650px, 70vw, 1100px);
            height: clamp(650px, 70vw, 1100px);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(13, 31, 102, 0.45) 0%, rgba(9, 19, 56, 0.1) 70%, transparent 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            top: -300px;
            left: -200px;
            animation: slowFloatA 18s ease-in-out infinite alternate;
        }

        /* Large circular shape in the upper right, directly matching reference image */
        .bg-shape-circle-tr {
            position: absolute;
            width: clamp(480px, 50vw, 750px);
            height: clamp(480px, 50vw, 750px);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.03);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            top: -120px;
            right: -100px;
            animation: slowFloatB 22s ease-in-out infinite alternate;
        }

        /* Deep Crimson sweep at the bottom-right */
        .bg-shape-circle-bl {
            position: absolute;
            width: clamp(520px, 55vw, 850px);
            height: clamp(520px, 55vw, 850px);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(122, 0, 0, 0.35) 0%, rgba(74, 0, 13, 0.1) 70%, transparent 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.07);
            bottom: -220px;
            right: -80px;
            animation: slowFloatA 20s ease-in-out infinite alternate-reverse;
        }

        /* Ambient Glowing Blooms */
        .bg-glow-blue {
            position: absolute;
            width: 700px;
            height: 700px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(26, 47, 122, 0.5) 0%, rgba(37, 99, 235, 0.22) 40%, transparent 70%);
            top: -80px;
            left: 5%;
            filter: blur(80px);
        }

        .bg-glow-red {
            position: absolute;
            width: 750px;
            height: 750px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(196, 18, 48, 0.42) 0%, rgba(122, 0, 0, 0.2) 45%, transparent 70%);
            bottom: -100px;
            right: 0%;
            filter: blur(80px);
        }

        @keyframes slowFloatA {
            0% {
                transform: translate(0, 0) scale(1);
            }

            100% {
                transform: translate(25px, 20px) scale(1.03);
            }
        }

        @keyframes slowFloatB {
            0% {
                transform: translate(0, 0) scale(1);
            }

            100% {
                transform: translate(-20px, 25px) scale(0.97);
            }
        }

        /* Floating Background Icons */
        .bg-icons {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bg-icons i {
            position: absolute;
            color: #ffffff;
            opacity: 0.035;
            animation: floatAnim 24s ease-in-out infinite alternate;
        }

        @keyframes floatAnim {
            0% {
                transform: translateY(0) rotate(0deg) scale(1);
            }

            50% {
                transform: translateY(-40px) rotate(10deg) scale(1.06);
            }

            100% {
                transform: translateY(20px) rotate(-8deg) scale(0.96);
            }
        }

        /* ── Top Nav ────────────────────────────────────────────────────────── */
        .top-nav {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 36px;
            background: rgba(7, 14, 38, 0.78) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12) !important;
            z-index: 100;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.35) !important;
            flex-shrink: 0;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-logo {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-weight: 800;
            font-size: 14px;
            color: #ffffff !important;
            line-height: 1.15;
        }

        .brand-subtitle {
            font-size: 10px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.65) !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .top-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-btn {
            padding: 7px 18px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn.login-btn {
            background: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.22) !important;
            backdrop-filter: blur(8px);
        }

        .nav-btn.login-btn:hover {
            background: rgba(255, 255, 255, 0.18) !important;
            border-color: rgba(255, 255, 255, 0.45) !important;
            transform: translateY(-1px);
        }

        .nav-btn.register-btn {
            background: linear-gradient(135deg, #1d4ed8, #b91c1c) !important;
            color: #fff !important;
            border: 1px solid rgba(255, 255, 255, 0.35) !important;
            box-shadow: 0 4px 15px rgba(185, 28, 28, 0.35) !important;
            border-radius: 9999px;
            padding: 7px 20px;
        }

        .nav-btn.register-btn:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 6px 22px rgba(185, 28, 28, 0.5) !important;
        }


        /* ── Main Container ─────────────────────────────────────────────────── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px 24px;
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1080px;
            margin: 0 auto;
            text-align: center;
        }

        /* ── Hero Section ───────────────────────────────────────────────────── */
        .hero-section {
            text-align: center;
            max-width: 860px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            overflow: hidden;
            background: rgba(9, 18, 48, 0.58) !important;
            backdrop-filter: blur(24px) !important;
            -webkit-backdrop-filter: blur(24px) !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5), inset 0 1px 1px rgba(255, 255, 255, 0.14) !important;
            border-radius: 28px !important;
            padding: clamp(34px, 5vw, 48px) clamp(22px, 4vw, 42px) !important;
            animation: riseUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .hero-accent-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1d4ed8 0%, #3b82f6 30%, #8b5cf6 60%, #b91c1c 100%);
        }

        /* Hero Brand Header: Pill Tag & Logos matching reference image */
        .hero-brand-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .blob-school-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 16px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.28);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #ffffff;
            text-transform: uppercase;
            margin-bottom: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(8px);
        }

        .hero-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 8px 18px;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.05);
            border: 1.5px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
            position: relative;
        }

        .hero-logo-wrap::before {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            pointer-events: none;
            animation: ringPulse 4s ease-in-out infinite;
        }

        @keyframes ringPulse {

            0%,
            100% {
                opacity: 0.35;
                transform: scale(1);
            }

            50% {
                opacity: 0.75;
                transform: scale(1.03);
            }
        }

        .hero-logo {
            width: clamp(48px, 5.5vw, 56px);
            height: clamp(48px, 5.5vw, 56px);
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.45));
            transition: transform 0.25s ease;
        }

        .hero-logo:hover {
            transform: scale(1.08);
        }

        .main-title {
            font-size: clamp(2rem, 3.2vw, 3rem);
            font-weight: 900;
            color: #ffffff !important;
            line-height: 1.25;
            letter-spacing: -0.5px;
            margin: 0 0 16px;
            text-shadow: 0 3px 20px rgba(0, 0, 0, 0.4);
            text-wrap: balance;
        }

        .main-title .highlight {
            background: linear-gradient(135deg, #60a5fa 0%, #c084fc 50%, #fb7185 100%);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }

        .sys-desc {
            font-size: clamp(14px, 1.1vw, 16.5px);
            color: rgba(255, 255, 255, 0.86) !important;
            line-height: 1.65;
            max-width: 760px;
            margin: 0 auto;
            font-weight: 450;
        }


        .hero-trust-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            font-size: 12.5px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.75);
        }

        .trust-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .trust-dot {
            opacity: 0.4;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--card-bg);
            padding: 36px 32px;
            border-radius: 24px;
            width: 100%;
            max-width: 480px;
            transform: translateY(20px);
            transition: all 0.3s;
            position: relative;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .close-btn {
            position: absolute;
            top: 18px;
            right: 18px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            font-size: 16px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .close-btn:hover {
            color: var(--text-main);
            background: var(--toggle-bg);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 6px;
            text-align: center;
        }

        .modal-schools {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 20px;
        }

        .m-school-btn {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border-radius: 16px;
            text-decoration: none;
            border: 1px solid var(--border-color);
            transition: all 0.25s;
            background: var(--input-bg);
        }

        .m-school-btn:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            background: var(--card-bg);
        }

        .m-school-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .m-school-info {
            display: flex;
            flex-direction: column;
        }

        .m-school-name {
            font-weight: 800;
            font-size: 1.15rem;
            color: var(--text-main);
        }

        .m-school-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .role-tag {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            letter-spacing: 0.3px;
        }

        @keyframes riseUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            body {
                height: auto;
                min-height: 100vh;
                min-height: 100dvh;
                overflow-y: auto;
            }

            .top-nav {
                padding: 12px 20px;
            }

            .main {
                padding: 40px 20px;
            }
        }

        @media (max-width: 600px) {
            .brand-subtitle {
                display: none;
            }

            .main-title {
                font-size: 1.65rem;
            }

            .top-nav {
                padding: 10px 14px;
            }

            .nav-btn {
                padding: 6px 12px;
                font-size: 11.5px;
            }

            .modal-content {
                padding: 24px 18px;
                width: calc(100% - 24px);
            }
        }
    </style>
</head>

<body>
    <!-- MCNP-ISAP Signature Ambient Shapes & Curved Blobs -->
    <div class="bg-decorative-shapes">
        <div class="bg-glow-blue"></div>
        <div class="bg-glow-red"></div>
        <div class="bg-shape-curve-top"></div>
        <div class="bg-shape-circle-tr"></div>
        <div class="bg-shape-circle-bl"></div>
    </div>

    <!-- Floating Background Icons -->
    <div class="bg-icons">
        <i class="fas fa-clipboard-check" style="top:10%;left:6%;font-size:140px;animation-delay:0s;"></i>
        <i class="fas fa-chart-pie" style="top:72%;left:86%;font-size:160px;animation-delay:-5s;"></i>
        <i class="fas fa-user-graduate" style="top:16%;left:82%;font-size:130px;animation-delay:-10s;"></i>
        <i class="fas fa-hospital" style="top:68%;left:8%;font-size:180px;animation-delay:-15s;"></i>
        <i class="fas fa-chalkboard-teacher" style="top:48%;left:-2%;font-size:150px;animation-delay:-3s;"></i>
        <i class="fas fa-award" style="top:84%;left:46%;font-size:100px;animation-delay:-12s;"></i>
    </div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav">
        <a class="brand" href="index.php">
            <img src="MCNP_Logo.png" alt="MCNP Logo" class="brand-logo" onerror="this.src='Logo.png'">
            <img src="ISAP_Logo.png" alt="ISAP Logo" class="brand-logo" onerror="this.src='Logo.png'">
            <div class="brand-text">
                <span class="brand-title">MCNP-ISAP</span>
                <span class="brand-subtitle">Evaluation System</span>
            </div>
        </a>
        <div class="top-right">
            <a href="javascript:void(0)" onclick="openModal('login')" class="nav-btn login-btn">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
            <a href="javascript:void(0)" onclick="openModal('register')" class="nav-btn register-btn">
                <i class="fas fa-user-plus"></i> Register
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="main">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-accent-bar"></div>

            <!-- Brand Header: Pill Tag & Dual Logos matching login blob -->
            <div class="hero-brand-header">
                <div class="blob-school-tag">MCNP-ISAP</div>
                <div class="hero-logo-wrap">
                    <img src="MCNP_Logo.png" class="hero-logo" alt="MCNP" onerror="this.src='Logo.png'">
                    <img src="ISAP_Logo.png" class="hero-logo" alt="ISAP" onerror="this.src='Logo.png'">
                </div>
            </div>

            <h1 class="main-title">
                Development of a School-Wide Evaluation System with Sentiment Analysis for <span class="highlight">MCNP-ISAP</span>
            </h1>

            <p class="sys-desc">
                An intelligent institutional quality assurance platform integrating multi-stakeholder evaluations with automated sentiment analysis and real-time performance analytics for MCNP &amp; ISAP.
            </p>


            <!-- Bottom Institutional Trust Bar -->
            <div class="hero-trust-bar">
                <span class="trust-pill"><i class="fas fa-shield-alt" style="color: #34d399;"></i> 100% Confidential Submissions</span>
                <span class="trust-dot">·</span>
                <span class="trust-pill"><i class="fas fa-university" style="color: #60a5fa;"></i> Unified MCNP &amp; ISAP Campuses</span>
                <span class="trust-dot">·</span>
                <span class="trust-pill"><i class="fas fa-bolt" style="color: #fbbf24;"></i> Real-Time Quality Assurance</span>
            </div>
        </div>
    </main>

    <!-- Modal for Institution Selection -->
    <div class="modal-overlay" id="instModal" onclick="if(event.target===this) closeModal()">
        <div class="modal-content">
            <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            <div class="modal-title" id="modalTitle">Select Institution</div>
            <div id="modalSubtitle" style="text-align:center; font-size:13px; color:var(--text-muted); margin-top:-2px; margin-bottom:16px; font-weight:500;"></div>
            <div class="modal-schools">
                <!-- MCNP-ISAP: Faculty, Dean, Admin, ICT Admin, Visitor -->
                <a href="#" id="btnMCNPISAP" class="m-school-btn" style="border-left:4px solid #8b5cf6;">
                    <div style="display:flex; gap:6px; flex-shrink:0;">
                        <img src="MCNP_Logo.png" alt="MCNP" class="m-school-logo" style="width:38px;height:38px;" onerror="this.src='Logo.png'">
                        <img src="ISAP_Logo.png" alt="ISAP" class="m-school-logo" style="width:38px;height:38px;" onerror="this.src='Logo.png'">
                    </div>
                    <div class="m-school-info">
                        <span class="m-school-name">MCNP-ISAP</span>
                        <span class="m-school-desc">Faculty, Dean, Admin &amp; ICT Staff</span>
                        <div style="margin-top:5px; display:flex; gap:5px; flex-wrap:wrap;">
                            <span class="role-tag" style="background:rgba(139,92,246,0.12); color:#8b5cf6;">Faculty</span>
                            <span class="role-tag" style="background:rgba(59,130,246,0.12); color:#3b82f6;">Dean</span>
                            <span class="role-tag" style="background:rgba(245,158,11,0.12); color:#f59e0b;">Admin</span>
                            <span class="role-tag" style="background:rgba(16,185,129,0.12); color:#10b981;">ICT</span>
                        </div>
                    </div>
                </a>

                <div style="display:flex; align-items:center; gap:12px; margin:4px 0;">
                    <div style="flex:1; height:1px; background:var(--border-color);"></div>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;">Students</span>
                    <div style="flex:1; height:1px; background:var(--border-color);"></div>
                </div>

                <!-- MCNP: Students only -->
                <a href="#" id="btnMCNP" class="m-school-btn" style="border-left:4px solid #2563eb;">
                    <img src="MCNP_Logo.png" alt="MCNP" class="m-school-logo" onerror="this.src='Logo.png'">
                    <div class="m-school-info">
                        <span class="m-school-name">MCNP</span>
                        <span class="m-school-desc">Medical Colleges of Northern Philippines</span>
                        <div style="margin-top:5px;">
                            <span class="role-tag" style="background:rgba(37,99,235,0.12); color:#2563eb;">Student Portal</span>
                        </div>
                    </div>
                </a>
                <!-- ISAP: Students only -->
                <a href="#" id="btnISAP" class="m-school-btn" style="border-left:4px solid #c41230;">
                    <img src="ISAP_Logo.png" alt="ISAP" class="m-school-logo" onerror="this.src='Logo.png'">
                    <div class="m-school-info">
                        <span class="m-school-name">ISAP</span>
                        <span class="m-school-desc">International School of Asia and the Pacific</span>
                        <div style="margin-top:5px;">
                            <span class="role-tag" style="background:rgba(196,18,48,0.12); color:#c41230;">Student Portal</span>
                        </div>
                    </div>
                </a>

                <div style="display:flex; align-items:center; gap:12px; margin:4px 0;">
                    <div style="flex:1; height:1px; background:var(--border-color);"></div>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;">Visitors &amp; Guests</span>
                    <div style="flex:1; height:1px; background:var(--border-color);"></div>
                </div>

                <!-- Visitor Portal Option -->
                <a href="#" id="btnVisitor" class="m-school-btn" style="border-left:4px solid #10b981; text-decoration:none;">
                    <div style="display:flex; gap:6px; flex-shrink:0;">
                        <img src="MCNP_Logo.png" alt="MCNP" class="m-school-logo" style="width:38px;height:38px;" onerror="this.src='Logo.png'">
                        <img src="ISAP_Logo.png" alt="ISAP" class="m-school-logo" style="width:38px;height:38px;" onerror="this.src='Logo.png'">
                    </div>
                    <div class="m-school-info">
                        <span class="m-school-name" style="color:#10b981;">Campus Visitor Portal</span>
                        <span class="m-school-desc">Visitors, parents &amp; community — Evaluate campus facilities &amp; services directly</span>
                        <div style="margin-top:5px; display:flex; gap:5px; flex-wrap:wrap;">
                            <span class="role-tag" style="background:rgba(16,185,129,0.12); color:#10b981;">Facilities &amp; Services</span>
                            <span class="role-tag" style="background:rgba(59,130,246,0.12); color:#3b82f6;">Guest or Account</span>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <script>
        function openModal(action) {
            const isLogin = action === 'login';
            document.getElementById('modalTitle').textContent = isLogin ? 'Select Institution to Login' : 'Select Institution to Register';
            document.getElementById('modalSubtitle').textContent = isLogin ?
                'Choose where your account belongs' :
                'Select the institution you want to register under';
            const isReg = !isLogin;
            const hash = isReg ? '#register' : '';
            const panelParam = isReg ? '&panel=register' : '';
            document.getElementById('btnMCNPISAP').href = 'login.php?school=mcnp-isap' + panelParam + hash;
            document.getElementById('btnMCNP').href = 'login.php?school=mcnp' + panelParam + hash;
            document.getElementById('btnISAP').href = 'login.php?school=isap' + panelParam + hash;
            document.getElementById('btnVisitor').href = 'login.php?school=mcnp-isap&role=visitor' + panelParam + hash;
            document.getElementById('instModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('instModal').classList.remove('active');
        }
    </script>
</body>

</html>