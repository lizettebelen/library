<?php
require_once __DIR__ . '/config/db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
	header('Location: index.php');
	exit();
}

$story_id = (int) $_GET['id'];
$initial_chapter = isset($_GET['chapter']) ? max(1, (int) $_GET['chapter']) : 1;

$story = null;
$story_query = 'SELECT id, title, author, cover_image, genre, type, file_path, created_at, updated_at FROM stories WHERE id = ? LIMIT 1';
$story_stmt = $conn->prepare($story_query);
if ($story_stmt) {
	$story_stmt->bind_param('i', $story_id);
	$story_stmt->execute();
	$story_result = $story_stmt->get_result();
	if ($story_result && $story_result->num_rows > 0) {
		$story = $story_result->fetch_assoc();
	}
	$story_stmt->close();
}

if (!$story) {
	header('Location: index.php');
	exit();
}

$text_pages = [];
$chapter_media_map = [];

if (($story['type'] ?? '') === 'encoded') {
	$pages_query = 'SELECT id, page_number, chapter_title, content FROM pages WHERE story_id = ? ORDER BY page_number ASC';
	$pages_stmt = $conn->prepare($pages_query);
	if ($pages_stmt) {
		$pages_stmt->bind_param('i', $story_id);
		$pages_stmt->execute();
		$pages_result = $pages_stmt->get_result();
		$page_ids = [];
		while ($row = $pages_result->fetch_assoc()) {
			$content = trim((string) ($row['content'] ?? ''));
			if ($content === '') {
				continue;
			}

			$chapter_title = trim((string) ($row['chapter_title'] ?? ''));
			if ($chapter_title === '') {
				$chapter_title = 'Chapter ' . (int) ($row['page_number'] ?? (count($text_pages) + 1));
			}

			$text_pages[] = [
				'page_id' => (int) ($row['id'] ?? 0),
				'chapter_title' => $chapter_title,
				'content' => $content,
				'media_items' => [],
			];

			$page_id = (int) ($row['id'] ?? 0);
			if ($page_id > 0) {
				$page_ids[] = $page_id;
			}
		}
		$pages_stmt->close();

		$chapter_media_table_check = $conn->query("SHOW TABLES LIKE 'chapter_page_media'");
		$chapter_media_table_exists = $chapter_media_table_check && $chapter_media_table_check->num_rows > 0;

		if ($chapter_media_table_exists && !empty($page_ids)) {
			$chapter_media_query = 'SELECT page_id, media_path, media_type FROM chapter_page_media WHERE story_id = ? ORDER BY page_id ASC, sort_order ASC, id ASC';
			$chapter_media_stmt = $conn->prepare($chapter_media_query);
			if ($chapter_media_stmt) {
				$chapter_media_stmt->bind_param('i', $story_id);
				$chapter_media_stmt->execute();
				$chapter_media_result = $chapter_media_stmt->get_result();
				while ($media_row = $chapter_media_result->fetch_assoc()) {
					$page_id = (int) ($media_row['page_id'] ?? 0);
					$media_path = trim((string) ($media_row['media_path'] ?? ''));
					$media_type = strtolower(trim((string) ($media_row['media_type'] ?? '')));

					if ($page_id <= 0 || $media_path === '' || !in_array($media_type, ['image', 'video'], true)) {
						continue;
					}

					$full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($media_path, '/\\'));
					if (!file_exists($full_path)) {
						continue;
					}

					if (!isset($chapter_media_map[$page_id])) {
						$chapter_media_map[$page_id] = [];
					}

					$chapter_media_map[$page_id][] = [
						'path' => $media_path,
						'type' => $media_type,
					];
				}
				$chapter_media_stmt->close();
			}

			foreach ($text_pages as $idx => $page) {
				$page_id = (int) ($page['page_id'] ?? 0);
				$text_pages[$idx]['media_items'] = $page_id > 0 && isset($chapter_media_map[$page_id])
					? $chapter_media_map[$page_id]
					: [];
			}
		}
	}
}

