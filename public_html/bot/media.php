<?php
session_start();

if (($_SESSION['logged_in'] ?? false) !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$filename = $_GET['file'] ?? '';
if (!is_string($filename) || !preg_match('/\A[a-zA-Z0-9._-]+\z/', $filename) || $filename === '.' || $filename === '..') {
    http_response_code(400);
    exit('Invalid file');
}

$uploads_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
$file_path = $uploads_dir === false ? false : realpath($uploads_dir . DIRECTORY_SEPARATOR . $filename);
if ($uploads_dir === false || $file_path === false || dirname($file_path) !== $uploads_dir || !is_file($file_path)) {
    http_response_code(404);
    exit('File not found');
}

$mime_type = mime_content_type($file_path);
if (!is_string($mime_type)) {
    $mime_type = 'application/octet-stream';
}

$is_image = strpos($mime_type, 'image/') === 0;
header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . ($is_image ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
