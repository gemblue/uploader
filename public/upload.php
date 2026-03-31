<?php

/**
 * Endpoint: Upload File
 *
 * Alur penggunaan:
 *   1. Minta signed token ke sign-url.php dengan App Key yang valid.
 *   2. POST file ke endpoint ini beserta token yang diterima.
 *
 * Method : POST (multipart/form-data)
 * Field  :
 *   - token  : Signed token dari sign-url.php (wajib)
 *   - file   : File yang akan diunggah (wajib)
 *
 * Response sukses:
 * {
 *   "status"  : "success",
 *   "file"    : "abc123_240101120000_xyz789.pdf",
 *   "path"    : "files/penilaian/abc123_240101120000_xyz789.pdf",
 *   "folder"  : "files/penilaian",
 *   "size"    : 204800,
 *   "message" : "File berhasil diunggah."
 * }
 */

set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include __DIR__ . '/../helper.php';
$config = include __DIR__ . '/../config.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(['status' => 'failed', 'message' => 'Method not allowed. Gunakan POST.'], 405);
}

// ------------------------------------------------------------------
// 1. Validasi token
// ------------------------------------------------------------------
$token = $_POST['token'] ?? $_GET['token'] ?? null;

if (!$token) {
    response(['status' => 'failed', 'message' => 'Upload token diperlukan.'], 401);
}

$payload = verifyUploadToken($token, $config['sign_secret']);

if (!$payload) {
    response(['status' => 'failed', 'message' => 'Token tidak valid atau sudah kadaluarsa.'], 403);
}

// Klaim token dari whitelist — tolak jika sudah dipakai atau tidak terdaftar
if (!claimToken($payload['jti'])) {
    response(['status' => 'failed', 'message' => 'Token sudah digunakan atau tidak valid.'], 403);
}

// ------------------------------------------------------------------
// 2. Validasi keberadaan file
// ------------------------------------------------------------------

// Jika $_FILES kosong, kemungkinan post_max_size di php.ini terlampaui
if (empty($_FILES)) {
    response(['status' => 'failed', 'message' => 'Ukuran file melebihi batas post_max_size server.'], 413);
}

if (empty($_FILES['file'])) {
    response(['status' => 'failed', 'message' => 'Field "file" tidak ditemukan dalam request.'], 400);
}

// Tangani error upload dari PHP
$uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_OK;
if ($uploadError !== UPLOAD_ERR_OK) {
    $httpCode = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 413 : 400;
    response(['status' => 'failed', 'message' => uploadErrorMessage($uploadError)], $httpCode);
}

$file = $_FILES['file'];

// ------------------------------------------------------------------
// 3. Validasi tipe file
// ------------------------------------------------------------------
$parts   = explode('.', $file['name']);
$ext     = strtolower(end($parts));
$allowed = $payload['allowed'];

if (count($parts) < 2 || !$ext) {
    response(['status' => 'failed', 'message' => 'Nama file tidak memiliki ekstensi.'], 415);
}

if (!in_array($ext, $allowed)) {
    response([
        'status'  => 'failed',
        'message' => 'Tipe file tidak diizinkan.',
        'allowed' => $allowed,
        'received' => $ext,
    ], 415);
}

// Verifikasi MIME type untuk keamanan tambahan
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$safeMime = validateMimeVsExtension($ext, $mimeType);

if (!$safeMime) {
    response([
        'status'  => 'failed',
        'message' => 'MIME type file tidak sesuai dengan ekstensinya.',
        'mime'    => $mimeType,
    ], 415);
}

// ------------------------------------------------------------------
// 4. Validasi ukuran file
// ------------------------------------------------------------------
if ($file['size'] > $payload['max_size']) {
    $maxMb = round($payload['max_size'] / 1024 / 1024, 2);
    response([
        'status'   => 'failed',
        'message'  => "Ukuran file terlalu besar. Maksimum: {$maxMb} MB.",
        'max_size' => $payload['max_size'],
        'received' => $file['size'],
    ], 413);
}

// ------------------------------------------------------------------
// 5. Unggah ke direktori sementara
// ------------------------------------------------------------------
$basePath = rtrim(__DIR__ . '/..', '/');

$tempDir = $basePath . '/' . rtrim($config['temp_dir'], '/');
if (!is_dir($tempDir)) {
    if (!mkdir($tempDir, 0775, true)) {
        response(['status' => 'failed', 'message' => 'Gagal membuat direktori sementara.'], 500);
    }
}

$prefix = $payload['filename_prefix'] ?? '';
$suffix = $payload['filename_suffix'] ?? '';

$rand1    = generateRandomString(6);
$rand2    = generateRandomString(6);
$datetime = date('ymdHis');

$parts_name = array_filter([$prefix, $rand1, $datetime, $rand2, $suffix]);
$baseName   = implode('_', $parts_name);
$tempName = $baseName . '.' . $ext;
$tempPath = $tempDir . '/' . $tempName;

if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
    response(['status' => 'failed', 'message' => 'Gagal menyimpan file sementara.'], 500);
}

// ------------------------------------------------------------------
// 6. Pindahkan ke direktori tujuan akhir
// ------------------------------------------------------------------
// Cek bila $payload['folder'] adalah absolute path (dimulai dengan /), jika ya gunakan langsung, jika tidak gabungkan dengan base path
if (isset($payload['folder'][0]) && $payload['folder'][0] === '/') {
    $finalDir = rtrim($payload['folder'], '/');
} else {
    $finalDir = $basePath . '/' . rtrim($payload['folder'], '/');
}
if (!is_dir($finalDir)) {
    if (!mkdir($finalDir, 0775, true)) {
        @unlink($tempPath);
        response(['status' => 'failed', 'message' => 'Gagal membuat direktori tujuan.'], 500);
    }
}

$finalName = $baseName . '.' . $ext;
$finalPath = $finalDir . '/' . $finalName;

if (!rename($tempPath, $finalPath)) {
    @unlink($tempPath);
    response(['status' => 'failed', 'message' => 'Gagal memindahkan file ke tujuan akhir.'], 500);
}

// ------------------------------------------------------------------
// 7. Respons sukses
// ------------------------------------------------------------------
response([
    'status'  => 'success',
    'file'    => $finalName,
    'path'    => $finalPath,
    'folder'  => $finalDir,
    'size'    => $file['size'],
    'message' => 'File berhasil diunggah.',
]);

// ------------------------------------------------------------------
// Helper: validasi MIME vs ekstensi
// ------------------------------------------------------------------
function validateMimeVsExtension(string $ext, string $mime): bool
{
    $map = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'txt'  => ['text/plain'],
        'mp4'  => ['video/mp4'],
        'mp3'  => ['audio/mpeg'],
    ];

    if (!isset($map[$ext])) {
        // Tipe tidak terdaftar dalam peta, loloskan dengan peringatan
        return true;
    }

    return in_array($mime, $map[$ext]);
}
