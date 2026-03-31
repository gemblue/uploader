# PHP File Uploader

Sistem upload file berbasis PHP yang aman dengan mekanisme **signed token one-time-use**. Klien harus meminta token terlebih dahulu menggunakan App Key sebelum dapat mengunggah file. Setiap token hanya dapat digunakan sekali.

---

## Struktur File

```
uploader/
├── config.php              # Konfigurasi utama (app keys, secret, dll.)
├── helper.php              # Fungsi pembantu (token, response, whitelist, dll.)
├── public/
│   ├── index.php           # Halaman simulasi upload (UI tester)
│   ├── sign-url.php        # Endpoint: minta signed token
│   └── upload.php          # Endpoint: upload file menggunakan token
├── storage/
│   ├── .htaccess           # Deny from all (blokir akses publik)
│   └── used_tokens.json    # Whitelist token aktif (one-time-use)
└── files/
    ├── temp/               # Direktori sementara selama proses upload
    ├── umum/               # Contoh direktori tujuan
    ├── dokumen/            # Contoh direktori tujuan
    └── penilaian/          # Contoh direktori tujuan
```

---

## Persyaratan

- PHP >= 8.0
- Ekstensi PHP: `fileinfo` (biasanya sudah aktif)
- Web server: Apache / Nginx (atau PHP built-in server untuk development)
- Direktori `files/` dan `storage/` harus dapat ditulis oleh web server

---

## Setup

### 1. Clone / letakkan file di direktori web server

```bash
# Contoh untuk Apache di Ubuntu
cp -r uploader/ /var/www/html/uploader
```

### 2. Buat direktori yang diperlukan dan atur permission

```bash
mkdir -p files/temp files/umum files/dokumen files/penilaian storage
chmod 775 files/temp files/umum files/dokumen files/penilaian
chmod 775 storage
echo '{}' > storage/used_tokens.json
chmod 664 storage/used_tokens.json
```

### 3. Proteksi direktori `storage/`

Untuk Apache, file `storage/.htaccess` sudah dibuat otomatis dengan isi `Deny from all`.

Untuk Nginx, tambahkan ke konfigurasi server:

```nginx
location /storage {
    deny all;
    return 404;
}
```

### 4. Edit `config.php`

```php
return [

    // WAJIB DIGANTI dengan string acak yang panjang dan kuat
    'sign_secret' => 'isi-dengan-random-string-yang-sangat-panjang',

    // Masa berlaku token upload (detik). Default: 5 menit.
    'token_ttl' => 300,

    // Direktori sementara sebelum file dipindah ke tujuan akhir
    'temp_dir' => 'files/temp',

    'app_keys' => [

        'KUNCI_APLIKASI_SAYA' => [
            'name'          => 'Nama Aplikasi Saya',
            'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size'      => 10 * 1024 * 1024, // 10 MB
            'upload_dir'    => 'files/umum',
        ],

        // tambahkan app key lain sesuai kebutuhan ...
    ],
];
```

> **Penting:** Jangan pernah menyimpan `config.php` di version control produksi, atau pastikan `sign_secret` dan `app_keys` dibaca dari environment variable.

### 5. (Opsional) Uji dengan PHP built-in server

```bash
cd /path/to/uploader
php -S localhost:8000
```

Buka `http://localhost:8000/public/index.php` di browser.

---

## Konfigurasi per App Key

| Parameter       | Tipe     | Keterangan                                    |
|-----------------|----------|-----------------------------------------------|
| `name`          | string   | Nama deskriptif (untuk identifikasi/logging)  |
| `allowed_types` | string[] | Ekstensi file yang diizinkan, huruf kecil     |
| `max_size`      | int      | Batas ukuran file dalam bytes                 |
| `upload_dir`    | string   | Path direktori tujuan akhir penyimpanan file  |

---

## API Reference

### `POST /public/sign-url.php` — Minta Token Upload

Autentikasi via **salah satu** cara berikut:
- Header HTTP: `X-App-Key: <app_key>`
- Body field: `app_key=<app_key>`

#### Parameter Body (form-data)

