<?php

/**
 * UploaderSDK
 *
 * PHP client untuk berkomunikasi dengan uploader server.
 * Digunakan di sisi server (bukan browser) agar App Key tidak terekspos ke publik.
 *
 * Instalasi: Salin file ini ke proyek Anda, lalu include/require.
 *
 * Contoh penggunaan:
 *
 *   require_once 'UploaderSDK.php';
 *
 *   $sdk = new UploaderSDK(
 *       signUrl: 'https://uploader.example.com/public/sign-url.php',
 *       appKey:  getenv('UPLOADER_APP_KEY'),   // dari env / config, JANGAN hardcode
 *   );
 *
 *   $result = $sdk->requestToken([
 *       'folder'          => 'files/invoice',
 *       'filename_prefix' => 'inv',
 *   ]);
 *
 *   // $result['token']      — token untuk dikirim ke client
 *   // $result['upload_url'] — URL upload untuk dikirim ke client
 *   // $result['expires_at'] — waktu kedaluarsa
 *   // $result['config']     — konfigurasi aktif
 */
class UploaderSDK
{
    protected string $signUrl;
    protected string $appKey;
    protected array  $defaults;
    protected int    $timeout;

    /**
     * @param string $signUrl   URL endpoint sign-url.php di uploader server
     * @param string $appKey    App key (simpan di .env / config server, JANGAN di client)
     * @param array  $defaults  Nilai default yang akan dikirim di setiap requestToken()
     *                          Kunci yang didukung: folder, allowed_types, max_size,
     *                          filename_prefix, filename_suffix
     * @param int    $timeout   Timeout HTTP request dalam detik (default: 10)
     */
    public function __construct(
        string $signUrl,
        string $appKey,
        array  $defaults = [],
        int    $timeout  = 10
    ) {
        $this->signUrl  = rtrim($signUrl, '/');
        $this->appKey   = $appKey;
        $this->defaults = $defaults;
        $this->timeout  = $timeout;
    }

    // ─────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Minta signed token ke uploader server.
     *
     * @param array $options Override per-request. Kunci yang didukung:
     *   - folder          (string)
     *   - allowed_types   (string|array)  mis. 'pdf,jpg' atau ['pdf','jpg']
     *   - max_size        (int)           bytes
     *   - filename_prefix (string)
     *   - filename_suffix (string)
     *
     * @return array  Response penuh dari sign-url.php:
     *   [
     *     'status'     => 'success',
     *     'token'      => '...',
     *     'upload_url' => 'https://...',
     *     'expires_at' => '2026-01-01T00:05:00+07:00',
     *     'config'     => [ 'folder' => ..., 'allowed_types' => [...], ... ],
     *   ]
     *
     * @throws UploaderSDKException  Jika request gagal atau server merespons error
     */
    public function requestToken(array $options = []): array
    {
        $params = array_merge($this->defaults, $options);

        // Normalisasi allowed_types dari array ke string
        if (isset($params['allowed_types']) && is_array($params['allowed_types'])) {
            $params['allowed_types'] = implode(',', $params['allowed_types']);
        }

        $fields              = $params;
        $fields['app_key']   = $this->appKey;

        $response = $this->httpPost($this->signUrl, $fields);

        if (($response['status'] ?? '') !== 'success') {
            throw new UploaderSDKException(
                'sign-url gagal: ' . ($response['message'] ?? 'unknown error'),
                $response
            );
        }

        return $response;
    }

    /**
     * Kembalikan hanya data yang aman untuk dikirim ke client browser:
     * token, upload_url, expires_at, config (tanpa app_key).
     *
     * Gunakan ini sebagai response JSON dari proxy endpoint Anda.
     *
     * @param array $tokenData  Hasil dari requestToken()
     * @return array
     */
    public static function publicPayload(array $tokenData): array
    {
        return [
            'status'     => $tokenData['status'],
            'token'      => $tokenData['token'],
            'upload_url' => $tokenData['upload_url'],
            'expires_at' => $tokenData['expires_at'],
            'config'     => $tokenData['config'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Kirim POST request via cURL. Fallback ke file_get_contents jika tidak ada cURL.
     *
     * @param string $url
     * @param array  $fields  Asosiatif key => value (akan di-encode sebagai form-data)
     * @return array  Decoded JSON response
     * @throws UploaderSDKException
     */
    protected function httpPost(string $url, array $fields): array
    {
        if (function_exists('curl_init')) {
            return $this->curlPost($url, $fields);
        }

        return $this->streamPost($url, $fields);
    }

    protected function curlPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $body === false) {
            throw new UploaderSDKException("cURL error ({$errno}): {$error}");
        }

        return $this->decodeJson($body, $code);
    }

    protected function streamPost(string $url, array $fields): array
    {
        $body    = http_build_query($fields);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                             "Content-Length: " . strlen($body) . "\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new UploaderSDKException("HTTP request gagal ke: {$url}");
        }

        // Ambil HTTP code dari $http_response_header
        $code = 200;
        if (!empty($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $code = (int) ($m[1] ?? 200);
        }

        return $this->decodeJson($response, $code);
    }

    protected function decodeJson(string $body, int $httpCode): array
    {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new UploaderSDKException(
                "Respons bukan JSON (HTTP {$httpCode}): " . substr($body, 0, 200)
            );
        }
        return $data;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Exception class
// ─────────────────────────────────────────────────────────────────────

class UploaderSDKException extends RuntimeException
{
    protected array $context;

    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /** Response asli dari server (jika ada) */
    public function getContext(): array
    {
        return $this->context;
    }
}