if (($story['type'] ?? '') === 'file' && !empty($story['file_path'])) {
	$parser_paths = [
		__DIR__ . '/config/file_parser.php',
		__DIR__ . '/../config/file_parser.php',
	];

	foreach ($parser_paths as $parser_path) {
		if (file_exists($parser_path)) {
			require_once $parser_path;
			break;
		}
	}

	if (class_exists('FileParser')) {
		$file_content = FileParser::parseFile($story['file_path']);
		if ($file_content && !isset($file_content['error']) && ($file_content['type'] ?? '') === 'text') {
			$parsed_pages = isset($file_content['pages']) && is_array($file_content['pages']) ? $file_content['pages'] : [];
			foreach ($parsed_pages as $idx => $page_data) {
				if (is_array($page_data)) {
					$chapter_title = trim((string) ($page_data['chapter_title'] ?? ''));
					$content = trim((string) ($page_data['content'] ?? ''));
					if ($chapter_title === '') {
						$chapter_title = 'Chapter ' . ($idx + 1);
					}
				} else {
					$chapter_title = 'Chapter ' . ($idx + 1);
					$content = trim((string) $page_data);
				}

				if ($content === '') {
					continue;
				}

				$text_pages[] = [
					'chapter_title' => $chapter_title,
					'content' => $content,
				];
			}
		}
	}
}

$header_media_items = [];
$header_media_table_exists = false;
$header_media_table_check = $conn->query("SHOW TABLES LIKE 'story_header_media'");
if ($header_media_table_check && $header_media_table_check->num_rows > 0) {
	$header_media_table_exists = true;
}

if ($header_media_table_exists) {
	$header_media_query = 'SELECT media_path, media_type FROM story_header_media WHERE story_id = ? ORDER BY sort_order ASC, id ASC';
	$header_media_stmt = $conn->prepare($header_media_query);
	if ($header_media_stmt) {
		$header_media_stmt->bind_param('i', $story_id);
		$header_media_stmt->execute();
		$header_media_result = $header_media_stmt->get_result();
		while ($media_row = $header_media_result->fetch_assoc()) {
			$media_path = trim((string) ($media_row['media_path'] ?? ''));
			$media_type = strtolower(trim((string) ($media_row['media_type'] ?? '')));
			if ($media_path === '' || !in_array($media_type, ['image', 'video'], true)) {
				continue;
			}

			$full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($media_path, '/\\'));
			if (!file_exists($full_path)) {
				continue;
			}

			$header_media_items[] = [
				'path' => $media_path,
				'type' => $media_type,
			];
		}
		$header_media_stmt->close();
	}
}

if (empty($header_media_items)) {
	$legacy_path = trim((string) ($story['header_media_path'] ?? ''));
	$legacy_type = strtolower(trim((string) ($story['header_media_type'] ?? '')));
	if ($legacy_path !== '' && in_array($legacy_type, ['image', 'video'], true)) {
		$legacy_full = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($legacy_path, '/\\'));
		if (file_exists($legacy_full)) {
			$header_media_items[] = [
				'path' => $legacy_path,
				'type' => $legacy_type,
			];
		}
	}
}

$chapter_count = count($text_pages);
$safe_initial_chapter = max(1, min($initial_chapter, max(1, $chapter_count)));
$current_chapter_index = $safe_initial_chapter - 1;
$current_chapter = ($chapter_count > 0 && isset($text_pages[$current_chapter_index])) ? $text_pages[$current_chapter_index] : null;
$next_chapter = $safe_initial_chapter < $chapter_count ? $safe_initial_chapter + 1 : null;

$word_total = 0;
foreach ($text_pages as $chapter) {
	$word_total += str_word_count(strip_tags((string) ($chapter['content'] ?? '')));
}
$estimated_minutes = max(1, (int) ceil($word_total / 220));

function read_split_paragraphs($content) {
	$content = str_replace("\r", '', (string) $content);
	$parts = preg_split('/\n{2,}/', $content);
	if (!is_array($parts)) {
		return [];
	}

	$paragraphs = [];
	foreach ($parts as $part) {
		$normalized = preg_replace('/\s*\n+\s*/', ' ', trim((string) $part));
		if ($normalized !== '') {
			$paragraphs[] = $normalized;
		}
	}

	return $paragraphs;
}

function read_decode_html_entities($value) {
	$decoded = (string) $value;
	for ($i = 0; $i < 2; $i++) {
		$next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($next === $decoded) {
			break;
		}
		$decoded = $next;
	}

	return $decoded;
}

