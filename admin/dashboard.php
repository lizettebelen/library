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

function ensureGenreColumn(&$conn) {
    $check = $conn->query("SHOW COLUMNS FROM stories LIKE 'genre'");
    if ($check && $check->num_rows > 0) {
        $conn->query("UPDATE stories SET genre = 'General' WHERE genre IS NULL OR TRIM(genre) = ''");
        return true;
    }

    $alter = $conn->query("ALTER TABLE stories ADD COLUMN genre VARCHAR(100) DEFAULT NULL AFTER author");
    if ($alter) {
        $conn->query("ALTER TABLE stories ADD INDEX idx_genre (genre)");
        $conn->query("UPDATE stories SET genre = 'General' WHERE genre IS NULL OR TRIM(genre) = ''");
        return true;
    }

    return false;
}

function ensureHeaderMediaColumns(&$conn) {
    $pathCol = $conn->query("SHOW COLUMNS FROM stories LIKE 'header_media_path'");
    if (!$pathCol || $pathCol->num_rows === 0) {
        $conn->query("ALTER TABLE stories ADD COLUMN header_media_path VARCHAR(255) DEFAULT NULL AFTER cover_image");
    }

    $typeCol = $conn->query("SHOW COLUMNS FROM stories LIKE 'header_media_type'");
    if (!$typeCol || $typeCol->num_rows === 0) {
        $conn->query("ALTER TABLE stories ADD COLUMN header_media_type ENUM('image','video') DEFAULT NULL AFTER header_media_path");
    }
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

function ensureChapterTitleColumn(&$conn) {
    $check = $conn->query("SHOW COLUMNS FROM pages LIKE 'chapter_title'");
    if ($check && $check->num_rows > 0) {
        return true;
    }

    return $conn->query("ALTER TABLE pages ADD COLUMN chapter_title VARCHAR(255) DEFAULT NULL AFTER page_number");
}

function ensureChapterMediaTable(&$conn) {
    $check = $conn->query("SHOW TABLES LIKE 'chapter_page_media'");
    if ($check && $check->num_rows > 0) {
        return true;
    }

    $create = "
        CREATE TABLE IF NOT EXISTS chapter_page_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            page_id INT NOT NULL,
            media_path VARCHAR(255) NOT NULL,
            media_type ENUM('image','video') NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chapter_media_page (page_id, sort_order),
            INDEX idx_chapter_media_story (story_id),
            CONSTRAINT fk_chapter_media_story
                FOREIGN KEY (story_id) REFERENCES stories(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_chapter_media_page
                FOREIGN KEY (page_id) REFERENCES pages(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    return (bool) $conn->query($create);
}

ensureGenreColumn($conn);
ensureHeaderMediaColumns($conn);
ensureChapterTitleColumn($conn);
ensureStoryHeaderMediaTable($conn);
ensureChapterMediaTable($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $author = isset($_POST['author']) ? trim($_POST['author']) : '';
    $genre = isset($_POST['genre']) ? trim($_POST['genre']) : '';
    $story_type = isset($_POST['type']) ? $_POST['type'] : '';
    
    // Validate common fields
    if (empty($title) || empty($author) || empty($genre)) {
        $error = 'Title, Author, and Genre are required.';
    } else if ($story_type === 'encoded') {
        // Handle encoded story
        handleEncodedStory($conn, $title, $author, $genre, $error, $success);
    } else if ($story_type === 'file') {
        // Handle file upload
        handleFileUpload($conn, $title, $author, $genre, $error, $success);
    } else {
        $error = 'Please select a story type.';
    }
}

function handleCoverUpload($title, &$error) {
    // Check if cover image was uploaded
    if (!isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] === UPLOAD_ERR_NO_FILE) {
        // No cover uploaded, use placeholder
        return createPlaceholderCover($title);
    }
    
    $file = $_FILES['cover_image'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'Cover image exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'Cover image exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'Cover image was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Cover upload blocked by extension'
        ];
        // Log error but use placeholder
        error_log("Cover upload error: " . ($upload_errors[$file['error']] ?? 'Unknown error'));
        return createPlaceholderCover($title);
    }
    
    if (!is_uploaded_file($file['tmp_name'])) {
        error_log("Invalid cover image upload attempted");
        return createPlaceholderCover($title);
    }
    
    // Validate image type
    $mime_type = mime_content_type($file['tmp_name']);
    
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime_type, $allowed_mimes)) {
        $error = 'Cover image must be JPG, PNG, GIF, or WebP format (detected: ' . $mime_type . ')';
        error_log("Invalid cover mime type: " . $mime_type);
        return createPlaceholderCover($title);
    }
    
    // Check file size (max 5MB)
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        $error = 'Cover image is too large. Maximum size is 5MB.';
        error_log("Cover too large: " . $file['size'] . " bytes");
        return createPlaceholderCover($title);
    }
    
    // Create covers directory
    $cover_dir = '../uploads/covers/';
    if (!is_dir($cover_dir)) {
        if (!mkdir($cover_dir, 0755, true)) {
            error_log("Failed to create covers directory");
            return createPlaceholderCover($title);
        }
    }
    
    // Save with unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = uniqid('cover_') . '.' . $ext;
    $cover_path = $cover_dir . $new_filename;
    $relative_path = 'uploads/covers/' . $new_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $cover_path)) {
        error_log("Failed to move cover image from " . $file['tmp_name'] . " to " . $cover_path);
        return createPlaceholderCover($title);
    }
    
    if (!file_exists($cover_path)) {
        error_log("Cover file not found after move: " . $cover_path);
        return createPlaceholderCover($title);
    }
    
    error_log("✓ Cover uploaded successfully: " . $relative_path);
    // Return relative path for database
    return $relative_path;
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

