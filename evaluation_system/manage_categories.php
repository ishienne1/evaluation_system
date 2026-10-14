<?php
session_start();
include 'db_connect.php';

$role = $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    header("Location: index.php");
    exit();
}

$msg = '';

// Handle Add / Edit Category via AJAX or POST
if (isset($_POST['save_category'])) {
    $id = (int)$_POST['id'];
    $category_name = trim($_POST['category_name']);

    if ($id > 0) {
        $check = $conn->prepare("SELECT id FROM evaluation_categories WHERE category_name = ? AND id != ?");
        $check->bind_param("si", $category_name, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: manage_categories.php?msg=error_exists");
        } else {
            $stmt = $conn->prepare("UPDATE evaluation_categories SET category_name = ? WHERE id = ?");
            $stmt->bind_param("si", $category_name, $id);
            $stmt->execute();
            header("Location: manage_categories.php?msg=updated");
        }
    } else {
        $check = $conn->prepare("SELECT id FROM evaluation_categories WHERE category_name = ?");
        $check->bind_param("s", $category_name);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: manage_categories.php?msg=error_exists");
        } else {
            $stmt = $conn->prepare("INSERT INTO evaluation_categories (category_name) VALUES (?)");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            header("Location: manage_categories.php?msg=added");
        }
    }
    exit();
}

// Handle Delete Category
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM evaluation_categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_categories.php?msg=deleted");
    exit();
}

// Fetch category for editing (for modal pre-fill via JSON)
$edit_category = null;
if (isset($_GET['get_category'])) {
    $id = (int)$_GET['get_category'];
    $stmt = $conn->prepare("SELECT * FROM evaluation_categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_category = $stmt->get_result()->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode($edit_category);
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include_once 'theme_style.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
<div class="split-container">
    <?php 
    $active_page = 'categories';
    include 'admin_sidebar.php';
    ?>

    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:10px;">
            <h2 style="color:var(--text-main); margin:0;">
                <i class="fas fa-tags" style="color:var(--accent);"></i> Manage Evaluation Categories
            </h2>
            <button type="button" onclick="openAddModal()" class="btn-action" style="background:#10b981; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;">
                <i class="fas fa-plus"></i> Add Category
            </button>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'added'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Category created successfully.</div>
            <?php elseif ($_GET['msg'] === 'updated'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Category updated successfully.</div>
            <?php elseif ($_GET['msg'] === 'deleted'): ?>
                <div class="flash-success"><i class="fas fa-check-circle"></i> Category deleted.</div>
            <?php elseif ($_GET['msg'] === 'error_exists'): ?>
                <div class="flash-error"><i class="fas fa-exclamation-circle"></i> Category name already exists.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;"><i class="fas fa-list-ul"></i> Category List</h3>
                <span class="category-badge" style="font-size:13px;">
                    <?php 
                    $cats = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM evaluation_questions q WHERE q.category = c.category_name) as question_count FROM evaluation_categories c ORDER BY c.category_name ASC");
                    echo $cats->num_rows; 
                    ?> total categories
                </span>
            </div>
            <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>Category Name</th>
                        <th>Associated Questions</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($cats && $cats->num_rows > 0):
                        while($row = $cats->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['category_name']); ?></strong></td>
                        <td><span class="category-badge" style="background:#eef2ff; color:#4338ca; font-weight:700;"><?php echo $row['question_count']; ?> questions</span></td>
                        <td style="text-align:right; white-space:nowrap;">
                            <button type="button" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes(htmlspecialchars($row['category_name'])); ?>')" class="btn-action" style="background:#3b82f6; color:white; border:none; cursor:pointer;" title="Edit Category">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="manage_categories.php?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this category? This may affect existing questions using this category.')" title="Delete Category">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">No categories found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>
</div>

<!-- Add / Edit Category Modal -->
<div id="categoryModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:16px; padding:30px; width:90%; max-width:480px; color:var(--text-main); box-shadow:0 20px 60px rgba(0,0,0,0.35);">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:22px;">
            <h3 class="modal-title" style="margin:0; font-size:18px;" id="categoryModalTitle">
                <i class="fas fa-plus-circle" style="color:#0d2158;"></i> Add Category
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeModal()" aria-label="Close modal">&times;</button>
        </div>
        <form method="POST" id="categoryForm">
            <input type="hidden" name="id" id="category_id" value="0">
            <div class="input-group" style="margin-bottom:20px;">
                <div class="input-label"><i class="fas fa-tags"></i> Category Name</div>
                <input 
                    type="text" 
                    name="category_name" 
                    id="category_name_input" 
                    placeholder="e.g. Teaching, Professionalism, Support..." 
                    required
                    style="width:100%; padding:13px 15px; border:1px solid var(--border-color); border-radius:10px; background:var(--input-bg); color:var(--text-main); font-size:14px;"
                >
                <small style="color:var(--text-muted); font-size:12px; margin-top:5px; display:block;">This category will be available as a combobox option when adding evaluation questions.</small>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeModal()" class="nav-link-btn" style="background:var(--text-muted); color:white; padding:11px 20px;">Cancel</button>
                <button type="submit" name="save_category" class="login-btn" style="width:auto; padding:11px 28px;" id="modalSubmitBtn">Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('category_id').value = '0';
        document.getElementById('category_name_input').value = '';
        document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color:#0d2158;"></i> Add Category';
        document.getElementById('modalSubmitBtn').textContent = 'Add Category';
        document.getElementById('categoryModal').style.display = 'flex';
        setTimeout(() => document.getElementById('category_name_input').focus(), 100);
    }

    function openEditModal(id, name) {
        document.getElementById('category_id').value = id;
        document.getElementById('category_name_input').value = name;
        document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-edit" style="color:#0d2158;"></i> Edit Category';
        document.getElementById('modalSubmitBtn').textContent = 'Update Category';
        document.getElementById('categoryModal').style.display = 'flex';
        setTimeout(() => document.getElementById('category_name_input').focus(), 100);
    }

    function closeModal() {
        document.getElementById('categoryModal').style.display = 'none';
    }

    // Close modal on backdrop click
    document.getElementById('categoryModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Keyboard shortcut: Esc closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    function toggleTheme() {
        const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('theme', t);
        updateThemeIcon(t);
    }
    function updateThemeIcon(t) {
        const icon = document.querySelector('#themeBtn i');
        if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'light');
    updateThemeIcon(localStorage.getItem('theme') || 'light');
</script>
</body>
</html>
