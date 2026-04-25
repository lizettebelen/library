<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'config/db.php';

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

function normalizeGenreKey($genre) {
    $genre = strtolower(trim((string) $genre));
    $genre = preg_replace('/[^a-z0-9]+/', '-', $genre);
    return trim($genre, '-');
}

function getStoryGenreLabel(array $story) {
    $genre = trim((string) ($story['genre'] ?? ''));
    if ($genre !== '') {
        return $genre;
    }

    $fallback = trim((string) ($story['type'] ?? ''));
    return $fallback !== '' ? ucfirst($fallback) : 'General';
}

function getCoverOrientation($coverPath) {
    $path = trim((string) $coverPath);
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

$hasGenreColumn = ensureGenreColumn($conn);

// Fetch all stories from database
$stories = [];
$query = $hasGenreColumn
    ? "SELECT id, title, author, cover_image, type, genre FROM stories ORDER BY created_at DESC"
    : "SELECT id, title, author, cover_image, type FROM stories ORDER BY created_at DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $stories[] = $row;
    }
}

// Get featured story (first one or random)
$featured_story = !empty($stories) ? $stories[0] : null;
$grid_stories = !empty($stories) ? array_slice($stories, 1) : [];

$genres = [];
if ($hasGenreColumn) {
    $genre_result = $conn->query("SELECT DISTINCT TRIM(genre) AS genre FROM stories WHERE genre IS NOT NULL AND TRIM(genre) <> '' ORDER BY genre ASC");
    if ($genre_result && $genre_result->num_rows > 0) {
        while ($row = $genre_result->fetch_assoc()) {
            $genre = trim((string) $row['genre']);
            if ($genre !== '') {
                $genres[normalizeGenreKey($genre)] = $genre;
            }
        }
    }
}

// Check if user is admin
$is_admin = isset($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'admin');
$is_logged_in = isset($_SESSION['user_id']);