function saveStoryHeaderMedia(&$conn, $story_id, $items) {
    if (empty($items)) {
        return true;
    }

    $delete_stmt = $conn->prepare('DELETE FROM story_header_media WHERE story_id = ?');
    if ($delete_stmt) {
        $delete_stmt->bind_param('i', $story_id);
        $delete_stmt->execute();
        $delete_stmt->close();
    }

    $insert_stmt = $conn->prepare('INSERT INTO story_header_media (story_id, media_path, media_type, sort_order) VALUES (?, ?, ?, ?)');
    if (!$insert_stmt) {
        return false;
    }

    foreach ($items as $index => $item) {
        $path = (string) ($item['path'] ?? '');
        $type = (string) ($item['type'] ?? '');
        $sort_order = (int) $index;
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

function handleChapterMediaUploads(&$error) {
    $uploaded = [];

    if (!isset($_FILES['chapter_media'])) {
        return $uploaded;
    }

    $files = $_FILES['chapter_media'];
    if (!is_array($files['name'] ?? null)) {
        return $uploaded;
    }

    $chapter_dir = '../uploads/chapters/';
    if (!is_dir($chapter_dir) && !mkdir($chapter_dir, 0755, true)) {
        $error = 'Failed to create chapter uploads directory.';
        return [];
    }

    $image_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $video_mimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-m4v', 'video/x-msvideo', 'video/x-matroska'];
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $video_exts = ['mp4', 'webm', 'ogg', 'mov', 'm4v', 'avi', 'mkv'];

    foreach ($files['name'] as $chapter_index => $chapter_names) {
        if (!is_array($chapter_names)) {
            continue;
        }

        foreach ($chapter_names as $file_index => $name) {
            $file_error = (int) ($files['error'][$chapter_index][$file_index] ?? UPLOAD_ERR_NO_FILE);
            if ($file_error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($file_error !== UPLOAD_ERR_OK) {
                $error = 'One of the chapter media files failed to upload.';
                return [];
            }

            $tmp_name = (string) ($files['tmp_name'][$chapter_index][$file_index] ?? '');
            $file_size = (int) ($files['size'][$chapter_index][$file_index] ?? 0);
            if (!is_uploaded_file($tmp_name)) {
                $error = 'Invalid chapter media upload.';
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
                $error = 'Chapter media must be images (JPG/PNG/GIF/WebP) or videos (MP4/WebM/OGG/MOV).';
                return [];
            }

            if ($file_size > $max_size) {
                $error = $media_type === 'image'
                    ? 'One of the chapter images is too large. Maximum size is 10MB.'
                    : 'One of the chapter videos is too large. Maximum size is 120MB.';
                return [];
            }

            $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
            if ($safe_ext === '') {
                $safe_ext = $media_type === 'image' ? 'jpg' : 'mp4';
            }

            $filename = uniqid('chapter_' . ((int) $chapter_index + 1) . '_', true) . '.' . $safe_ext;
            $full_path = $chapter_dir . $filename;
            $relative_path = 'uploads/chapters/' . $filename;

            if (!move_uploaded_file($tmp_name, $full_path)) {
                $error = 'Failed to save chapter media.';
                return [];
            }

            if (!isset($uploaded[$chapter_index])) {
                $uploaded[$chapter_index] = [];
            }

            $uploaded[$chapter_index][] = [
                'path' => $relative_path,
                'type' => $media_type,
            ];
        }
    }

    return $uploaded;
}

function saveChapterMediaItems(&$conn, $story_id, $page_id, $items) {
    if (empty($items)) {
        return true;
    }

    $insert_stmt = $conn->prepare('INSERT INTO chapter_page_media (story_id, page_id, media_path, media_type, sort_order) VALUES (?, ?, ?, ?, ?)');
    if (!$insert_stmt) {
        return false;
    }

    foreach ($items as $index => $item) {
        $path = (string) ($item['path'] ?? '');
        $type = (string) ($item['type'] ?? '');
        $sort_order = (int) $index;
        if ($path === '' || !in_array($type, ['image', 'video'], true)) {
            continue;
        }

        $insert_stmt->bind_param('iissi', $story_id, $page_id, $path, $type, $sort_order);
        if (!$insert_stmt->execute()) {
            $insert_stmt->close();
            return false;
        }
    }

    $insert_stmt->close();
    return true;
}

function deleteMediaFiles($items) {
    if (empty($items) || !is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        $relative = trim((string) ($item['path'] ?? ''));
        if ($relative === '') {
            continue;
        }

        $absolute = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, '/\\'));
        if ($absolute && file_exists($absolute)) {
            @unlink($absolute);
        }
    }
}

function handleEncodedStory(&$conn, $title, $author, $genre, &$error, &$success) {
    global $_POST;
    
    $chapter_titles = isset($_POST['chapter_titles']) ? (array) $_POST['chapter_titles'] : [];
    $pages = isset($_POST['pages']) ? (array) $_POST['pages'] : [];
    
    $chapters = [];
    $page_count = max(count($chapter_titles), count($pages));

    for ($index = 0; $index < $page_count; $index++) {
        $chapter_title = isset($chapter_titles[$index]) ? trim((string) $chapter_titles[$index]) : '';
        $content = isset($pages[$index]) ? trim((string) $pages[$index]) : '';

        if ($content === '') {
            continue;
        }

        if ($chapter_title === '') {
            $chapter_title = 'Chapter ' . ($index + 1);
        }

        $chapters[] = [
            'chapter_title' => $chapter_title,
            'content' => $content,
            'source_index' => $index,
        ];
    }
    
    if (empty($chapters)) {
        $error = 'Please add at least one chapter with content.';
        return;
    }
    
    // Handle cover image upload
    $cover_image = handleCoverUpload($title, $error);
    $chapter_media_map = handleChapterMediaUploads($error);
    if (!empty($error)) {
        return;
    }
    
    // Insert story
    $query = "INSERT INTO stories (title, author, genre, cover_image, type) VALUES (?, ?, ?, ?, 'encoded')";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $error = "Prepare failed: " . $conn->error;
        return;
    }
    
    $stmt->bind_param("ssss", $title, $author, $genre, $cover_image);
    
    if (!$stmt->execute()) {
        $error = "Execute failed: " . $stmt->error;
        $stmt->close();
        return;
    }
    
    $story_id = $stmt->insert_id;
    $stmt->close();

    // Insert chapters/pages
    $page_number = 1;
    $used_source_indexes = [];
    foreach ($chapters as $chapter) {
        $query = "INSERT INTO pages (story_id, page_number, chapter_title, content) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $chapter_title = $chapter['chapter_title'];
        $chapter_content = $chapter['content'];
        $stmt->bind_param("iiss", $story_id, $page_number, $chapter_title, $chapter_content);
        $stmt->execute();
        $page_id = (int) $stmt->insert_id;
        $stmt->close();

        $source_index = (int) ($chapter['source_index'] ?? ($page_number - 1));
        $used_source_indexes[$source_index] = true;
        $chapter_media_items = isset($chapter_media_map[$source_index]) && is_array($chapter_media_map[$source_index])
            ? $chapter_media_map[$source_index]
            : [];
        if (!saveChapterMediaItems($conn, $story_id, $page_id, $chapter_media_items)) {
            $error = 'Story was created, but some chapter media could not be saved.';
        }

        $page_number++;
    }

    foreach ($chapter_media_map as $source_index => $items) {
        if (!isset($used_source_indexes[(int) $source_index])) {
            deleteMediaFiles($items);
        }
    }
    
    $success = 'Story created successfully! <a href="../view.php?id=' . $story_id . '">Read it now</a>';
}

