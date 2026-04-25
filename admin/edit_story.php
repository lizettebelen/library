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
$story_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (isset($_SESSION['edit_story_flash_success'])) {
    $success = (string) $_SESSION['edit_story_flash_success'];
    unset($_SESSION['edit_story_flash_success']);
}

if (isset($_SESSION['edit_story_flash_error'])) {
    $error = (string) $_SESSION['edit_story_flash_error'];
    unset($_SESSION['edit_story_flash_error']);
}

function ensureStoryHeaderMediaTable(&$conn) {
    $check = $conn->query("SHOW TABLES LIKE 'story_header_media'");
    if ($check && $check->num_rows > 0) {
        return true;
    }

    $create = "
        CREATE TABLE IF NOT EXISTS story_header_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            media_path VARCHAR(255) NOT NULL,
            media_type ENUM('image','video') NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_story_media_order (story_id, sort_order),
            CONSTRAINT fk_story_header_media_story
                FOREIGN KEY (story_id) REFERENCES stories(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    return (bool) $conn->query($create);
}

function handleHeaderMediaUpload(&$error) {
    $uploaded = [];

    if (!isset($_FILES['header_media'])) {
        return $uploaded;
    }

    $files = $_FILES['header_media'];
    $is_multi = is_array($files['name'] ?? null);

    $names = $is_multi ? $files['name'] : [$files['name'] ?? ''];
    $tmp_names = $is_multi ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
    $errors = $is_multi ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = $is_multi ? $files['size'] : [$files['size'] ?? 0];

    $header_dir = '../uploads/headers/';
    if (!is_dir($header_dir) && !mkdir($header_dir, 0755, true)) {
        $error = 'Failed to create header uploads directory.';
        return [];
    }

    $image_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $video_mimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-m4v', 'video/x-msvideo', 'video/x-matroska'];
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $video_exts = ['mp4', 'webm', 'ogg', 'mov', 'm4v', 'avi', 'mkv'];

    foreach ($names as $index => $name) {
        $file_error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($file_error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file_error !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'Header media is too large for server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'Header media exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'Header media was only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write header media to disk',
                UPLOAD_ERR_EXTENSION => 'Header media upload blocked by extension'
            ];
            $error = $upload_errors[$file_error] ?? 'Unknown header media upload error';
            if ($file_error === UPLOAD_ERR_INI_SIZE || $file_error === UPLOAD_ERR_FORM_SIZE) {
                $error .= ' Current server limits: upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . '.';
            }
            return [];
        }

        $tmp_name = (string) ($tmp_names[$index] ?? '');
        $file_size = (int) ($sizes[$index] ?? 0);
        if (!is_uploaded_file($tmp_name)) {
            $error = 'Invalid header media upload. Please try again.';
            return [];
        }

        $mime_type = (string) mime_content_type($tmp_name);
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        if (in_array($mime_type, $image_mimes, true) || in_array($ext, $image_exts, true)) {
            $media_type = 'image';
            $max_size = 10 * 1024 * 1024;
        } elseif (in_array($mime_type, $video_mimes, true) || in_array($ext, $video_exts, true)) {
            $media_type = 'video';
            $max_size = 120 * 1024 * 1024;
        } else {
            $error = 'Header media must be images (JPG/PNG/GIF/WebP) or videos (MP4/WebM/OGG/MOV).';
            return [];
        }

        if ($file_size > $max_size) {
            $error = $media_type === 'image'
                ? 'One of the header images is too large. Maximum size is 10MB.'
                : 'One of the header videos is too large. Maximum size is 120MB.';
            return [];
        }

        $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
        if ($safe_ext === '') {
            $safe_ext = $media_type === 'image' ? 'jpg' : 'mp4';
        }

        $filename = uniqid('header_', true) . '.' . $safe_ext;
        $full_path = $header_dir . $filename;
        $relative_path = 'uploads/headers/' . $filename;

        if (!move_uploaded_file($tmp_name, $full_path)) {
            $error = 'Failed to save header media.';
            return [];
        }

        $uploaded[] = [
            'path' => $relative_path,
            'type' => $media_type,
        ];
    }

    return $uploaded;
}

function appendStoryHeaderMedia(&$conn, $story_id, $items) {
    if (empty($items)) {
        return true;
    }

    $max_order = -1;
    $max_stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM story_header_media WHERE story_id = ?');
    if ($max_stmt) {
        $max_stmt->bind_param('i', $story_id);
        $max_stmt->execute();
        $max_result = $max_stmt->get_result();
        $max_row = $max_result ? $max_result->fetch_assoc() : null;
        $max_order = (int) ($max_row['max_order'] ?? -1);
        $max_stmt->close();
    }

    $insert_stmt = $conn->prepare('INSERT INTO story_header_media (story_id, media_path, media_type, sort_order) VALUES (?, ?, ?, ?)');
    if (!$insert_stmt) {
        return false;
    }

    foreach ($items as $index => $item) {
        $path = (string) ($item['path'] ?? '');
        $type = (string) ($item['type'] ?? '');
        $sort_order = $max_order + $index + 1;
        if ($path === '' || !in_array($type, ['image', 'video'], true)) {
            continue;
        }

        $insert_stmt->bind_param('issi', $story_id, $path, $type, $sort_order);
        if (!$insert_stmt->execute()) {
            $insert_stmt->close();
            return false;
        }
    }

    $insert_stmt->close();
    return true;
}

function syncPrimaryHeaderMediaToStory(&$conn, $story_id) {
    $query = 'SELECT media_path, media_type FROM story_header_media WHERE story_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1';
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $story_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $path = $row ? (string) ($row['media_path'] ?? null) : null;
    $type = $row ? (string) ($row['media_type'] ?? null) : null;

    $update = $conn->prepare('UPDATE stories SET header_media_path = ?, header_media_type = ? WHERE id = ?');
    if (!$update) {
        return false;
    }

    $update->bind_param('ssi', $path, $type, $story_id);
    $ok = $update->execute();
    $update->close();
    return $ok;
}