function read_sanitize_chapter_html($content) {
	$decoded = read_decode_html_entities($content);
	$decoded = preg_replace('/<\s*\/\s*([a-z0-9]+)/i', '</$1', $decoded);
	$decoded = preg_replace('/<\s*([a-z0-9]+)/i', '<$1', $decoded);
	$sanitized = strip_tags($decoded, '<p><br><strong><b><em><i><u><span>');

	$sanitized = preg_replace_callback('/<(strong|b|em|i|u|br)\b[^>]*>/i', function ($matches) {
		$tag = strtolower((string) ($matches[1] ?? ''));
		if ($tag === 'br') {
			return '<br>';
		}

		return '<' . $tag . '>';
	}, $sanitized);

	$allowed_class_regex = '/^(ql-align-(center|right|justify)|ql-size-(small|large|huge))$/';
	$sanitized = preg_replace_callback('/<(p|span)\b([^>]*)>/i', function ($matches) use ($allowed_class_regex) {
		$tag = strtolower((string) ($matches[1] ?? 'p'));
		$attrs = (string) ($matches[2] ?? '');
		$safe_classes = [];

		if (preg_match('/class\s*=\s*("|\')(.*?)\1/i', $attrs, $class_match)) {
			$classes = preg_split('/\s+/', trim((string) ($class_match[2] ?? '')));
			if (is_array($classes)) {
				foreach ($classes as $class_name) {
					$class_name = trim((string) $class_name);
					if ($class_name !== '' && preg_match($allowed_class_regex, $class_name)) {
						$safe_classes[] = $class_name;
					}
				}
			}
		}

		if (!empty($safe_classes)) {
			return '<' . $tag . ' class="' . htmlspecialchars(implode(' ', $safe_classes), ENT_QUOTES, 'UTF-8') . '">';
		}

		return '<' . $tag . '>';
	}, $sanitized);

	return trim((string) $sanitized);
}