function handleFileUpload(&$conn, $title, $author, $genre, &$error, &$success) {
    if (!isset($_FILES['story_file'])) {
        $error = 'No file input detected. Please select a file to upload.';
        return;
    }
    
    if ($_FILES['story_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload blocked by extension'
        ];
        $error = $upload_errors[$_FILES['story_file']['error']] ?? 'Unknown upload error';
        return;
    }
    
    $file = $_FILES['story_file'];
    
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid file upload. Please try again.';
        return;
    }
    
    $allowed_extensions = [
        'pdf', 'txt', 'doc', 'docx', 'odt', 'rtf',
        'ppt', 'pptx', 'odp',
        'xls', 'xlsx', 'ods', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        'mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'ogv',
        'mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a',
        'zip', 'rar', '7z', 'tar', 'gz'
    ];
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed_extensions)) {
        $error = 'File type .' . htmlspecialchars($file_ext) . ' is not supported.';
        return;
    }
    
    $max_size = 100 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        $error = 'File is too large. Maximum size is 100MB.';
        return;
    }
    
    $uploads_dir = '../uploads';
    if (!is_dir($uploads_dir)) {
        if (!mkdir($uploads_dir, 0755, true)) {
            $error = 'Failed to create uploads directory.';
            return;
        }
    }
    
    $new_filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $file_path = $uploads_dir . '/' . $new_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        $error = 'Failed to upload file.';
        return;
    }
    
    if (!file_exists($file_path)) {
        $error = 'File was moved but verification failed.';
        return;
    }
    
    // Handle cover image upload
    $cover_image = handleCoverUpload($title, $error);
    if (!empty($error)) {
        return;
    }
    
    // Store relative path for database
    $file_path_db = 'uploads/' . $new_filename;
    
    $query = "INSERT INTO stories (title, author, genre, cover_image, type, file_path) VALUES (?, ?, ?, ?, 'file', ?)";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $error = "Database error: " . $conn->error;
        unlink($file_path);
        return;
    }
    
    $stmt->bind_param("sssss", $title, $author, $genre, $cover_image, $file_path_db);
    
    if (!$stmt->execute()) {
        $error = "Failed to save story to database: " . $stmt->error;
        unlink($file_path);
        $stmt->close();
        return;
    }
    
    $story_id = $stmt->insert_id;
    $stmt->close();

    $success = 'File uploaded successfully! Redirecting to your story in 2 seconds... <br><a href="../view.php?id=' . $story_id . '" style="color: #6366f1; text-decoration: underline;">Click here if not redirected</a>';
}