| Field             | Wajib | Keterangan                                                                      |
|-------------------|-------|---------------------------------------------------------------------------------|
| `app_key`         | Ya*   | App key yang terdaftar di `config.php` (*atau via header `X-App-Key`)          |
| `folder`          | Tidak | Override direktori tujuan (harus di dalam `upload_dir` app key)                |
| `allowed_types`   | Tidak | Override tipe file, dipisah koma. Cth: `pdf,jpg`. Harus subset app key.        |
| `max_size`        | Tidak | Override batas ukuran (bytes). Tidak boleh melebihi batas app key.             |
| `filename_prefix` | Tidak | Awalan nama file. Cth: `invoice` → `invoice_a1b2c3_260331_x9y8z7.pdf`         |
| `filename_suffix` | Tidak | Akhiran nama file (sebelum ekstensi). Cth: `draft` → `..._x9y8z7_draft.pdf`   |

> Karakter yang diizinkan untuk `filename_prefix` dan `filename_suffix`: huruf, angka, `_`, `-`.

#### Format Nama File yang Dihasilkan

```
{prefix}_{rand6}_{ymdHis}_{rand6}_{suffix}.ext
```

Bagian yang kosong diabaikan. Contoh:
- Tanpa prefix/suffix: `a1b2c3_260331120000_x9y8z7.pdf`
- Dengan prefix `invoice`: `invoice_a1b2c3_260331120000_x9y8z7.pdf`
- Dengan prefix & suffix: `invoice_a1b2c3_260331120000_x9y8z7_draft.pdf`

#### Response Sukses `200`

```json
{
  "status": "success",
  "token": "<signed_token>",
  "upload_url": "https://example.com/public/upload.php",
  "expires_at": "2026-03-31T12:05:00+07:00",
  "config": {
    "allowed_types": ["pdf"],
    "max_size": 6291456,
    "max_size_mb": 6,
    "folder": "files/penilaian",
    "filename_prefix": "invoice",
    "filename_suffix": ""
  }
}
```

#### Response Gagal

| HTTP | Kondisi                                   |
|------|-------------------------------------------|
| 401  | App key tidak disertakan                  |
| 403  | App key tidak valid                       |
| 405  | Method bukan POST                         |
| 422  | Override `allowed_types` tidak valid      |

---

### `POST /public/upload.php` — Upload File

Gunakan `upload_url` dan `token` yang diterima dari `sign-url.php`.

> **Token hanya dapat digunakan satu kali.** Setelah upload berhasil, token otomatis dicabut.

#### Parameter Body (multipart/form-data)

| Field   | Wajib | Keterangan                             |
|---------|-------|----------------------------------------|
| `token` | Ya    | Signed token dari `sign-url.php`       |
| `file`  | Ya    | File yang akan diunggah                |

#### Response Sukses `200`

```json
{
  "status": "success",
  "file": "invoice_a1b2c3_260331120000_x9y8z7.pdf",
  "path": "files/penilaian/invoice_a1b2c3_260331120000_x9y8z7.pdf",
  "folder": "files/penilaian",
  "size": 204800,
  "message": "File berhasil diunggah."
}
```

#### Response Gagal

| HTTP | Kondisi                                                     |
|------|-------------------------------------------------------------|
| 400  | Token tidak disertakan / error upload PHP                   |
| 401  | Token tidak disertakan                                      |
| 403  | Token tidak valid, sudah kadaluarsa, atau sudah digunakan   |
| 405  | Method bukan POST                                           |
| 413  | Ukuran file melebihi batas token / `post_max_size`          |
| 415  | Ekstensi tidak diizinkan / MIME type tidak sesuai           |
| 500  | Gagal menyimpan file di server                              |

---

## Contoh Penggunaan

### cURL

```bash
# Step 1: Minta token
curl -s -X POST https://example.com/public/sign-url.php \
  -F "app_key=KUNCI_APLIKASI_SAYA" \
  -F "allowed_types=pdf" \
  -F "filename_prefix=invoice" | python3 -m json.tool
```

