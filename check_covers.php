<?php
$conn = new mysqli('localhost', 'root', '', 'story_library');
if ($conn->connect_error) {
    die('Connection error: ' . $conn->connect_error);
}
$result = $conn->query('SELECT id, title, cover_image FROM stories');
echo "Stories in database:" . PHP_EOL;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $exists = file_exists($row['cover_image']) ? 'EXISTS' : 'MISSING';
        echo 'ID: ' . $row['id'] . ', Title: ' . $row['title'] . ', Cover: ' . $row['cover_image'] . ' (' . $exists . ')' . PHP_EOL;
    }
} else {
    echo "No stories found in database." . PHP_EOL;
}
?>