function deleteHeaderMediaItemsByIds(&$conn, $story_id, $media_ids, &$deleted_count, &$error_message) {
    $deleted_count = 0;
    $error_message = '';

    if (!is_array($media_ids)) {
        $media_ids = [];
    }

    $clean_ids = [];
    foreach ($media_ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean_ids[$id] = $id;
        }
    }
    $clean_ids = array_values($clean_ids);

    if (empty($clean_ids)) {
        $error_message = 'No media selected.';
        return false;
    }

    $selected = [];
    $select_stmt = $conn->prepare('SELECT id, media_path FROM story_header_media WHERE id = ? AND story_id = ? LIMIT 1');
    if (!$select_stmt) {
        $error_message = 'Failed to prepare media lookup.';
        return false;
    }

    foreach ($clean_ids as $id) {
        $select_stmt->bind_param('ii', $id, $story_id);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        $row = $select_result ? $select_result->fetch_assoc() : null;
        if ($row) {
            $selected[] = [
                'id' => (int) $row['id'],
                'path' => (string) ($row['media_path'] ?? ''),
            ];
        }
    }
    $select_stmt->close();

    if (empty($selected)) {
        $error_message = 'Media item not found.';
        return false;
    }

    $deleted_paths = [];
    $delete_stmt = $conn->prepare('DELETE FROM story_header_media WHERE id = ? AND story_id = ?');
    if (!$delete_stmt) {
        $error_message = 'Failed to prepare media deletion.';
        return false;
    }

    foreach ($selected as $item) {
        $media_id = (int) $item['id'];
        $media_path = (string) $item['path'];
        $delete_stmt->bind_param('ii', $media_id, $story_id);
        if ($delete_stmt->execute()) {
            $deleted_count++;
            if ($media_path !== '') {
                $deleted_paths[$media_path] = $media_path;
            }
        }
    }
    $delete_stmt->close();

    if ($deleted_count <= 0) {
        $error_message = 'Failed to delete selected media.';
        return false;
    }

    $count_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM story_header_media WHERE media_path = ?');
    if ($count_stmt) {
        foreach ($deleted_paths as $media_path) {
            $count_stmt->bind_param('s', $media_path);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_row = $count_result ? $count_result->fetch_assoc() : null;
            $usage_count = (int) ($count_row['cnt'] ?? 0);

            if ($usage_count === 0) {
                $full_media_path = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($media_path, '/\\'));
                if ($full_media_path && file_exists($full_media_path)) {
                    @unlink($full_media_path);
                }
            }
        }
        $count_stmt->close();
    }

    return true;
}

function deleteChapterMediaItemsByIds(&$conn, $story_id, $media_ids, &$deleted_count, &$error_message) {
    $deleted_count = 0;
    $error_message = '';

    $table_check = $conn->query("SHOW TABLES LIKE 'chapter_page_media'");
    if (!$table_check || $table_check->num_rows === 0) {
        $error_message = 'No chapter media found.';
        return false;
    }

    if (!is_array($media_ids)) {
        $media_ids = [];
    }

    $clean_ids = [];
    foreach ($media_ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean_ids[$id] = $id;
        }
    }
    $clean_ids = array_values($clean_ids);

    if (empty($clean_ids)) {
        $error_message = 'No media selected.';
        return false;
    }

    $selected = [];
    $select_stmt = $conn->prepare('SELECT id, media_path FROM chapter_page_media WHERE id = ? AND story_id = ? LIMIT 1');
    if (!$select_stmt) {
        $error_message = 'Failed to prepare chapter media lookup.';
        return false;
    }

    foreach ($clean_ids as $id) {
        $select_stmt->bind_param('ii', $id, $story_id);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        $row = $select_result ? $select_result->fetch_assoc() : null;
        if ($row) {
            $selected[] = [
                'id' => (int) $row['id'],
                'path' => (string) ($row['media_path'] ?? ''),
            ];
        }
    }
    $select_stmt->close();

    if (empty($selected)) {
        $error_message = 'Chapter media item not found.';
        return false;
    }

    $deleted_paths = [];
    $delete_stmt = $conn->prepare('DELETE FROM chapter_page_media WHERE id = ? AND story_id = ?');
    if (!$delete_stmt) {
        $error_message = 'Failed to prepare chapter media deletion.';
        return false;
    }

    foreach ($selected as $item) {
        $media_id = (int) $item['id'];
        $media_path = (string) $item['path'];
        $delete_stmt->bind_param('ii', $media_id, $story_id);
        if ($delete_stmt->execute()) {
            $deleted_count++;
            if ($media_path !== '') {
                $deleted_paths[$media_path] = $media_path;
            }
        }
    }
    $delete_stmt->close();

    if ($deleted_count <= 0) {
        $error_message = 'Failed to delete chapter media.';
        return false;
    }

    $chapter_count_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM chapter_page_media WHERE media_path = ?');
    $header_count_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM story_header_media WHERE media_path = ?');

    foreach ($deleted_paths as $media_path) {
        $chapter_usage_count = 0;
        $header_usage_count = 0;

        if ($chapter_count_stmt) {
            $chapter_count_stmt->bind_param('s', $media_path);
            $chapter_count_stmt->execute();
            $chapter_result = $chapter_count_stmt->get_result();
            $chapter_row = $chapter_result ? $chapter_result->fetch_assoc() : null;
            $chapter_usage_count = (int) ($chapter_row['cnt'] ?? 0);
        }

        if ($header_count_stmt) {
            $header_count_stmt->bind_param('s', $media_path);
            $header_count_stmt->execute();
            $header_result = $header_count_stmt->get_result();
            $header_row = $header_result ? $header_result->fetch_assoc() : null;
            $header_usage_count = (int) ($header_row['cnt'] ?? 0);
        }

        if (($chapter_usage_count + $header_usage_count) === 0) {
            $full_media_path = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($media_path, '/\\'));
            if ($full_media_path && file_exists($full_media_path)) {
                @unlink($full_media_path);
            }
        }
    }

    if ($chapter_count_stmt) {
        $chapter_count_stmt->close();
    }
    if ($header_count_stmt) {
        $header_count_stmt->close();
    }

    return true;
}

