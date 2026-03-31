<?php

/**
 * Endpoint: GET Sign URL
 *
 * Menghasilkan signed token yang diperlukan untuk mengunggah file.
 * Klien harus menyertakan App Key yang valid agar mendapatkan token.
 *
 * Autentikasi (salah satu):
 *   - Header  : X-App-Key: <app_key>
 *   - POST body: app_key=<app_key>
 *
 * Parameter opsional (POST body):
 *   - folder        : Override direktori tujuan upload (tidak boleh keluar batas upload_dir app key)
 *   - allowed_types : Override tipe file, dipisah koma. Contoh: "pdf,jpg"
 *                     (hanya berlaku jika subset dari allowed_types app key)
 *   - max_size      : Override ukuran maksimum (bytes).
 *                     (tidak boleh melebihi max_size app key)
 *   - filename_prefix : Awalan nama file. Contoh: "invoice" → "invoice_a1b2c3_..."
 *   - filename_suffix : Akhiran nama file (sebelum ekstensi). Contoh: "draft" → "..._draft.pdf"
 *                       Karakter yang diizinkan: huruf, angka, underscore, strip.
 *
 * Response sukses:
 * {
 *   "status": "success",
 *   "token": "<signed_token>",
 *   "upload_url": "https://.../upload-v2.php",
 *   "expires_at": "2026-01-01T00:05:00+07:00",
 *   "config": {
 *     "allowed_types": ["pdf"],
 *     "max_size": 6291456,
 *     "folder": "files/penilaian"
 *   }
 * }
 */

// CORS preflight — headers spesifik di-set setelah app key divalidasi
// Untuk OPTIONS, kita echo back Origin agar browser tidak langsung blokir
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-App-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Pada preflight, origin belum bisa divalidasi (app key bisa jadi ada di POST body).
    // Echo back origin agar browser lanjut ke request sesungguhnya;
    // validasi origin yang ketat dilakukan saat POST.
    $preflightOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($preflightOrigin) {
        header('Access-Control-Allow-Origin: ' . $preflightOrigin);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
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
// 1. Validasi App Key
// ------------------------------------------------------------------
$appKey = $_SERVER['HTTP_X_APP_KEY'] ?? $_POST['app_key'] ?? null;

if (!$appKey) {
    response(['status' => 'failed', 'message' => 'App key diperlukan.'], 401);
}

if (!isset($config['app_keys'][$appKey])) {
    response(['status' => 'failed', 'message' => 'App key tidak valid.'], 403);
}

$appConfig = $config['app_keys'][$appKey];

// ------------------------------------------------------------------
// 2. Validasi Origin & set CORS headers
// ------------------------------------------------------------------
if (!validateOrigin($appConfig)) {
    response([
        'status'  => 'failed',
        'message' => 'Origin tidak diizinkan untuk App Key ini.',
    ], 403);
}

// Set header CORS yang sesuai (hanya untuk origin yang terdaftar)
setCorsHeaders($appConfig);

// ------------------------------------------------------------------
// 3. Rate Limiting
// ------------------------------------------------------------------
if (
    !empty($appConfig['rate_limit']) &&
    !checkRateLimit($appKey, $appConfig['rate_limit'])
) {
    response([
        'status'  => 'failed',
        'message' => 'Terlalu banyak permintaan. Coba lagi setelah beberapa saat.',
    ], 429);
}

// ------------------------------------------------------------------
// 4. Tentukan konfigurasi token (dengan override opsional)
// ------------------------------------------------------------------

// Folder: pastikan masih di dalam upload_dir yang diizinkan app key
$requestedFolder = isset($_POST['folder']) ? trim($_POST['folder'], '/') : null;
if ($requestedFolder && str_starts_with($requestedFolder . '/', $appConfig['upload_dir'] . '/')) {
    $folder = $requestedFolder;
} elseif ($requestedFolder && $requestedFolder === $appConfig['upload_dir']) {
    $folder = $requestedFolder;
} else {
    $folder = $appConfig['upload_dir'];
}

// Max size: tidak boleh melebihi batas app key
$maxSize = isset($_POST['max_size']) ? (int) $_POST['max_size'] : $appConfig['max_size'];
if ($maxSize <= 0 || $maxSize > $appConfig['max_size']) {
    $maxSize = $appConfig['max_size'];
}

// Filename prefix & suffix: hanya izinkan karakter aman
$sanitize = fn(string $s) => preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
$filenamePrefix = isset($_POST['filename_prefix']) ? $sanitize(trim($_POST['filename_prefix'])) : '';
$filenameSuffix = isset($_POST['filename_suffix']) ? $sanitize(trim($_POST['filename_suffix'])) : '';

// Allowed types: harus subset dari yang diizinkan app key
if (isset($_POST['allowed_types'])) {
    $requested = array_map('trim', explode(',', strtolower($_POST['allowed_types'])));
    $allowed   = array_values(array_intersect($requested, $appConfig['allowed_types']));
    if (empty($allowed)) {
        response([
            'status'  => 'failed',
            'message' => 'Tipe file yang diminta tidak ada yang diizinkan untuk app key ini.',
            'allowed' => $appConfig['allowed_types'],
        ], 422);
    }
} else {
    $allowed = $appConfig['allowed_types'];
}

// ------------------------------------------------------------------
// 5. Buat token
// ------------------------------------------------------------------
$payload = [
    'app'             => $appKey,
    'folder'          => $folder,
    'allowed'         => $allowed,
    'max_size'        => $maxSize,
    'filename_prefix' => $filenamePrefix,
    'filename_suffix' => $filenameSuffix,
    'exp'             => time() + $config['token_ttl'],
    'jti'             => generateRandomString(16), // mencegah replay attack
];

$token = generateUploadToken($payload, $config['sign_secret']);

// Daftarkan JTI ke whitelist (one-time-use)
registerToken($payload['jti'], $payload['exp']);

// ------------------------------------------------------------------
// 6. Bangun upload URL
// ------------------------------------------------------------------
$scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$basePath  = rtrim(dirname($_SERVER['REQUEST_URI']), '/');
$uploadUrl = $scheme . '://' . $host . $basePath . '/upload.php';

// ------------------------------------------------------------------
// 7. Respons
// ------------------------------------------------------------------
response([
    'status'     => 'success',
    'token'      => $token,
    'upload_url' => $uploadUrl,
    'expires_at' => date('c', $payload['exp']),
    'config'     => [
        'allowed_types'   => $allowed,
        'max_size'        => $maxSize,
        'max_size_mb'     => round($maxSize / 1024 / 1024, 2),
        'folder'          => $folder,
        'filename_prefix' => $filenamePrefix,
        'filename_suffix' => $filenameSuffix,
    ],
]);
