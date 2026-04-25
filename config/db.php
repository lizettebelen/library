<?php
// Database Configuration
$server_name = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
$http_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$is_local = in_array($server_name, ['localhost', '127.0.0.1', '::1'], true)
    || in_array($http_host, ['localhost', '127.0.0.1', '::1'], true)
    || strpos($http_host, 'localhost:') === 0;

$default_host = $is_local ? 'localhost' : 'sql301.infinityfree.com';
$default_user = $is_local ? 'root' : 'if0_41418874';
$default_pass = $is_local ? '' : 'Sayth3nam317';
$default_name = $is_local ? 'story_library' : 'if0_41418874_lindleyslibrary';

define('DB_HOST', getenv('DB_HOST') ?: $default_host);
define('DB_USER', getenv('DB_USER') ?: $default_user);
define('DB_PASS', getenv('DB_PASS') ?: $default_pass);
define('DB_NAME', getenv('DB_NAME') ?: $default_name);

// InfinityFree requires connecting to an existing database/user.
// Import schema.sql manually in phpMyAdmin before loading the site.
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

if ($conn->connect_error) {
    die('Database connection error: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// Auto-create tables if they don't exist
function initializeDatabaseSchema(&$conn) {
    $tables_sql = array(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            role ENUM('admin', 'user') DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "CREATE TABLE IF NOT EXISTS stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NOT NULL,
            genre VARCHAR(100) DEFAULT NULL,
            cover_image VARCHAR(255),
            header_media_path VARCHAR(255) DEFAULT NULL,
            header_media_type ENUM('image', 'video') DEFAULT NULL,
            type ENUM('encoded', 'file') NOT NULL,
            file_path VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_genre (genre),
            INDEX idx_type (type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "CREATE TABLE IF NOT EXISTS pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            page_number INT NOT NULL,
            chapter_title VARCHAR(255) DEFAULT NULL,
            content LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_page (story_id, page_number),
            INDEX idx_story_id (story_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS story_header_media (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS chapter_page_media (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    
    foreach ($tables_sql as $sql) {
        if (!$conn->query($sql)) {
            error_log("Failed to create table: " . $conn->error);
        }
    }
}

// Initialize schema on first connection
initializeDatabaseSchema($conn);
?>