function read_render_chapter_html($content) {
	$sanitized = read_sanitize_chapter_html($content);
	if ($sanitized === '') {
		return '';
	}

	$has_structured_html = (stripos($sanitized, '<p') !== false) || (stripos($sanitized, '<br') !== false);
	if ($has_structured_html) {
		return $sanitized;
	}

	$paragraphs = read_split_paragraphs(strip_tags($sanitized));
	if (empty($paragraphs)) {
		return '';
	}

	$output = '';
	foreach ($paragraphs as $paragraph) {
		$output .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>';
	}

	return $output;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($story['title']); ?> - Read</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
	<style>
		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			background: #eef2f7;
			color: #0f172a;
			font-family: 'Source Serif 4', Georgia, serif;
		}

		.topbar {
			position: sticky;
			top: 0;
			z-index: 10;
			backdrop-filter: blur(8px);
			background: rgba(255, 255, 255, 0.94);
			border-bottom: 1px solid #d8dee9;
		}

		.topbar-inner {
			width: min(100%, 980px);
			margin: 0 auto;
			padding: 12px 16px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
		}

		.brand {
			display: inline-flex;
			align-items: center;
			gap: 10px;
			color: #0f172a;
			font-weight: 800;
			text-decoration: none;
			font-family: 'Playfair Display', serif;
			font-size: 1.02rem;
		}

		.brand img {
			width: 34px;
			height: 34px;
			object-fit: contain;
		}

		.back-link {
			text-decoration: none;
			color: #4338ca;
			font-weight: 700;
			border: 1px solid #c7d2fe;
			background: #eef2ff;
			border-radius: 10px;
			padding: 8px 12px;
			font-size: 0.95rem;
		}

		.reader {
			width: min(100%, 760px);
			margin: 14px auto 38px;
			background: #ffffff;
			border: 1px solid #dde3ec;
			border-radius: 14px;
			box-shadow: 0 20px 44px rgba(15, 23, 42, 0.12);
			overflow: hidden;
		}

		.story-head {
			padding: 30px 24px 18px;
			text-align: center;
			border-bottom: 1px solid #e5eaf1;
		}

		.story-title {
			margin: 0;
			font-family: 'Playfair Display', serif;
			font-size: clamp(1.7rem, 4vw, 2.3rem);
			line-height: 1.08;
			letter-spacing: -0.01em;
			color: #0f172a;
		}

		.story-author {
			margin: 10px 0 0;
			color: #64748b;
			font-size: 1rem;
			font-weight: 600;
		}

		.story-meta {
			margin-top: 10px;
			display: flex;
			gap: 14px;
			justify-content: center;
			flex-wrap: wrap;
			color: #64748b;
			font-size: 0.9rem;
			font-weight: 600;
		}

		.header-media-strip {
			padding: 12px 16px 0;
		}

		.header-media-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
			gap: 10px;
		}

		.header-media-item {
			border: 1px solid #dbe3ec;
			border-radius: 10px;
			overflow: hidden;
			background: #f8fafd;
		}

		.header-media-item img,
		.header-media-item video {
			display: block;
			width: 100%;
			height: 130px;
			object-fit: cover;
		}

		.header-media-item img,
		.header-media-item video {
			cursor: zoom-in;
		}

		.image-lightbox {
			position: fixed;
			inset: 0;
			z-index: 2000;
			display: none;
			align-items: center;
			justify-content: center;
			padding: 16px;
			background: rgba(2, 6, 23, 0.88);
		}

		.image-lightbox.open {
			display: flex;
		}

		.image-lightbox-close {
			position: absolute;
			top: 12px;
			right: 12px;
			width: 40px;
			height: 40px;
			border: 1px solid rgba(255, 255, 255, 0.35);
			border-radius: 999px;
			background: rgba(15, 23, 42, 0.75);
			color: #ffffff;
			font-size: 1.4rem;
			line-height: 1;
			cursor: pointer;
		}

		.image-lightbox-img {
			max-width: min(96vw, 1200px);
			max-height: 88vh;
			object-fit: contain;
			border-radius: 12px;
			box-shadow: 0 26px 60px rgba(0, 0, 0, 0.45);
			display: none;
		}

		.image-lightbox-video {
			max-width: min(96vw, 1200px);
			max-height: 88vh;
			border-radius: 12px;
			box-shadow: 0 26px 60px rgba(0, 0, 0, 0.45);
			display: none;
		}

		.chapter-nav {
			padding: 12px 20px;
			border-bottom: 1px solid #ecf0f4;
			background: #f9fbfd;
			display: flex;
			gap: 8px;
			overflow-x: auto;
		}

		.chapter-chip {
			flex: 0 0 auto;
			text-decoration: none;
			color: #334155;
			border: 1px solid #d3dbe6;
			background: #ffffff;
			border-radius: 999px;
			padding: 7px 12px;
			font-size: 0.82rem;
			font-weight: 700;
		}

		.chapter-chip.active {
			color: #ffffff;
			border-color: #0f172a;
			background: #0f172a;
		}

		.story-body {
			padding: 26px 26px 34px;
		}

		.chapter {
			margin-bottom: 30px;
		}

		.chapter:last-child {
			margin-bottom: 0;
		}

		.chapter-title {
			margin: 0 0 14px;
			padding-bottom: 6px;
			border-bottom: 2px solid #1f2937;
			font-family: 'Playfair Display', serif;
			font-size: clamp(1.25rem, 3vw, 1.6rem);
			color: #111827;
		}

		.chapter p {
			margin: 0 0 14px;
			font-size: clamp(1rem, 1.75vw, 1.12rem);
			line-height: 1.78;
			color: #1f2937;
			text-align: left;
		}

		.chapter .ql-align-center {
			text-align: center;
		}

		.chapter .ql-align-right {
			text-align: right;
		}

		.chapter .ql-align-justify {
			text-align: justify;
		}

		.chapter .ql-size-small {
			font-size: 0.9em;
		}

		.chapter .ql-size-large {
			font-size: 1.25em;
		}

		.chapter .ql-size-huge {
			font-size: 1.55em;
		}

		.read-actions {
			padding: 0 26px 28px;
		}

		.next-part-btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 100%;
			text-decoration: none;
			border-radius: 999px;
			padding: 12px 16px;
			font-size: 1rem;
			font-weight: 800;
			letter-spacing: 0.01em;
			color: #ffffff;
			background: #09090b;
			border: 1px solid #111827;
			box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
		}

		.next-part-btn:hover {
			background: #111827;
		}

		.chapter p:first-of-type::first-letter {
			float: left;
			font-family: 'Playfair Display', serif;
			font-size: 3.5em;
			line-height: 0.8;
			margin: 0.02em 0.14em 0 0;
			color: #1e293b;
		}

		.empty {
			padding: 34px 26px 40px;
			text-align: center;
			color: #64748b;
			font-size: 1rem;
		}

		.chapter-media-strip {
			margin: 0 0 16px;
		}

		.chapter-media-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
			gap: 10px;
		}

		.chapter-media-item {
			border: 1px solid #dbe3ec;
			border-radius: 10px;
			overflow: hidden;
			background: #f8fafd;
		}

		.chapter-media-item img,
		.chapter-media-item video {
			display: block;
			width: 100%;
			height: 130px;
			object-fit: cover;
			cursor: zoom-in;
		}

		@media (max-width: 768px) {
			.topbar-inner {
				padding: 10px 12px;
			}

			.brand {
				font-size: 0.95rem;
			}

			.reader {
				margin: 10px auto 22px;
				border-radius: 10px;
			}

			.story-head {
				padding: 20px 14px 14px;
			}

			.story-body {
				padding: 18px 14px 24px;
			}

			.header-media-strip {
				padding: 10px 10px 0;
			}

			.header-media-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.header-media-item img,
			.header-media-item video {
				height: 110px;
			}

			.chapter-media-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.chapter-media-item img,
			.chapter-media-item video {
				height: 110px;
			}

			.chapter-title {
				margin-bottom: 10px;
			}

			.chapter p {
				margin-bottom: 12px;
				font-size: 1.03rem;
				line-height: 1.72;
			}

			.read-actions {
				padding: 0 14px 20px;
			}

			.next-part-btn {
				padding: 11px 14px;
				font-size: 0.95rem;
			}

			.chapter-nav {
				padding: 10px 10px;
			}
		}
	</style>