function reorderChapterMediaItems(&$conn, $story_id, $media_order, &$updated_count, &$error_message) {
    $updated_count = 0;
    $error_message = '';

    $table_check = $conn->query("SHOW TABLES LIKE 'chapter_page_media'");
    if (!$table_check || $table_check->num_rows === 0) {
        $error_message = 'No chapter media found.';
        return false;
    }

    if (!is_array($media_order) || empty($media_order)) {
        $error_message = 'No media order data found.';
        return false;
    }

    $pairs = [];
    foreach ($media_order as $media_id => $sort_order) {
        $media_id = (int) $media_id;
        $sort_order = (int) $sort_order;
        if ($media_id > 0 && $sort_order >= 0) {
            $pairs[$media_id] = $sort_order;
        }
    }

    if (empty($pairs)) {
        $error_message = 'No valid media order provided.';
        return false;
    }

    $update_stmt = $conn->prepare('UPDATE chapter_page_media SET sort_order = ? WHERE id = ? AND story_id = ?');
    if (!$update_stmt) {
        $error_message = 'Failed to prepare media reorder query.';
        return false;
    }

    foreach ($pairs as $media_id => $sort_order) {
        $update_stmt->bind_param('iii', $sort_order, $media_id, $story_id);
        if ($update_stmt->execute()) {
            $updated_count++;
        }
    }

    $update_stmt->close();

    if ($updated_count === 0) {
        $error_message = 'No media order updates were applied.';
        return false;
    }

    return true;
}

ensureStoryHeaderMediaTable($conn);

if (!$story_id) {
    header('Location: manage_stories.php');
    exit();
}

// Fetch story details
$query = "SELECT * FROM stories WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $story_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage_stories.php');
    exit();
}

$story = $result->fetch_assoc();
$stmt->close();

// Fetch pages for encoded stories
$pages = [];
if ($story['type'] === 'encoded') {
    $query = "SELECT id, page_number, chapter_title, content FROM pages WHERE story_id = ? ORDER BY page_number ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $story_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $pages[] = $row;
    }
    $stmt->close();
}

$header_media_items = [];
$header_media_stmt = $conn->prepare('SELECT id, media_path, media_type FROM story_header_media WHERE story_id = ? ORDER BY sort_order ASC, id ASC');
if ($header_media_stmt) {
    $header_media_stmt->bind_param('i', $story_id);
    $header_media_stmt->execute();
    $header_media_result = $header_media_stmt->get_result();

    while ($media_row = $header_media_result->fetch_assoc()) {
        $header_media_items[] = $media_row;
    }

    $header_media_stmt->close();
}

if (empty($header_media_items) && !empty($story['header_media_path']) && !empty($story['header_media_type'])) {
    $header_media_items[] = [
        'id' => null,
        'media_path' => (string) $story['header_media_path'],
        'media_type' => (string) $story['header_media_type'],
    ];
}

