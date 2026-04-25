<?php
session_start();
require_once 'config/db.php';

// Fetch all stories from database
$stories = [];
$query = "SELECT id, title, author, cover_image, type FROM stories ORDER BY created_at DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $stories[] = $row;
    }
}

echo "=== INDEX PAGE DEBUG ===" . PHP_EOL . PHP_EOL;
echo "Total stories: " . count($stories) . PHP_EOL . PHP_EOL;

foreach ($stories as $idx => $story) {
    echo "Story " . ($idx + 1) . ": " . $story['title'] . PHP_EOL;
    echo "  Cover path: " . $story['cover_image'] . PHP_EOL;
    echo "  file_exists check: " . (file_exists($story['cover_image']) ? "YES" : "NO") . PHP_EOL;
    echo "  file_exists htmlscaped: " . htmlspecialchars($story['cover_image']) . PHP_EOL;
    echo PHP_EOL;
}

// Test the Featured story section HTML
$featured_story = !empty($stories) ? $stories[0] : null;

echo "=== FEATURED STORY HTML THAT WOULD BE RENDERED ===" . PHP_EOL;
if ($featured_story) {
    echo "Condition: (\$featured_story['cover_image'] && file_exists(\$featured_story['cover_image']))" . PHP_EOL;
    echo "  cover_image value: " . ($featured_story['cover_image'] ? 'set' : 'empty') . PHP_EOL;
    echo "  file_exists result: " . (file_exists($featured_story['cover_image']) ? 'true' : 'false') . PHP_EOL;
    echo "  Final condition: " . (($featured_story['cover_image'] && file_exists($featured_story['cover_image'])) ? 'TRUE - will show img tag' : 'FALSE - will show placeholder') . PHP_EOL;
    
    if ($featured_story['cover_image'] && file_exists($featured_story['cover_image'])) {
        echo PHP_EOL . "HTML that would render:" . PHP_EOL;
        echo '<img src="' . htmlspecialchars($featured_story['cover_image']) . '" alt="' . htmlspecialchars($featured_story['title']) . '">' . PHP_EOL;
    }
} else {
    echo "No featured story!" . PHP_EOL;
}
?>
