<?php
require_once 'config/db.php';

// Check users table
echo "=== Users Table ===\n";
$result = $conn->query('SELECT id, username, name, role FROM users');
if ($result) {
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "ID: " . $row['id'] . " | Username: " . $row['username'] . " | Name: " . $row['name'] . " | Role: " . $row['role'] . "\n";
        }
    } else {
        echo "No users found\n";
    }
} else {
    echo "Users table error: " . $conn->error . "\n";
}

// Check stories table structure
echo "\n=== Stories Table Structure ===\n";
$result = $conn->query('DESCRIBE stories');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n=== Current Stories ===\n";
$result = $conn->query('SELECT id, title, author, type, file_path FROM stories');
if ($result) {
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "ID: " . $row['id'] . " | Title: " . $row['title'] . " | Author: " . $row['author'] . " | Type: " . $row['type'] . " | Path: " . $row['file_path'] . "\n";
        }
    } else {
        echo "No stories found\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n=== Uploads Directory Contents ===\n";
if (is_dir('uploads')) {
    $files = scandir('uploads');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo $file . "\n";
        }
    }
} else {
    echo "Uploads directory does not exist\n";
}

$conn->close();
?>
