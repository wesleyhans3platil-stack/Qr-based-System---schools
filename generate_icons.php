<?php
/**
 * PWA Icon Generator
 * Run in browser: http://localhost/Qr based System - schools/generate_icons.php
 * Creates 192x192 and 512x512 PNG icons for the PWA manifest.
 * After running this, you can update manifest.json to use PNG instead of SVG.
 */

if (php_sapi_name() === 'cli') {
    echo "Please run this in a browser via XAMPP.\n";
    echo "URL: http://localhost/Qr%20based%20System%20-%20schools/generate_icons.php\n";
    exit;
}

$sizes = [192, 512];
$dir = __DIR__ . '/assets/icons/';

if (!is_dir($dir)) mkdir($dir, 0755, true);

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    
    // Indigo background (#4338ca)
    $bg = imagecolorallocate($img, 67, 56, 202);
    imagefill($img, 0, 0, $bg);

    // White text
    $white = imagecolorallocate($img, 255, 255, 255);

    // Draw "QR" text centered
    $fontSize = $size * 0.35;
    $fontFile = null;

    // Try to use a system font, fall back to built-in
    $systemFonts = [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
    ];
    foreach ($systemFonts as $f) {
        if (file_exists($f)) { $fontFile = $f; break; }
    }

    if ($fontFile) {
        // TTF rendering
        $bbox = imagettfbbox($fontSize, 0, $fontFile, 'QR');
        $textW = abs($bbox[2] - $bbox[0]);
        $textH = abs($bbox[7] - $bbox[1]);
        $x = ($size - $textW) / 2 - $bbox[0];
        $y = ($size - $textH) / 2 - $bbox[7];
        imagettftext($img, $fontSize, 0, (int)$x, (int)$y, $white, $fontFile, 'QR');
    } else {
        // Fallback: built-in font
        $text = 'QR';
        $fw = imagefontwidth(5) * strlen($text);
        $fh = imagefontheight(5);
        imagestring($img, 5, ($size - $fw) / 2, ($size - $fh) / 2, $text, $white);
    }

    // Round corners (approximate with filled circles)
    $radius = (int)($size * 0.18);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    
    // Create mask for rounded corners
    $mask = imagecreatetruecolor($size, $size);
    imagealphablending($mask, false);
    imagesavealpha($mask, true);
    imagefill($mask, 0, 0, $transparent);
    
    $cornerBg = imagecolorallocate($mask, 67, 56, 202);
    imagefilledrectangle($mask, $radius, 0, $size - $radius - 1, $size - 1, $cornerBg);
    imagefilledrectangle($mask, 0, $radius, $size - 1, $size - $radius - 1, $cornerBg);
    imagefilledellipse($mask, $radius, $radius, $radius * 2, $radius * 2, $cornerBg);
    imagefilledellipse($mask, $size - $radius - 1, $radius, $radius * 2, $radius * 2, $cornerBg);
    imagefilledellipse($mask, $radius, $size - $radius - 1, $radius * 2, $radius * 2, $cornerBg);
    imagefilledellipse($mask, $size - $radius - 1, $size - $radius - 1, $radius * 2, $radius * 2, $cornerBg);
    imagedestroy($mask);

    $outFile = $dir . "icon-{$size}.png";
    imagepng($img, $outFile);
    imagedestroy($img);
    echo "Created: $outFile ({$size}x{$size})<br>";
}

echo "<br>Done! You can delete this file now.";
echo "<br><br><a href='app_login.php'>Go to Mobile App &rarr;</a>";
