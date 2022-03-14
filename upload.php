<?php

header('Access-Control-Allow-Origin: *');

/**
 * Dependency
 */
include('helper.php');

/**
 * Config
 */
$allowed = ['pdf'];
$folder = 'files';
$maxsize = 25000;

// Is there a file?
if (!isset($_FILES['file']['name'])) {
    response(['status' => 'failed', 'message' => 'Butuh file untuk diunggah.']);
}

$file = $_FILES['file'];
$targetName = generateRandomString(20);
$explode = explode('.', $file['name']);
$ext = strtolower(end($explode));

// Check.
if (!in_array($ext, $allowed)) {
    response(['status' => 'failed', 'message' => 'Hanya menerima ekstensi PDF']);
}

if ($file['size'] >= $maxsize) {
    response(['status' => 'failed', 'message' => 'Ukuran file terlalu besar, kompres dan coba lagi']);
}

// Do upload.
$move = move_uploaded_file($file['tmp_name'], $folder . '/' . $targetName . '.' . $ext);

if ($move) {
    response(['status' => 'success', 'file' => $targetName . '.' . $ext, 'message' => 'Uploaded successfully.']);
} else {
    response(['status' => 'failed', 'message' => 'There is an issue']);
}
