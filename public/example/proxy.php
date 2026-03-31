<?php

/**
 * example-proxy.php
 *
 * Contoh endpoint proxy di sisi server aplikasi Anda.
 * File ini dipanggil oleh browser — App Key TIDAK pernah keluar ke client.
 *
 * Salin + sesuaikan file ini ke proyek Anda (Laravel, CI, plain PHP, dll).
 *
 * Alurnya:
 *   Browser → POST /upload/request-token (file ini)
 *                     ↓  (server-to-server, App Key tersembunyi)
 *              POST sign-url.php (uploader server)
 *                     ↓
 *   Browser ← { token, upload_url, expires_at, config }
 */

require_once __DIR__ . '/../../sdk/UploaderSDK.php';

header('Content-Type: application/json');

// ─────────────────────────────────────────────────────────────────────
// 1. OTORISASI — pastikan request berasal dari user yang sah
//    Sesuaikan dengan mekanisme auth sistem Anda.
// ─────────────────────────────────────────────────────────────────────

// Contoh: cek session PHP biasa
// session_start();
// if (empty($_SESSION['user_id'])) {
//     http_response_code(401);
//     echo json_encode(['status' => 'error', 'message' => 'Belum login.']);
//     exit;
// }

// Contoh: cek JWT di header Authorization
// $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
// if (!verifyJwt($token)) { ... }

// Contoh: cek CSRF token
// if (($_POST['_csrf'] ?? '') !== $_SESSION['csrf_token']) { ... }

// Untuk demo ini, otorisasi dilewati (semua request diterima)


// ─────────────────────────────────────────────────────────────────────
// 2. INISIALISASI SDK
//    Simpan UPLOADER_APP_KEY di .env atau config server — JANGAN hardcode.
// ─────────────────────────────────────────────────────────────────────

$appKey  = getenv('UPLOADER_APP_KEY') ?: 'APP_KEY_CONTOH_1'; // ganti dengan getenv() di produksi
$signUrl = getenv('UPLOADER_SIGN_URL') ?: 'https://uploader.test/sign-url.php';

$sdk = new UploaderSDK(
    signUrl:  $signUrl,
    appKey:   $appKey,
    defaults: [
        // Default yang berlaku untuk SEMUA request melalui proxy ini
        // Bisa di-override per-request di bagian bawah
        'folder'          => 'files/umum',
        'filename_prefix' => 'upload',
    ],
);


// ─────────────────────────────────────────────────────────────────────
// 3. BACA PARAMETER DARI CLIENT
//    Hanya izinkan parameter yang aman — jangan langsung forward semua POST.
// ─────────────────────────────────────────────────────────────────────

$options = [];

// Contoh: izinkan client memilih folder dari daftar yang disetujui saja
$allowedFolders = ['files/umum', 'files/invoice', 'files/laporan'];
if (!empty($_POST['folder']) && in_array($_POST['folder'], $allowedFolders, true)) {
    $options['folder'] = $_POST['folder'];
}

// Contoh: izinkan client mengirim prefix (tapi batasi panjangnya)
if (!empty($_POST['filename_prefix'])) {
    $options['filename_prefix'] = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['filename_prefix']), 0, 32);
}

// Contoh: prefix otomatis berdasarkan user yang login
// $options['filename_prefix'] = 'user_' . $_SESSION['user_id'];


// ─────────────────────────────────────────────────────────────────────
// 4. MINTA TOKEN KE UPLOADER SERVER
// ─────────────────────────────────────────────────────────────────────

try {
    $tokenData = $sdk->requestToken($options);

    // Kembalikan ke client hanya data yang diperlukan (tanpa App Key)
    echo json_encode(UploaderSDK::publicPayload($tokenData));

} catch (UploaderSDKException $e) {
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Gagal mendapatkan token upload: ' . $e->getMessage(),
    ]);
}