```bash
# Step 2: Upload file menggunakan token yang diterima
TOKEN="<token dari step 1>"

curl -s -X POST https://example.com/public/upload.php \
  -F "token=$TOKEN" \
  -F "file=@/path/ke/dokumen.pdf" | python3 -m json.tool
```

### JavaScript (fetch)

```js
// Step 1: Minta token
const signRes = await fetch('https://example.com/public/sign-url.php', {
  method: 'POST',
  body: Object.assign(new FormData(), {
    app_key:         'KUNCI_APLIKASI_SAYA',
    filename_prefix: 'invoice',
  }),
});
const { token, upload_url } = await signRes.json();

// Step 2: Upload file (token hanya bisa dipakai sekali)
const form = new FormData();
form.append('token', token);
form.append('file', fileInput.files[0]);

const uploadRes = await fetch(upload_url, { method: 'POST', body: form });
const result    = await uploadRes.json();
console.log(result);
```

### JavaScript (XMLHttpRequest + progress bar)

```js
const xhr = new XMLHttpRequest();
xhr.open('POST', upload_url);

xhr.upload.addEventListener('progress', (e) => {
  if (e.lengthComputable) {
    console.log(`Progress: ${Math.round(e.loaded / e.total * 100)}%`);
  }
});

xhr.addEventListener('load', () => {
  console.log(JSON.parse(xhr.responseText));
});

const form = new FormData();
form.append('token', token);
form.append('file', file);
xhr.send(form);
```

---

## Alur Kerja

```
Klien                      sign-url.php                    upload.php
  │                             │                               │
  │── POST app_key ────────────►│                               │
  │                  Validasi app key                           │
  │                  Buat token + JTI                           │
  │                  Simpan JTI ke whitelist                    │
  │◄── token + upload_url ──────│                               │
  │                             │                               │
  │── POST token + file ───────────────────────────────────────►│
  │                                          Verifikasi token   │
  │                                          Klaim JTI dari     │
  │                                          whitelist (hapus)  │
  │                                          Validasi file      │
  │                                          Upload ke temp/    │
  │                                          Pindah ke tujuan   │
  │◄── { status, file, path } ─────────────────────────────────│
```

---

## Mekanisme One-Time-Use Token

Token menggunakan sistem **whitelist** berbasis file (`storage/used_tokens.json`):

1. **`sign-url.php`** memanggil `registerToken(jti, exp)` — JTI dicatat bersama waktu expired-nya. Sekaligus membersihkan JTI lain yang sudah expired namun belum pernah diklaim.
2. **`upload.php`** memanggil `claimToken(jti)` — jika JTI ada, dihapus dan upload dilanjutkan; jika tidak ada (sudah dipakai/tidak terdaftar), request ditolak `403`.

Format `storage/used_tokens.json` — hanya berisi token **aktif yang belum digunakan**:
```json
{ "abc123xyz789def0": 1743401200, "uvw456rst012ghi3": 1743401500 }
```

---

## Keamanan

- **Signed token** — HMAC-SHA256, tidak dapat dipalsukan tanpa `sign_secret`.
- **One-time-use** — token dicabut setelah dipakai, mencegah replay attack.
- **Token TTL** — token otomatis kadaluarsa (default 5 menit).
- **MIME type verification** — MIME type aktual file diverifikasi menggunakan `finfo`, bukan hanya ekstensi.
- **Direktori tujuan terkunci** per app key — klien tidak bisa upload ke direktori di luar `upload_dir`.
- **`storage/` diblokir** dari akses publik via `.htaccess` / konfigurasi Nginx.
- Ganti `sign_secret` dengan nilai acak yang kuat sebelum digunakan di produksi.
- Pertimbangkan untuk tidak mengekspos direktori `files/` secara langsung (gunakan download script terpisah).


---

## Struktur File

```
uploader/
├── config.php          # Konfigurasi utama (app keys, secret, dll.)
├── helper.php          # Fungsi pembantu (token, response, dll.)
├── sign-url.php        # Endpoint: minta signed token
├── upload-v2.php       # Endpoint: upload file menggunakan token
├── test.html           # Halaman simulasi upload (UI)
└── files/
    ├── temp/           # Direktori sementara selama proses upload
    ├── umum/           # Contoh direktori tujuan
    ├── dokumen/        # Contoh direktori tujuan
    └── penilaian/      # Contoh direktori tujuan
```

