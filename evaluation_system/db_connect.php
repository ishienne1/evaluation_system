<?php
// Prevent duplicate connection initialization if already connected
if (isset($conn) && $conn instanceof mysqli && @$conn->ping()) {
    return;
}

// Start output buffering if not started to prevent "Headers already sent" errors
if (ob_get_level() === 0) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Connection candidates for cross-machine compatibility
$hosts = ['127.0.0.1', 'localhost'];
$ini_port = (int)ini_get('mysqli.default_port');
$ports = array_unique(array_filter([$ini_port ?: 3306, 3306, 3307, 3308]));
$creds = [
    ['user' => 'root', 'pass' => ''],
    ['user' => 'root', 'pass' => 'root'],
];
$db_candidates = ['faculty_eval', 'faculty_eval_system'];

/** @var mysqli|null $conn */
$conn = null;
$connected_host = null;
$connected_port = 3306;
$connected_user = 'root';
$connection_errors = [];

// Temporarily disable exception throwing during probing
$prev_report = mysqli_report(MYSQLI_REPORT_OFF);

// Step 1: Probe and establish connection to MySQL Server
foreach ($hosts as $h) {
    foreach ($ports as $p) {
        foreach ($creds as $c) {
            try {
                $test_conn = @new mysqli($h, $c['user'], $c['pass'], null, (int)$p);
                if ($test_conn && !$test_conn->connect_error) {
                    $conn = $test_conn;
                    $connected_host = $h;
                    $connected_port = (int)$p;
                    $connected_user = $c['user'];
                    break 3;
                } else {
                    $connection_errors[] = "$h:$p (" . ($test_conn ? $test_conn->connect_error : 'Connection refused') . ")";
                }
            } catch (Throwable $e) {
                $connection_errors[] = "$h:$p (" . $e->getMessage() . ")";
            }
        }
    }
}