$stories_for_grid = !empty($grid_stories)
    ? $grid_stories
    : (!empty($featured_story) ? [$featured_story] : []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lindley's Library</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="container nav-content">
            <div class="nav-brand">
                <img src="assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Lindley's Library" class="logo-image">
            </div>
            
            <div class="nav-search">
                <input type="text" placeholder="Search stories..." class="search-input">
            </div>
            
            <div class="nav-actions">
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <span class="hero-tag">Digital Archive v2.0</span>
                <h1 class="hero-title">Where Every Word Finds Its <span class="highlight">Luster</span></h1>
                <p class="hero-description">
                    Browse through thousands of stories and narratives. From high-tech futurism to ancient myths, 
                    explore stories designed for the modern digital reader.
                </p>
            </div>
        </div>
    </section>

    <!-- Categories Filter -->
    <section class="categories-section">
        <div class="container">
            <div class="categories-scroll">
                <button class="category-btn active" onclick="filterByCategory('all', this)">All</button>
                <?php foreach ($genres as $genreKey => $genreLabel): ?>
                    <button class="category-btn" onclick="filterByCategory('<?php echo htmlspecialchars($genreKey); ?>', this)"><?php echo htmlspecialchars($genreLabel); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="categories-sort">
                <span class="sort-text">
                    <svg style="width:16px;height:16px;display:inline-block;margin-right:4px;vertical-align:middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 6 13 16 8 11 1 18"></polyline>
                        <polyline points="17 6 23 6 23 12"></polyline>
                    </svg>
                    Trending
                </span>
                <span class="sort-text">
                    <svg style="width:16px;height:16px;display:inline-block;margin-right:4px;vertical-align:middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Recent
                </span>
            </div>
        </div>
    </section>

    <div class="container">
        <?php if ($featured_story): ?>
        <!-- Featured Story Section -->
        <section class="featured-section">
            <div class="featured-header">
                <svg class="featured-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <h2>Featured Story</h2>
            </div>
            
            <?php $featuredGenreLabel = getStoryGenreLabel($featured_story); ?>
            <a href="view.php?id=<?php echo $featured_story['id']; ?>" class="featured-card" data-genre="<?php echo htmlspecialchars(normalizeGenreKey($featuredGenreLabel)); ?>">
                <?php $featuredOrientation = getCoverOrientation($featured_story['cover_image'] ?? ''); ?>
                <div class="featured-image orientation-<?php echo htmlspecialchars($featuredOrientation); ?>">
                    <?php if ($featured_story['cover_image'] && file_exists($featured_story['cover_image'])): ?>
                        <img src="<?php echo htmlspecialchars($featured_story['cover_image']); ?>" alt="<?php echo htmlspecialchars($featured_story['title']); ?>">
                    <?php else: ?>
                        <div class="featured-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="featured-overlay">
                        <span class="featured-badge"><?php echo htmlspecialchars($featuredGenreLabel); ?></span>
                        <button class="read-btn">Read Story →</button>
                    </div>
                </div>
                <div class="featured-content">
                    <h3 class="featured-title"><?php echo htmlspecialchars($featured_story['title']); ?></h3>
                    <p class="featured-author">by <?php echo htmlspecialchars($featured_story['author']); ?></p>
                    <p class="featured-description">Dive into this compelling narrative and experience an unforgettable journey through captivating storytelling.</p>
                </div>
            </a>
        </section>
        <?php endif; ?>

        <!-- All Stories Section -->
        <section class="stories-section">
            <div class="stories-header">
                <svg class="stories-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                <h2>All Stories</h2>
            </div>

            <?php if (empty($stories)): ?>
                <div class="empty-state-alt">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        </svg>
                    </div>
                    <h3>No stories yet</h3>
                    <p>Start building your library by adding your first story.</p>
                    <a href="add_story.php" class="btn btn-primary">Create First Story</a>
                </div>
            <?php else: ?>
                <div class="stories-grid">
                    <?php foreach ($stories_for_grid as $story): ?>
                        <?php $storyGenreLabel = getStoryGenreLabel($story); ?>
                        <?php $coverOrientation = getCoverOrientation($story['cover_image'] ?? ''); ?>
                        <a href="view.php?id=<?php echo $story['id']; ?>" class="story-card-new" data-genre="<?php echo htmlspecialchars(normalizeGenreKey($storyGenreLabel)); ?>">
                            <div class="story-card-image orientation-<?php echo htmlspecialchars($coverOrientation); ?>">
                                <?php if ($story['cover_image'] && file_exists($story['cover_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($story['cover_image']); ?>" alt="<?php echo htmlspecialchars($story['title']); ?>">
                                <?php else: ?>
                                    <div class="story-card-placeholder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                                <div class="story-card-overlay">
                                    <span class="story-card-badge"><?php echo htmlspecialchars($storyGenreLabel); ?></span>
                                </div>
                            </div>
                            <div class="story-card-content">
                                <h3 class="story-card-title"><?php echo htmlspecialchars($story['title']); ?></h3>
                                <p class="story-card-author">by <?php echo htmlspecialchars($story['author']); ?></p>
                                <div class="story-card-meta">
                                    <span class="story-rating">
                                        <svg style="width:14px;height:14px;display:inline-block;margin-right:4px;vertical-align:middle;" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                        </svg>
                                        4.5
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Footer -->
    <footer>
        <p>&copy; 2026 Digital Story Library. All rights reserved.</p>
    </footer>

    <script>
        function filterByCategory(category, button) {
            document.querySelectorAll('.category-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            if (button) {
                button.classList.add('active');
            }

            const normalizedCategory = String(category || 'all').toLowerCase();
            const storyCards = document.querySelectorAll('.story-card-new');

            storyCards.forEach((card) => {
                const cardGenre = (card.dataset.genre || '').toLowerCase();
                const isVisible = normalizedCategory === 'all' || cardGenre === normalizedCategory;
                card.style.display = isVisible ? '' : 'none';
            });

            const featuredSection = document.querySelector('.featured-section');
            if (featuredSection) {
                const featuredCard = featuredSection.querySelector('.featured-card');
                const featuredGenre = featuredCard ? (featuredCard.dataset.genre || '').toLowerCase() : '';
                const showFeatured = normalizedCategory === 'all' || featuredGenre === normalizedCategory;
                featuredSection.style.display = showFeatured ? '' : 'none';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            filterByCategory('all', document.querySelector('.category-btn.active'));
        });
    </script>
</body>
</html>