$chapter_media_map = [];
if ($story['type'] === 'encoded' && !empty($pages)) {
    $chapter_media_table_check = $conn->query("SHOW TABLES LIKE 'chapter_page_media'");
    if ($chapter_media_table_check && $chapter_media_table_check->num_rows > 0) {
        $chapter_media_stmt = $conn->prepare('SELECT id, page_id, media_path, media_type FROM chapter_page_media WHERE story_id = ? ORDER BY page_id ASC, sort_order ASC, id ASC');
        if ($chapter_media_stmt) {
            $chapter_media_stmt->bind_param('i', $story_id);
            $chapter_media_stmt->execute();
            $chapter_media_result = $chapter_media_stmt->get_result();

            while ($chapter_media_row = $chapter_media_result->fetch_assoc()) {
                $page_id = (int) ($chapter_media_row['page_id'] ?? 0);
                if ($page_id <= 0) {
                    continue;
                }

                if (!isset($chapter_media_map[$page_id])) {
                    $chapter_media_map[$page_id] = [];
                }

                $chapter_media_map[$page_id][] = $chapter_media_row;
            }

            $chapter_media_stmt->close();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($action === 'delete_header_media' || $action === 'delete_selected_header_media') {
        $media_ids = [];
        if ($action === 'delete_header_media') {
            $media_ids[] = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
        } else {
            $media_ids = isset($_POST['media_ids']) && is_array($_POST['media_ids']) ? $_POST['media_ids'] : [];
        }

        $deleted_count = 0;
        $delete_error = '';
        if (!deleteHeaderMediaItemsByIds($conn, $story_id, $media_ids, $deleted_count, $delete_error)) {
            $_SESSION['edit_story_flash_error'] = $delete_error !== '' ? $delete_error : 'Failed to delete selected media.';
            header('Location: edit_story.php?id=' . (int) $story_id);
            exit();
        }

        syncPrimaryHeaderMediaToStory($conn, $story_id);
        $_SESSION['edit_story_flash_success'] = $deleted_count > 1
            ? $deleted_count . ' media items deleted.'
            : 'Header media deleted.';
        header('Location: edit_story.php?id=' . (int) $story_id);
        exit();
    }

    if ($action === 'delete_chapter_media') {
        $media_ids = [isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0];
        $deleted_count = 0;
        $delete_error = '';

        if (!deleteChapterMediaItemsByIds($conn, $story_id, $media_ids, $deleted_count, $delete_error)) {
            $_SESSION['edit_story_flash_error'] = $delete_error !== '' ? $delete_error : 'Failed to delete chapter media.';
            header('Location: edit_story.php?id=' . (int) $story_id);
            exit();
        }

        $_SESSION['edit_story_flash_success'] = 'Chapter media deleted.';
        header('Location: edit_story.php?id=' . (int) $story_id);
        exit();
    }

    if ($action === 'delete_selected_chapter_media') {
        $media_ids = isset($_POST['media_ids']) && is_array($_POST['media_ids']) ? $_POST['media_ids'] : [];
        $deleted_count = 0;
        $delete_error = '';

        if (!deleteChapterMediaItemsByIds($conn, $story_id, $media_ids, $deleted_count, $delete_error)) {
            $_SESSION['edit_story_flash_error'] = $delete_error !== '' ? $delete_error : 'Failed to delete selected chapter media.';
            header('Location: edit_story.php?id=' . (int) $story_id);
            exit();
        }

        $_SESSION['edit_story_flash_success'] = $deleted_count > 1
            ? $deleted_count . ' chapter media items deleted.'
            : 'Chapter media deleted.';
        header('Location: edit_story.php?id=' . (int) $story_id);
        exit();
    }

    if ($action === 'reorder_chapter_media') {
        $media_order = isset($_POST['media_order']) && is_array($_POST['media_order']) ? $_POST['media_order'] : [];
        $updated_count = 0;
        $order_error = '';

        if (!reorderChapterMediaItems($conn, $story_id, $media_order, $updated_count, $order_error)) {
            $_SESSION['edit_story_flash_error'] = $order_error !== '' ? $order_error : 'Failed to update chapter media order.';
            header('Location: edit_story.php?id=' . (int) $story_id);
            exit();
        }

        $_SESSION['edit_story_flash_success'] = 'Chapter media order updated.';
        header('Location: edit_story.php?id=' . (int) $story_id);
        exit();
    }

    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $author = isset($_POST['author']) ? trim($_POST['author']) : '';
    $genre = isset($_POST['genre']) ? trim($_POST['genre']) : '';
    
    if (empty($title) || empty($author) || empty($genre)) {
        $error = 'Title, Author, and Genre are required.';
    } else {
        // Update story info
        $query = "UPDATE stories SET title = ?, author = ?, genre = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssi", $title, $author, $genre, $story_id);
        
        if ($stmt->execute()) {
            $new_header_media_items = handleHeaderMediaUpload($error);
            if (empty($error) && !empty($new_header_media_items)) {
                if (!appendStoryHeaderMedia($conn, $story_id, $new_header_media_items)) {
                    $error = 'Story updated, but new header media could not be saved.';
                } else {
                    syncPrimaryHeaderMediaToStory($conn, $story_id);
                }
            }

            if (empty($error) && $story['type'] === 'encoded') {
                $chapter_titles = isset($_POST['chapter_titles']) ? (array) $_POST['chapter_titles'] : [];
                $page_contents = isset($_POST['pages']) ? (array) $_POST['pages'] : [];

                foreach ($pages as $page) {
                    $chapter_title = isset($chapter_titles[$page['page_number'] - 1]) ? trim($chapter_titles[$page['page_number'] - 1]) : '';
                    $content = isset($page_contents[$page['page_number'] - 1]) ? trim($page_contents[$page['page_number'] - 1]) : '';

                    if (!empty($content)) {
                        if ($chapter_title === '') {
                            $chapter_title = 'Chapter ' . $page['page_number'];
                        }

                        $update_page = "UPDATE pages SET chapter_title = ?, content = ? WHERE id = ?";
                        $stmt_page = $conn->prepare($update_page);
                        $stmt_page->bind_param("ssi", $chapter_title, $content, $page['id']);
                        $stmt_page->execute();
                        $stmt_page->close();
                    }
                }
            }

            $stmt->close();

            if (empty($error)) {
                $_SESSION['edit_story_flash_success'] = 'Story updated successfully!';
                header('Location: edit_story.php?id=' . (int) $story_id);
                exit();
            }
        } else {
            $error = 'Failed to update story: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Story - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
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
            z-index: 100;
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
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            padding: 40px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .admin-header h1 {
            font-size: 2em;
            color: #1e293b;
            margin: 0;
        }

        .admin-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid rgba(70, 130, 180, 0.36);
            background: linear-gradient(180deg, #ffffff 0%, #eef6ff 100%);
            color: #2f5f86;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            box-shadow: 0 8px 18px rgba(22, 45, 66, 0.12);
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .admin-back-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(22, 45, 66, 0.18);
            background: linear-gradient(180deg, #ffffff 0%, #e8f2ff 100%);
        }

        .admin-back-link:focus-visible {
            outline: 2px solid #4682b4;
            outline-offset: 2px;
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                width: 200px;
            }

            .admin-main {
                margin-left: 200px;
                padding: 20px;
            }

            .admin-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                margin-bottom: 24px;
            }

            .admin-back-link {
                width: 100%;
                justify-content: center;
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

        .existing-media-grid,
        .new-media-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .existing-media-item,
        .new-media-preview-item {
            border: 1px solid #d7e0ec;
            border-radius: 8px;
            background: #ffffff;
            padding: 6px;
            position: relative;
        }

        .existing-media-delete-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            border: 1px solid rgba(127, 29, 29, 0.25);
            background: rgba(127, 29, 29, 0.9);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .existing-media-delete-btn:hover {
            background: rgba(127, 29, 29, 1);
        }

        .existing-media-select {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #4682b4;
            z-index: 2;
        }

        .chapter-media-select {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #4682b4;
            z-index: 2;
        }

        .header-media-bulk-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 10px;
        }

        .header-media-select-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .header-media-delete-selected {
            border: 1px solid rgba(127, 29, 29, 0.22);
            background: rgba(127, 29, 29, 0.94);
            color: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
        }

        .header-media-delete-selected:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .chapter-media-bulk-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .chapter-media-select-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .chapter-media-delete-selected {
            border: 1px solid rgba(127, 29, 29, 0.22);
            background: rgba(127, 29, 29, 0.94);
            color: #fff;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
        }

        .chapter-media-delete-selected:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .chapter-media-save-order {
            border: 1px solid rgba(15, 118, 110, 0.22);
            background: rgba(15, 118, 110, 0.94);
            color: #fff;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
        }

        .chapter-media-save-order:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .chapter-media-draggable {
            cursor: grab;
        }

        .chapter-media-draggable.dragging {
            opacity: 0.45;
            transform: scale(0.98);
        }

        .chapter-media-handle {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(15, 23, 42, 0.72);
            color: #fff;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 6px;
            user-select: none;
        }

        .existing-media-item img,
        .existing-media-item video,
        .new-media-preview-item img,
        .new-media-preview-item video {
            width: 100%;
            height: 90px;
            object-fit: cover;
            border-radius: 6px;
            display: block;
        }

        .existing-media-item p,
        .new-media-preview-item p {
            margin: 6px 0 0;
            font-size: 11px;
            color: #64748b;
            word-break: break-all;
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

            .sidebar-toggle {
                top: 14px;
                left: 14px;
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
                <li><a href="dashboard.php">✏️ New Story</a></li>
                <li><a href="manage_stories.php" class="active">📖 Manage Stories</a></li>
            </ul>
            <div class="admin-logout">
                <a href="logout.php">🚪 Logout</a>
                <small>Signed in as <?php echo htmlspecialchars($_SESSION['name']); ?></small>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="admin-main">
            <div class="admin-header">
                <h1>Edit Story</h1>
                <a href="manage_stories.php" class="admin-back-link" aria-label="Back to Manage Stories">← Back to Manage Stories</a>
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

            <div class="story-form-container">
                <form method="POST" id="edit-form" enctype="multipart/form-data">
                    <div class="form-row">
                        <!-- Left Column: Story Details -->
                        <div class="form-column">
                            <div class="form-group">
                                <label for="title">STORY TITLE</label>
                                <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars($story['title']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="author">AUTHOR NAME</label>
                                <input type="text" id="author" name="author" required value="<?php echo htmlspecialchars($story['author']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="genre">GENRE</label>
                                <input type="text" id="genre" name="genre" required value="<?php echo htmlspecialchars($story['genre']); ?>">
                            </div>

                            <div class="form-group">
                                <label>STORY TYPE</label>
                                <p style="color: #64748b; margin: 10px 0 0; font-weight: 500;">
                                    <?php echo $story['type'] === 'encoded' ? '✏️ Written' : '📁 File Upload'; ?>
                                </p>
                            </div>
                        </div>

                        <!-- Right Column: Story Info -->
                        <div class="form-column">
                            <div class="form-group">
                                <label>CREATED</label>
                                <p style="color: #64748b; margin: 10px 0 0; font-weight: 500;">
                                    <?php 
                                    $date = new DateTime($story['created_at']);
                                    echo $date->format('M d, Y g:i A');
                                    ?>
                                </p>
                            </div>

                            <div class="form-group">
                                <label>CHAPTERS/PAGES</label>
                                <p style="color: #64748b; margin: 10px 0 0; font-weight: 500;">
                                    <?php echo count($pages); ?> <?php echo count($pages) === 1 ? 'chapter' : 'chapters'; ?>
                                </p>
                            </div>

                            <div class="form-group" style="margin-top: 12px;">
                                <label for="header_media">ADD HEADER MEDIA (MULTIPLE)</label>
                                <div class="file-upload-area" id="header-media-upload" onclick="document.getElementById('header_media').click()">
                                    <p style="color: #64748b; margin: 0; font-weight: 600;">Upload one or more images/videos</p>
                                    <p style="color: #94a3b8; font-size: 0.85em; margin: 5px 0 0 0;">Supported: JPG, PNG, GIF, WebP, MP4, WebM, OGG, MOV</p>
                                    <div id="new-header-media-preview" class="new-media-preview-grid"></div>
                                </div>
                                <input type="file" id="header_media" name="header_media[]" accept="image/*,video/*" multiple style="display: none;">
                            </div>

                            <?php if (!empty($header_media_items)): ?>
                                <div class="form-group" style="margin-top: 12px;">
                                    <label>CURRENT HEADER MEDIA</label>
                                    <div class="header-media-bulk-actions">
                                        <label class="header-media-select-all">
                                            <input type="checkbox" id="select-all-media">
                                            <span>Select all</span>
                                        </label>
                                        <button type="button" class="header-media-delete-selected" id="delete-selected-media" onclick="deleteSelectedHeaderMedia()" disabled>Delete selected</button>
                                    </div>
                                    <div class="existing-media-grid">
                                        <?php foreach ($header_media_items as $media): ?>
                                            <?php
                                                $media_id = isset($media['id']) ? (int) $media['id'] : 0;
                                                $media_path = trim((string) ($media['media_path'] ?? ''));
                                                $media_type = strtolower(trim((string) ($media['media_type'] ?? '')));
                                                if ($media_path === '' || !in_array($media_type, ['image', 'video'], true)) {
                                                    continue;
                                                }
                                            ?>
                                            <div class="existing-media-item">
                                                <?php if ($media_id > 0): ?>
                                                    <input type="checkbox" class="existing-media-select" value="<?php echo $media_id; ?>" aria-label="Select media for delete">
                                                    <button type="button" class="existing-media-delete-btn" onclick="deleteHeaderMedia(<?php echo $media_id; ?>)" aria-label="Delete media">✕</button>
                                                <?php endif; ?>
                                                <?php if ($media_type === 'image'): ?>
                                                    <img src="../<?php echo htmlspecialchars($media_path); ?>" alt="Header media">
                                                <?php else: ?>
                                                    <video controls preload="metadata" playsinline>
                                                        <source src="../<?php echo htmlspecialchars($media_path); ?>">
                                                        Your browser does not support the video tag.
                                                    </video>
                                                <?php endif; ?>
                                                <p><?php echo htmlspecialchars(basename($media_path)); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($story['type'] === 'encoded' && !empty($pages)): ?>
                        <!-- Chapters Section -->
                        <div style="margin-top: 40px;">
                            <h3 style="color: #1e293b; margin-bottom: 20px; font-size: 1.1em;">
                                📖 CHAPTERS
                            </h3>
                            <div id="chapters-container">
                                <?php foreach ($pages as $page_index => $page): ?>
                                    <div class="page-input">
                                        <label>Chapter <?php echo $page['page_number']; ?></label>
                                        <div class="form-group" style="margin-bottom: 14px;">
                                            <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter title</label>
                                            <input type="text" name="chapter_titles[]" placeholder="Chapter <?php echo $page['page_number']; ?>: Title" value="<?php echo htmlspecialchars($page['chapter_title']); ?>">
                                        </div>
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter content</label>
                                            <?php $editor_id = 'chapter-editor-' . (int) ($page['id'] ?? ($page_index + 1)); ?>
                                            <?php $toolbar_id = 'chapter-toolbar-' . (int) ($page['id'] ?? ($page_index + 1)); ?>
                                            <div id="<?php echo $toolbar_id; ?>" class="ql-toolbar ql-snow" style="border: 1px solid #cbd5e1; border-bottom: none; border-radius: 8px 8px 0 0; background: #f8fafc;">
                                                <span class="ql-formats">
                                                    <select class="ql-size">
                                                        <option value="small"></option>
                                                        <option selected></option>
                                                        <option value="large"></option>
                                                        <option value="huge"></option>
                                                    </select>
                                                </span>
                                                <span class="ql-formats">
                                                    <button class="ql-bold"></button>
                                                    <button class="ql-italic"></button>
                                                    <button class="ql-underline"></button>
                                                </span>
                                                <span class="ql-formats">
                                                    <select class="ql-align">
                                                        <option selected></option>
                                                        <option value="center"></option>
                                                        <option value="right"></option>
                                                        <option value="justify"></option>
                                                    </select>
                                                </span>
                                            </div>
                                            <div
                                                id="<?php echo $editor_id; ?>"
                                                class="chapter-quill-editor"
                                                data-toolbar-id="<?php echo $toolbar_id; ?>"
                                                data-initial-content="<?php echo htmlspecialchars((string) $page['content'], ENT_QUOTES); ?>"
                                                style="min-height: 220px; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 8px 8px; background: #fff;"
                                            ></div>
                                            <textarea class="chapter-content-input" name="pages[]" required style="display: none;"><?php echo htmlspecialchars($page['content']); ?></textarea>
                                        </div>

                                        <?php
                                            $page_id = (int) ($page['id'] ?? 0);
                                            $page_media_items = isset($chapter_media_map[$page_id]) && is_array($chapter_media_map[$page_id])
                                                ? $chapter_media_map[$page_id]
                                                : [];
                                        ?>
                                        <?php if (!empty($page_media_items)): ?>
                                            <div class="form-group" style="margin-top: 12px; margin-bottom: 0;">
                                                <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Current chapter media</label>
                                                <div class="chapter-media-bulk-actions">
                                                    <label class="chapter-media-select-all">
                                                        <input type="checkbox" class="chapter-select-all" data-page-id="<?php echo $page_id; ?>">
                                                        <span>Select all</span>
                                                    </label>
                                                    <div style="display:flex;gap:8px;align-items:center;">
                                                        <button
                                                            type="button"
                                                            class="chapter-media-save-order"
                                                            data-page-id="<?php echo $page_id; ?>"
                                                            onclick="saveChapterMediaOrder(<?php echo $page_id; ?>)"
                                                            disabled
                                                        >Save order</button>
                                                        <button
                                                            type="button"
                                                            class="chapter-media-delete-selected"
                                                            data-page-id="<?php echo $page_id; ?>"
                                                            onclick="deleteSelectedChapterMedia(<?php echo $page_id; ?>)"
                                                            disabled
                                                        >Delete selected</button>
                                                    </div>
                                                </div>
                                                <div class="existing-media-grid chapter-media-grid" data-page-id="<?php echo $page_id; ?>">
                                                    <?php foreach ($page_media_items as $chapter_media): ?>
                                                        <?php
                                                            $chapter_media_id = isset($chapter_media['id']) ? (int) $chapter_media['id'] : 0;
                                                            $chapter_media_path = trim((string) ($chapter_media['media_path'] ?? ''));
                                                            $chapter_media_type = strtolower(trim((string) ($chapter_media['media_type'] ?? '')));
                                                            if ($chapter_media_path === '' || !in_array($chapter_media_type, ['image', 'video'], true)) {
                                                                continue;
                                                            }
                                                        ?>
                                                        <div class="existing-media-item chapter-media-draggable" draggable="true" data-media-id="<?php echo $chapter_media_id; ?>">
                                                            <?php if ($chapter_media_id > 0): ?>
                                                                <input type="checkbox" class="chapter-media-select" data-page-id="<?php echo $page_id; ?>" value="<?php echo $chapter_media_id; ?>" aria-label="Select chapter media for delete">
                                                                <button type="button" class="existing-media-delete-btn" onclick="deleteChapterMedia(<?php echo $chapter_media_id; ?>)" aria-label="Delete chapter media">✕</button>
                                                            <?php endif; ?>

                                                            <?php if ($chapter_media_type === 'image'): ?>
                                                                <img src="../<?php echo htmlspecialchars($chapter_media_path); ?>" alt="Chapter media">
                                                            <?php else: ?>
                                                                <video controls preload="metadata" playsinline>
                                                                    <source src="../<?php echo htmlspecialchars($chapter_media_path); ?>">
                                                                    Your browser does not support the video tag.
                                                                </video>
                                                            <?php endif; ?>
                                                            <p><?php echo htmlspecialchars(basename($chapter_media_path)); ?></p>
                                                            <span class="chapter-media-handle">Drag</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Form Actions -->
                    <div class="form-actions-main" style="margin-top: 40px;">
                        <div></div>
                        <div style="gap: 12px; display: flex;">
                            <a href="manage_stories.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes →</button>
                        </div>
                    </div>
                </form>

                <form method="POST" id="delete-media-form" style="display:none;">
                    <input type="hidden" name="action" value="delete_header_media">
                    <input type="hidden" name="media_id" id="delete-media-id" value="">
                </form>

                <form method="POST" id="delete-selected-media-form" style="display:none;">
                    <input type="hidden" name="action" value="delete_selected_header_media">
                    <div id="delete-selected-media-inputs"></div>
                </form>

                <form method="POST" id="delete-chapter-media-form" style="display:none;">
                    <input type="hidden" name="action" value="delete_chapter_media">
                    <input type="hidden" name="media_id" id="delete-chapter-media-id" value="">
                </form>

                <form method="POST" id="delete-selected-chapter-media-form" style="display:none;">
                    <input type="hidden" name="action" value="delete_selected_chapter_media">
                    <div id="delete-selected-chapter-media-inputs"></div>
                </form>

                <form method="POST" id="reorder-chapter-media-form" style="display:none;">
                    <input type="hidden" name="action" value="reorder_chapter_media">
                    <div id="reorder-chapter-media-inputs"></div>
                </form>
            </div>
        </main>
    </div>
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
    <script>
        // Sidebar toggle
        const toggleBtn = document.getElementById('toggleSidebar');
        const sidebar = document.getElementById('adminSidebar');
        const mainContent = document.querySelector('.admin-main');
        const chapterEditors = [];
        const editForm = document.getElementById('edit-form');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('hidden');
                mainContent.classList.toggle('expanded');
            });
        }

        function initializeChapterEditors() {
            if (typeof Quill === 'undefined') {
                return;
            }

            const editorElements = Array.from(document.querySelectorAll('.chapter-quill-editor'));
            editorElements.forEach((editorElement) => {
                const toolbarId = editorElement.getAttribute('data-toolbar-id');
                const hiddenInput = editorElement.parentElement.querySelector('.chapter-content-input');
                if (!toolbarId || !hiddenInput) {
                    return;
                }

                const quill = new Quill('#' + editorElement.id, {
                    theme: 'snow',
                    modules: {
                        toolbar: '#' + toolbarId
                    }
                });

                const initialHtml = editorElement.getAttribute('data-initial-content') || '';
                if (initialHtml.trim() !== '') {
                    quill.root.innerHTML = initialHtml;
                }

                hiddenInput.value = quill.root.innerHTML;

                quill.on('text-change', () => {
                    hiddenInput.value = quill.root.innerHTML;
                });

                chapterEditors.push({ quill, hiddenInput });
            });
        }

        const headerMediaInput = document.getElementById('header_media');
        const newHeaderPreview = document.getElementById('new-header-media-preview');
        const deleteMediaForm = document.getElementById('delete-media-form');
        const deleteMediaIdInput = document.getElementById('delete-media-id');
        const selectAllMedia = document.getElementById('select-all-media');
        const deleteSelectedMediaButton = document.getElementById('delete-selected-media');
        const deleteSelectedMediaForm = document.getElementById('delete-selected-media-form');
        const deleteSelectedMediaInputs = document.getElementById('delete-selected-media-inputs');
        const deleteChapterMediaForm = document.getElementById('delete-chapter-media-form');
        const deleteChapterMediaIdInput = document.getElementById('delete-chapter-media-id');
        const deleteSelectedChapterMediaForm = document.getElementById('delete-selected-chapter-media-form');
        const deleteSelectedChapterMediaInputs = document.getElementById('delete-selected-chapter-media-inputs');
        const reorderChapterMediaForm = document.getElementById('reorder-chapter-media-form');
        const reorderChapterMediaInputs = document.getElementById('reorder-chapter-media-inputs');

        function getMediaCheckboxes() {
            return Array.from(document.querySelectorAll('.existing-media-select'));
        }

        function updateBulkDeleteState() {
            const checkboxes = getMediaCheckboxes();
            const selectedCount = checkboxes.filter((cb) => cb.checked).length;

            if (deleteSelectedMediaButton) {
                deleteSelectedMediaButton.disabled = selectedCount === 0;
                deleteSelectedMediaButton.textContent = selectedCount > 0
                    ? 'Delete selected (' + selectedCount + ')'
                    : 'Delete selected';
            }

            if (selectAllMedia && checkboxes.length > 0) {
                selectAllMedia.checked = selectedCount === checkboxes.length;
                selectAllMedia.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
            }
        }

        function deleteHeaderMedia(mediaId) {
            if (!deleteMediaForm || !deleteMediaIdInput) {
                return;
            }

            if (!confirm('Delete this uploaded media?')) {
                return;
            }

            deleteMediaIdInput.value = String(mediaId || '');
            deleteMediaForm.submit();
        }

        function deleteSelectedHeaderMedia() {
            const checkboxes = getMediaCheckboxes().filter((cb) => cb.checked);
            if (checkboxes.length === 0 || !deleteSelectedMediaForm || !deleteSelectedMediaInputs) {
                return;
            }

            if (!confirm('Delete selected media items?')) {
                return;
            }

            deleteSelectedMediaInputs.innerHTML = '';
            checkboxes.forEach((cb) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'media_ids[]';
                input.value = cb.value;
                deleteSelectedMediaInputs.appendChild(input);
            });

            deleteSelectedMediaForm.submit();
        }

        function deleteChapterMedia(mediaId) {
            if (!deleteChapterMediaForm || !deleteChapterMediaIdInput) {
                return;
            }

            if (!confirm('Delete this chapter media item?')) {
                return;
            }

            deleteChapterMediaIdInput.value = String(mediaId || '');
            deleteChapterMediaForm.submit();
        }

        function getChapterMediaCheckboxes(pageId) {
            return Array.from(document.querySelectorAll('.chapter-media-select[data-page-id="' + pageId + '"]'));
        }

        function updateChapterBulkDeleteState(pageId) {
            const checkboxes = getChapterMediaCheckboxes(pageId);
            const selectedCount = checkboxes.filter((cb) => cb.checked).length;
            const selectAll = document.querySelector('.chapter-select-all[data-page-id="' + pageId + '"]');
            const deleteButton = document.querySelector('.chapter-media-delete-selected[data-page-id="' + pageId + '"]');

            if (deleteButton) {
                deleteButton.disabled = selectedCount === 0;
                deleteButton.textContent = selectedCount > 0
                    ? 'Delete selected (' + selectedCount + ')'
                    : 'Delete selected';
            }

            if (selectAll && checkboxes.length > 0) {
                selectAll.checked = selectedCount === checkboxes.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
            }
        }

        function markChapterOrderDirty(pageId) {
            const saveButton = document.querySelector('.chapter-media-save-order[data-page-id="' + pageId + '"]');
            if (!saveButton) {
                return;
            }

            saveButton.disabled = false;
            saveButton.textContent = 'Save order *';
        }

        function syncChapterMediaOrderInputs() {
            if (!reorderChapterMediaInputs) {
                return;
            }

            reorderChapterMediaInputs.innerHTML = '';
            const grids = Array.from(document.querySelectorAll('.chapter-media-grid'));

            grids.forEach((grid) => {
                const cards = Array.from(grid.querySelectorAll('.chapter-media-draggable[data-media-id]'));
                cards.forEach((card, index) => {
                    const mediaId = card.getAttribute('data-media-id');
                    if (!mediaId) {
                        return;
                    }

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'media_order[' + mediaId + ']';
                    input.value = String(index);
                    reorderChapterMediaInputs.appendChild(input);
                });
            });
        }

        function saveChapterMediaOrder(pageId) {
            const saveButton = document.querySelector('.chapter-media-save-order[data-page-id="' + pageId + '"]');
            if (!saveButton || saveButton.disabled || !reorderChapterMediaForm) {
                return;
            }

            syncChapterMediaOrderInputs();
            reorderChapterMediaForm.submit();
        }

        function deleteSelectedChapterMedia(pageId) {
            const selected = getChapterMediaCheckboxes(pageId).filter((cb) => cb.checked);
            if (selected.length === 0 || !deleteSelectedChapterMediaForm || !deleteSelectedChapterMediaInputs) {
                return;
            }

            if (!confirm('Delete selected chapter media items?')) {
                return;
            }

            deleteSelectedChapterMediaInputs.innerHTML = '';
            selected.forEach((cb) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'media_ids[]';
                input.value = cb.value;
                deleteSelectedChapterMediaInputs.appendChild(input);
            });

            deleteSelectedChapterMediaForm.submit();
        }

        if (selectAllMedia) {
            selectAllMedia.addEventListener('change', function () {
                const checkboxes = getMediaCheckboxes();
                checkboxes.forEach((cb) => {
                    cb.checked = !!selectAllMedia.checked;
                });
                updateBulkDeleteState();
            });
        }

        getMediaCheckboxes().forEach((cb) => {
            cb.addEventListener('change', updateBulkDeleteState);
        });

        Array.from(document.querySelectorAll('.chapter-select-all')).forEach((selectAll) => {
            selectAll.addEventListener('change', function () {
                const pageId = selectAll.getAttribute('data-page-id');
                const checkboxes = getChapterMediaCheckboxes(pageId);
                checkboxes.forEach((cb) => {
                    cb.checked = !!selectAll.checked;
                });
                updateChapterBulkDeleteState(pageId);
            });
        });

        Array.from(document.querySelectorAll('.chapter-media-select')).forEach((cb) => {
            cb.addEventListener('change', function () {
                const pageId = cb.getAttribute('data-page-id');
                updateChapterBulkDeleteState(pageId);
            });
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.chapter-media-draggable:not(.dragging)')];

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
        }

        Array.from(document.querySelectorAll('.chapter-media-grid')).forEach((grid) => {
            const pageId = grid.getAttribute('data-page-id');
            if (!pageId) {
                return;
            }

            let dragged = null;

            Array.from(grid.querySelectorAll('.chapter-media-draggable')).forEach((item) => {
                item.addEventListener('dragstart', () => {
                    dragged = item;
                    item.classList.add('dragging');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    dragged = null;
                });
            });

            grid.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (!dragged) {
                    return;
                }

                const afterElement = getDragAfterElement(grid, event.clientY);
                if (afterElement == null) {
                    grid.appendChild(dragged);
                } else {
                    grid.insertBefore(dragged, afterElement);
                }
            });

            grid.addEventListener('drop', (event) => {
                event.preventDefault();
                markChapterOrderDirty(pageId);
            });
        });

        updateBulkDeleteState();

        Array.from(document.querySelectorAll('.chapter-select-all')).forEach((selectAll) => {
            const pageId = selectAll.getAttribute('data-page-id');
            updateChapterBulkDeleteState(pageId);
        });

        if (headerMediaInput && newHeaderPreview) {
            headerMediaInput.addEventListener('change', function () {
                newHeaderPreview.innerHTML = '';

                if (!this.files || this.files.length === 0) {
                    return;
                }

                Array.from(this.files).forEach((file) => {
                    const card = document.createElement('div');
                    card.className = 'new-media-preview-item';

                    const mediaType = (file.type || '').toLowerCase();
                    const objectUrl = URL.createObjectURL(file);

                    if (mediaType.startsWith('image/')) {
                        const image = document.createElement('img');
                        image.src = objectUrl;
                        image.alt = file.name;
                        card.appendChild(image);
                    } else if (mediaType.startsWith('video/')) {
                        const video = document.createElement('video');
                        video.src = objectUrl;
                        video.controls = true;
                        video.muted = true;
                        video.playsInline = true;
                        card.appendChild(video);
                    }

                    const label = document.createElement('p');
                    label.textContent = file.name;
                    card.appendChild(label);
                    newHeaderPreview.appendChild(card);
                });
            });
        }

        initializeChapterEditors();

        if (editForm) {
            editForm.addEventListener('submit', function () {
                chapterEditors.forEach((editorData) => {
                    editorData.hiddenInput.value = editorData.quill.root.innerHTML;
                });
            });
        }
    </script>
</body>
</html>