---

## Persyaratan

- PHP >= 8.0
- Ekstensi PHP: `fileinfo` (biasanya sudah aktif)
- Web server: Apache / Nginx (atau PHP built-in server untuk development)
- Direktori `files/` harus dapat ditulis oleh web server

---

## Setup

### 1. Clone / letakkan file di direktori web server

```bash
# Contoh untuk Apache di Ubuntu
cp -r uploader/ /var/www/html/uploader
```

### 2. Buat direktori yang diperlukan dan atur permission

```bash
mkdir -p files/temp files/umum files/dokumen files/penilaian
chmod 775 files/temp files/umum files/dokumen files/penilaian
```

### 3. Edit `config.php`

```php
return [

    // WAJIB DIGANTI dengan string acak yang panjang dan kuat
    'sign_secret' => 'isi-dengan-random-string-yang-sangat-panjang',

    // Masa berlaku token upload (detik). Default: 5 menit.
    'token_ttl' => 300,

    // Direktori sementara sebelum file dipindah ke tujuan akhir
    'temp_dir' => 'files/temp',

    'app_keys' => [

        'KUNCI_APLIKASI_SAYA' => [
            'name'          => 'Nama Aplikasi Saya',
            'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size'      => 10 * 1024 * 1024, // 10 MB
            'upload_dir'    => 'files/umum',
        ],

        // tambahkan app key lain sesuai kebutuhan ...
    ],
];
```

> **Penting:** Jangan pernah menyimpan `config.php` di version control produksi, atau minimal pastikan `sign_secret` dan `app_keys` dibaca dari environment variable.

### 4. (Opsional) Uji dengan PHP built-in server

```bash
cd /path/to/uploader
php -S localhost:8000
```

Buka `http://localhost:8000/test.html` di browser.

---

## Konfigurasi per App Key

| Parameter       | Tipe     | Keterangan                                             |
|-----------------|----------|--------------------------------------------------------|
| `name`          | string   | Nama deskriptif (untuk identifikasi/logging)          |
| `allowed_types` | string[] | Ekstensi file yang diizinkan, huruf kecil             |
| `max_size`      | int      | Batas ukuran file dalam bytes                         |
| `upload_dir`    | string   | Path direktori tujuan akhir penyimpanan file          |

---

## API Reference

### `POST /sign-url.php` — Minta Token Upload

Autentikasi via **salah satu** cara berikut:
- Header HTTP: `X-App-Key: <app_key>`
- Body field: `app_key=<app_key>`

#### Parameter Body (form-data)

| Field           | Wajib | Keterangan                                                                 |
|-----------------|-------|----------------------------------------------------------------------------|
| `app_key`       | Ya*   | App key yang terdaftar di `config.php` (*atau via header `X-App-Key`)     |
| `folder`        | Tidak | Override direktori tujuan (harus di dalam `upload_dir` app key)           |
| `allowed_types` | Tidak | Override tipe file, dipisah koma. Cth: `pdf,jpg`. Harus subset app key.  |
| `max_size`      | Tidak | Override batas ukuran (bytes). Tidak boleh melebihi batas app key.        |

#### Response Sukses `200`

```json
{
  "status": "success",
  "token": "<signed_token>",
  "upload_url": "http://localhost/uploader/upload-v2.php",
  "expires_at": "2026-03-31T12:05:00+07:00",
  "config": {
    "allowed_types": ["pdf"],
    "max_size": 6291456,
    "max_size_mb": 6,
    "folder": "files/penilaian"
  }
}
```

#### Response Gagal

| HTTP | Kondisi                                  |
|------|------------------------------------------|
| 401  | App key tidak disertakan                 |
| 403  | App key tidak valid                      |
| 405  | Method bukan POST                        |
| 422  | Override `allowed_types` tidak valid     |

---

