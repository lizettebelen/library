<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$story_id = isset($_GET['id']) ? intval($_GET['id']) : null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = intval($_POST['story_id']);
    
    // Get story to find cover file
    $query = "SELECT id, cover_image FROM stories WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $story = $result->fetch_assoc();
    $stmt->close();
    
    if ($story) {
        // Delete story (pages will be deleted automatically due to FK constraint)
        $query = "DELETE FROM stories WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $delete_id);
        
        if ($stmt->execute()) {
            // Try to delete cover image
            if (!empty($story['cover_image']) && file_exists($story['cover_image'])) {
                @unlink($story['cover_image']);
            }
            $success = 'Story deleted successfully!';
        } else {
            $error = 'Failed to delete story: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = 'Story not found.';
    }
}

// Fetch all stories
$stories = [];
$query = "SELECT id, title, author, genre, type, created_at FROM stories ORDER BY created_at DESC";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $stories[] = $row;
    }
}

$total_stories = count($stories);
$encoded_stories = 0;
$file_stories = 0;
foreach ($stories as $story_row) {
    if (($story_row['type'] ?? '') === 'file') {
        $file_stories++;
    } else {
        $encoded_stories++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Stories - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 280px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 30px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.15);
        }

        .admin-sidebar h2 {
            padding: 0 20px 30px;
            margin: 0;
            font-size: 1.3em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .admin-nav {
            list-style: none;
            padding: 0;
            margin: 20px 0 0;
        }

        .admin-nav li {
            margin: 0;
        }

        .admin-nav a {
            display: block;
            padding: 16px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .admin-nav a:hover,
        .admin-nav a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #fbbf24;
            color: white;
        }

        .admin-logout {
            padding: 20px;
            margin-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .admin-logout a {
            display: block;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .admin-logout a:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .sidebar-toggle {
            display: block;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            color: white;
            font-size: 1.5em;
            padding: 12px 16px;
            cursor: pointer;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 101;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }

        .sidebar-toggle:hover {
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .admin-sidebar {
            transition: transform 0.3s ease;
        }

        .admin-sidebar.hidden {
            transform: translateX(-100%);
        }

        .admin-main.expanded {
            margin-left: 0;
        }

        @media (max-width: 768px) {

            .admin-sidebar {
                width: 250px;
                z-index: 100;
                transform: translateX(0);
                transition: transform 0.3s ease;
            }

            .admin-main {
                margin-left: 0 !important;
                padding: 60px 20px 20px;
            }
        }

        .admin-main {
            margin-left: 280px;
            flex: 1;
            padding: 40px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .admin-header p {
            color: #64748b;
            margin: 8px 0 0;
        }

        .admin-header h1 {
            font-size: 2em;
            color: #1e293b;
            margin: 0;
        }

        .stories-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }

        .stat-label {
            margin: 0;
            color: #64748b;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            margin: 8px 0 0;
            color: #0f172a;
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1;
        }

        .tools-bar {
            display: grid;
            grid-template-columns: 1fr 180px;
            gap: 10px;
            margin-bottom: 16px;
        }

        .tools-input,
        .tools-select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            padding: 11px 12px;
            font-size: 0.95rem;
        }

        .tools-input:focus,
        .tools-select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.14);
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 12px;
        }

        .stories-table thead {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid #e2e8f0;
        }

        .stories-table th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 700;
            color: #1e293b;
            font-size: 0.95em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stories-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            color: #475569;
        }

        .stories-table tbody tr:hover {
            background: #f8fafc;
        }

        .stories-table tbody tr:last-child td {
            border-bottom: none;
        }

        .story-title {
            font-weight: 700;
            color: #1e293b;
        }

        .story-type {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(99, 102, 241, 0.1);
            color: #6366f1;
            border-radius: 6px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .story-type.file {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .story-actions {
            display: flex;
            gap: 10px;
        }

        .btn-edit, .btn-delete {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .btn-edit {
            background: #6366f1;
            color: white;
        }

        .btn-edit:hover {
            background: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 12px;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            color: #cbd5e1;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            color: #64748b;
            font-size: 1.1em;
            margin-bottom: 20px;
        }

        .delete-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .delete-modal.active {
            display: flex;
        }

        .delete-modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .delete-modal-content h3 {
            margin: 0 0 15px;
            color: #1e293b;
            font-size: 1.2em;
        }

        .delete-modal-content p {
            color: #64748b;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .delete-modal-actions {
            display: flex;
            gap: 12px;
        }

        .delete-modal-actions button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .delete-modal-actions .btn-cancel {
            background: #e2e8f0;
            color: #1e293b;
        }

        .delete-modal-actions .btn-cancel:hover {
            background: #cbd5e1;
        }

        .delete-modal-actions .btn-confirm {
            background: #ef4444;
            color: white;
        }

        .delete-modal-actions .btn-confirm:hover {
            background: #dc2626;
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                width: 200px;
            }

            .admin-main {
                margin-left: 200px;
                padding: 20px;
            }
        }

        .admin-sidebar {
            width: 300px;
            padding: 22px;
            inset: 16px auto 16px 16px;
            height: auto;
            overflow: hidden;
            border-radius: 24px;
            border: 1px solid rgba(196, 224, 247, 0.36);
            background: linear-gradient(180deg, rgba(70, 130, 180, 0.98) 0%, rgba(57, 106, 148, 0.97) 48%, rgba(40, 79, 114, 0.96) 100%);
            box-shadow: 0 22px 62px rgba(34, 72, 103, 0.34);
            display: flex;
            flex-direction: column;
            gap: 18px;
            isolation: isolate;
        }

        .admin-sidebar::before,
        .admin-sidebar::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .admin-sidebar::before {
            inset: auto -30% -35% auto;
            width: 220px;
            height: 220px;
            background: rgba(219, 238, 255, 0.2);
        }

        .admin-sidebar::after {
            top: 84px;
            left: -70px;
            width: 180px;
            height: 180px;
            background: rgba(182, 221, 250, 0.16);
        }

        .admin-sidebar h2 {
            padding: 0;
            margin: 0;
            font-size: 1.2em;
            letter-spacing: -0.02em;
            line-height: 1.1;
        }

        .admin-sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            position: relative;
            z-index: 1;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(215, 234, 250, 0.3);
        }

        .admin-brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            font-size: 1.35rem;
            background: rgba(224, 241, 255, 0.24);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.26);
            flex: 0 0 auto;
        }

        .admin-sidebar-subtitle {
            margin-top: 4px;
            color: rgba(235, 246, 255, 0.82);
            font-size: 0.86em;
        }

        .admin-nav {
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .admin-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: rgba(245, 251, 255, 0.96);
            border: 1px solid rgba(192, 220, 242, 0.18);
            border-radius: 16px;
            background: rgba(215, 235, 252, 0.1);
            font-weight: 600;
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .admin-nav a:hover,
        .admin-nav a.active {
            background: rgba(232, 246, 255, 0.24);
            border-color: rgba(226, 242, 255, 0.45);
            color: white;
            transform: translateX(4px);
            box-shadow: 0 12px 24px rgba(21, 49, 72, 0.28);
        }

        .admin-logout {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid rgba(215, 234, 250, 0.3);
            position: relative;
            z-index: 1;
        }

        .admin-logout a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 16px;
            background: rgba(228, 244, 255, 0.2);
            color: white;
            border-radius: 16px;
            font-weight: 700;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.26);
        }

        .admin-logout a:hover {
            background: rgba(234, 247, 255, 0.3);
            transform: translateY(-1px);
        }

        .admin-logout small {
            display: block;
            margin-top: 10px;
            color: rgba(235, 246, 255, 0.82);
            font-size: 0.85em;
            text-align: center;
        }

        .sidebar-toggle {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            background: rgba(238, 248, 255, 0.94);
            color: #2f5f86;
            border: 1px solid rgba(70, 130, 180, 0.3);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(22, 45, 66, 0.16);
            backdrop-filter: blur(12px);
        }

        .sidebar-toggle:hover {
            background: #ffffff;
            box-shadow: 0 14px 34px rgba(22, 45, 66, 0.2);
        }

        .admin-main {
            margin-left: 330px;
            padding: 36px 40px;
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                width: min(84vw, 300px);
                inset: 0 auto 0 0;
                border-radius: 0 24px 24px 0;
                padding: 18px;
            }

            .admin-main {
                margin-left: 0;
                padding: 84px 18px 18px;
            }

            .admin-header {
                margin-bottom: 20px;
            }

            .admin-header h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .tools-bar {
                grid-template-columns: 1fr;
            }

            .stories-table {
                min-width: 720px;
            }

            .sidebar-toggle {
                top: 14px;
                left: 14px;
            }
        }

        @media (max-width: 640px) {
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .stories-table {
                min-width: 640px;
            }

            .stories-table th,
            .stories-table td {
                padding: 12px 10px;
                font-size: 0.84rem;
            }

            .story-actions {
                flex-wrap: wrap;
            }

            .btn-edit,
            .btn-delete {
                padding: 8px 10px;
            }

            .delete-modal-content {
                width: calc(100vw - 28px);
                max-width: 420px;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="toggleSidebar">☰</button>
    <div class="admin-container">
        <!-- Sidebar -->
        <nav class="admin-sidebar" id="adminSidebar">
            <div class="admin-sidebar-brand">
                <div class="admin-brand-icon">📚</div>
                <div>
                    <h2>Admin</h2>
                    <div class="admin-sidebar-subtitle">Library controls</div>
                </div>
            </div>
            <ul class="admin-nav">
                <li><a href="dashboard.php" class="nav-link">✏️ New Story</a></li>
                <li><a href="manage_stories.php" class="nav-link active">📖 Manage Stories</a></li>
            </ul>
            <div class="admin-logout">
                <a href="logout.php">🚪 Logout</a>
                <small>Signed in as <?php echo htmlspecialchars($_SESSION['name']); ?></small>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="admin-main">
            <div class="admin-header">
                <div>
                    <h1>Manage Stories</h1>
                    <p>View, edit, and delete your stories</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom: 30px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 30px;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($stories)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                    </svg>
                    <p>No stories yet</p>
                    <a href="dashboard.php" class="btn btn-primary">Create Your First Story</a>
                </div>
            <?php else: ?>
                <section class="stats-grid" aria-label="Story statistics">
                    <article class="stat-card">
                        <p class="stat-label">Total Stories</p>
                        <p class="stat-value"><?php echo (int) $total_stories; ?></p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Written Stories</p>
                        <p class="stat-value"><?php echo (int) $encoded_stories; ?></p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Uploaded Files</p>
                        <p class="stat-value"><?php echo (int) $file_stories; ?></p>
                    </article>
                </section>

                <section class="tools-bar" aria-label="Story filters">
                    <input type="search" id="storySearch" class="tools-input" placeholder="Search by title, author, or genre">
                    <select id="typeFilter" class="tools-select" aria-label="Filter by type">
                        <option value="all">All types</option>
                        <option value="encoded">Written</option>
                        <option value="file">File uploads</option>
                    </select>
                </section>

                <div class="table-wrap">
                    <table class="stories-table" id="storiesTable">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <th>Type</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stories as $story): ?>
                                <tr data-title="<?php echo htmlspecialchars(strtolower((string) ($story['title'] ?? ''))); ?>" data-author="<?php echo htmlspecialchars(strtolower((string) ($story['author'] ?? ''))); ?>" data-genre="<?php echo htmlspecialchars(strtolower((string) ($story['genre'] ?? ''))); ?>" data-type="<?php echo htmlspecialchars((string) ($story['type'] ?? '')); ?>">
                                    <td class="story-title"><?php echo htmlspecialchars($story['title']); ?></td>
                                    <td><?php echo htmlspecialchars($story['author']); ?></td>
                                    <td><?php echo htmlspecialchars($story['genre']); ?></td>
                                    <td>
                                        <span class="story-type <?php echo $story['type'] === 'file' ? 'file' : ''; ?>">
                                            <?php echo ucfirst($story['type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $date = new DateTime($story['created_at']);
                                        echo $date->format('M d, Y');
                                        ?>
                                    </td>
                                    <td>
                                        <div class="story-actions">
                                            <a href="edit_story.php?id=<?php echo $story['id']; ?>" class="btn-edit" title="Edit Story">✏️</a>
                                            <a href="../view.php?id=<?php echo $story['id']; ?>" class="btn-edit" target="_blank" title="View Story">👁️</a>
                                            <button
                                                type="button"
                                                class="btn-delete js-delete-story"
                                                data-story-id="<?php echo (int) $story['id']; ?>"
                                                data-story-title="<?php echo htmlspecialchars((string) $story['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                title="Delete Story"
                                            >🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="delete-modal" id="deleteModal">
        <div class="delete-modal-content">
            <h3>Delete Story?</h3>
            <p>Are you sure you want to delete "<span id="storyTitle"></span>"? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="story_id" id="storyId">
                <div class="delete-modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-confirm">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openDeleteModal(storyId, storyTitle) {
            document.getElementById('storyId').value = storyId;
            document.getElementById('storyTitle').textContent = storyTitle;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        // Sidebar toggle
        const toggleBtn = document.getElementById('toggleSidebar');
        const sidebar = document.getElementById('adminSidebar');
        const mainContent = document.querySelector('.admin-main');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('hidden');
                mainContent.classList.toggle('expanded');
            });
        }

        const searchInput = document.getElementById('storySearch');
        const typeFilter = document.getElementById('typeFilter');
        const table = document.getElementById('storiesTable');

        function filterStories() {
            if (!table) {
                return;
            }

            const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
            const selectedType = typeFilter ? typeFilter.value : 'all';
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach((row) => {
                const haystack = [row.dataset.title || '', row.dataset.author || '', row.dataset.genre || ''].join(' ');
                const type = row.dataset.type || '';
                const matchQuery = query === '' || haystack.includes(query);
                const matchType = selectedType === 'all' || type === selectedType;

                row.style.display = (matchQuery && matchType) ? '' : 'none';
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterStories);
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', filterStories);
        }

        document.querySelectorAll('.js-delete-story').forEach((button) => {
            button.addEventListener('click', function () {
                const storyId = parseInt(button.getAttribute('data-story-id') || '0', 10);
                const storyTitle = button.getAttribute('data-story-title') || 'Untitled Story';
                if (!storyId) {
                    return;
                }

                openDeleteModal(storyId, storyTitle);
            });
        });
    </script>
</body>
</html>
