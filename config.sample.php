<?php

/**
 * Konfigurasi Upload
 *
 * - sign_secret  : Secret key untuk menandatangani token. WAJIB diganti!
 * - token_ttl    : Masa berlaku token upload (detik). Default 5 menit.
 * - temp_dir     : Direktori sementara sebelum file dipindah ke tujuan akhir.
 * - app_keys     : Daftar app key yang diizinkan beserta konfigurasinya.
 *
 * Konfigurasi per app key:
 *   - name            : Nama aplikasi (untuk keperluan logging/identifikasi)
 *   - allowed_types   : Ekstensi file yang diperbolehkan
 *   - max_size        : Batas maksimum ukuran file (bytes)
 *   - upload_dir      : Direktori tujuan akhir penyimpanan file
 *   - allowed_origins : Daftar origin (scheme+host) yang boleh menggunakan app key ini.
 *                       null atau ['*'] = izinkan semua (untuk development / server-to-server).
 *                       Request tanpa header Origin (misal: cURL, SDK) selalu diizinkan.
 *   - rate_limit      : Batasi jumlah permintaan token per IP dalam jangka waktu tertentu.
 *                       max_requests = maks token, window = jendela waktu (detik).
 */

return [

    'sign_secret' => 'GANTI_DENGAN_SECRET_KEY_YANG_KUAT_DAN_ACAK',

    'token_ttl' => 300, // 5 menit

    'temp_dir' => 'files/temp',

    'app_keys' => [

        'APP_KEY_CONTOH_1' => [
            'name'            => 'Aplikasi Pertama',
            'allowed_types'   => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size'        => 10 * 1024 * 1024, // 10 MB
            'upload_dir'      => 'files/test',
            
            // Ganti dengan domain produksi Anda, misal: ['https://aplikasi-saya.com']
            'allowed_origins' => null, // null = izinkan semua (untuk development)
            'rate_limit'      => ['max_requests' => 20, 'window' => 60],
        ],

    ],

];
