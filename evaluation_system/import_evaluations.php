<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

$allowed_roles = ['admin', 'system admin', 'dean'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$session_id = $_SESSION['id'] ?? 0;
$session_dept = $_SESSION['dept_id'] ?? null;

// Get active academic period
$active_period = $conn->query("SELECT id, semester, school_year FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$active_id = (int)($active_period['id'] ?? 1);
$active_sy = trim($active_period['school_year'] ?? '2026-2027');
if ($active_sy === '2026' || empty($active_sy)) $active_sy = '2026-2027';
$active_sem = trim($active_period['semester'] ?? '1st Semester');
$period_label = "{$active_sem} {$active_sy}";

// Fetch QAO questions from database
$q_online_ids = [];
$q_inperson_ids = [];
$q_feedbacking_ids = [];

$q_res = $conn->query("SELECT id, category FROM evaluation_questions WHERE category IN ('Conduct of Online Class', 'Conduct of In-person Class', 'Feedbacking Mechanism') ORDER BY id ASC");
if ($q_res) {
    while ($q = $q_res->fetch_assoc()) {
        if ($q['category'] === 'Conduct of Online Class') $q_online_ids[] = (int)$q['id'];
        elseif ($q['category'] === 'Conduct of In-person Class') $q_inperson_ids[] = (int)$q['id'];
        elseif ($q['category'] === 'Feedbacking Mechanism') $q_feedbacking_ids[] = (int)$q['id'];
    }
}

// Function to find or create respondent student user
function getOrCreateStudent($conn, $resp_no, $dept_id, $school)
{
    $uname = "RESP-" . str_pad($resp_no, 4, '0', STR_PAD_LEFT);
    $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $chk->bind_param("s", $uname);
    $chk->execute();
    $found = $chk->get_result()->fetch_assoc();
    if ($found) {
        return (int)$found['id'];
    }

    $fname = "Student Respondent " . $resp_no;
    $pass = password_hash('imported_eval_pwd', PASSWORD_DEFAULT);
    $d_id = $dept_id ?: 3;
    $sch = in_array(strtolower($school), ['mcnp', 'isap']) ? strtolower($school) : 'isap';
    $ins = $conn->prepare("
        INSERT INTO users (username, password, role, full_name, first_name, last_name, dept_id, school, status)
        VALUES (?, ?, 'student', ?, 'Student', ?, ?, ?, 'active')
    ");
    $l_name = "Respondent " . $resp_no;
    $ins->bind_param("ssssss", $uname, $pass, $fname, $l_name, $d_id, $sch);
    $ins->execute();
    return (int)$conn->insert_id;
}

// Function to extract and parse XLSX (OpenXML) files
function parseXlsxToRows($filePath)
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xlsx_' . uniqid();
    if (!@mkdir($tempDir, 0777, true)) {
        return [];
    }

    $extracted = false;
    // 1. Try tar.exe (built-in on Windows 10/11)
    $cmd = 'tar.exe -xf ' . escapeshellarg($filePath) . ' -C ' . escapeshellarg($tempDir) . ' 2>&1';
    @shell_exec($cmd);
    if (file_exists($tempDir . '/xl/worksheets/sheet1.xml') || file_exists($tempDir . '/xl/sharedStrings.xml')) {
        $extracted = true;
    }

    // 2. Fallback to PowerShell Expand-Archive
    if (!$extracted) {
        $psCmd = 'powershell -NoProfile -Command "Expand-Archive -LiteralPath ' . escapeshellarg($filePath) . ' -DestinationPath ' . escapeshellarg($tempDir) . ' -Force" 2>&1';
        @shell_exec($psCmd);
        if (file_exists($tempDir . '/xl/worksheets/sheet1.xml')) {
            $extracted = true;
        }
    }

    if (!$extracted) {
        @rmdir($tempDir);
        return [];
    }

    // Read shared strings
    $sharedStrings = [];
    $ssFile = $tempDir . '/xl/sharedStrings.xml';
    if (file_exists($ssFile)) {
        $xml = @simplexml_load_string(file_get_contents($ssFile));
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $txt = '';
                    foreach ($si->r as $r) {
                        $txt .= (string)$r->t;
                    }
                    $sharedStrings[] = $txt;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    // Read sheet1.xml
    $rows = [];
    $sheetFile = $tempDir . '/xl/worksheets/sheet1.xml';
    if (file_exists($sheetFile)) {
        $sheetXml = @simplexml_load_string(file_get_contents($sheetFile));
        if ($sheetXml && isset($sheetXml->sheetData->row)) {
            foreach ($sheetXml->sheetData->row as $rowNode) {
                $cells = [];
                $colIdx = 0;
                foreach ($rowNode->c as $cNode) {
                    $rAttr = (string)$cNode['r'];
                    if (preg_match('/^([A-Z]+)(\d+)$/', $rAttr, $m)) {
                        $colLetters = $m[1];
                        $targetIdx = 0;
                        for ($i = 0; $i < strlen($colLetters); $i++) {
                            $targetIdx = $targetIdx * 26 + (ord($colLetters[$i]) - ord('A') + 1);
                        }
                        $targetIdx -= 1;
                        while ($colIdx < $targetIdx) {
                            $cells[$colIdx] = '';
                            $colIdx++;
                        }
                    }

                    $type = (string)$cNode['t'];
                    $val = isset($cNode->v) ? (string)$cNode->v : '';
                    if ($type === 's' && isset($sharedStrings[(int)$val])) {
                        $val = $sharedStrings[(int)$val];
                    } elseif ($type === 'inlineStr' && isset($cNode->is->t)) {
                        $val = (string)$cNode->is->t;
                    }
                    $cells[$colIdx] = trim($val);
                    $colIdx++;
                }
                if (!empty($cells)) {
                    $rows[] = $cells;
                }
            }
        }
    }

    // Cleanup temp files recursively
    try {
        $it = new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $f) {
            if ($f->isDir()) {
                @rmdir($f->getRealPath());
            } else {
                @unlink($f->getRealPath());
            }
        }
        @rmdir($tempDir);
    } catch (Exception $e) {
    }

    return $rows;
}

// Helper to auto-match faculty by name from file header
function matchFacultyByName($conn, $raw_name, $role, $session_dept)
{
    $clean = trim($raw_name);
    if (empty($clean)) return null;

    // Remove prefixes/suffixes
    $clean = preg_replace('/^(dr\.|engr\.|prof\.|ms\.|mr\.|mrs\.)\s+/i', '', $clean);

    // Split into distinct words
    $words = preg_split('/[\s,]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) return null;

    $dept_filter = ($role === 'dean' && $session_dept) ? " AND dept_id = " . (int)$session_dept : "";

    // 1. Try full string match
    $stmt1 = $conn->prepare("SELECT id, full_name, dept_id, school FROM users WHERE role='faculty' AND status='active' $dept_filter AND full_name LIKE ? LIMIT 1");
    $p1 = "%$clean%";
    $stmt1->bind_param("s", $p1);
    $stmt1->execute();
    $r1 = $stmt1->get_result()->fetch_assoc();
    if ($r1) return $r1;

    // 2. Try match if 2 or more words (e.g. Ganal Maricris or Maricris Ganal)
    if (count($words) >= 2) {
        $w1 = "%" . $words[0] . "%";
        $w2 = "%" . $words[1] . "%";
        $stmt2 = $conn->prepare("
            SELECT id, full_name, dept_id, school FROM users 
            WHERE role='faculty' AND status='active' $dept_filter AND (
                (full_name LIKE ? AND full_name LIKE ?) OR
                (last_name LIKE ? AND first_name LIKE ?) OR
                (last_name LIKE ? AND first_name LIKE ?)
            ) LIMIT 1
        ");
        $stmt2->bind_param("ssssss", $w1, $w2, $w1, $w2, $w2, $w1);
        $stmt2->execute();
        $r2 = $stmt2->get_result()->fetch_assoc();
        if ($r2) return $r2;
    }

    // 3. Match by any unique word >= 4 chars (like 'Ganal')
    foreach ($words as $w) {
        if (strlen($w) >= 4) {
            $wLike = "%$w%";
            $stmt3 = $conn->prepare("SELECT id, full_name, dept_id, school FROM users WHERE role='faculty' AND status='active' $dept_filter AND (last_name LIKE ? OR full_name LIKE ?) LIMIT 1");
            $stmt3->bind_param("ss", $wLike, $wLike);
            $stmt3->execute();
            $r3 = $stmt3->get_result()->fetch_assoc();
            if ($r3) return $r3;
        }
    }

    return null;
}

$success_data = null;
$error_message = '';

// Handle Import Process
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['eval_file'])) {
    $file = $_FILES['eval_file'];
    $selected_fac_id = (int)($_POST['faculty_id'] ?? 0);
    $replace_existing = isset($_POST['replace_existing']) ? true : false;

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] == 0) {
        $error_message = 'Please select a valid non-empty file to upload.';
    } else {
        $filename = $file['name'];
        $tmp_path = $file['tmp_name'];
        $content  = file_get_contents($tmp_path);

        $parsed_faculty_name = '';
        $students_rows = [];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Helper to extract faculty name from a line of text
        $extractFacultyName = function ($line_txt) use (&$parsed_faculty_name) {
            $faculty_keys = ['Name of Faculty:', 'Faculty Name:', 'Name of Instructor:', 'Instructor:', 'Faculty:'];
            foreach ($faculty_keys as $fk) {
                if (stripos($line_txt, $fk) !== false) {
                    $parts = explode($fk, $line_txt);
                    $candidate = trim($parts[1] ?? '');
                    foreach (['School Year:', 'Semester:', 'S.Y.:', 'Academic Year:', 'Period:'] as $sk) {
                        if (stripos($candidate, $sk) !== false) {
                            $sp = explode($sk, $candidate);
                            $candidate = trim($sp[0]);
                        }
                    }
                    if (!empty($candidate)) {
                        $parsed_faculty_name = $candidate;
                        return true;
                    }
                }
            }
            return false;
        };

        // Helper to check and append student row
        $checkAppendRow = function ($cells) use (&$students_rows) {
            if (empty($cells)) return;
            $c0 = trim($cells[0] ?? '');
            $c1 = trim($cells[1] ?? '');
            if (is_numeric($c0) && (int)$c0 > 0) {
                $students_rows[] = array_map('trim', $cells);
            } elseif (empty($c0) && is_numeric($c1) && (int)$c1 > 0) {
                // Shifted row (column A was an empty margin)
                array_shift($cells);
                $students_rows[] = array_map('trim', $cells);
            }
        };

        if ($file_ext === 'xlsx') {
            // Modern Excel .xlsx (OpenXML ZIP)
            $xlsx_rows = parseXlsxToRows($tmp_path);
            foreach ($xlsx_rows as $row) {
                $line_txt = implode(' ', $row);
                $extractFacultyName($line_txt);
                $checkAppendRow($row);
            }
        } elseif (stripos($content, '<table') !== false && stripos($content, '<tr') !== false) {
            // HTML / Excel table parsing (.xls)
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $tables = $dom->getElementsByTagName('table');
            foreach ($tables as $tbl) {
                $trs = $tbl->getElementsByTagName('tr');
                foreach ($trs as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $node) {
                        if ($node->nodeName === 'td' || $node->nodeName === 'th') {
                            $cells[] = trim($node->textContent);
                        }
                    }
                    if (empty($cells)) continue;

                    $line_txt = implode(' ', $cells);
                    $extractFacultyName($line_txt);
                    $checkAppendRow($cells);
                }
            }
        } else {
            // CSV / TSV / Delimited parsing
            $lines = preg_split("/\r\n|\n|\r/", $content);
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row = str_getcsv($line);
                if (empty($row) || (count($row) === 1 && empty($row[0]))) continue;

                $line_txt = implode(' ', $row);
                $extractFacultyName($line_txt);
                $checkAppendRow($row);
            }
        }

        // Resolve Target Faculty
        $target_faculty = null;
        if ($selected_fac_id > 0) {
            $f_res = $conn->query("SELECT id, full_name, dept_id, school FROM users WHERE id = $selected_fac_id AND role='faculty'");
            $target_faculty = $f_res ? $f_res->fetch_assoc() : null;
        } elseif (!empty($parsed_faculty_name)) {
            $target_faculty = matchFacultyByName($conn, $parsed_faculty_name, $role, $session_dept);
        }

        if (!$target_faculty) {
            $error_message = "Could not identify the Faculty Member. Please select a faculty member from the dropdown or verify the 'Name of Faculty' in your file (Detected: '" . htmlspecialchars($parsed_faculty_name) . "').";
        } elseif (empty($students_rows)) {
            $error_message = "No student evaluation response rows found. Please ensure the file has student rows numbered 1, 2, 3... with ratings.";
        } else {
            // Process the Rows
            $fac_id  = (int)$target_faculty['id'];
            $dept_id = (int)($target_faculty['dept_id'] ?? 3);
            $school  = $target_faculty['school'] ?? 'isap';

            $conn->begin_transaction();
            try {
                // Delete existing evaluations if requested
                if ($replace_existing) {
                    $old_evals_stmt = $conn->prepare("SELECT id FROM evaluations WHERE faculty_id = ? AND academic_period_id = ?");
                    $old_evals_stmt->bind_param("ii", $fac_id, $active_id);
                    $old_evals_stmt->execute();
                    $old_evals = $old_evals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                    if (!empty($old_evals)) {
                        $old_ids = array_column($old_evals, 'id');
                        $old_ids_str = implode(',', array_map('intval', $old_ids));
                        $conn->query("DELETE FROM evaluation_responses WHERE evaluation_id IN ($old_ids_str)");
                        $conn->query("DELETE FROM evaluations WHERE id IN ($old_ids_str)");
                    }
                }

                $imported_count = 0;
                $sum_overall = 0;
                $sum_online = 0;
                $sum_inperson = 0;
                $sum_feedbacking = 0;

                foreach ($students_rows as $row) {
                    $resp_no = (int)$row[0];
                    $st_id = null; // Anonymous imported evaluation (no fake student respondent accounts created)

                    // Extract items depending on column count
                    $col_count = count($row);
                    $online_ratings = [];
                    $inperson_ratings = [];
                    $feedbacking_ratings = [];
                    $comment = '';

                    if ($col_count >= 40) {
                        // Standard official 42-column layout (with 3 AVG columns)
                        // Online: 1..16
                        for ($i = 1; $i <= 16; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $online_ratings[] = min(5.0, max(1.0, $val));
                        }
                        // In-person: 18..32 (skip col 17 online avg)
                        for ($i = 18; $i <= 32; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $inperson_ratings[] = min(5.0, max(1.0, $val));
                        }
                        // Feedbacking: 34..39 (skip col 33 in-person avg)
                        for ($i = 34; $i <= 39; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $feedbacking_ratings[] = min(5.0, max(1.0, $val));
                        }
                        // Comment: last element
                        $comment = trim(end($row));
                    } elseif ($col_count >= 37) {
                        // Direct 37 items layout without intermediate AVGs
                        for ($i = 1; $i <= 16; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $online_ratings[] = min(5.0, max(1.0, $val));
                        }
                        for ($i = 17; $i <= 31; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $inperson_ratings[] = min(5.0, max(1.0, $val));
                        }
                        for ($i = 32; $i <= 37; $i++) {
                            $val = isset($row[$i]) && is_numeric($row[$i]) ? (float)$row[$i] : 5.0;
                            $feedbacking_ratings[] = min(5.0, max(1.0, $val));
                        }
                        $comment = isset($row[38]) ? trim($row[38]) : '';
                    } else {
                        // Dynamic fallback for any column size
                        $numeric_vals = [];
                        for ($i = 1; $i < $col_count - 1; $i++) {
                            if (is_numeric($row[$i])) $numeric_vals[] = min(5.0, max(1.0, (float)$row[$i]));
                        }
                        // Distribute proportionally
                        $online_ratings = array_slice($numeric_vals, 0, 16);
                        while (count($online_ratings) < 16) $online_ratings[] = 5.0;

                        $inperson_ratings = array_slice($numeric_vals, 16, 15);
                        while (count($inperson_ratings) < 15) $inperson_ratings[] = 5.0;

                        $feedbacking_ratings = array_slice($numeric_vals, 31, 6);
                        while (count($feedbacking_ratings) < 6) $feedbacking_ratings[] = 5.0;

                        $comment = trim(end($row));
                    }

                    if (empty($comment) || is_numeric($comment)) {
                        $comment = 'None';
                    }

                    // Averages
                    $avg_on = array_sum($online_ratings) / count($online_ratings);
                    $avg_in = array_sum($inperson_ratings) / count($inperson_ratings);
                    $avg_fb = array_sum($feedbacking_ratings) / count($feedbacking_ratings);
                    $eval_rating = round(($avg_on + $avg_in + $avg_fb) / 3, 2);

                    $sum_online += $avg_on;
                    $sum_inperson += $avg_in;
                    $sum_feedbacking += $avg_fb;
                    $sum_overall += $eval_rating;

                    // Insert Evaluation
                    $ins_e = $conn->prepare("
                        INSERT INTO evaluations (student_id, faculty_id, academic_period_id, rating, feedback, target_name)
                        VALUES (?, ?, ?, ?, ?, 'Faculty')
                    ");
                    $ins_e->bind_param("iiids", $st_id, $fac_id, $active_id, $eval_rating, $comment);
                    $ins_e->execute();
                    $eval_id = $conn->insert_id;

                    // Insert item responses for Online Questions
                    foreach ($online_ratings as $idx => $r_val) {
                        $qid = $q_online_ids[$idx] ?? 0;
                        if ($qid > 0) {
                            $r_ins = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                            $r_int = round($r_val);
                            $r_ins->bind_param("iii", $eval_id, $qid, $r_int);
                            $r_ins->execute();
                        }
                    }

                    // Insert item responses for In-person Questions
                    foreach ($inperson_ratings as $idx => $r_val) {
                        $qid = $q_inperson_ids[$idx] ?? 0;
                        if ($qid > 0) {
                            $r_ins = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                            $r_int = round($r_val);
                            $r_ins->bind_param("iii", $eval_id, $qid, $r_int);
                            $r_ins->execute();
                        }
                    }

                    // Insert item responses for Feedbacking Questions
                    foreach ($feedbacking_ratings as $idx => $r_val) {
                        $qid = $q_feedbacking_ids[$idx] ?? 0;
                        if ($qid > 0) {
                            $r_ins = $conn->prepare("INSERT INTO evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                            $r_int = round($r_val);
                            $r_ins->bind_param("iii", $eval_id, $qid, $r_int);
                            $r_ins->execute();
                        }
                    }

                    $imported_count++;
                }

                $conn->commit();

                // Compute Summary Stats
                $mean_online = $imported_count > 0 ? round($sum_online / $imported_count, 2) : 0;
                $mean_inperson = $imported_count > 0 ? round($sum_inperson / $imported_count, 2) : 0;
                $mean_feedbacking = $imported_count > 0 ? round($sum_feedbacking / $imported_count, 2) : 0;
                $grand_overall = $imported_count > 0 ? round($sum_overall / $imported_count, 2) : 0;

                $success_data = [
                    'faculty'       => $target_faculty,
                    'count'         => $imported_count,
                    'online_mean'   => $mean_online,
                    'inperson_mean' => $mean_inperson,
                    'feed_mean'     => $mean_feedbacking,
                    'grand_mean'    => $grand_overall,
                    'period'        => $period_label
                ];
            } catch (Exception $ex) {
                $conn->rollback();
                $error_message = "Error during import: " . $ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Evaluation Responses</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .import-container {
            max-width: 950px;
            margin: 0 auto;
        }

        .card-upload {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
        }

        .dropzone {
            border: 2px dashed var(--accent);
            border-radius: 10px;
            padding: 35px 20px;
            text-align: center;
            background: rgba(99, 102, 241, 0.03);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .dropzone:hover {
            background: rgba(99, 102, 241, 0.08);
            border-color: #4f46e5;
        }

        .stats-badge {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .grid-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .summary-box {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }

        .summary-val {
            font-size: 22px;
            font-weight: 800;
            color: var(--accent);
            margin-top: 4px;
        }

        .summary-lbl {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 600;
        }
    </style>
</head>

<body class="dashboard-body">
    <div class="split-container">
        <?php
        $active_page = 'import_evaluations';
        $active_page_dean = 'import_evaluations';
        if ($role === 'dean') {
            include 'dean_sidebar.php';
        } else {
            include 'admin_sidebar.php';
        }
        ?>

        <main class="main-content">
            <div class="import-container">

                <!-- Header -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                    <div>
                        <a href="print_center.php" style="color:var(--text-muted); text-decoration:none; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:5px; margin-bottom:6px;">
                            <i class="fas fa-arrow-left"></i> Back to Print Center
                        </a>
                        <h2 style="color:var(--text-main); margin:0;">
                            <i class="fas fa-file-import" style="color:var(--accent);"></i> Import Evaluation Responses (Excel / CSV)
                        </h2>
                        <p style="color:var(--text-muted); font-size:13px; margin:4px 0 0;">
                            Directly upload spreadsheet evaluation sheets to populate student ratings, category scores, and feedback.
                        </p>
                    </div>
                    <div class="stats-badge">
                        <i class="fas fa-calendar-alt"></i> Active: <?php echo htmlspecialchars($period_label); ?>
                    </div>
                </div>

                <!-- Error Banner -->
                <?php if (!empty($error_message)): ?>
                    <div style="background:#fee2e2; border:1px solid #f87171; color:#991b1b; padding:15px 20px; border-radius:10px; margin-bottom:20px; display:flex; align-items:center; gap:12px; font-weight:600;">
                        <i class="fas fa-exclamation-triangle" style="font-size:20px;"></i>
                        <div><?php echo htmlspecialchars($error_message); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Success State Banner -->
                <?php if ($success_data): ?>
                    <div class="card-upload" style="border-top: 4px solid #10b981; background: var(--card-bg);">
                        <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px;">
                            <div style="width:48px; height:48px; border-radius:50%; background:#d1fae5; color:#10b981; display:flex; align-items:center; justify-content:center; font-size:24px;">
                                <i class="fas fa-check"></i>
                            </div>
                            <div>
                                <h3 style="margin:0; color:var(--text-main);">Evaluations Successfully Imported!</h3>
                                <p style="margin:4px 0 0; color:var(--text-muted); font-size:13px;">
                                    Total <strong><?php echo $success_data['count']; ?></strong> student evaluation responses were recorded for <strong><?php echo htmlspecialchars($success_data['faculty']['full_name']); ?></strong> (<?php echo htmlspecialchars($success_data['period']); ?>).
                                </p>
                            </div>
                        </div>

                        <div class="grid-summary">
                            <div class="summary-box">
                                <div class="summary-lbl">Online Class</div>
                                <div class="summary-val"><?php echo number_format($success_data['online_mean'], 2); ?></div>
                            </div>
                            <div class="summary-box">
                                <div class="summary-lbl">In-person Class</div>
                                <div class="summary-val"><?php echo number_format($success_data['inperson_mean'], 2); ?></div>
                            </div>
                            <div class="summary-box">
                                <div class="summary-lbl">Feedbacking</div>
                                <div class="summary-val"><?php echo number_format($success_data['feed_mean'], 2); ?></div>
                            </div>
                            <div class="summary-box" style="border-color:#10b981; background:rgba(16,185,129,0.05);">
                                <div class="summary-lbl" style="color:#059669;">Overall Rating</div>
                                <div class="summary-val" style="color:#059669;"><?php echo number_format($success_data['grand_mean'], 2); ?></div>
                            </div>
                        </div>

                        <!-- Direct Action Buttons -->
                        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:20px; border-top:1px solid var(--border-color); padding-top:20px;">
                            <a href="print_report.php?faculty_id=<?php echo $success_data['faculty']['id']; ?>&school=<?php echo htmlspecialchars($success_data['faculty']['school'] ?? 'isap'); ?>" target="_blank" class="btn-action" style="background:#0b5394; color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-print"></i> Print Official Form 013
                            </a>
                            <a href="export_excel.php?faculty_id=<?php echo $success_data['faculty']['id']; ?>&school=<?php echo htmlspecialchars($success_data['faculty']['school'] ?? 'isap'); ?>" class="btn-action" style="background:#107c41; color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-file-excel"></i> Download Excel Summary (.xls)
                            </a>
                            <a href="export_word.php?faculty_id=<?php echo $success_data['faculty']['id']; ?>&school=<?php echo htmlspecialchars($success_data['faculty']['school'] ?? 'isap'); ?>" class="btn-action" style="background:#2b579a; color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-file-word"></i> Download Word (.doc)
                            </a>
                            <a href="view_results.php?faculty_id=<?php echo $success_data['faculty']['id']; ?>" class="btn-action" style="background:var(--accent); color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-chart-line"></i> View in Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Upload Form Card -->
                <div class="card-upload">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 style="margin:0; color:var(--text-main);"><i class="fas fa-cloud-upload-alt" style="color:var(--accent);"></i> Upload Evaluation File</h3>
                        <div style="display:flex; gap:10px;">
                            <a href="download_template.php?format=excel" class="btn-action" style="background:#107c41; color:white; font-size:12px; padding:7px 12px; border-radius:6px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fas fa-download"></i> Blank Excel Template (.xls)
                            </a>
                            <a href="download_template.php?format=csv" class="btn-action" style="background:#475569; color:white; font-size:12px; padding:7px 12px; border-radius:6px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fas fa-download"></i> CSV Template (.csv)
                            </a>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px;">Select Faculty Member:</label>
                                <select name="faculty_id" id="faculty_id" style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-main);">
                                    <option value="0">-- Auto-detect from file header (or select manually) --</option>
                                    <?php
                                    $query = "SELECT u.id, u.full_name, u.school, d.dept_alias FROM users u LEFT JOIN departments d ON u.dept_id = d.id WHERE u.role = 'faculty' AND u.status = 'active'";
                                    if ($role == 'dean' && $session_dept) {
                                        $query .= " AND u.dept_id = $session_dept";
                                    }
                                    $query .= " ORDER BY u.full_name ASC";
                                    $fac_res = $conn->query($query);
                                    if ($fac_res) {
                                        while ($f = $fac_res->fetch_assoc()) {
                                            $sc_tag = strtoupper($f['school'] ?? 'ISAP');
                                            $d_tag = $f['dept_alias'] ? " ({$f['dept_alias']})" : "";
                                            echo "<option value='{$f['id']}'>[" . htmlspecialchars($sc_tag) . "] " . htmlspecialchars($f['full_name'] . $d_tag) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                                <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">
                                    If you leave this as Auto-detect, the system will read the <code>Name of Faculty:</code> row in your Excel/CSV.
                                </small>
                            </div>

                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px;">Academic Period:</label>
                                <input type="text" value="<?php echo htmlspecialchars($period_label); ?>" disabled style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-main); font-weight:bold;">
                                <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">
                                    Evaluations are strictly linked to the active academic period.
                                </small>
                            </div>
                        </div>

                        <!-- File Dropzone -->
                        <div class="dropzone" onclick="document.getElementById('eval_file').click()">
                            <i class="fas fa-file-excel" style="font-size:42px; color:#107c41; margin-bottom:12px;"></i>
                            <h4 style="margin:0 0 6px; color:var(--text-main);">Click to browse or drop evaluation spreadsheet</h4>
                            <p style="margin:0; font-size:12px; color:var(--text-muted);">
                                Supported formats: <strong>.xls</strong>, <strong>.xlsx</strong>, <strong>.csv</strong>, <strong>.tsv</strong>, <strong>.txt</strong>
                            </p>
                            <input type="file" name="eval_file" id="eval_file" required accept=".xls,.xlsx,.csv,.tsv,.txt" style="display:none;" onchange="updateFileName(this)">
                            <div id="file-name-display" style="margin-top:12px; font-weight:700; color:var(--accent); font-size:13px;"></div>
                        </div>

                        <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center;">
                            <label style="display:inline-flex; align-items:center; gap:8px; font-size:13px; color:var(--text-main); cursor:pointer;">
                                <input type="checkbox" name="replace_existing" value="1" checked style="width:16px; height:16px;">
                                <span>Replace any existing evaluations for this faculty in <strong><?php echo htmlspecialchars($period_label); ?></strong></span>
                            </label>

                            <button type="submit" class="btn-action" style="background:#10b981; color:white; border:none; padding:12px 24px; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-cloud-upload-alt"></i> Import Evaluations Now
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Format Reference Guide -->
                <div class="card-upload" style="background:var(--card-bg); font-size:13px;">
                    <h4 style="margin:0 0 10px; color:var(--text-main);"><i class="fas fa-info-circle" style="color:var(--accent);"></i> Spreadsheet Layout Structure</h4>
                    <p style="color:var(--text-muted); line-height:1.6; margin-bottom:12px;">
                        Your uploaded file should match the official <strong>Quality Assurance Office (QAO)</strong> format as shown below:
                    </p>
                    <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:8px; padding:15px; font-family:monospace; font-size:12px; overflow-x:auto;">
                        <strong>Header:</strong><br>
                        Row 1: STUDENT FACULTY EVALUATION RESPONSES SUMMARY<br>
                        Row 2: Name of Faculty: LASTNAME, FIRSTNAME<br>
                        Row 3: School Year: 2026-2027 &nbsp;&nbsp;&nbsp;&nbsp; Semester: First<br><br>
                        <strong>Columns (42 Columns):</strong><br>
                        &bull; <code>Col 1</code>: No. (1, 2, 3... 85)<br>
                        &bull; <code>Cols 2-17</code>: Conduct of Online Class (Items 1 to 16)<br>
                        &bull; <code>Col 18</code>: Online AVG<br>
                        &bull; <code>Cols 19-33</code>: Conduct of In-person Class (Items 1 to 15)<br>
                        &bull; <code>Col 34</code>: In-person AVG<br>
                        &bull; <code>Cols 35-40</code>: Feedbacking Mechanism (Items 1 to 6)<br>
                        &bull; <code>Col 41</code>: Feedbacking AVG<br>
                        &bull; <code>Col 42</code>: Comments and Suggestions for Improvement<br>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        function updateFileName(input) {
            const display = document.getElementById('file-name-display');
            if (input.files && input.files[0]) {
                display.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> Selected file: <strong>' + input.files[0].name + '</strong> (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
            } else {
                display.innerHTML = '';
            }
        }
    </script>
</body>

</html>