// If MySQL is completely unreachable, display friendly diagnostics
if (!$conn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Connection Error — Faculty Evaluation System</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            .box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 580px; width: 100%; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
            h2 { color: #f87171; margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 22px; }
            p { color: #94a3b8; line-height: 1.6; font-size: 14.5px; }
            .step-box { background: #0b0f19; border: 1px solid #1e293b; border-radius: 10px; padding: 16px 20px; margin: 18px 0; }
            .step-box ol { margin: 0; padding-left: 20px; color: #cbd5e1; font-size: 14px; line-height: 1.8; }
            .step-box ol strong { color: #38bdf8; }
            .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 22px; border-radius: 8px; font-weight: 600; font-size: 14px; transition: background 0.2s; cursor: pointer; border: none; }
            .btn:hover { background: #1d4ed8; }
            .details { font-family: monospace; font-size: 11.5px; color: #64748b; background: rgba(0,0,0,0.3); padding: 10px; border-radius: 6px; margin-top: 14px; overflow-x: auto; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>⚠️ Database Connection Error</h2>
            <p>The system was unable to connect to MySQL on your local machine. Please make sure MySQL is running in your <strong>XAMPP Control Panel</strong>.</p>
            
            <div class="step-box">
                <ol>
                    <li>Open <strong>XAMPP Control Panel</strong> on your computer.</li>
                    <li>Look for <strong>MySQL</strong> and click the <strong>Start</strong> button.</li>
                    <li>Ensure the status changes to green (<strong>Running</strong>).</li>
                    <li>Click the <strong>Retry Connection</strong> button below.</li>
                </ol>
            </div>

            <button onclick="window.location.reload()" class="btn">🔄 Retry Connection</button>

            <div class="details">
                Attempted ports: <?php echo implode(', ', $ports); ?><br>
                Host(s): <?php echo implode(', ', $hosts); ?><br>
                Diagnostic logs: <?php echo htmlspecialchars(implode(' | ', array_slice($connection_errors, 0, 4))); ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

/** @var mysqli $conn */

// Step 2: Select or create database
$selected_db = null;
foreach ($db_candidates as $db_name) {
    if (@$conn->select_db($db_name)) {
        $selected_db = $db_name;
        break;
    }
}

// Auto-create database if neither candidate exists
if (!$selected_db) {
    $conn->query("CREATE DATABASE IF NOT EXISTS `faculty_eval` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    if ($conn->select_db('faculty_eval')) {
        $selected_db = 'faculty_eval';
    }
}

if (!$selected_db) {
    die("Database setup failed. Please manually create a database named 'faculty_eval' in phpMyAdmin.");
}

$conn->set_charset("utf8mb4");

// Step 3: Check if database has essential tables; if empty, auto-import faculty_eval.sql
$check_tables = @$conn->query("SHOW TABLES LIKE 'users'");
if ($check_tables && $check_tables->num_rows === 0) {
    $sql_file = __DIR__ . '/faculty_eval.sql';
    if (file_exists($sql_file)) {
        $sql_content = file_get_contents($sql_file);
        if ($sql_content) {
            if ($conn->multi_query($sql_content)) {
                do {
                    if ($res = $conn->store_result()) {
                        $res->free();
                    }
                } while ($conn->more_results() && $conn->next_result());
            }
        }
    }
}

// Step 4: Safe Migrations
try {
    // Check if users table exists before modifying
    $chk_u = @$conn->query("SHOW TABLES LIKE 'users'");
    if ($chk_u && $chk_u->num_rows > 0) {
        // Auto-migrate theme_color column
        $check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_color'");
        if ($check_col && $check_col->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD theme_color VARCHAR(20) DEFAULT 'blue'");
        }

        // Auto-migrate first_name, middle_name, last_name columns
        $check_first = $conn->query("SHOW COLUMNS FROM users LIKE 'first_name'");
        if ($check_first && $check_first->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD first_name VARCHAR(50) NULL DEFAULT NULL AFTER full_name");
        }
        $check_middle = $conn->query("SHOW COLUMNS FROM users LIKE 'middle_name'");
        if ($check_middle && $check_middle->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD middle_name VARCHAR(50) NULL DEFAULT NULL AFTER first_name");
        }
        $check_last = $conn->query("SHOW COLUMNS FROM users LIKE 'last_name'");
        if ($check_last && $check_last->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD last_name VARCHAR(50) NULL DEFAULT NULL AFTER middle_name");
        }

        // Auto-migrate reset_token and reset_token_expiry columns
        $check_token = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_token'");
        if ($check_token && $check_token->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD reset_token VARCHAR(64) NULL DEFAULT NULL AFTER email");
        }
        $check_expiry = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_token_expiry'");
        if ($check_expiry && $check_expiry->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD reset_token_expiry DATETIME NULL DEFAULT NULL AFTER reset_token");
        }

        // Auto-migrate role column enum if 'system admin' is not in type definition
        $check_role_col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($check_role_col) {
            $role_info = $check_role_col->fetch_assoc();
            if ($role_info && strpos($role_info['Type'], 'system admin') === false) {
                $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'system admin', 'dean', 'student', 'faculty', 'visitor') NOT NULL");
            }
        }
    }

    // Auto-migrate email_otp table for OTP-based email verification
    $conn->query("CREATE TABLE IF NOT EXISTS email_otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(191) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        purpose ENUM('register', 'reset') NOT NULL DEFAULT 'register',
        expires_at DATETIME NOT NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_purpose (email, purpose)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Auto-migrate evaluation_categories table
    $conn->query("CREATE TABLE IF NOT EXISTS evaluation_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(100) NOT NULL UNIQUE
    )");

    // Seed default categories if empty
    $chk_cat_tbl = @$conn->query("SHOW TABLES LIKE 'evaluation_categories'");
    if ($chk_cat_tbl && $chk_cat_tbl->num_rows > 0) {
        $check_cats = $conn->query("SELECT COUNT(*) FROM evaluation_categories")->fetch_row()[0] ?? 0;
        if ($check_cats == 0) {
            $default_cats = ['Teaching', 'Professionalism', 'Support', 'Assessment', 'School', 'Canteen', 'Laboratory', 'Gym', 'Registrar', 'Finance'];
            foreach ($default_cats as $cat_item) {
                $conn->query("INSERT IGNORE INTO evaluation_categories (category_name) VALUES ('" . $conn->real_escape_string($cat_item) . "')");
            }
        }
    }

    // Auto-migrate subjects table columns
    $chk_subj_tbl = @$conn->query("SHOW TABLES LIKE 'subjects'");
    if ($chk_subj_tbl && $chk_subj_tbl->num_rows > 0) {
        $check_subj_fac = $conn->query("SHOW COLUMNS FROM subjects LIKE 'faculty_id'");
        if ($check_subj_fac && $check_subj_fac->num_rows == 0) {
            $conn->query("ALTER TABLE subjects ADD faculty_id INT NULL AFTER dept_id");
        }
        $check_subj_yl = $conn->query("SHOW COLUMNS FROM subjects LIKE 'year_level'");
        if ($check_subj_yl && $check_subj_yl->num_rows == 0) {
            $conn->query("ALTER TABLE subjects ADD year_level VARCHAR(50) NULL AFTER faculty_id");
        }
        $check_subj_sec = $conn->query("SHOW COLUMNS FROM subjects LIKE 'section'");
        if ($check_subj_sec && $check_subj_sec->num_rows == 0) {
            $conn->query("ALTER TABLE subjects ADD section VARCHAR(50) NULL AFTER year_level");
        }
        $check_subj_course = $conn->query("SHOW COLUMNS FROM subjects LIKE 'course_id'");
        if ($check_subj_course && $check_subj_course->num_rows == 0) {
            $conn->query("ALTER TABLE subjects ADD course_id INT NULL AFTER dept_id");
        }
    }

    // Ensure visitor_category column exists in users table
    $chk_vc = @$conn->query("SHOW COLUMNS FROM users LIKE 'visitor_category'");
    if ($chk_vc && $chk_vc->num_rows == 0) {
        @$conn->query("ALTER TABLE users ADD COLUMN visitor_category VARCHAR(50) DEFAULT 'Campus Guest' AFTER role");
    }

    // Auto-seed additional evaluation questions (including Dean Evaluation)
    $chk_eq_tbl = @$conn->query("SHOW TABLES LIKE 'evaluation_questions'");
    if ($chk_eq_tbl && $chk_eq_tbl->num_rows > 0) {
        // Ensure Dean category exists
        @$conn->query("INSERT IGNORE INTO evaluation_categories (category_name) VALUES ('Dean')");

        $additional_questions = [
            ['School', 'Overall quality and standard of academic programs offered by the institution'],
            ['School', 'Responsiveness of the institution to student concerns and feedback'],
            ['Gym', 'Availability of gym schedules and fairness in usage allocation for students'],
            ['Guidance', 'Accessibility and availability of guidance counselors during school hours'],
            ['Clinic', 'Availability of essential medicines and first-aid supplies in the school clinic'],
            ['IT-Services', 'Responsiveness and helpfulness of IT support staff for technical issues'],
            ['Dean', 'Demonstrates effective academic leadership and strategic vision for the college/department.'],
            ['Dean', 'Actively supports faculty professional growth, academic endeavors, and instructional welfare.'],
            ['Dean', 'Communicates administrative policies, institutional updates, and memos clearly and promptly.'],
            ['Dean', 'Demonstrates impartiality, fairness, and transparency in assigning teaching loads and departmental duties.'],
            ['Dean', 'Handles faculty concerns, administrative requests, and student issues promptly and objectively.'],
            ['Dean', 'Promotes a positive, respectful, and collaborative working environment among departmental colleagues.'],
            ['Dean', 'Encourages curricular quality, modern instructional delivery, and innovation in teaching practices.'],
            ['Dean', 'Maintains accessibility, approachability, and high standards of professional ethics.']
        ];
        foreach ($additional_questions as $aq) {
            $chk = $conn->prepare("SELECT id FROM evaluation_questions WHERE question_text = ?");
            if ($chk) {
                $chk->bind_param("s", $aq[1]);
                $chk->execute();
                if ($chk->get_result()->num_rows == 0) {
                    $ins = $conn->prepare("INSERT INTO evaluation_questions (question_text, category) VALUES (?, ?)");
                    if ($ins) {
                        $ins->bind_param("ss", $aq[1], $aq[0]);
                        $ins->execute();
                    }
                }
            }
        }
    }

    // Ensure classes and enrollments exist for the active period
    $chk_ap_tbl = @$conn->query("SHOW TABLES LIKE 'academic_periods'");
    if ($chk_ap_tbl && $chk_ap_tbl->num_rows > 0) {
        $active_period = $conn->query("SELECT id FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
        $active_period_id = $active_period['id'] ?? 0;

        if ($active_period_id > 0) {
            $chk_cls_tbl = @$conn->query("SHOW TABLES LIKE 'classes'");
            if ($chk_cls_tbl && $chk_cls_tbl->num_rows > 0) {
                $check_classes = $conn->query("SELECT COUNT(*) FROM classes WHERE period_id = $active_period_id")->fetch_row()[0] ?? 0;
                if ($check_classes == 0) {
                    $fac_res = $conn->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 3");
                    $fac_ids = [];
                    if ($fac_res) {
                        while ($row = $fac_res->fetch_assoc()) {
                            $fac_ids[] = $row['id'];
                        }
                    }

                    $sub_res = $conn->query("SELECT id FROM subjects WHERE subject_code IN ('IT301', 'IT101', 'GE101') LIMIT 3");
                    $sub_ids = [];
                    if ($sub_res) {
                        while ($row = $sub_res->fetch_assoc()) {
                            $sub_ids[] = $row['id'];
                        }
                    }

                    for ($i = 0; $i < min(count($fac_ids), count($sub_ids)); $i++) {
                        $f_id = (int)$fac_ids[$i];
                        $s_id = (int)$sub_ids[$i];
                        $conn->query("INSERT INTO classes (faculty_id, subject_id, period_id) VALUES ($f_id, $s_id, $active_period_id) 
                            ON DUPLICATE KEY UPDATE period_id = period_id");
                    }
                }

                $chk_enr_tbl = @$conn->query("SHOW TABLES LIKE 'enrollments'");
                if ($chk_enr_tbl && $chk_enr_tbl->num_rows > 0) {
                    $students_res = $conn->query("SELECT id, course_id, dept_id FROM users WHERE role = 'student'");
                    if ($students_res) {
                        while ($std = $students_res->fetch_assoc()) {
                            $std_id = (int)$std['id'];
                            $std_cid = (int)($std['course_id'] ?? 0);
                            $std_did = (int)($std['dept_id'] ?? 0);
                            
                            $check_std_enroll = $conn->query("SELECT COUNT(*) FROM enrollments WHERE student_id = $std_id")->fetch_row()[0] ?? 0;
                            if ($check_std_enroll == 0 && ($std_cid > 0 || $std_did > 0)) {
                                $c_query = "
                                    SELECT cl.id 
                                    FROM classes cl 
                                    JOIN subjects s ON cl.subject_id = s.id 
                                    WHERE cl.period_id = $active_period_id 
                                      AND (
                                          ($std_cid > 0 AND s.course_id = $std_cid)
                                          OR ($std_cid = 0 AND s.course_id IS NULL AND s.dept_id = $std_did)
                                      )
                                ";
                                $classes_res = $conn->query($c_query);
                                if ($classes_res) {
                                    while ($cls = $classes_res->fetch_assoc()) {
                                        $cls_id = (int)$cls['id'];
                                        $conn->query("INSERT IGNORE INTO enrollments (student_id, class_id) VALUES ($std_id, $cls_id)");
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    // Migrations completed with warnings, do not crash page execution
    error_log("Faculty Eval System Migration Notice: " . $e->getMessage());
}
?>