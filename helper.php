<?php

/**
 * Helper
 */
function generateRandomString($length = 10) 
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    
    return $randomString;
}

function response(array $param, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($param);
    exit;
}

/**
 * Generate a signed upload token.
 *
 * Token format:  base64url(json_payload) . "." . hmac_sha256_hex
 */
function generateUploadToken(array $payload, string $secret): string
{
    $encoded   = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', $encoded, $secret);
    return $encoded . '.' . $signature;
}

/**
 * Verify and decode a signed upload token.
 * Returns the payload array on success, null on failure.
 */
function verifyUploadToken(string $token, string $secret): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encoded, $signature] = $parts;

    // Verify signature
    $expected = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    // Decode payload
    $payload = json_decode(base64UrlDecode($encoded), true);
    if (!is_array($payload)) {
        return null;
    }

    // Check expiry
    if (!isset($payload['exp']) || time() > $payload['exp']) {
        return null;
    }

    return $payload;
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
    return base64_decode(strtr($padded, '-_', '+/'));
}

/**
 * Token whitelist — one-time-use enforcement.
 *
 * Alur:
 *   1. sign-url.php memanggil registerToken() saat token dibuat.
 *      JTI disimpan bersama waktu expired-nya.
 *   2. upload.php memanggil claimToken() saat memproses upload.
 *      Jika JTI ditemukan → dihapus dari file → upload dilanjutkan.
 *      Jika JTI tidak ditemukan → token sudah dipakai atau tidak valid.
 *
 * Format file: { "<jti>": <exp_timestamp>, ... }
 */
function registerToken(string $jti, int $exp): void
{
    $file = __DIR__ . '/storage/used_tokens.json';
    $fp   = fopen($file, 'c+');
    if (!$fp) return;

    flock($fp, LOCK_EX);
    $tokens = json_decode(stream_get_contents($fp), true) ?? [];

    // Bersihkan token expired yang belum pernah diklaim
    $now    = time();
    $tokens = array_filter($tokens, fn($e) => $e > $now);

    $tokens[$jti] = $exp;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($tokens));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Klaim token: cek keberadaan JTI lalu hapus.
 * Mengembalikan true jika token valid & berhasil diklaim, false jika tidak.
 */
function claimToken(string $jti): bool
{
    $file = __DIR__ . '/storage/used_tokens.json';
    $fp   = fopen($file, 'c+');
    if (!$fp) return false;

    flock($fp, LOCK_EX);
    $tokens = json_decode(stream_get_contents($fp), true) ?? [];

    // Buang JTI yang sudah expired sekalian
    $now    = time();
    $tokens = array_filter($tokens, fn($exp) => $exp > $now);

    $found = isset($tokens[$jti]);
    if ($found) {
        unset($tokens[$jti]);
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($tokens));
    flock($fp, LOCK_UN);
    fclose($fp);

    return $found;
}

/**
 * Human-readable PHP upload error messages.
 */
function uploadErrorMessage(int $code): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'File melampaui batas upload_max_filesize di php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'File melampaui batas MAX_FILE_SIZE di form HTML.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak ditemukan.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP.',
    ];
    return $messages[$code] ?? 'Terjadi kesalahan tidak diketahui saat upload.';
}