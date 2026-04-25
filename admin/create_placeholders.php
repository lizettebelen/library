<?php
// Create better placeholder cover images using GD library
$cover_dir = '../uploads/covers/';

if (!is_dir($cover_dir)) {
    mkdir($cover_dir, 0755, true);
}

// Define placeholder images with different colors
$placeholders = [
    'placeholder.jpg' => ['r' => 100, 'g' => 120, 'b' => 240],
    'image.jpg' => ['r' => 160, 'g' => 100, 'b' => 220],
    'video.jpg' => ['r' => 220, 'g' => 100, 'b' => 180],
    'audio.jpg' => ['r' => 100, 'g' => 200, 'b' => 150],
    'document.jpg' => ['r' => 100, 'g' => 150, 'b' => 220],
    'presentation.jpg' => ['r' => 240, 'g' => 140, 'b' => 80],
    'spreadsheet.jpg' => ['r' => 80, 'g' => 180, 'b' => 220]
];

foreach ($placeholders as $filename => $color) {
    // Create image (300x400 for 3:4 aspect ratio)
    $image = imagecreatetruecolor(300, 400);
    
    // Fill with main color
    $main_color = imagecolorallocate($image, $color['r'], $color['g'], $color['b']);
    imagefill($image, 0, 0, $main_color);
    
    // Add a darker shade rectangle at bottom for depth
    $darker_color = imagecolorallocate($image, 
        max($color['r'] - 30, 0), 
        max($color['g'] - 30, 0), 
        max($color['b'] - 30, 0)
    );
    imagefilledrectangle($image, 0, 300, 300, 400, $darker_color);
    
    // Add white text at center
    $white = imagecolorallocate($image, 255, 255, 255);
    imagestring($image, 5, 100, 185, 'Story Cover', $white);
    
    // Save as JPEG
    imagejpeg($image, $cover_dir . $filename, 90);
    imagedestroy($image);
}

echo "✓ Placeholder images created successfully!";
?>