</head>
<body>
	<header class="topbar">
		<div class="topbar-inner">
			<a href="index.php" class="brand">
				<img src="assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Lindley's Library">
				<span>Lindley's Library</span>
			</a>
			<a href="view.php?id=<?php echo (int) $story_id; ?>" class="back-link">Back to details</a>
		</div>
	</header>

	<main class="reader">
		<section class="story-head">
			<h1 class="story-title"><?php echo htmlspecialchars($story['title']); ?></h1>
			<p class="story-author">by <?php echo htmlspecialchars((string) ($story['author'] ?? 'Unknown Author')); ?></p>
			<div class="story-meta">
				<span><?php echo (int) $chapter_count; ?> chapters</span>
				<span><?php echo (int) $estimated_minutes; ?> min read</span>
				<span><?php echo htmlspecialchars((string) ($story['genre'] ?: 'General')); ?></span>
			</div>
		</section>

		<?php if (!empty($header_media_items)): ?>
			<section class="header-media-strip" aria-label="Story header media gallery">
				<div class="header-media-grid">
					<?php foreach ($header_media_items as $media): ?>
						<div class="header-media-item">
							<?php if ($media['type'] === 'image'): ?>
								<img src="<?php echo htmlspecialchars($media['path']); ?>" alt="<?php echo htmlspecialchars($story['title']); ?> header image" class="zoomable-header-image" data-media-type="image" data-media-src="<?php echo htmlspecialchars($media['path']); ?>">
							<?php else: ?>
								<video controls preload="metadata" playsinline class="zoomable-header-video" data-media-type="video" data-media-src="<?php echo htmlspecialchars($media['path']); ?>">
									<source src="<?php echo htmlspecialchars($media['path']); ?>">
									Your browser does not support the video tag.
								</video>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if (!empty($text_pages)): ?>
			<nav class="chapter-nav" aria-label="Chapter list">
				<?php foreach ($text_pages as $index => $chapter): ?>
					<?php $chapter_number = $index + 1; ?>
					<a href="read.php?id=<?php echo (int) $story_id; ?>&chapter=<?php echo (int) $chapter_number; ?>" class="chapter-chip<?php echo $chapter_number === $safe_initial_chapter ? ' active' : ''; ?>">#<?php echo $chapter_number; ?></a>
				<?php endforeach; ?>
			</nav>

			<section class="story-body">
				<?php if ($current_chapter): ?>
					<?php
						$chapter_title = trim((string) ($current_chapter['chapter_title'] ?? ''));
						if ($chapter_title === '') {
							$chapter_title = 'Chapter ' . $safe_initial_chapter;
						}
						$chapter_html = read_render_chapter_html((string) ($current_chapter['content'] ?? ''));
					?>
					<article class="chapter" id="chapter-<?php echo (int) $safe_initial_chapter; ?>">
						<h2 class="chapter-title"><?php echo htmlspecialchars($chapter_title); ?></h2>
						<?php $chapter_media_items = isset($current_chapter['media_items']) && is_array($current_chapter['media_items']) ? $current_chapter['media_items'] : []; ?>
						<?php if (!empty($chapter_media_items)): ?>
							<div class="chapter-media-strip" aria-label="Chapter media gallery">
								<div class="chapter-media-grid">
									<?php foreach ($chapter_media_items as $media): ?>
										<div class="chapter-media-item">
											<?php if ($media['type'] === 'image'): ?>
												<img src="<?php echo htmlspecialchars($media['path']); ?>" alt="<?php echo htmlspecialchars($chapter_title); ?> image" class="zoomable-chapter-image" data-media-type="image" data-media-src="<?php echo htmlspecialchars($media['path']); ?>">
											<?php else: ?>
												<video controls preload="metadata" playsinline class="zoomable-chapter-video" data-media-type="video" data-media-src="<?php echo htmlspecialchars($media['path']); ?>">
													<source src="<?php echo htmlspecialchars($media['path']); ?>">
													Your browser does not support the video tag.
												</video>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
						<?php echo $chapter_html; ?>
					</article>
				<?php endif; ?>
			</section>

			<section class="read-actions">
				<?php if ($next_chapter !== null): ?>
					<a class="next-part-btn" href="read.php?id=<?php echo (int) $story_id; ?>&chapter=<?php echo (int) $next_chapter; ?>">Continue to next chapter</a>
				<?php else: ?>
					<a class="next-part-btn" href="view.php?id=<?php echo (int) $story_id; ?>">Back to details</a>
				<?php endif; ?>
			</section>
		<?php else: ?>
			<section class="empty">No readable text content found for this story.</section>
		<?php endif; ?>
	</main>

	<div class="image-lightbox" id="image-lightbox" aria-hidden="true">
		<button type="button" class="image-lightbox-close" id="image-lightbox-close" aria-label="Close image preview">&times;</button>
		<img src="" alt="Expanded image" class="image-lightbox-img" id="image-lightbox-img">
		<video controls playsinline class="image-lightbox-video" id="image-lightbox-video"></video>
	</div>

	<script>
		const lightbox = document.getElementById('image-lightbox');
		const lightboxImage = document.getElementById('image-lightbox-img');
		const lightboxVideo = document.getElementById('image-lightbox-video');
		const lightboxClose = document.getElementById('image-lightbox-close');

		function closeLightbox() {
			if (!lightbox) return;
			if (lightboxVideo) {
				lightboxVideo.pause();
				lightboxVideo.removeAttribute('src');
				lightboxVideo.load();
				lightboxVideo.style.display = 'none';
			}
			if (lightboxImage) {
				lightboxImage.removeAttribute('src');
				lightboxImage.style.display = 'none';
			}
			lightbox.classList.remove('open');
			lightbox.setAttribute('aria-hidden', 'true');
			document.body.style.overflow = '';
		}

		if (lightbox && lightboxImage && lightboxVideo && lightboxClose) {
			document.querySelectorAll('.zoomable-header-image, .zoomable-header-video, .zoomable-chapter-image, .zoomable-chapter-video').forEach((mediaEl) => {
				mediaEl.addEventListener('click', () => {
					const mediaType = (mediaEl.dataset.mediaType || '').toLowerCase();
					const mediaSrc = mediaEl.dataset.mediaSrc || '';

					if (mediaType === 'video') {
						lightboxImage.style.display = 'none';
						lightboxImage.removeAttribute('src');
						lightboxVideo.src = mediaSrc;
						lightboxVideo.style.display = 'block';
					} else {
						lightboxVideo.pause();
						lightboxVideo.removeAttribute('src');
						lightboxVideo.load();
						lightboxVideo.style.display = 'none';
						lightboxImage.src = mediaSrc || mediaEl.src;
						lightboxImage.alt = mediaEl.alt || 'Expanded image';
						lightboxImage.style.display = 'block';
					}

					lightbox.classList.add('open');
					lightbox.setAttribute('aria-hidden', 'false');
					document.body.style.overflow = 'hidden';
				});
			});

			lightboxClose.addEventListener('click', closeLightbox);

			lightbox.addEventListener('click', (event) => {
				if (event.target === lightbox) {
					closeLightbox();
				}
			});

			document.addEventListener('keydown', (event) => {
				if (event.key === 'Escape' && lightbox.classList.contains('open')) {
					closeLightbox();
				}
			});
		}
	</script>

</body>
</html>
