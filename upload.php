<?php
/**
 * Dependency
 */
include('helper.php');

/**
 * Config
 */
$allowed = ['jpg', 'png', 'jpeg', 'pdf', 'zip'];
$folder = 'files';
$maxsize = 300000;

// Is there a file?
if (!isset($_FILES['file']['name'])) {
    response(['status' => 'failed', 'message' => 'Need file to upload.']);
}

$file = $_FILES['file'];
$targetName = generateRandomString(20);
$explode = explode('.', $file['name']);
$ext = strtolower(end($explode));

// Check.
if (!in_array($ext, $allowed)) {
    response(['status' => 'failed', 'message' => 'Extension is not allowed']);
}

if ($file['size'] >= $maxsize) {
    response(['status' => 'failed', 'message' => 'File size is too large.']);
}

// Do upload.
$move = move_uploaded_file($file['tmp_name'], $folder . '/' . $targetName . '.' . $ext);

if ($move) {
    response(['status' => 'success', 'file' => $targetName . '.' . $ext, 'message' => 'Uploaded successfully.']);
} else {
    response(['status' => 'failed', 'message' => 'There is an issue']);
}