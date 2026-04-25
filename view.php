<?php
require_once __DIR__ . '/config/db.php';

$file_parser_paths = [
    __DIR__ . '/config/file_parser.php',
    __DIR__ . '/../config/file_parser.php',
];

foreach ($file_parser_paths as $file_parser_path) {
    if (file_exists($file_parser_path)) {
        require_once $file_parser_path;
        break;
    }
}

function view_ensure_live_connection(&$conn) {
    if ($conn instanceof mysqli) {
        try {
            if ($conn->ping()) {
                return true;
            }
        } catch (Throwable $e) {
            // Ignore and reconnect below.
        }
    }

    $reconnected = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($reconnected->connect_error) {
        error_log('view.php reconnect failed: ' . $reconnected->connect_error);
        return false;
    }

    $reconnected->set_charset('utf8mb4');
    $conn = $reconnected;
    return true;
}

function view_is_connection_lost_error($exception) {
    $code = (int) $exception->getCode();
    $message = strtolower((string) $exception->getMessage());

    return in_array($code, [2006, 2013], true)
        || strpos($message, 'server has gone away') !== false
        || strpos($message, 'lost connection') !== false;
}

function view_execute_stmt(&$conn, $query, $types = '', $params = []) {
    if (!view_ensure_live_connection($conn)) {
        return false;
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $stmt = null;

        try {
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                error_log('view.php prepare failed: ' . $conn->error);
                return false;
            }

            if ($types !== '' && !empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            return $stmt;
        } catch (mysqli_sql_exception $e) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }

            if ($attempt === 0 && view_is_connection_lost_error($e) && view_ensure_live_connection($conn)) {
                continue;
            }

            error_log('view.php query failed: ' . $e->getMessage());
            return false;
        }
    }

    return false;
}

function view_get_image_orientation($filePath) {
    $path = trim((string) $filePath);
    if ($path === '' || !file_exists($path)) {
        return 'unknown';
    }

    $imageInfo = @getimagesize($path);
    if (!$imageInfo || !isset($imageInfo[0], $imageInfo[1])) {
        return 'unknown';
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    if ($width <= 0 || $height <= 0) {
        return 'unknown';
    }

    if ($width > $height) {
        return 'landscape';
    }

    if ($height > $width) {
        return 'portrait';
    }

    return 'square';
}

// Get story ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$story_id = intval($_GET['id']);

// Fetch story details
$query = "SELECT * FROM stories WHERE id = ?";
$stmt = view_execute_stmt($conn, $query, 'i', [$story_id]);
if (!$stmt) {
    header('Location: index.php');
    exit();
}
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: index.php');
    exit();
}

$story = $result->fetch_assoc();
$stmt->close();

$recommended_stories = [];
$recommend_query = "SELECT id, title, author, cover_image, type FROM stories WHERE id <> ? ORDER BY created_at DESC LIMIT 6";
$recommend_stmt = view_execute_stmt($conn, $recommend_query, 'i', [$story_id]);
if ($recommend_stmt) {
    $recommend_result = $recommend_stmt->get_result();

    while ($recommend_row = $recommend_result->fetch_assoc()) {
        $recommended_stories[] = $recommend_row;
    }

    $recommend_stmt->close();
}

// If encoded story, fetch pages
$pages = [];
if ($story['type'] === 'encoded') {
    $query = "SELECT * FROM pages WHERE story_id = ? ORDER BY page_number ASC";
    $stmt = view_execute_stmt($conn, $query, 'i', [$story_id]);
    if ($stmt) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $pages[] = $row;
        }
        $stmt->close();
    }
}

// If file-based story, parse the file
$file_content = null;
if ($story['type'] === 'file' && !empty($story['file_path'])) {
    if (class_exists('FileParser')) {
        $file_content = FileParser::parseFile($story['file_path']);
    }
}

$text_pages = [];
if ($story['type'] === 'encoded') {
    foreach ($pages as $page_row) {
        $content = isset($page_row['content']) ? trim((string) $page_row['content']) : '';
        if ($content !== '') {
            $chapter_title = isset($page_row['chapter_title']) ? trim((string) $page_row['chapter_title']) : '';
            if ($chapter_title === '') {
                $chapter_title = 'Chapter ' . (int) ($page_row['page_number'] ?? (count($text_pages) + 1));
            }

            $text_pages[] = [
                'chapter_title' => $chapter_title,
                'content' => $content,
            ];
        }
    }
} elseif ($file_content && !isset($file_content['error']) && ($file_content['type'] ?? '') === 'text') {
    $text_pages = isset($file_content['pages']) && is_array($file_content['pages']) ? $file_content['pages'] : [];
}

$story_parts = [];
$story_summary = trim((string) ($story['description'] ?? ''));
$story_cover_path = trim((string) ($story['cover_image'] ?? ''));
$story_cover_full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($story_cover_path, '/\\'));
$has_story_cover = $story_cover_path !== '' && file_exists($story_cover_full_path);
$story_cover_orientation = $has_story_cover ? view_get_image_orientation($story_cover_full_path) : 'unknown';
$is_read_page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'read.php';
$auto_open_reader = $is_read_page || (isset($_GET['read']) && $_GET['read'] === '1');
$initial_part_number = isset($_GET['chapter']) ? max(1, (int) $_GET['chapter']) : 0;

foreach ($text_pages as $index => $text_page) {
    $part_title = 'Part ' . ($index + 1);
    $part_content = '';

    if (is_array($text_page)) {
        $candidate_title = trim((string) ($text_page['chapter_title'] ?? ''));
        if ($candidate_title !== '') {
            $part_title = $candidate_title;
        }
        $part_content = trim((string) ($text_page['content'] ?? ''));
    } else {
        $part_content = trim((string) $text_page);
    }

    $story_parts[] = [
        'title' => $part_title,
        'content' => $part_content,
    ];
}