function createPlaceholderCover($title, $file_ext = '') {
    $cover_dir = '../uploads/covers/';
    if (!is_dir($cover_dir)) {
        mkdir($cover_dir, 0755, true);
    }
    
    $type_icon = 'placeholder.jpg';
    
    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
        $type_icon = 'image.jpg';
    } elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'ogv'])) {
        $type_icon = 'video.jpg';
    } elseif (in_array($file_ext, ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a'])) {
        $type_icon = 'audio.jpg';
    } elseif (in_array($file_ext, ['doc', 'docx', 'odt', 'rtf'])) {
        $type_icon = 'document.jpg';
    } elseif (in_array($file_ext, ['ppt', 'pptx', 'odp'])) {
        $type_icon = 'presentation.jpg';
    } elseif (in_array($file_ext, ['xls', 'xlsx', 'ods', 'csv'])) {
        $type_icon = 'spreadsheet.jpg';
    }
    
    return 'uploads/covers/' . $type_icon;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Lindley's Library</title>
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
            margin-bottom: 40px;
        }

        .admin-header h1 {
            font-size: 2em;
            color: #1e293b;
            margin: 0 0 10px;
        }

        .admin-header .hero-tag {
            display: inline-block;
            background: rgba(99, 102, 241, 0.1);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 700;
            color: #6366f1;
            margin-bottom: 15px;
        }

        .admin-header p {
            color: #64748b;
            margin: 10px 0 0;
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                width: 200px;
            }

            .admin-main {
                margin-left: 200px;
                padding: 20px;
            }

            .admin-header h1 {
                font-size: 1.5em;
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

            .sidebar-toggle {
                top: 14px;
                left: 14px;
            }
        }

        @media (max-width: 640px) {
            .story-form-container {
                padding: 16px;
            }

            .cover-upload-area,
            .file-upload-area {
                min-height: 160px;
                padding: 18px;
            }

            .page-input {
                padding: 14px;
            }

            .form-actions-main {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .form-actions-main > div:last-child {
                display: flex !important;
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .form-actions-main > div:last-child .btn {
                width: 100%;
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
                <li><a href="dashboard.php" class="active">✏️ New Story</a></li>
                <li><a href="manage_stories.php">📖 Manage Stories</a></li>
            </ul>
            <div class="admin-logout">
                <a href="logout.php">🚪 Logout</a>
                <small>Signed in as <?php echo htmlspecialchars($_SESSION['name']); ?></small>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="admin-main">
            <div class="admin-header">
                <span class="hero-tag">Admin Panel</span>
                <h1>Create New Story</h1>
                <p>Write a new story or upload a file to the library.</p>
            </div>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
            <script>
                setTimeout(function() {
                    const link = document.querySelector('.alert-success a');
                    if (link) {
                        window.location.href = link.href;
                    }
                }, 3000);
            </script>
        <?php endif; ?>

        <div class="story-form-container">
            <form method="POST" enctype="multipart/form-data" id="story-form">
                <div class="form-row">
                    <!-- Left Column: Story Details -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title">STORY TITLE</label>
                            <input type="text" id="title" name="title" required placeholder="The Echoes of Tomorrow" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="author">AUTHOR NAME</label>
                            <input type="text" id="author" name="author" required placeholder="Jane Doe" value="<?php echo isset($_POST['author']) ? htmlspecialchars($_POST['author']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="genre">GENRE</label>
                            <input type="text" id="genre" name="genre" required placeholder="Fantasy" value="<?php echo isset($_POST['genre']) ? htmlspecialchars($_POST['genre']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>STORY TYPE</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="type" value="encoded" checked onchange="switchStoryType()">
                                    <span>Write Story</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="type" value="file" onchange="switchStoryType()">
                                    <span>Upload File</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Cover Upload -->
                    <div class="form-column">
                        <div class="form-group">
                            <label>STORY COVER</label>
                            <div class="cover-upload-area" id="cover-upload" style="position: relative; overflow: hidden;">
                                <img id="cover-preview" style="width: 100%; height: 100%; object-fit: cover; display: none; position: absolute; top: 0; left: 0;">
                                <div id="cover-placeholder" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                                    <svg style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <p style="color: #64748b; margin: 0; font-weight: 600;">Upload portrait cover (3:4)</p>
                                    <p style="color: #94a3b8; font-size: 0.85em; margin: 5px 0 0 0;">Drag or add file</p>
                                </div>
                                <input type="file" id="cover_image" name="cover_image" accept="image/*" style="display: none;">
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-actions-inline">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('cover_image').click()">
                        <svg style="width: 16px; height: 16px; display: inline-block; margin-right: 6px; vertical-align: middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        Upload Cover
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="generateCover()">
                        <svg style="width: 16px; height: 16px; display: inline-block; margin-right: 6px; vertical-align: middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        Generate
                    </button>
                </div>

                <!-- Content Section -->
                <div id="encoded-section" style="margin-top: 30px;">
                    <div class="pages-section">
                        <h3 style="color: #1e293b; margin-bottom: 20px; font-size: 1.1em;">
                            <svg style="width: 20px; height: 20px; display: inline-block; margin-right: 8px; vertical-align: middle; color: #6366f1;" viewBox="0 0 24 24" fill="currentColor">
                                <circle cx="12" cy="12" r="10"></circle>
                                <text x="12" y="16" text-anchor="middle" font-size="12" fill="white" font-weight="bold">1</text>
                            </svg>
                            CHAPTER CONTENT
                        </h3>
                        <div id="pages-container">
                            <div class="page-input">
                                <label>Chapter 1</label>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter title</label>
                                    <input type="text" name="chapter_titles[]" placeholder="Chapter 1: The Beginning" value="<?php echo isset($_POST['chapter_titles'][0]) ? htmlspecialchars($_POST['chapter_titles'][0]) : ''; ?>">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter content</label>
                                    <div class="chapter-quill-toolbar ql-toolbar ql-snow" style="border: 1px solid #cbd5e1; border-bottom: none; border-radius: 8px 8px 0 0; background: #f8fafc;">
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
                                        class="chapter-quill-editor"
                                        data-initial-content="<?php echo isset($_POST['pages'][0]) ? htmlspecialchars((string) $_POST['pages'][0], ENT_QUOTES) : ''; ?>"
                                        style="min-height: 220px; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 8px 8px; background: #fff;"
                                    ></div>
                                    <textarea class="chapter-content-input" name="pages[]" required style="display: none;"><?php echo isset($_POST['pages'][0]) ? htmlspecialchars($_POST['pages'][0]) : ''; ?></textarea>
                                </div>
                                <div class="form-group" style="margin-top: 12px; margin-bottom: 0;">
                                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter media (images/videos)</label>
                                    <input type="file" name="chapter_media[0][]" accept="image/*,video/*" multiple onchange="previewChapterMedia(this)">
                                    <small style="display: block; margin-top: 6px; color: #64748b;">Optional. Files uploaded here will only be attached to Chapter 1.</small>
                                    <div class="chapter-media-preview" style="margin-top: 10px;"></div>
                                </div>
                                <button type="button" class="btn-remove-page" onclick="removePage(this)" style="display: none;">Remove This Page</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addPage()" style="width: 100%; margin-top: 15px; text-align: center;">
                            + Append Next Chapter
                        </button>
                    </div>
                </div>

                <div id="file-section" style="display: none; margin-top: 30px;">
                    <div class="form-group">
                        <label for="story_file">SELECT FILE</label>
                        <div class="file-upload-area" onclick="document.getElementById('story_file').click()">
                            <svg style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                                <polyline points="13 2 13 9 20 9"></polyline>
                            </svg>
                            <p style="color: #64748b; margin: 0; font-weight: 600;">Drag your file here or click to browse</p>
                            <p style="color: #94a3b8; font-size: 0.85em; margin: 5px 0 0 0;">Supported: Documents, Images, Videos, Audio, Archives • Max: 100MB</p>
                        </div>
                        <input type="file" id="story_file" name="story_file" accept=".pdf,.txt,.doc,.docx,.odt,.rtf,.ppt,.pptx,.odp,.xls,.xlsx,.ods,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.mp4,.avi,.mov,.mkv,.flv,.wmv,.webm,.ogv,.mp3,.wav,.aac,.flac,.ogg,.m4a,.zip,.rar,.7z,.tar,.gz" style="display: none;">
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions-main">
                    <div>
                        <span style="color: #64748b; font-size: 0.9em;">Draft saved</span>
                    </div>
                    <div style="gap: 12px; display: flex;">
                        <a href="../index.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Publish Story →</button>
                    </div>
                </div>
            </form>
        </div>
        </main>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
    <script>
        const chapterEditors = new Map();
        const storyForm = document.getElementById('story-form');

        function initializeChapterEditor(pageInput) {
            if (typeof Quill === 'undefined' || !pageInput) {
                return;
            }

            const editorElement = pageInput.querySelector('.chapter-quill-editor');
            const toolbarElement = pageInput.querySelector('.chapter-quill-toolbar');
            const hiddenTextarea = pageInput.querySelector('textarea.chapter-content-input');

            if (!editorElement || !toolbarElement || !hiddenTextarea || chapterEditors.has(editorElement)) {
                return;
            }

            const quill = new Quill(editorElement, {
                theme: 'snow',
                modules: {
                    toolbar: toolbarElement
                }
            });

            const initialContent = editorElement.getAttribute('data-initial-content') || hiddenTextarea.value || '';
            if (initialContent.trim() !== '') {
                quill.root.innerHTML = initialContent;
            }

            hiddenTextarea.value = quill.root.innerHTML;

            quill.on('text-change', function () {
                hiddenTextarea.value = quill.root.innerHTML;
            });

            chapterEditors.set(editorElement, { quill, hiddenTextarea });
        }

        function switchStoryType() {
            const type = document.querySelector('input[name="type"]:checked').value;
            const encodedSection = document.getElementById('encoded-section');
            const fileSection = document.getElementById('file-section');
            const encodedFields = document.querySelectorAll('#encoded-section textarea');
            
            if (type === 'encoded') {
                encodedSection.style.display = 'block';
                fileSection.style.display = 'none';
                encodedFields.forEach(ta => ta.required = true);
                document.getElementById('story_file').required = false;
            } else {
                encodedSection.style.display = 'none';
                fileSection.style.display = 'block';
                encodedFields.forEach(ta => ta.required = false);
                document.getElementById('story_file').required = true;
            }
        }

        function addPage() {
            const container = document.getElementById('pages-container');
            const pageCount = container.children.length + 1;
            const chapterIndex = pageCount - 1;
            
            const pageDiv = document.createElement('div');
            pageDiv.className = 'page-input';
            pageDiv.innerHTML = `
                <label>Chapter ${pageCount}</label>
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter title</label>
                    <input type="text" name="chapter_titles[]" placeholder="Chapter ${pageCount}: The Beginning">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter content</label>
                    <div class="chapter-quill-toolbar ql-toolbar ql-snow" style="border: 1px solid #cbd5e1; border-bottom: none; border-radius: 8px 8px 0 0; background: #f8fafc;">
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
                    <div class="chapter-quill-editor" data-initial-content="" style="min-height: 220px; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 8px 8px; background: #fff;"></div>
                    <textarea class="chapter-content-input" name="pages[]" required style="display: none;"></textarea>
                </div>
                <div class="form-group" style="margin-top: 12px; margin-bottom: 0;">
                    <label style="font-size: 0.95em; margin-bottom: 8px; text-transform: uppercase;">Chapter media (images/videos)</label>
                    <input type="file" name="chapter_media[${chapterIndex}][]" accept="image/*,video/*" multiple onchange="previewChapterMedia(this)">
                    <small style="display: block; margin-top: 6px; color: #64748b;">Optional. Files uploaded here will only be attached to Chapter ${pageCount}.</small>
                    <div class="chapter-media-preview" style="margin-top: 10px;"></div>
                </div>
                <button type="button" class="btn-remove-page" onclick="removePage(this)">Remove This Page</button>
            `;
            
            container.appendChild(pageDiv);
            initializeChapterEditor(pageDiv);
            switchStoryType();
            
            document.querySelectorAll('.btn-remove-page').forEach(btn => {
                btn.style.display = container.children.length > 1 ? 'block' : 'none';
            });
        }

        function removePage(button) {
            button.parentElement.remove();
            const container = document.getElementById('pages-container');
            
            if (container.children.length === 1) {
                container.children[0].querySelector('.btn-remove-page').style.display = 'none';
            }
            
            Array.from(container.children).forEach((child, index) => {
                child.querySelector(':scope > label').textContent = `Chapter ${index + 1}`;

                const titleInput = child.querySelector('input[name="chapter_titles[]"]');
                const mediaInput = child.querySelector('input[type="file"][name^="chapter_media["]');
                const mediaHelper = child.querySelector('.form-group small');

                if (titleInput) {
                    titleInput.placeholder = `Chapter ${index + 1}: The Beginning`;
                }

                if (mediaInput) {
                    mediaInput.name = `chapter_media[${index}][]`;
                }

                if (mediaHelper) {
                    mediaHelper.textContent = `Optional. Files uploaded here will only be attached to Chapter ${index + 1}.`;
                }
            });
        }

        function removeChapterMediaAtIndex(input, removeIndex) {
            if (!input || !input.files) {
                return;
            }

            const dataTransfer = new DataTransfer();
            Array.from(input.files).forEach((file, index) => {
                if (index !== removeIndex) {
                    dataTransfer.items.add(file);
                }
            });

            input.files = dataTransfer.files;
            previewChapterMedia(input);
        }

        function previewChapterMedia(input) {
            const preview = input.closest('.form-group').querySelector('.chapter-media-preview');
            if (!preview) {
                return;
            }

            preview.innerHTML = '';
            if (!input.files || input.files.length === 0) {
                return;
            }

            const list = document.createElement('div');
            list.style.display = 'grid';
            list.style.gridTemplateColumns = 'repeat(auto-fill, minmax(120px, 1fr))';
            list.style.gap = '10px';

            Array.from(input.files).forEach((file, fileIndex) => {
                const card = document.createElement('div');
                card.style.border = '1px solid #dbe3ee';
                card.style.borderRadius = '8px';
                card.style.padding = '6px';
                card.style.background = '#fff';
                card.style.position = 'relative';

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '✕';
                removeBtn.style.position = 'absolute';
                removeBtn.style.top = '6px';
                removeBtn.style.right = '6px';
                removeBtn.style.width = '22px';
                removeBtn.style.height = '22px';
                removeBtn.style.border = 'none';
                removeBtn.style.borderRadius = '999px';
                removeBtn.style.background = 'rgba(127, 29, 29, 0.9)';
                removeBtn.style.color = '#fff';
                removeBtn.style.fontSize = '12px';
                removeBtn.style.fontWeight = '800';
                removeBtn.style.cursor = 'pointer';
                removeBtn.setAttribute('aria-label', 'Remove media');
                removeBtn.addEventListener('click', function () {
                    removeChapterMediaAtIndex(input, fileIndex);
                });
                card.appendChild(removeBtn);

                const mediaType = (file.type || '').toLowerCase();
                const objectUrl = URL.createObjectURL(file);

                if (mediaType.startsWith('image/')) {
                    const image = document.createElement('img');
                    image.src = objectUrl;
                    image.alt = file.name;
                    image.style.width = '100%';
                    image.style.height = '90px';
                    image.style.objectFit = 'cover';
                    image.style.borderRadius = '6px';
                    card.appendChild(image);
                } else if (mediaType.startsWith('video/')) {
                    const video = document.createElement('video');
                    video.src = objectUrl;
                    video.controls = true;
                    video.muted = true;
                    video.playsInline = true;
                    video.style.width = '100%';
                    video.style.height = '90px';
                    video.style.objectFit = 'cover';
                    video.style.borderRadius = '6px';
                    card.appendChild(video);
                }

                const label = document.createElement('p');
                label.textContent = file.name;
                label.style.margin = '6px 0 0 0';
                label.style.fontSize = '11px';
                label.style.color = '#64748b';
                label.style.wordBreak = 'break-all';
                card.appendChild(label);

                list.appendChild(card);
            });

            preview.appendChild(list);
        }

        function generateCover() {
            alert('Cover generation coming soon!');
        }

        document.getElementById('cover-upload').addEventListener('click', function() {
            document.getElementById('cover_image').click();
        });

        document.getElementById('cover_image').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const file = this.files[0];
                const reader = new FileReader();
                
                reader.onload = function(event) {
                    const preview = document.getElementById('cover-preview');
                    const placeholder = document.getElementById('cover-placeholder');
                    
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                    placeholder.style.display = 'none';
                };
                
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('story_file').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const fileSize = (this.files[0].size / (1024 * 1024)).toFixed(2);
                const fileUploadArea = document.querySelector('.file-upload-area');
                fileUploadArea.innerHTML = '<p style="color: #10b981; font-weight: 600;">✓ ' + fileName + ' selected (' + fileSize + 'MB)</p>';
            }
        });

        if (storyForm) {
            storyForm.addEventListener('submit', function () {
                chapterEditors.forEach((entry) => {
                    entry.hiddenTextarea.value = entry.quill.root.innerHTML;
                });
            });
        }

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && storyForm) {
                storyForm.submit();
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

        document.querySelectorAll('#pages-container .page-input').forEach((pageInput) => {
            initializeChapterEditor(pageInput);
        });

        switchStoryType();
    </script>
</body>
</html>
