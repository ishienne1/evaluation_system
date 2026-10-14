<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$msg = '';
$err_msg = '';

// Handle Bulk Upload
if (isset($_POST['bulk_upload_questions']) && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (in_array($ext, ['csv', 'xlsx']) && $file['size'] > 0) {
        $handle = fopen($file['tmp_name'], "r");
        if ($handle !== FALSE) {
            $inserted = 0;
            $skipped = 0;
            $row_idx = 0;
            while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
                $row_idx++;
                if ($row_idx == 1 && (strtolower(trim($data[0])) == 'category' || strtolower(trim($data[1] ?? '')) == 'question_text')) {
                    continue;
                }
                if (count($data) >= 2) {
                    $cat = trim($data[0]);
                    $q_text = trim($data[1]);
                    if (!empty($cat) && !empty($q_text)) {
                        $chk = $conn->prepare("SELECT id FROM evaluation_questions WHERE question_text = ?");
                        $chk->bind_param("s", $q_text);
                        $chk->execute();
                        if ($chk->get_result()->num_rows == 0) {
                            $stmt = $conn->prepare("INSERT INTO evaluation_questions (question_text, category) VALUES (?, ?)");
                            $stmt->bind_param("ss", $q_text, $cat);
                            $stmt->execute();
                            $inserted++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            }
            fclose($handle);
            header("Location: manage_questions.php?msg=bulk_added&count=$inserted&skipped=$skipped");
            exit();
        } else {
            $err_msg = 'Unable to parse uploaded file.';
        }
    } else {
        $err_msg = 'Please upload an Excel format file only (.csv or .xlsx).';
    }
}

// Handle Single Question Save
if (isset($_POST['save_question'])) {
    $id = (int)$_POST['id'];
    $question = trim($_POST['question_text']);
    $category = trim($_POST['category']);

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE evaluation_questions SET question_text = ?, category = ? WHERE id = ?");
        $stmt->bind_param("ssi", $question, $category, $id);
        $msg = 'updated';
    } else {
        $stmt = $conn->prepare("INSERT INTO evaluation_questions (question_text, category) VALUES (?, ?)");
        $stmt->bind_param("ss", $question, $category);
        $msg = 'added';
    }

    $stmt->execute();
    header("Location: manage_questions.php?msg=$msg");
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM evaluation_questions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_questions.php?msg=deleted");
    exit();
}

// Filter by Category
$selected_category = isset($_GET['filter_category']) && $_GET['filter_category'] !== '' ? trim($_GET['filter_category']) : null;
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Questions</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="split-container">
        <!-- Sidebar -->
        <?php 
        $active_page = 'questions'; 
        include 'admin_sidebar.php';
        ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:10px;">
                <h2 style="color:var(--text-main); margin:0;">
                    <i class="fas fa-tasks" style="color:var(--accent);"></i> Manage Questions
                </h2>
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="openAddModal()" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;">
                        <i class="fas fa-plus"></i> Add Question
                    </button>
                    <button type="button" onclick="openBulkModal()" class="btn-action" style="background:var(--accent); color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;">
                        <i class="fas fa-file-excel"></i> Bulk Upload Excel
                    </button>
                </div>
            </div>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Question added successfully.</div>
                <?php elseif ($_GET['msg'] === 'updated'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Question updated successfully.</div>
                <?php elseif ($_GET['msg'] === 'deleted'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Question deleted.</div>
                <?php elseif ($_GET['msg'] === 'bulk_added'): ?>
                    <div class="flash-success"><i class="fas fa-check-circle"></i> Bulk upload complete! Imported <strong><?php echo (int)($_GET['count'] ?? 0); ?></strong> questions<?php if (!empty($_GET['skipped'])): ?>, skipped <strong><?php echo (int)$_GET['skipped']; ?></strong> duplicates<?php endif; ?>.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($err_msg)): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($err_msg); ?></div>
            <?php endif; ?>
            
            <!-- Filter Card -->
            <div class="card" style="margin-bottom:20px;">
                <h3 style="margin-bottom:15px;"><i class="fas fa-filter"></i> Filter Criteria by Category</h3>
                <form method="GET" style="display:flex; gap:15px; align-items:flex-end;">
                    <div class="input-group" style="flex:1; margin:0;">
                        <div class="input-label">Select Category</div>
                        <select name="filter_category" onchange="this.form.submit()" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                            <option value="">All Categories</option>
                            <?php
                            $cat_list = $conn->query("SELECT category_name FROM evaluation_categories ORDER BY category_name ASC");
                            if ($cat_list && $cat_list->num_rows > 0) {
                                while($c = $cat_list->fetch_assoc()) {
                                    $sel = ($selected_category === $c['category_name']) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($c['category_name']) . "\" {$sel}>" . htmlspecialchars($c['category_name']) . "</option>";
                                }
                            } else {
                                $dist = $conn->query("SELECT DISTINCT category FROM evaluation_questions ORDER BY category ASC");
                                while($c = $dist->fetch_assoc()) {
                                    $sel = ($selected_category === $c['category']) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($c['category']) . "\" {$sel}>" . htmlspecialchars($c['category']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <?php if ($selected_category): ?>
                        <a href="manage_questions.php" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:12px 20px; text-decoration:none; border-radius:8px;">Reset Filter</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- List Questions -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;"><i class="fas fa-list-ul"></i> Active Evaluation Criteria</h3>
                    <span class="category-badge" style="font-size:13px;">
                        <?php
                        $cnt_sql = "SELECT COUNT(*) FROM evaluation_questions";
                        if ($selected_category) {
                            $cnt_sql .= " WHERE category = '" . $conn->real_escape_string($selected_category) . "'";
                        }
                        echo $conn->query($cnt_sql)->fetch_row()[0];
                        ?> Total Questions
                    </span>
                </div>
                <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Question</th>
                        <th>Category</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $sql = "SELECT * FROM evaluation_questions";
                    if ($selected_category) {
                        $sql .= " WHERE category = '" . $conn->real_escape_string($selected_category) . "'";
                    }
                    $sql .= " ORDER BY category ASC, id ASC";
                    $res = $conn->query($sql);
                    if ($res && $res->num_rows > 0) {
                        while($row = $res->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                            echo "<td class='cell-wrap'><strong>" . htmlspecialchars($row['question_text']) . "</strong></td>";
                            echo "<td><span class='category-badge' style='background:#eef2ff; color:#4338ca; font-weight:700;'>" . htmlspecialchars($row['category']) . "</span></td>";
                            echo "<td style='text-align:right; white-space:nowrap;'>
                                    <button type='button' onclick='openEditQuestion(" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . ")' class='btn-action' style='background:#3b82f6; color:white; border:none; cursor:pointer;' title='Edit Question'>
                                        <i class='fas fa-edit'></i>
                                    </button>
                                    <a href='manage_questions.php?delete=" . htmlspecialchars($row['id']) . "' class='btn-action btn-delete' onclick='return confirm(\"Delete this question?\");' title='Delete Question'>
                                        <i class='fas fa-trash-alt'></i>
                                    </a>
                                  </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align:center; padding:30px; color:var(--text-muted);'>No questions found for this category.</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Question Modal -->
    <div id="questionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:25px; width:90%; max-width:550px; color:var(--text-main); box-shadow:0 20px 40px rgba(0,0,0,0.3);">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 class="modal-title" style="margin:0;" id="modalTitle"><i class="fas fa-plus-circle" style="color:#0d2158;"></i> Add Evaluation Question</h3>
                <button type="button" class="modal-close-btn" onclick="closeAddModal()" aria-label="Close modal">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="id" id="question_id" value="0">
                <div class="input-group" style="margin-bottom:15px;">
                    <div class="input-label"><i class="fas fa-tags"></i> Category</div>
                    <select name="category" id="question_category" required style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                        <option value="" disabled selected>Select Category</option>
                        <?php
                        $cat_res = $conn->query("SELECT category_name FROM evaluation_categories ORDER BY category_name ASC");
                        while($c = $cat_res->fetch_assoc()) {
                            echo "<option value=\"" . htmlspecialchars($c['category_name']) . "\">" . htmlspecialchars($c['category_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="input-group" style="margin-bottom:20px;">
                    <div class="input-label"><i class="fas fa-question-circle"></i> Question Text</div>
                    <textarea name="question_text" id="question_text" rows="4" placeholder="Enter evaluation question statement..." required style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main); font-family:inherit;"></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeAddModal()" class="nav-link-btn" style="background:var(--text-muted); color:white;">Cancel</button>
                    <button type="submit" name="save_question" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;">Save Question</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Upload Excel Modal -->
    <div id="bulkModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:25px; width:90%; max-width:560px; color:var(--text-main); box-shadow:0 20px 40px rgba(0,0,0,0.3);">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 class="modal-title" style="margin:0;"><i class="fas fa-file-excel" style="color:#10b981;"></i> Bulk Upload Questions</h3>
                <button type="button" class="modal-close-btn" onclick="closeBulkModal()" aria-label="Close modal">&times;</button>
            </div>
            <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); border-radius:10px; padding:14px; margin-bottom:18px;">
                <p style="font-size:13px; color:var(--text-main); margin:0 0 8px 0; font-weight:700;"><i class="fas fa-info-circle" style="color:#10b981;"></i> Excel Format Only (.csv or .xlsx)</p>
                <p style="font-size:12px; color:var(--text-muted); margin:0;">Required columns: <strong>Column A = Category</strong>, <strong>Column B = Question Text</strong>. Row 1 is treated as header and will be skipped.</p>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="input-group" style="margin-bottom:20px;">
                    <div class="input-label">Select File (.csv or .xlsx only)</div>
                    <input type="file" name="excel_file" accept=".csv,.xlsx" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-main);">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeBulkModal()" class="nav-link-btn" style="background:var(--text-muted); color:white;">Cancel</button>
                    <button type="submit" name="bulk_upload_questions" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:700;"><i class="fas fa-upload"></i> Upload File</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('question_id').value = '0';
            document.getElementById('question_category').value = '';
            document.getElementById('question_text').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color:var(--accent);"></i> Add Evaluation Question';
            document.getElementById('questionModal').style.display = 'flex';
        }
        function openEditQuestion(q) {
            document.getElementById('question_id').value = q.id;
            document.getElementById('question_category').value = q.category;
            document.getElementById('question_text').value = q.question_text;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit" style="color:var(--accent);"></i> Edit Evaluation Question';
            document.getElementById('questionModal').style.display = 'flex';
        }
        function closeAddModal() {
            document.getElementById('questionModal').style.display = 'none';
        }
        function openBulkModal() {
            document.getElementById('bulkModal').style.display = 'flex';
        }
        function closeBulkModal() {
            document.getElementById('bulkModal').style.display = 'none';
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
</body>
</html>