if ($story_summary === '') {
    $summary_source = '';
    if (!empty($story_parts)) {
        $summary_source = trim((string) ($story_parts[0]['content'] ?? ''));
    }

    if ($summary_source !== '') {
        $summary_source = html_entity_decode(strip_tags($summary_source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $summary_source = preg_replace('/\s+/', ' ', $summary_source);
        $story_summary = function_exists('mb_substr')
            ? mb_substr($summary_source, 0, 240)
            : substr($summary_source, 0, 240);

        if (
            (function_exists('mb_strlen') && mb_strlen($summary_source) > 240) ||
            (!function_exists('mb_strlen') && strlen($summary_source) > 240)
        ) {
            $story_summary .= '...';
        }
    } else {
        $story_summary = 'No summary available for this story yet.';
    }
}

$total_words = 0;
foreach ($story_parts as $part_item) {
    $total_words += str_word_count(strip_tags((string) ($part_item['content'] ?? '')));
}
$estimated_minutes = max(1, (int) ceil($total_words / 220));
$story_parts_count = count($story_parts);

$story_date_label = 'Unknown date';
$story_date_source = trim((string) ($story['updated_at'] ?? ($story['created_at'] ?? '')));
if ($story_date_source !== '') {
    $story_timestamp = strtotime($story_date_source);
    if ($story_timestamp !== false) {
        $story_date_label = date('M j, Y', $story_timestamp);
    }
}

$story_tags = [];
$raw_tag_sources = [
    (string) ($story['genre'] ?? ''),
    (string) ($story['type'] ?? ''),
    (string) ($story['author'] ?? ''),
];

$summary_words_for_tags = preg_split('/[^a-z0-9]+/i', strtolower($story_summary));
if (is_array($summary_words_for_tags)) {
    foreach ($summary_words_for_tags as $word) {
        if (strlen($word) >= 6) {
            $raw_tag_sources[] = $word;
        }
        if (count($raw_tag_sources) >= 20) {
            break;
        }
    }
}

foreach ($raw_tag_sources as $tag_source) {
    $normalized = strtolower(trim((string) $tag_source));
    $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
    if ($normalized === '') {
        continue;
    }

    if (!in_array($normalized, $story_tags, true)) {
        $story_tags[] = $normalized;
    }

    if (count($story_tags) >= 12) {
        break;
    }
}

$header_media_path = trim((string) ($story['header_media_path'] ?? ''));
$header_media_type = strtolower(trim((string) ($story['header_media_type'] ?? '')));

if ($header_media_path !== '' && $header_media_type === '') {
    $header_ext = strtolower(pathinfo($header_media_path, PATHINFO_EXTENSION));
    if (in_array($header_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $header_media_type = 'image';
    } elseif (in_array($header_ext, ['mp4', 'webm', 'ogg', 'mov'], true)) {
        $header_media_type = 'video';
    }
}

$header_media_full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($header_media_path, '/\\'));
$has_header_media = $header_media_path !== '' && file_exists($header_media_full_path) && in_array($header_media_type, ['image', 'video'], true);

$header_media_markup = null;
if ($has_header_media) {
    if ($header_media_type === 'image') {
        $header_media_markup = '<div class="inline-page-header-media"><img src="' . htmlspecialchars($header_media_path, ENT_QUOTES) . '" alt="' . htmlspecialchars($story['title'], ENT_QUOTES) . ' header media"></div>';
    } else {
        $header_media_markup = '<div class="inline-page-header-media"><video controls preload="metadata" playsinline><source src="' . htmlspecialchars($header_media_path, ENT_QUOTES) . '">Your browser does not support the video tag.</video></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($story['title']); ?> - Lindley's Library</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/dearflip@latest/dflip/css/dflip.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css?family=Crimson+Text:400,700,900,400italic,700italic,900italic|Playfair+Display:400,700,900,400italic,700italic,900italic|Rock+Salt:400');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --flip-duration: 760ms;
            --flip-ease: cubic-bezier(0.22, 0.82, 0.28, 1);
            --turn-sheet-duration: 980ms;
            --border-color: #e2e8f0;
            --primary-color: #6366f1;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            width: 100%;
        }

        .navbar {
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 8px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .nav-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            cursor: pointer;
            min-width: fit-content;
        }

        .logo-image {
            height: 80px;
            width: auto;
            object-fit: contain;
            transition: transform 0.3s ease;
        }

        .nav-brand:hover .logo-image {
            transform: scale(1.05);
        }

        .nav-search {
            flex: 1;
            max-width: 350px;
        }

        .search-input {
            width: 100%;
            padding: 10px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95em;
            transition: all 0.3s ease;
            background: white;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 80px;
        }

        .back-to-library-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 8px;
            text-decoration: none;
            color: #4f46e5;
            font-weight: 600;
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
            white-space: nowrap;
        }

        .back-to-library-link:hover {
            background: rgba(99, 102, 241, 0.08);
            color: #3730a3;
            transform: translateY(-1px);
        }

        body {
            background: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            min-height: 100vh;
            padding: 0;
        }

        .reader-container {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 24px 24px 40px;
        }

        .story-meta-card {
            width: min(100%, 1000px);
            margin: 0 auto 18px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.16);
            overflow: hidden;
            position: relative;
            z-index: 10;
        }

        .story-preview {
            width: min(100%, 1000px);
            margin: 0 auto 18px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.16);
            padding: 18px 18px 16px;
            position: relative;
            z-index: 12;
        }

        .story-preview.hidden {
            display: none;
        }

        .story-preview-main {
            --preview-cover-col: 150px;
            display: grid;
            grid-template-columns: var(--preview-cover-col) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .story-preview-main.orientation-landscape {
            --preview-cover-col: 290px;
        }

        .story-preview-main.orientation-square {
            --preview-cover-col: 180px;
        }

        .story-preview-brand {
            display: inline-block;
            margin: 2px 0 8px;
            color: #f97316;
            font-weight: 800;
            letter-spacing: 0.02em;
            font-size: 0.95rem;
        }

        .story-preview-cover {
            width: 100%;
            height: auto;
            aspect-ratio: 3 / 4;
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(155deg, #1f2937 0%, #334155 100%);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.18);
        }

        .story-preview-cover.orientation-landscape {
            aspect-ratio: 16 / 10;
        }

        .story-preview-cover.orientation-square {
            aspect-ratio: 1 / 1;
        }

        .story-preview-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        .story-preview-cover-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.78);
            font-size: 1.9rem;
            font-weight: 700;
        }

        .story-preview-title {
            margin: 2px 0 6px;
            color: #0f172a;
            font-size: clamp(1.35rem, 2.5vw, 2rem);
            line-height: 1.15;
            font-weight: 800;
        }

        .story-preview-author {
            color: #475569;
            margin: 0 0 10px;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .story-preview-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 0 0 14px;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .story-preview-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .start-reading-btn {
            appearance: none;
            border: none;
            border-radius: 999px;
            background: linear-gradient(90deg, #020617 0%, #0f172a 100%);
            color: #ffffff;
            padding: 12px 22px;
            font-size: 0.95rem;
            font-weight: 800;
            cursor: pointer;
            min-width: 280px;
            max-width: 100%;
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.24);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .start-reading-btn:hover {
            transform: translateY(-1px);
        }

        .add-library-btn {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.25);
            background: #ffffff;
            color: #0f172a;
            font-size: 1.4rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .reader-stage {
            display: none;
        }

        .reader-stage.open {
            display: block;
        }

        .recommendations-card {
            width: min(100%, 1000px);
            margin: 0 auto 18px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.12);
            padding: 14px 16px 16px;
        }

        .recommendations-title {
            margin: 0 0 12px;
            font-size: 1.02rem;
            color: #0f172a;
            font-weight: 800;
        }

        .recommendations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 10px;
        }

        .recommendation-item {
            display: flex;
            gap: 10px;
            text-decoration: none;
            color: inherit;
            padding: 8px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: #f8fafc;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .recommendation-item:hover {
            border-color: #94a3b8;
            background: #f1f5f9;
        }

        .recommendation-cover {
            width: 42px;
            height: auto;
            aspect-ratio: 3 / 4;
            border-radius: 6px;
            overflow: hidden;
            background: linear-gradient(155deg, #1f2937 0%, #334155 100%);
            flex-shrink: 0;
        }

        .recommendation-cover.orientation-landscape {
            width: 68px;
            aspect-ratio: 16 / 10;
        }

        .recommendation-cover.orientation-square {
            width: 52px;
            aspect-ratio: 1 / 1;
        }

        .recommendation-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        .recommendation-cover-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 700;
        }

        .recommendation-title {
            margin: 0;
            color: #0f172a;
            font-size: 0.85rem;
            line-height: 1.25;
            font-weight: 700;
        }

        .recommendation-author {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .story-meta-tabs {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 0 12px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
        }

        .story-meta-tab {
            appearance: none;
            border: none;
            background: transparent;
            color: #334155;
            font-weight: 700;
            font-size: 0.92rem;
            line-height: 1;
            padding: 13px 2px;
            cursor: pointer;
            pointer-events: auto;
            position: relative;
            z-index: 2;
            border-bottom: 3px solid transparent;
        }

        .story-meta-tab:hover {
            color: #0f172a;
        }

        .story-meta-tab.active {
            box-shadow: none;
            color: #111827;
            border-bottom-color: #f97316;
        }

        .story-meta-pane {
            display: none;
            padding: 14px 16px 16px;
        }

        .story-meta-pane.active {
            display: block;
        }

        .story-summary-text {
            color: #334155;
            font-size: 0.98rem;
            line-height: 1.65;
            margin: 0;
        }

        .story-summary-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 12px;
            color: #334155;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .story-summary-meta span::after {
            content: '•';
            margin-left: 8px;
            color: #94a3b8;
        }

        .story-summary-meta span:last-child::after {
            content: '';
            margin: 0;
        }

        .story-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 0 0 12px;
        }

        .story-tag {
            display: inline-flex;
            align-items: center;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: #f8fafc;
            color: #1e293b;
            border-radius: 5px;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 4px 7px;
            text-transform: lowercase;
        }

        .parts-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .parts-link {
            width: 100%;
            text-align: left;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 8px;
            background: #ffffff;
            color: #1f2937;
            padding: 9px 12px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            pointer-events: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
        }

        .parts-link:hover {
            border-color: #6366f1;
            background: rgba(99, 102, 241, 0.06);
        }

        .parts-link-index {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 700;
            margin-right: 10px;
            min-width: 34px;
        }

        .parts-link-title {
            flex: 1;
        }

        .parts-link-arrow {
            color: #94a3b8;
            margin-left: 10px;
            font-size: 1rem;
            line-height: 1;
        }

        .inline-page-header-media {
            margin: 0.35rem 0 0.7rem;
            border-radius: 0.32rem;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.12);
            box-shadow: 0 0.45rem 1rem rgba(0, 0, 0, 0.14);
        }

        .inline-page-header-media img,
        .inline-page-header-media video {
            width: 100%;
            height: min(30vh, 220px);
            object-fit: cover;
            display: block;
            background: #0f172a;
        }

        .pdf-flip-wrap {
            width: min(100%, 1000px);
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.16);
            overflow: hidden;
            padding: 14px;
        }

        .pdf-flip-wrap ._df_book {
            width: 100%;
            height: 72vh;
            min-height: 520px;
        }

        #wrapper {
            margin-left: auto;
            margin-right: auto;
            max-width: 56rem;
            padding: 0.45rem 0.45rem 0.7rem;
            background: transparent;
        }

        #container {
            float: left;
            padding: 0;
            width: 100%;
        }

        .open-book {
            background: #fff;
            box-shadow:
                0 40px 80px rgba(0, 0, 0, 0.7),
                0 8px 20px rgba(0, 0, 0, 0.5),
                0 0 60px rgba(40, 100, 200, 0.12);
            color: #000;
            padding: 0.9em 2em 0.85em;
            position: relative;
            border-radius: 0.3em;
            width: 100%;
            aspect-ratio: 210 / 297;
            max-height: 48rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .open-book::before {
            background-color: #ffffff;
            border-radius: 0.25em;
            bottom: -1em;
            content: '';
            left: -1em;
            position: absolute;
            right: -1em;
            top: -1em;
            z-index: -1;
        }

        .open-book::after {
            background: linear-gradient(to right, transparent 0%, rgba(0, 0, 0, 0.26) 46%, rgba(0, 0, 0, 0.62) 49%, rgba(255, 255, 255, 0.1) 50%, rgba(0, 0, 0, 0.62) 51%, rgba(0, 0, 0, 0.26) 54%, transparent 100%);
            bottom: -1em;
            content: '';
            left: 50%;
            position: absolute;
            top: -1em;
            transform: translate(-50%, 0);
            width: 0.95em;
            z-index: 1;
        }

        .open-book * {
            position: relative;
        }

        .open-book *::selection {
            background: rgba(222, 255, 0, 0.75);
        }

        .open-book header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            padding-bottom: 0.2em;
        }

        .open-book header h1,
        .open-book header h6,
        .open-book footer * {
            font: 700 1em/1.25 'Playfair Display', serif;
            letter-spacing: 0.125em;
            margin: 0;
        }

        .open-book header h1 {
            font-size: 0.8em;
            text-transform: uppercase;
            color: #1f2937;
            letter-spacing: 0.22em;
        }

        .open-book header h6 {
            font-size: 0.8em;
            text-transform: uppercase;
            text-align: right;
            color: #374151;
            letter-spacing: 0.16em;
        }

        .open-book article {
            line-height: 1.55;
            column-count: 2;
            column-gap: 1.85em;
            column-rule: 1px solid rgba(0, 0, 0, 0.08);
            position: relative;
            padding-top: 0.05em;
            font-size: 0.98rem;
        }

        .open-book article p {
            text-indent: 2em;
            margin-bottom: 0.6em;
            font: 400 1rem/1.48 'Crimson Text', serif;
            break-inside: avoid-column;
        }

        .book-panel .chapter-heading {
            margin: 0 0 0.85em;
            padding-bottom: 0.35em;
            font: 800 1.15rem/1.15 'Playfair Display', serif;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #111827;
            border-bottom: 2px solid rgba(0, 0, 0, 0.18);
            break-after: avoid;
        }

        .open-book .chapter-title {
            column-span: all;
            font: 700 clamp(1.9rem, 3.2vw, 2.7rem)/1.05 'Playfair Display', serif;
            letter-spacing: 0.11em;
            margin: 0;
            padding: 0.5em 0 0.44em;
            text-align: center;
            text-transform: uppercase;
            border-top: 2px solid rgba(0, 0, 0, 0.8);
            border-bottom: 2px solid rgba(0, 0, 0, 0.8);
            position: relative;
        }

        .open-book .chapter-title::after {
            content: '◈';
            position: absolute;
            left: 50%;
            bottom: -0.52em;
            transform: translateX(-50%);
            background: #fff;
            padding: 0 0.35em;
            color: #666;
            font-size: 0.72em;
        }

        .open-book article > p:first-of-type {
            column-span: none;
        }

        .open-book article > p:first-of-type:first-letter {
            float: left;
            font: 700 2.7em/0.66 'Playfair Display', serif;
            padding: 0.12em 0.08em 0 0;
            text-transform: uppercase;
        }

        .open-book article ul,
        .open-book article ol,
        .open-book article dl {
            break-inside: avoid-column;
            margin-bottom: 0.75em;
        }

        .open-book article > ul,
        .open-book article > ol {
            padding-left: 1.35em;
        }

        .text-reader {
            width: min(100%, 56rem);
            min-height: 44rem;
            margin: 0 auto;
            overflow: hidden;
            perspective: 1800px;
            transform-style: preserve-3d;
            transition: transform 0.65s var(--flip-ease), box-shadow 0.65s var(--flip-ease);
        }

        .text-reader.turning-next,
        .text-reader.turning-prev {
            transform: translateY(-1px) scale(0.998) rotateX(0.35deg);
            box-shadow: rgba(0, 0, 0, 0.72) 0 1.5em 4.1em;
        }

        .text-reader.turning-next {
            transform: translateY(-1px) scale(0.998) rotateY(-0.8deg) rotateX(0.35deg);
        }

        .text-reader.turning-prev {
            transform: translateY(-1px) scale(0.998) rotateY(0.8deg) rotateX(0.35deg);
        }

        .book-top {
            padding-bottom: 0;
        }

        .book-title-block {
            padding: 0;
        }

        .book-spread {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 1.8rem minmax(0, 1fr);
            gap: 0;
            align-items: stretch;
            flex: 1 1 auto;
            min-height: 0;
            position: relative;
            perspective: 2000px;
            transform-style: preserve-3d;
            will-change: transform;
        }

        .book-spread::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 18px;
            background: linear-gradient(
                to right,
                rgba(0, 0, 0, 0.62) 0%,
                rgba(0, 0, 0, 0.2) 28%,
                rgba(255, 255, 255, 0.1) 50%,
                rgba(0, 0, 0, 0.2) 72%,
                rgba(0, 0, 0, 0.62) 100%
            );
            transform: translateX(-50%);
            z-index: 0;
        }

        .book-spread::after {
            content: '';
            position: absolute;
            inset: -1rem -0.5rem -0.6rem;
            background: radial-gradient(ellipse at center, rgba(0, 0, 0, 0.26) 0%, rgba(0, 0, 0, 0.08) 42%, rgba(0, 0, 0, 0) 72%);
            pointer-events: none;
            opacity: 0.26;
            transition: opacity 0.35s ease;
            z-index: 0;
        }

        .text-reader.turning-next .book-spread::after,
        .text-reader.turning-prev .book-spread::after {
            opacity: 0.52;
        }

        .book-panel {
            position: relative;
            z-index: 1;
            padding: 0 0.1rem 1.5rem;
            font: 400 1rem/1.5 'Crimson Text', serif;
            color: #1f2937;
            overflow: hidden;
            overflow-wrap: anywhere;
        }

        .book-panel p {
            margin: 0 0 0.95em;
            text-indent: 2em;
            break-inside: avoid;
        }

        .book-panel p:first-child {
            text-indent: 0;
        }

        .book-panel p:first-child::first-letter,
        .book-panel .chapter-heading + p::first-letter {
            float: left;
            font: 700 3.2em/0.68 'Playfair Display', serif;
            padding: 0.1em 0.08em 0 0;
            text-transform: uppercase;
        }

        .turn-sheet {
            position: absolute;
            top: 0;
            bottom: 0;
            width: calc((100% - 1.8rem) / 2);
            z-index: 5;
            transform-style: preserve-3d;
            backface-visibility: hidden;
            pointer-events: none;
            overflow: visible;
            background: #ffffff;
            border-radius: 0.22rem;
            box-shadow: 0 0.45rem 1.25rem rgba(0, 0, 0, 0.16), inset 0 0 0 1px rgba(0, 0, 0, 0.06);
            will-change: transform, opacity;
        }

        .turn-sheet::before,
        .turn-sheet::after {
            content: '';
            position: absolute;
            pointer-events: none;
        }

        .turn-sheet::before {
            inset: 0;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(255, 255, 255, 0.95) 38%, rgba(0, 0, 0, 0.09) 100%),
                linear-gradient(90deg, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0.02) 48%, rgba(0, 0, 0, 0.08) 100%);
            opacity: 1;
        }

        .turn-sheet.turn-next::before {
            background:
                linear-gradient(270deg, rgba(0, 0, 0, 0.34) 0%, rgba(0, 0, 0, 0.14) 18%, rgba(255, 255, 255, 0.98) 54%, rgba(255, 255, 255, 0.93) 100%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(255, 255, 255, 0.86) 62%, rgba(0, 0, 0, 0.09) 100%);
            animation: pageSheetShadeNext var(--turn-sheet-duration) linear forwards;
        }

        .turn-sheet.turn-prev::before {
            background:
                linear-gradient(90deg, rgba(0, 0, 0, 0.34) 0%, rgba(0, 0, 0, 0.14) 18%, rgba(255, 255, 255, 0.98) 54%, rgba(255, 255, 255, 0.93) 100%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(255, 255, 255, 0.86) 62%, rgba(0, 0, 0, 0.09) 100%);
            animation: pageSheetShadePrev var(--turn-sheet-duration) linear forwards;
        }

        .turn-sheet.turn-next::after,
        .turn-sheet.turn-prev::after {
            top: auto;
            bottom: -0.45rem;
            width: 9rem;
            height: 9rem;
            opacity: 0;
            border-radius: 0;
            filter: drop-shadow(0 0.75rem 1rem rgba(0, 0, 0, 0.3));
        }

        .turn-sheet.turn-next::after {
            right: -0.2rem;
            left: auto;
            background:
                radial-gradient(circle at 100% 100%, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.12) 20%, rgba(255, 255, 255, 0.18) 56%, rgba(255, 255, 255, 0) 70%),
                linear-gradient(315deg, rgba(245, 245, 245, 0.98) 0%, rgba(230, 230, 230, 0.86) 38%, rgba(215, 215, 215, 0.1) 72%, rgba(255, 255, 255, 0) 100%);
            clip-path: polygon(100% 0, 100% 100%, 0 100%);
            transform-origin: bottom right;
            animation: pageCurlNext var(--turn-sheet-duration) var(--flip-ease) forwards;
        }

        .turn-sheet.turn-prev::after {
            left: -0.2rem;
            right: auto;
            background:
                radial-gradient(circle at 0 100%, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.12) 20%, rgba(255, 255, 255, 0.18) 56%, rgba(255, 255, 255, 0) 70%),
                linear-gradient(45deg, rgba(245, 245, 245, 0.98) 0%, rgba(230, 230, 230, 0.86) 38%, rgba(215, 215, 215, 0.1) 72%, rgba(255, 255, 255, 0) 100%);
            clip-path: polygon(0 0, 100% 100%, 0 100%);
            transform-origin: bottom left;
            animation: pageCurlPrev var(--turn-sheet-duration) var(--flip-ease) forwards;
        }

        .turn-face {
            position: absolute;
            inset: 0;
            backface-visibility: hidden;
            overflow: hidden;
            transform-style: preserve-3d;
            will-change: transform, opacity;
        }

        .turn-face .book-panel {
            height: 100%;
            width: 100%;
            background: #ffffff;
            box-shadow: 0 0 1.35rem rgba(0, 0, 0, 0.16);
            box-sizing: border-box;
            padding-top: var(--turn-content-top, 0px);
            padding-bottom: var(--turn-content-bottom, 0px);
            transform: translateZ(0);
        }

        .turn-sheet.turn-next .turn-face--front .book-panel {
            box-shadow: inset -20px 0 30px rgba(0, 0, 0, 0.18), 0 0 1.1rem rgba(0, 0, 0, 0.14);
        }

        .turn-sheet.turn-next .turn-face--back .book-panel {
            box-shadow: inset 24px 0 36px rgba(0, 0, 0, 0.2), 0 0 1.1rem rgba(0, 0, 0, 0.14);
        }

        .turn-sheet.turn-prev .turn-face--front .book-panel {
            box-shadow: inset 20px 0 30px rgba(0, 0, 0, 0.18), 0 0 1.1rem rgba(0, 0, 0, 0.14);
        }

        .turn-sheet.turn-prev .turn-face--back .book-panel {
            box-shadow: inset -24px 0 36px rgba(0, 0, 0, 0.2), 0 0 1.1rem rgba(0, 0, 0, 0.14);
        }

        .turn-face--front {
            transform: rotateY(0deg) translateZ(1px);
        }

        .turn-face--back {
            transform: rotateY(180deg) translateZ(1px);
        }

        .turn-sheet .page-number {
            display: none;
        }

        .turn-sheet.turn-next {
            right: 0;
            transform-origin: left center;
            box-shadow: -12px 0 28px rgba(0, 0, 0, 0.3), 0 0.55rem 1.2rem rgba(0, 0, 0, 0.16);
            animation: turnSheetNext var(--turn-sheet-duration) var(--flip-ease) forwards;
        }

        .turn-sheet.turn-prev {
            left: 0;
            transform-origin: right center;
            box-shadow: 12px 0 28px rgba(0, 0, 0, 0.3), 0 0.55rem 1.2rem rgba(0, 0, 0, 0.16);
            animation: turnSheetPrev var(--turn-sheet-duration) var(--flip-ease) forwards;
        }

        @keyframes turnSheetNext {
            0% {
                transform: perspective(1900px) rotateX(0.1deg) rotateY(0deg) rotateZ(0deg);
            }
            14% {
                transform: perspective(1900px) rotateX(0.7deg) rotateY(-24deg) rotateZ(0.25deg);
            }
            38% {
                transform: perspective(1900px) rotateX(1deg) rotateY(-86deg) rotateZ(0deg);
            }
            66% {
                transform: perspective(1900px) rotateX(0.6deg) rotateY(-152deg) rotateZ(-0.18deg);
            }
            100% {
                transform: perspective(1900px) rotateX(0.1deg) rotateY(-180deg) rotateZ(0deg);
            }
        }

        @keyframes turnSheetPrev {
            0% {
                transform: perspective(1900px) rotateX(0.1deg) rotateY(0deg) rotateZ(0deg);
            }
            14% {
                transform: perspective(1900px) rotateX(0.7deg) rotateY(24deg) rotateZ(-0.25deg);
            }
            38% {
                transform: perspective(1900px) rotateX(1deg) rotateY(86deg) rotateZ(0deg);
            }
            66% {
                transform: perspective(1900px) rotateX(0.6deg) rotateY(152deg) rotateZ(0.18deg);
            }
            100% {
                transform: perspective(1900px) rotateX(0.1deg) rotateY(180deg) rotateZ(0deg);
            }
        }

        @keyframes pageCurlNext {
            0% {
                opacity: 0;
                transform: translate(0.85rem, 0.78rem) rotate(2deg) scale(0.18);
            }
            12% {
                opacity: 1;
                transform: translate(0.12rem, 0.16rem) rotate(0deg) scale(0.68);
            }
            38% {
                opacity: 1;
                transform: translate(-0.05rem, -0.04rem) rotate(-1deg) scale(0.96);
            }
            72% {
                opacity: 0.95;
                transform: translate(-0.14rem, -0.1rem) rotate(-2deg) scale(1.01);
            }
            100% {
                opacity: 0;
                transform: translate(-0.24rem, -0.14rem) rotate(-3deg) scale(1.03);
            }
        }

        @keyframes pageCurlPrev {
            0% {
                opacity: 0;
                transform: translate(-0.85rem, 0.78rem) rotate(-2deg) scale(0.18);
            }
            12% {
                opacity: 1;
                transform: translate(-0.12rem, 0.16rem) rotate(0deg) scale(0.68);
            }
            38% {
                opacity: 1;
                transform: translate(0.05rem, -0.04rem) rotate(1deg) scale(0.96);
            }
            72% {
                opacity: 0.95;
                transform: translate(0.14rem, -0.1rem) rotate(2deg) scale(1.01);
            }
            100% {
                opacity: 0;
                transform: translate(0.24rem, -0.14rem) rotate(3deg) scale(1.03);
            }
        }

        @keyframes pageSheetShadeNext {
            0% {
                filter: brightness(1);
            }
            35% {
                filter: brightness(0.93);
            }
            68% {
                filter: brightness(0.88);
            }
            100% {
                filter: brightness(1);
            }
        }

        @keyframes pageSheetShadePrev {
            0% {
                filter: brightness(1);
            }
            35% {
                filter: brightness(0.93);
            }
            68% {
                filter: brightness(0.88);
            }
            100% {
                filter: brightness(1);
            }
        }

        .book-footer {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 0.75rem;
            padding-top: 0.25rem;
            margin-top: auto;
        }

        .book-nav-button {
            width: 3rem;
            height: 3rem;
            border-radius: 999px;
            border: 1px solid rgba(0, 0, 0, 0.15);
            background: linear-gradient(180deg, #ffffff 0%, #f3f4f6 100%);
            color: #111827;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI Symbol', 'Noto Sans Symbols', sans-serif;
            font-size: 1.6rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 0.45rem 1.1rem rgba(0, 0, 0, 0.14);
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, background 0.2s ease;
        }

        .book-nav-button:hover:not(:disabled) {
            transform: translateY(-1px) scale(1.03);
            box-shadow: 0 0.7rem 1.45rem rgba(0, 0, 0, 0.2);
            background: linear-gradient(180deg, #ffffff 0%, #e5e7eb 100%);
        }

        .book-nav-button:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            box-shadow: none;
        }

        @media only screen and (max-width: 49.99em) {
            .navbar {
                padding: 12px 0;
            }

            .nav-content {
                gap: 10px;
            }

            #wrapper {
                padding: 1rem 0.5rem 1.5rem;
            }

            .open-book {
                padding: 0.8em;
                aspect-ratio: auto;
                max-height: none;
                overflow: visible;
            }

            .open-book header {
                flex-direction: column;
            }

            .open-book header h6 {
                text-align: left;
            }

            .open-book article {
                column-count: 1;
                column-rule: none;
                font-size: 0.9rem;
            }

            .open-book .chapter-title {
                column-span: none;
                font-size: 1.4rem;
            }

            .pdf-flip-wrap {
                padding: 8px;
            }

            .pdf-flip-wrap ._df_book {
                height: 62vh;
                min-height: 420px;
            }

            .text-reader {
                width: 100%;
                min-height: auto;
            }

            .story-meta-card {
                margin-bottom: 12px;
            }

            .story-preview {
                margin-bottom: 12px;
                padding: 14px;
            }

            .recommendations-card {
                margin-bottom: 12px;
                padding: 12px;
            }

            .recommendations-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .story-preview-main {
                --preview-cover-col: 100px;
                grid-template-columns: var(--preview-cover-col) minmax(0, 1fr);
                gap: 12px;
            }

            .story-preview-main.orientation-landscape {
                --preview-cover-col: 170px;
            }

            .story-preview-main.orientation-square {
                --preview-cover-col: 110px;
            }

            .story-preview-cover {
                height: auto;
                aspect-ratio: 3 / 4;
            }

            .story-preview-cover.orientation-landscape {
                aspect-ratio: 16 / 10;
            }

            .story-preview-cover.orientation-square {
                aspect-ratio: 1 / 1;
            }

            .start-reading-btn {
                min-width: 0;
                width: 100%;
            }

            .story-preview-actions {
                flex-wrap: wrap;
            }

            .add-library-btn {
                display: none;
            }

            .story-meta-tab {
                font-size: 0.95rem;
                padding: 10px 12px;
            }

            .book-spread {
                grid-template-columns: 1fr;
                min-height: 0;
                flex: 1 1 auto;
            }

            .book-gutter {
                display: none;
            }

            .right-panel {
                padding-left: 0;
                border-top: 1px solid rgba(0, 0, 0, 0.08);
                margin-top: 1rem;
                padding-top: 1rem;
            }

            .book-footer {
                justify-content: center;
                grid-template-columns: 1fr;
                justify-items: center;
            }

            .book-footer #page-numbers {
                display: none;
            }
        }

    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container nav-content">
            <a href="index.php" class="nav-brand" aria-label="Back to Library">
                <img src="assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Lindley's Library" class="logo-image">
            </a>

            <div class="nav-search">
                <input type="text" placeholder="Search stories..." class="search-input" readonly>
            </div>

            <div class="nav-actions">
                <a href="index.php" class="back-to-library-link" aria-label="Back to library">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Library</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="reader-container">
        <?php if (!empty($text_pages)): ?>
                    <?php $textPages = $text_pages; ?>
                    <?php if (!$is_read_page): ?>
                    <section class="story-preview" id="story-preview">
                        <div class="story-preview-main orientation-<?php echo htmlspecialchars($story_cover_orientation); ?>">
                            <div class="story-preview-cover orientation-<?php echo htmlspecialchars($story_cover_orientation); ?>">
                                <?php if ($has_story_cover): ?>
                                    <img src="<?php echo htmlspecialchars($story_cover_path); ?>" alt="<?php echo htmlspecialchars($story['title']); ?> cover">
                                <?php else: ?>
                                    <div class="story-preview-cover-fallback">Story</div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="story-preview-brand">Lindley's Original</span>
                                <h1 class="story-preview-title"><?php echo htmlspecialchars($story['title']); ?></h1>
                                <p class="story-preview-author">by <?php echo !empty($story['author']) ? htmlspecialchars($story['author']) : 'Unknown Author'; ?></p>
                                <div class="story-preview-stats">
                                    <span><?php echo (int) $story_parts_count; ?> parts</span>
                                    <span><?php echo (int) $estimated_minutes; ?> min read</span>
                                </div>
                                <div class="story-preview-actions">
                                    <a href="read.php?id=<?php echo (int) $story_id; ?>" class="start-reading-btn" id="start-reading-btn">Start reading</a>
                                    <button type="button" class="add-library-btn" aria-label="Add to library">+</button>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="story-meta-card" aria-label="Story details tabs">
                        <div class="story-meta-tabs" role="tablist" aria-label="Story tabs">
                            <button type="button" class="story-meta-tab active" role="tab" aria-selected="true" aria-controls="story-summary-pane" data-tab-target="story-summary-pane">Summary</button>
                            <button type="button" class="story-meta-tab" role="tab" aria-selected="false" aria-controls="story-parts-pane" data-tab-target="story-parts-pane">Parts</button>
                        </div>
                        <div id="story-summary-pane" class="story-meta-pane active" role="tabpanel">
                            <div class="story-summary-meta">
                                <span>General</span>
                                <span>Complete</span>
                                <span><?php echo htmlspecialchars($story_date_label); ?></span>
                                <span><?php echo (int) $estimated_minutes; ?> min</span>
                            </div>
                            <?php if (!empty($story_tags)): ?>
                                <div class="story-tags">
                                    <?php foreach ($story_tags as $tag): ?>
                                        <span class="story-tag"><?php echo htmlspecialchars($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="story-summary-text"><?php echo htmlspecialchars($story_summary); ?></p>
                        </div>
                        <div id="story-parts-pane" class="story-meta-pane" role="tabpanel">
                            <ul class="parts-list">
                                <?php foreach ($story_parts as $partIndex => $part): ?>
                                    <li>
                                        <a href="read.php?id=<?php echo (int) $story_id; ?>&chapter=<?php echo $partIndex + 1; ?>" class="parts-link">
                                            <span class="parts-link-index">#<?php echo $partIndex + 1; ?></span>
                                            <span class="parts-link-title"><?php echo htmlspecialchars($part['title']); ?></span>
                                            <span class="parts-link-arrow">›</span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </section>

                    <?php if (!empty($recommended_stories)): ?>
                    <section class="recommendations-card" aria-label="You may also like">
                        <h3 class="recommendations-title">You may also like</h3>
                        <div class="recommendations-grid">
                            <?php foreach ($recommended_stories as $related_story): ?>
                                <?php
                                    $related_cover_path = trim((string) ($related_story['cover_image'] ?? ''));
                                    $related_cover_full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($related_cover_path, '/\\'));
                                    $has_related_cover = $related_cover_path !== '' && file_exists($related_cover_full_path);
                                    $related_cover_orientation = $has_related_cover ? view_get_image_orientation($related_cover_full_path) : 'unknown';
                                ?>
                                <a href="view.php?id=<?php echo (int) $related_story['id']; ?>" class="recommendation-item">
                                    <div class="recommendation-cover orientation-<?php echo htmlspecialchars($related_cover_orientation); ?>">
                                        <?php if ($has_related_cover): ?>
                                            <img src="<?php echo htmlspecialchars($related_cover_path); ?>" alt="<?php echo htmlspecialchars($related_story['title']); ?> cover">
                                        <?php else: ?>
                                            <div class="recommendation-cover-fallback">Story</div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="recommendation-title"><?php echo htmlspecialchars($related_story['title']); ?></p>
                                        <p class="recommendation-author"><?php echo !empty($related_story['author']) ? htmlspecialchars($related_story['author']) : 'Unknown Author'; ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php endif; ?>

                    <div id="reader-stage" class="reader-stage<?php echo $auto_open_reader ? ' open' : ''; ?>">
                    <div id="wrapper">
                        <div id="container">
                            <section class="open-book text-reader" id="text-book">
                                <header class="book-top">
                                    <h1>BOOK LAYOUT</h1>
                                    <h6><?php echo !empty($story['author']) ? htmlspecialchars($story['author']) : 'Lindley\'s Library'; ?></h6>
                                </header>

                                <div class="book-title-block">
                                    <h2 class="chapter-title"><?php echo htmlspecialchars($story['title']); ?></h2>
                                </div>

                                <article class="book-spread">
                                    <div class="book-panel left-panel" id="spread-left">
                                        <span class="page-number" id="page-left-num">1</span>
                                    </div>
                                    <div class="book-gutter"></div>
                                    <div class="book-panel right-panel" id="spread-right">
                                        <span class="page-number" id="page-right-num">2</span>
                                    </div>
                                </article>

                                <footer class="book-footer">
                                    <button type="button" class="book-nav-button" id="book-prev" aria-label="Previous page">‹</button>
                                    <div></div>
                                    <button type="button" class="book-nav-button" id="book-next" aria-label="Next page">›</button>
                                </footer>
                            </section>
                        </div>
                    </div>

                    <script>
                        const sourcePages = <?php echo json_encode($textPages); ?>;
                        let bookPageIndex = 0;
                        let bookAnimating = false;
                        let bookPages = [];
                        let pageMeasurer = null;
                        let chapterStartMap = {};
                        const autoOpenReader = <?php echo $auto_open_reader ? 'true' : 'false'; ?>;
                        const initialPartNumber = <?php echo (int) $initial_part_number; ?>;

                        const textBook = document.getElementById('text-book');
                        const bookSpread = document.querySelector('.book-spread');
                        const spreadLeft = document.getElementById('spread-left');
                        const spreadRight = document.getElementById('spread-right');
                        const prevButton = document.getElementById('book-prev');
                        const nextButton = document.getElementById('book-next');
                        const leftPageNum = document.getElementById('page-left-num');
                        const rightPageNum = document.getElementById('page-right-num');
                        const headerMediaHtml = <?php echo $header_media_markup ? json_encode($header_media_markup) : 'null'; ?>;
                        const storyPreview = document.getElementById('story-preview');
                        const readerStage = document.getElementById('reader-stage');
                        const isReadPage = <?php echo $is_read_page ? 'true' : 'false'; ?>;
                        const shouldInitBookReader = isReadPage || autoOpenReader;

                        if (!isReadPage) {
                            document.querySelectorAll('.story-meta-tab').forEach((tabButton) => {
                                tabButton.addEventListener('click', () => {
                                    const targetId = tabButton.dataset.tabTarget;
                                    if (!targetId) return;

                                    document.querySelectorAll('.story-meta-tab').forEach((button) => {
                                        button.classList.remove('active');
                                        button.setAttribute('aria-selected', 'false');
                                    });

                                    document.querySelectorAll('.story-meta-pane').forEach((pane) => {
                                        pane.classList.remove('active');
                                    });

                                    tabButton.classList.add('active');
                                    tabButton.setAttribute('aria-selected', 'true');

                                    const targetPane = document.getElementById(targetId);
                                    if (targetPane) {
                                        targetPane.classList.add('active');
                                    }
                                });
                            });
                        }

                        if (shouldInitBookReader) {
                        function getTurnDurationMs() {
                            const raw = getComputedStyle(document.documentElement).getPropertyValue('--turn-sheet-duration').trim();
                            if (!raw) return 980;
                            if (raw.endsWith('ms')) return Number.parseFloat(raw) || 980;
                            if (raw.endsWith('s')) return (Number.parseFloat(raw) || 0.98) * 1000;
                            return Number.parseFloat(raw) || 980;
                        }

                        function escapeHtml(text) {
                            const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
                            return String(text).replace(/[&<>"']/g, (char) => map[char]);
                        }

                        function splitTextToParagraphs(content) {
                            return String(content)
                                .replace(/\r/g, '')
                                .split(/\n{2,}/)
                                .map((paragraph) => paragraph.replace(/\s*\n+\s*/g, ' ').trim())
                                .filter(Boolean);
                        }

                        function decodeHtmlEntities(value) {
                            const textarea = document.createElement('textarea');
                            let decoded = String(value || '');

                            // Decode twice to handle values saved as &amp;lt;...&amp;gt;.
                            for (let i = 0; i < 2; i++) {
                                textarea.innerHTML = decoded;
                                const nextValue = textarea.value;
                                if (nextValue === decoded) {
                                    break;
                                }
                                decoded = nextValue;
                            }

                            return decoded;
                        }

                        function sanitizeRichTextHtml(html) {
                            const template = document.createElement('template');
                            template.innerHTML = String(html || '');

                            const allowedTags = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'SPAN']);
                            const classPattern = /^(ql-align-(center|right|justify)|ql-size-(small|large|huge))$/;

                            function sanitizeNode(node) {
                                const children = Array.from(node.childNodes);

                                children.forEach((child) => {
                                    if (child.nodeType === Node.TEXT_NODE) {
                                        return;
                                    }

                                    if (child.nodeType !== Node.ELEMENT_NODE) {
                                        child.remove();
                                        return;
                                    }

                                    const tagName = child.tagName.toUpperCase();
                                    if (!allowedTags.has(tagName)) {
                                        const fragment = document.createDocumentFragment();
                                        while (child.firstChild) {
                                            fragment.appendChild(child.firstChild);
                                        }
                                        child.replaceWith(fragment);
                                        sanitizeNode(node);
                                        return;
                                    }

                                    const classValue = child.getAttribute('class') || '';
                                    const safeClasses = classValue
                                        .split(/\s+/)
                                        .map((value) => value.trim())
                                        .filter((value) => classPattern.test(value));

                                    Array.from(child.attributes).forEach((attribute) => {
                                        child.removeAttribute(attribute.name);
                                    });

                                    if ((tagName === 'P' || tagName === 'SPAN') && safeClasses.length > 0) {
                                        child.setAttribute('class', safeClasses.join(' '));
                                    }

                                    sanitizeNode(child);
                                });
                            }

                            sanitizeNode(template.content);
                            return template.innerHTML;
                        }

                        function htmlToParagraphBlocks(html) {
                            const wrapper = document.createElement('div');
                            wrapper.innerHTML = sanitizeRichTextHtml(decodeHtmlEntities(html));

                            const paragraphNodes = Array.from(wrapper.querySelectorAll('p'));
                            if (paragraphNodes.length > 0) {
                                return paragraphNodes
                                    .map((paragraph) => ({ type: 'html', html: paragraph.outerHTML }))
                                    .filter((block) => block.html.replace(/<[^>]+>/g, '').trim() !== '' || /<br\s*\/?\s*>/i.test(block.html));
                            }

                            return splitTextToParagraphs(wrapper.textContent || '').map((paragraph) => ({ type: 'paragraph', text: paragraph }));
                        }

                        function getBlockText(block) {
                            if (!block || typeof block !== 'object') {
                                return '';
                            }

                            if (block.type === 'html') {
                                const temp = document.createElement('div');
                                temp.innerHTML = block.html || '';
                                return (temp.textContent || '').trim();
                            }

                            return String(block.text || '').trim();
                        }

                        function buildSourceBlocks() {
                            const blocks = [];

                            sourcePages.forEach((page, index) => {
                                if (page && typeof page === 'object') {
                                    const chapterTitle = String(page.chapter_title || '').trim() || `Chapter ${index + 1}`;
                                    const chapterContent = String(page.content || '');

                                    blocks.push({ type: 'heading', text: chapterTitle });

                                    htmlToParagraphBlocks(chapterContent).forEach((block) => {
                                        blocks.push(block);
                                    });
                                    return;
                                }

                                splitTextToParagraphs(page).forEach((paragraph) => {
                                    blocks.push({ type: 'paragraph', text: paragraph });
                                });
                            });

                            return blocks;
                        }

                        function createMeasurer() {
                            const measurer = document.createElement('div');
                            measurer.className = 'book-panel left-panel pagination-measurer';
                            measurer.style.width = `${spreadLeft.getBoundingClientRect().width}px`;
                            measurer.style.height = `${spreadLeft.getBoundingClientRect().height}px`;
                            document.body.appendChild(measurer);
                            return measurer;
                        }

                        function renderParagraphs(blocks) {
                            return blocks.map((block) => {
                                if (block && typeof block === 'object' && block.type === 'heading') {
                                    return '<h3 class="chapter-heading">' + escapeHtml(block.text) + '</h3>';
                                }

                                if (block && typeof block === 'object' && block.type === 'html') {
                                    return block.html;
                                }

                                const text = typeof block === 'string' ? block : (block && block.text) ? block.text : '';
                                return '<p>' + escapeHtml(text) + '</p>';
                            }).join('');
                        }

                        function createFacePanel(panelClass, pageNumber, blocks) {
                            const face = document.createElement('div');
                            face.className = `turn-face ${panelClass}`;

                            const panel = document.createElement('div');
                            panel.className = `book-panel ${panelClass.includes('left') ? 'left-panel' : 'right-panel'}`;
                            panel.innerHTML = renderParagraphs(blocks);

                            const number = document.createElement('span');
                            number.className = 'page-number';
                            number.textContent = pageNumber;

                            panel.appendChild(number);
                            face.appendChild(panel);
                            return face;
                        }

                        function fitsParagraphs(blocks) {
                            pageMeasurer.innerHTML = renderParagraphs(blocks);
                            return pageMeasurer.scrollHeight <= pageMeasurer.clientHeight;
                        }

                        function splitParagraphToFit(paragraph, contextBlocks = []) {
                            const words = paragraph.split(/\s+/).filter(Boolean);
                            const chunks = [];
                            let start = 0;

                            while (start < words.length) {
                                let low = start + 1;
                                let high = words.length;
                                let best = start + 1;

                                while (low <= high) {
                                    const mid = Math.floor((low + high) / 2);
                                    const candidate = words.slice(start, mid).join(' ');
                                    const candidateBlock = { type: 'paragraph', text: candidate };
                                    const testBlocks = start === 0
                                        ? contextBlocks.concat(candidateBlock)
                                        : [candidateBlock];

                                    if (fitsParagraphs(testBlocks)) {
                                        best = mid;
                                        low = mid + 1;
                                    } else {
                                        high = mid - 1;
                                    }
                                }

                                if (best <= start) {
                                    best = Math.min(words.length, start + 1);
                                }

                                chunks.push(words.slice(start, best).join(' '));
                                start = best;
                            }

                            return chunks;
                        }

                        function paginateText() {
                            pageMeasurer = createMeasurer();
                            const blocks = buildSourceBlocks();
                            const pages = [];
                            let currentPage = [];

                            blocks.forEach((block) => {
                                if (block.type === 'heading') {
                                    if (currentPage.length) {
                                        pages.push(currentPage);
                                        currentPage = [];
                                    }

                                    currentPage = [block];
                                    return;
                                }

                                const nextPage = currentPage.concat(block);

                                if (fitsParagraphs(nextPage)) {
                                    currentPage = nextPage;
                                    return;
                                }

                                const headingOnly = currentPage.length === 1 && currentPage[0] && currentPage[0].type === 'heading';
                                const appendChunkToPages = (chunkBlock) => {
                                    const chunkFits = fitsParagraphs([chunkBlock]);

                                    if (currentPage.length && !chunkFits) {
                                        pages.push(currentPage);
                                        currentPage = [];
                                    }

                                    if (currentPage.length && !fitsParagraphs(currentPage.concat(chunkBlock))) {
                                        pages.push(currentPage);
                                        currentPage = [chunkBlock];
                                        return;
                                    }

                                    if (currentPage.length && fitsParagraphs(currentPage.concat(chunkBlock))) {
                                        currentPage = currentPage.concat(chunkBlock);
                                        return;
                                    }

                                    if (!chunkFits) {
                                        pages.push([chunkBlock]);
                                        return;
                                    }

                                    currentPage = [chunkBlock];
                                };

                                if (headingOnly) {
                                    const splitWithHeading = splitParagraphToFit(getBlockText(block), currentPage.slice());

                                    if (splitWithHeading.length) {
                                        const firstChunk = { type: 'paragraph', text: splitWithHeading[0] };

                                        if (fitsParagraphs(currentPage.concat(firstChunk))) {
                                            currentPage = currentPage.concat(firstChunk);
                                            pages.push(currentPage);
                                            currentPage = [];

                                            splitWithHeading.slice(1).forEach((chunk) => {
                                                appendChunkToPages({ type: 'paragraph', text: chunk });
                                            });
                                            return;
                                        }
                                    }
                                }

                                if (currentPage.length) {
                                    pages.push(currentPage);
                                    currentPage = [];
                                }

                                if (fitsParagraphs([block])) {
                                    currentPage = [block];
                                    return;
                                }

                                splitParagraphToFit(getBlockText(block)).forEach((chunk) => {
                                    appendChunkToPages({ type: 'paragraph', text: chunk });
                                });
                            });

                            if (currentPage.length) {
                                pages.push(currentPage);
                            }

                            pageMeasurer.remove();
                            pageMeasurer = null;
                            return pages.length ? pages : [[]];
                        }

                        function buildChapterStartMap() {
                            const map = {};

                            bookPages.forEach((pageBlocks, pageIndex) => {
                                if (!Array.isArray(pageBlocks)) return;

                                pageBlocks.forEach((block) => {
                                    if (!block || block.type !== 'heading') return;
                                    const heading = String(block.text || '').trim();
                                    if (heading !== '' && map[heading] === undefined) {
                                        map[heading] = pageIndex;
                                    }
                                });
                            });

                            chapterStartMap = map;
                        }

                        function goToChapter(chapterTitle) {
                            const trimmedTitle = String(chapterTitle || '').trim();
                            if (trimmedTitle === '') return;

                            const chapterPage = chapterStartMap[trimmedTitle];
                            if (chapterPage === undefined) return;

                            bookPageIndex = Math.max(0, Math.floor(chapterPage / 2) * 2);
                            setSpread(bookPageIndex);
                        }

                        function goToPartNumber(partNumber) {
                            const normalizedPart = Number.parseInt(partNumber, 10);
                            if (!Number.isInteger(normalizedPart) || normalizedPart < 1) return false;

                            const pageData = sourcePages[normalizedPart - 1];
                            if (pageData && typeof pageData === 'object') {
                                const chapterTitle = String(pageData.chapter_title || '').trim() || `Chapter ${normalizedPart}`;
                                goToChapter(chapterTitle);
                                return true;
                            }

                            return false;
                        }

                        function setSpread(index) {
                            const leftIndex = index;
                            const rightIndex = index + 1;

                            spreadLeft.innerHTML = leftIndex < bookPages.length ? renderParagraphs(bookPages[leftIndex]) : '<p class="empty-page">&nbsp;</p>';
                            spreadRight.innerHTML = rightIndex < bookPages.length ? renderParagraphs(bookPages[rightIndex]) : '<p class="empty-page">&nbsp;</p>';

                            if (headerMediaHtml && leftIndex === 0) {
                                spreadLeft.insertAdjacentHTML('afterbegin', headerMediaHtml);
                            }

                            leftPageNum.textContent = leftIndex + 1;
                            rightPageNum.textContent = rightIndex < bookPages.length ? rightIndex + 1 : '';

                            prevButton.disabled = leftIndex === 0;
                            nextButton.disabled = rightIndex >= bookPages.length;
                        }

                        function animateSpread(direction) {
                            const targetIndex = direction === 'next' ? bookPageIndex + 2 : bookPageIndex - 2;

                            if (targetIndex < 0 || targetIndex >= bookPages.length) {
                                return;
                            }

                            bookAnimating = true;

                            const turnSheet = document.createElement('div');
                            turnSheet.className = direction === 'next' ? 'turn-sheet turn-next' : 'turn-sheet turn-prev';

                            const bookRect = textBook.getBoundingClientRect();
                            const spreadRect = bookSpread.getBoundingClientRect();
                            const leftPanelRect = spreadLeft.getBoundingClientRect();
                            const rightPanelRect = spreadRight.getBoundingClientRect();

                            const sideRect = direction === 'next' ? rightPanelRect : leftPanelRect;
                            const gutterWidth = Math.max(0, rightPanelRect.left - leftPanelRect.right);
                            const halfGutter = gutterWidth / 2;
                            const contentTop = Math.max(0, spreadRect.top - bookRect.top);
                            const contentBottom = Math.max(0, bookRect.bottom - spreadRect.bottom);

                            const sheetLeft = direction === 'next'
                                ? Math.max(0, sideRect.left - bookRect.left - halfGutter)
                                : Math.max(0, sideRect.left - bookRect.left);
                            const sheetWidth = direction === 'next'
                                ? sideRect.width + halfGutter
                                : sideRect.width + halfGutter;

                            turnSheet.style.left = `${sheetLeft}px`;
                            turnSheet.style.right = 'auto';
                            turnSheet.style.width = `${sheetWidth}px`;
                            turnSheet.style.setProperty('--turn-content-top', `${contentTop}px`);
                            turnSheet.style.setProperty('--turn-content-bottom', `${contentBottom}px`);

                            if (direction === 'next') {
                                const currentRight = bookPages[bookPageIndex + 1] || [];
                                const nextLeft = bookPages[bookPageIndex + 2] || [];
                                turnSheet.appendChild(createFacePanel('right-panel turn-face--front', bookPageIndex + 2, currentRight));
                                turnSheet.appendChild(createFacePanel('left-panel turn-face--back', bookPageIndex + 3, nextLeft));
                            } else {
                                const currentLeft = bookPages[bookPageIndex] || [];
                                const prevRight = bookPages[bookPageIndex - 1] || [];
                                turnSheet.appendChild(createFacePanel('left-panel turn-face--front', bookPageIndex, currentLeft));
                                turnSheet.appendChild(createFacePanel('right-panel turn-face--back', bookPageIndex - 1, prevRight));
                            }

                            textBook.classList.remove('turning-next', 'turning-prev');
                            void textBook.offsetWidth;
                            textBook.classList.add(direction === 'prev' ? 'turning-prev' : 'turning-next');

                            bookPageIndex = targetIndex;
                            setSpread(bookPageIndex);

                            textBook.appendChild(turnSheet);

                            const finishTurn = () => {
                                if (turnSheet.parentNode) {
                                    turnSheet.parentNode.removeChild(turnSheet);
                                }
                                textBook.classList.remove('turning-next', 'turning-prev');
                                bookAnimating = false;
                            };

                            turnSheet.addEventListener('animationend', finishTurn, { once: true });
                            window.setTimeout(() => {
                                if (turnSheet.parentNode) {
                                    finishTurn();
                                }
                            }, getTurnDurationMs() + 120);
                        }

                        prevButton.addEventListener('click', () => {
                            if (bookAnimating || bookPageIndex === 0) return;
                            animateSpread('prev');
                        });

                        nextButton.addEventListener('click', () => {
                            if (bookAnimating || bookPageIndex + 2 >= bookPages.length) return;
                            animateSpread('next');
                        });

                        document.addEventListener('keydown', (event) => {
                            if (event.key === 'ArrowLeft') prevButton.click();
                            if (event.key === 'ArrowRight') nextButton.click();
                        });

                        function refreshBook() {
                            bookPages = paginateText();
                            buildChapterStartMap();
                            bookPageIndex = 0;
                            const hasNavigatedToPart = initialPartNumber > 0 ? goToPartNumber(initialPartNumber) : false;
                            if (!hasNavigatedToPart) {
                                setSpread(0);
                            }

                            if (autoOpenReader && storyPreview) {
                                storyPreview.classList.add('hidden');
                            }
                        }

                        if (document.fonts && document.fonts.ready) {
                            document.fonts.ready.then(refreshBook);
                        } else {
                            refreshBook();
                        }

                        let resizeTimer = null;
                        window.addEventListener('resize', () => {
                            window.clearTimeout(resizeTimer);
                            resizeTimer = window.setTimeout(refreshBook, 120);
                        });
                        }
                    </script>
                    </div>

                <?php elseif ($file_content && !isset($file_content['error']) && $file_content['type'] === 'video'): ?>
                    <!-- Video Viewer -->
                    <div class="booklet-wrapper">
                        <div class="pages-display" style="justify-content: center;">
                            <div class="page" style="width: 100%; max-width: 800px; padding: 0; overflow: hidden;">
                                <video width="100%" height="auto" controls style="width: 100%; border-radius: 12px;">
                                    <source src="<?php echo htmlspecialchars($file_content['path']); ?>" type="<?php echo htmlspecialchars($file_content['mime']); ?>">
                                    Your browser does not support the video tag.
                                </video>
                                <div style="padding: 40px;">
                                    <h2 class="page-title"><?php echo htmlspecialchars($story['title']); ?></h2>
                                    <p style="color: #64748b; margin-top: 10px;">📹 Video • <?php echo number_format($file_content['size'] / (1024 * 1024), 2); ?> MB</p>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($file_content && !isset($file_content['error']) && $file_content['type'] === 'audio'): ?>
                    <!-- Audio Player -->
                    <div class="booklet-wrapper">
                        <div class="pages-display" style="justify-content: center;">
                            <div class="page" style="width: 100%; max-width: 600px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <div style="font-size: 64px; margin-bottom: 30px;">🎵</div>
                                <h2 class="page-title" style="text-align: center;"><?php echo htmlspecialchars($story['title']); ?></h2>
                                <div class="page-chapter">AUDIO</div>
                                <audio width="100%" controls style="width: 100%; margin-top: 30px;">
                                    <source src="<?php echo htmlspecialchars($file_content['path']); ?>" type="<?php echo htmlspecialchars($file_content['mime']); ?>">
                                    Your browser does not support the audio element.
                                </audio>
                                <p style="color: #94a3b8; margin-top: 20px; font-size: 14px;">
                                    🎵 <?php echo number_format($file_content['size'] / (1024 * 1024), 2); ?> MB
                                </p>
                            </div>
                        </div>
                    </div>

                <?php elseif ($file_content && !isset($file_content['error']) && $file_content['type'] === 'image'): ?>
                    <!-- Image Viewer -->
                    <div class="booklet-wrapper">
                        <div class="pages-display" style="justify-content: center;">
                            <div class="page" style="padding: 0; overflow: hidden; display: flex; flex-direction: column;">
                                <img src="<?php echo htmlspecialchars($file_content['path']); ?>" alt="<?php echo htmlspecialchars($story['title']); ?>" style="width: 100%; height: 400px; object-fit: cover; border-radius: 12px;">
                                <div style="padding: 40px;">
                                    <h2 class="page-title"><?php echo htmlspecialchars($story['title']); ?></h2>
                                    <p style="color: #64748b; margin-top: 10px;">🖼️ Image • <?php echo number_format($file_content['size'] / (1024 * 1024), 2); ?> MB</p>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($file_content && !isset($file_content['error']) && $file_content['type'] === 'pdf'): ?>
                    <!-- PDF Viewer -->
                    <div class="pdf-flip-wrap">
                        <div class="_df_book" source="<?php echo htmlspecialchars($file_content['path']); ?>"></div>
                    </div>
            <?php else: ?>
                <!-- Error or unsupported -->
                <div class="booklet-wrapper">
                    <div class="pages-display">
                        <div class="page previous"></div>
                        <div class="page" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <div style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;">⚠️</div>
                            <h2 class="page-title">Error</h2>
                            <p style="color: #94a3b8; margin-top: 20px; text-align: center;">
                                <?php echo isset($file_content['error']) ? htmlspecialchars($file_content['error']) : 'Unable to read file'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dearflip@latest/dflip/js/dflip.min.js"></script>
</body>
</html>
