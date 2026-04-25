<?php
require_once 'config/db.php';

echo "=== CHECKING DATABASE ===" . PHP_EOL . PHP_EOL;

// Check if stories table exists
$result = $conn->query("SHOW TABLES LIKE 'stories'");
if ($result->num_rows === 0) {
    echo "ERROR: stories table does not exist!" . PHP_EOL;
    exit;
}

echo "✓ stories table exists" . PHP_EOL;

// Get all stories
$result = $conn->query("SELECT id, title, author, cover_image, type, file_path FROM stories");

if (!$result) {
    echo "ERROR: Query failed - " . $conn->error . PHP_EOL;
    exit;
}

echo "✓ Found " . $result->num_rows . " stories:" . PHP_EOL . PHP_EOL;

while ($row = $result->fetch_assoc()) {
    echo "Story ID: " . $row['id'] . PHP_EOL;
    echo "  Title: " . $row['title'] . PHP_EOL;
    echo "  Author: " . $row['author'] . PHP_EOL;
    echo "  Type: " . $row['type'] . PHP_EOL;
    echo "  Cover: " . $row['cover_image'] . PHP_EOL;
    
    $cover_exists = file_exists($row['cover_image']);
    echo "  Cover exists: " . ($cover_exists ? "YES ✓" : "NO ✗") . PHP_EOL;
    
    if ($row['file_path']) {
        echo "  File: " . $row['file_path'] . PHP_EOL;
        $file_exists = file_exists($row['file_path']);
        echo "  File exists: " . ($file_exists ? "YES ✓" : "NO ✗") . PHP_EOL;
    }
    echo PHP_EOL;
}
?>
