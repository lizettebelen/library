<?php
echo "Testing cover file paths:" . PHP_EOL;
echo "Current working directory: " . getcwd() . PHP_EOL . PHP_EOL;

$test_path = 'uploads/covers/document.jpg';
echo "Testing path: " . $test_path . PHP_EOL;
echo "File exists: " . (file_exists($test_path) ? 'YES' : 'NO') . PHP_EOL;
echo "Real path: " . realpath($test_path) . PHP_EOL . PHP_EOL;

// List all files in uploads/covers
echo "Files in uploads/covers/:" . PHP_EOL;
$files = glob('uploads/covers/*');
foreach ($files as $file) {
    echo "  - " . basename($file) . " (exists: " . (file_exists($file) ? 'YES' : 'NO') . ")" . PHP_EOL;
}
?>