### `POST /upload-v2.php` — Upload File

Gunakan `upload_url` dan `token` yang diterima dari `/sign-url.php`.

#### Parameter Body (multipart/form-data)

| Field   | Wajib | Keterangan                            |
|---------|-------|---------------------------------------|
| `token` | Ya    | Signed token dari `/sign-url.php`     |
| `file`  | Ya    | File yang akan diunggah               |

#### Response Sukses `200`

```json
{
  "status": "success",
  "file": "a1b2c3_260331120000_x9y8z7.pdf",
  "path": "files/penilaian/a1b2c3_260331120000_x9y8z7.pdf",
  "folder": "files/penilaian",
  "size": 204800,
  "message": "File berhasil diunggah."
}
```

#### Response Gagal

| HTTP | Kondisi                                             |
|------|-----------------------------------------------------|
| 400  | Token tidak disertakan / error upload PHP           |
| 403  | Token tidak valid atau sudah kadaluarsa             |
| 405  | Method bukan POST                                   |
| 413  | Ukuran file melebihi batas token / post_max_size    |
| 415  | Ekstensi tidak diizinkan / MIME type tidak sesuai   |
| 500  | Gagal menyimpan file di server                      |

---

## Contoh Penggunaan

### cURL

```bash
# Step 1: Minta token
curl -s -X POST http://localhost/uploader/sign-url.php \
  -F "app_key=KUNCI_APLIKASI_SAYA" \
  -F "allowed_types=pdf" | python3 -m json.tool
```

```bash
# Step 2: Upload file menggunakan token yang diterima
TOKEN="<token dari step 1>"

curl -s -X POST http://localhost/uploader/upload-v2.php \
  -F "token=$TOKEN" \
  -F "file=@/path/ke/dokumen.pdf" | python3 -m json.tool
```

### JavaScript (fetch)

```js
// Step 1: Minta token
const signRes = await fetch('/uploader/sign-url.php', {
  method: 'POST',
  body: new URLSearchParams({ app_key: 'KUNCI_APLIKASI_SAYA' }),
});
const { token, upload_url } = await signRes.json();

// Step 2: Upload file
const form = new FormData();
form.append('token', token);
form.append('file', fileInput.files[0]);

const uploadRes = await fetch(upload_url, { method: 'POST', body: form });
const result    = await uploadRes.json();
console.log(result);
```

### JavaScript (XMLHttpRequest + progress)

```js
const xhr = new XMLHttpRequest();
xhr.open('POST', upload_url);

xhr.upload.addEventListener('progress', (e) => {
  if (e.lengthComputable) {
    console.log(`Progress: ${Math.round(e.loaded / e.total * 100)}%`);
  }
});

xhr.addEventListener('load', () => {
  console.log(JSON.parse(xhr.responseText));
});

const form = new FormData();
form.append('token', token);
form.append('file', file);
xhr.send(form);
```

---

## Alur Kerja

```
Klien                        sign-url.php                upload-v2.php
  │                               │                            │
  │── POST app_key ──────────────►│                            │
  │                    Validasi app key                        │
  │◄─── token + config ───────────│                            │
  │                               │                            │
  │── POST token + file ─────────────────────────────────────►│
  │                                            Verifikasi token│
  │                                            Validasi file   │
  │                                            Upload ke temp/ │
  │                                            Pindah ke tujuan│
  │◄─── { status, file, path } ───────────────────────────────│
```

---

## Keamanan

- **Signed token** menggunakan HMAC-SHA256 — token tidak dapat dipalsukan tanpa `sign_secret`.
- **Token TTL** — token otomatis kadaluarsa (default 5 menit), mencegah replay attack.
- **MIME type verification** — selain ekstensi, MIME type aktual file diverifikasi menggunakan `finfo`.
- **Direktori tujuan** terkunci per app key — klien tidak bisa upload ke direktori sembarangan.
- **Ganti `sign_secret`** dengan nilai acak yang kuat sebelum digunakan di produksi.
- Pertimbangkan untuk **tidak mengekspos** direktori `files/` secara langsung (gunakan download script terpisah).
