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
 *   - name           : Nama aplikasi (untuk keperluan logging/identifikasi)
 *   - allowed_types  : Ekstensi file yang diperbolehkan
 *   - max_size       : Batas maksimum ukuran file (bytes)
 *   - upload_dir     : Direktori tujuan akhir penyimpanan file
 */

return [

    'sign_secret' => 'GANTI_DENGAN_SECRET_KEY_YANG_KUAT_DAN_ACAK',

    'token_ttl' => 300, // 5 menit

    'temp_dir' => 'files/temp',

    'app_keys' => [

        'APP_KEY_CONTOH_1' => [
            'name'          => 'Aplikasi Pertama',
            'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size'      => 10 * 1024 * 1024, // 10 MB
            'upload_dir'    => 'files/umum',
        ],

        'APP_KEY_CONTOH_2' => [
            'name'          => 'Aplikasi Dokumen',
            'allowed_types' => ['pdf', 'zip', 'xls', 'xlsx', 'doc', 'docx'],
            'max_size'      => 50 * 1024 * 1024, // 50 MB
            'upload_dir'    => 'files/dokumen',
        ],

        'APP_KEY_PENILAIAN' => [
            'name'          => 'Aplikasi Penilaian',
            'allowed_types' => ['pdf'],
            'max_size'      => 6 * 1024 * 1024, // 6 MB
            'upload_dir'    => 'files/penilaian',
        ],

    ],

];
