# PHP File Uploader

Sistem upload file berbasis PHP yang aman dengan mekanisme **signed token one-time-use**. Klien harus meminta token terlebih dahulu menggunakan App Key sebelum dapat mengunggah file. Setiap token hanya dapat digunakan sekali.

---

## Struktur File

```
uploader/
├── config.php              # Konfigurasi utama (app keys, secret, dll.)
├── config.sample.php       # Template konfigurasi — salin ke config.php
├── helper.php              # Fungsi pembantu (token, response, whitelist, dll.)
├── public
│   ├── sign-url.php               # Endpoint: minta signed token
│   ├── upload.php                 # Endpoint: upload file menggunakan token
│   ├── uploader.js                # Plugin JS untuk integrasi ke sistem lain
│   └── example/
│       ├── index.html             # Contoh & simulasi penggunaan uploader.js
│       ├── proxy.php              # Contoh proxy endpoint (template integrasi)
│       └── test.php               # Skrip pengujian manual
├── sdk/
│   └── UploaderSDK.php            # PHP SDK — salin ke server aplikasi Anda
├── storage/
│   ├── rate_limits/        # File rate-limit per IP+appKey (auto-dibuat)
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
mkdir -p files/temp files/test storage/rate_limits
chmod 775 files/temp files/test
chmod 775 storage storage/rate_limits
echo '{}' > storage/used_tokens.json
chmod 664 storage/used_tokens.json
```

### 3. Proteksi direktori `storage/`

Untuk Nginx, tambahkan ke konfigurasi server:

```nginx
location /storage {
    deny all;
    return 404;
}
```

Untuk Apache, tambahkan ke konfigurasi atau buat `storage/.htaccess`:

```apache
Deny from all
```

### 4. Edit `config.php`

Salin template terlebih dahulu:

```bash
cp config.sample.php config.php
```

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
            'upload_dir'    => 'files/test',

            // null atau ['*'] = izinkan semua (development/server-to-server)
            // Produksi: daftarkan domain, mis. ['https://aplikasi-saya.com']
            'allowed_origins' => null,

            // Rate limit: maks token yang boleh diminta per IP per window (detik)
            'rate_limit' => ['max_requests' => 20, 'window' => 60],
        ],

        // tambahkan app key lain sesuai kebutuhan ...
    ],
];
```

> **Penting:** Jangan pernah menyimpan `config.php` di version control produksi, atau pastikan `sign_secret` dan `app_keys` dibaca dari environment variable.

### 5. (Opsional) Uji dengan PHP built-in server

```bash
cd /path/to/uploader/public
php -S localhost:8000
```

Buka `http://localhost:8000/example/index.html` atau `http://localhost:8000/example/test.php` di browser untuk mencoba simulasi dan contoh implementasi.

---

## Konfigurasi per App Key

| Parameter         | Tipe     | Keterangan                                                          |
|-------------------|----------|---------------------------------------------------------------------|
| `name`            | string   | Nama deskriptif (untuk identifikasi/logging)                        |
| `allowed_types`   | string[] | Ekstensi file yang diizinkan, huruf kecil                           |
| `max_size`        | int      | Batas ukuran file dalam bytes                                       |
| `upload_dir`      | string   | Path direktori tujuan akhir penyimpanan file                        |
| `allowed_origins` | array\|null | Domain yang boleh menggunakan app key ini. `null`/`['*']` = semua. Request tanpa `Origin` (cURL, SDK) selalu diizinkan. |
| `rate_limit`      | array    | `max_requests` — maks token per IP; `window` — jendela waktu (detik) |

---

## API Reference

### `POST /sign-url.php` — Minta Token Upload

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
  "upload_url": "https://example.com/upload.php",
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
| 403  | App key tidak valid / origin tidak diizinkan |
| 405  | Method bukan POST                         |
| 422  | Override `allowed_types` tidak valid      |
| 429  | Rate limit terlampaui                     |

---

### `POST /upload.php` — Upload File

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
curl -s -X POST https://example.com/sign-url.php \
  -F "app_key=KUNCI_APLIKASI_SAYA" \
  -F "allowed_types=pdf" \
  -F "filename_prefix=invoice" | python3 -m json.tool
```

```bash
# Step 2: Upload file menggunakan token yang diterima
TOKEN="<token dari step 1>"

curl -s -X POST https://example.com/upload.php \
  -F "token=$TOKEN" \
  -F "file=@/path/ke/dokumen.pdf" | python3 -m json.tool
```

### JavaScript (fetch)

```js
// Step 1: Minta token
const signRes = await fetch('https://example.com/sign-url.php', {
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

## Integrasi ke Sistem Lain — `uploader.js`

File `uploader.js` adalah plugin JavaScript yang bisa di-include di halaman manapun untuk menyederhanakan proses sign URL → upload.

### Cara include

```html
<script src="https://uploader.example.com/uploader.js"></script>
```

---

### Cara 1 — Auto-bind via atribut HTML (termudah)

Tambahkan atribut `data-uploader` beserta konfigurasi pada tag `<form>`. Semua `input[type=file]` di dalam form dikumpulkan dan diupload satu per satu secara otomatis.

```html
<form
  data-uploader
  data-sign-url="https://uploader.example.com/sign-url.php"
  data-app-key="APP_KEY_CONTOH_1"
  data-folder="files/invoice"
  data-filename-prefix="inv"
>
  <!-- Satu input dengan multiple, atau beberapa input berbeda -->
  <input type="file" name="file" multiple />
  <button type="submit">Upload</button>
</form>

<script src="https://uploader.example.com/uploader.js"></script>
<script>
  Uploader.bindForms();

  const form = document.querySelector('form[data-uploader]');
  form.addEventListener('uploader:success', (e) => {
    const { file, fileIndex, totalFiles } = e.detail;
    console.log(`File ${fileIndex + 1}/${totalFiles} berhasil: ${file}`);
  });
  form.addEventListener('uploader:error',    (e) => alert('Gagal: ' + e.detail.message));
  form.addEventListener('uploader:progress', (e) => console.log(`[${e.detail.fileIndex + 1}] ${e.detail.percent}%`));
</script>
```

#### Atribut `data-*` yang didukung

| Atribut               | Wajib | Keterangan                                  |
|-----------------------|-------|---------------------------------------------|
| `data-sign-url`       | ✔     | URL endpoint `sign-url.php`                 |
| `data-app-key`        | ✔     | App key rahasia aplikasi Anda               |
| `data-folder`         |       | Override folder tujuan                      |
| `data-allowed-types`  |       | Override tipe file (koma-separated)         |
| `data-max-size`       |       | Override max size (bytes)                   |
| `data-filename-prefix`|       | Prefix nama file                            |
| `data-filename-suffix`|       | Suffix nama file                            |

#### Event yang di-dispatch ke form

| Event                | `event.detail`                                                     |
|----------------------|--------------------------------------------------------------------|
| `uploader:sign`      | Data token + `fileIndex`, `fileName`                               |
| `uploader:progress`  | `{ percent, fileIndex, fileName }`                                 |
| `uploader:success`   | Response JSON upload + `fileIndex`, `fileName`, `totalFiles`       |
| `uploader:error`     | `{ message, detail, fileIndex, fileName }`                         |

---

### Cara 2 — JavaScript API (kontrol penuh)

```js
const uploader = new Uploader({
  signUrl:        'https://uploader.example.com/sign-url.php',
  appKey:         'APP_KEY_CONTOH_1',
  filenamePrefix: 'laporan',
});

const file = document.getElementById('fileInput').files[0];

await uploader.upload(file, {
  onSign:     (data) => console.log('Token:', data.token),
  onProgress: (pct)  => console.log(pct + '%'),
  onSuccess:  (data) => console.log('File tersimpan:', data.file),
  onError:    (msg)  => console.error(msg),
});
```

`uploader.upload()` mengembalikan `Promise<object>` — resolve dengan response sukses, reject jika ada error.

#### Config constructor

| Parameter         | Wajib | Keterangan                             |
|-------------------|-------|----------------------------------------|
| `signUrl`         | ✔     | URL endpoint `sign-url.php`            |
| `appKey`          | ✔     | App key                                |
| `folder`          |       | Override folder tujuan                 |
| `allowedTypes`    |       | Override tipe file (`"pdf,jpg"`)       |
| `maxSize`         |       | Override max size (bytes, angka)       |
| `filenamePrefix`  |       | Prefix nama file                       |
| `filenameSuffix`  |       | Suffix nama file                       |

---

### Cara 3 — Hanya minta token (manual)

Berguna jika Anda ingin mengontrol proses upload sendiri (mis. drag-and-drop, chunked upload):

```js
const uploader = new Uploader({ signUrl: '...', appKey: '...' });
const signData = await uploader.requestToken();

// signData.token      — token untuk dikirim ke upload.php
// signData.upload_url — URL upload
// signData.expires_at — waktu kedaluarsa token
// signData.config     — konfigurasi aktif (folder, allowed_types, dll)
```

---

### Cara 4 — Via Proxy Server (keamanan maksimal)

Pada Cara 1–3, App Key disertakan di sisi client. Meskipun dilindungi origin check, App Key tetap terlihat di source code halaman. Pendekatan proxy menyembunyikan App Key sepenuhnya — browser tidak pernah melihatnya.

**Alur:**
```
Browser  →  POST /api/request-token  (server aplikasi Anda)
                  ↓  App Key tersimpan di .env / config server
             POST sign-url.php  →  token
                  ↓
Browser  ←  { token, upload_url, expires_at, config }
```

**Keuntungan tambahan:** proxy bisa menambahkan lapisan otorisasi sendiri (cek login, CSRF, validasi folder) sebelum meneruskan permintaan ke uploader server.

#### Sisi server — buat proxy endpoint

Salin `sdk/UploaderSDK.php` ke proyek Anda, lalu buat endpoint proxy:

```php
<?php
// /api/request-token.php — endpoint di server aplikasi Anda

require_once 'UploaderSDK.php';

// 1. Otorisasi request (sesuaikan dengan sistem Anda)
session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Belum login.']);
    exit;
}

// 2. Inisialisasi SDK — App Key dari .env, TIDAK hardcode
$sdk = new UploaderSDK(
    signUrl: getenv('UPLOADER_SIGN_URL'),
    appKey:  getenv('UPLOADER_APP_KEY'),
    defaults: [
        'folder'          => 'files/umum',
        'filename_prefix' => 'upload',
    ],
);

// 3. (Opsional) Izinkan client memilih folder dari daftar yang disetujui
$options = [];
$allowedFolders = ['files/umum', 'files/invoice', 'files/laporan'];
if (!empty($_POST['folder']) && in_array($_POST['folder'], $allowedFolders, true)) {
    $options['folder'] = $_POST['folder'];
}

// 4. Minta token ke uploader server, kirim kembali ke browser
try {
    $tokenData = $sdk->requestToken($options);
    header('Content-Type: application/json');
    echo json_encode(UploaderSDK::publicPayload($tokenData)); // App Key tidak ikut
} catch (UploaderSDKException $e) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

`UploaderSDK::publicPayload()` memfilter response sehingga hanya `token`, `upload_url`, `expires_at`, dan `config` yang dikembalikan ke browser — App Key tidak ikut.

#### Sisi client — tanpa App Key

```html
<script src="https://uploader.example.com/uploader.js"></script>
<script>
  const uploader = new Uploader({
    signUrl: '/api/request-token',  // proxy di server Anda, tanpa appKey
  });

  const file = document.getElementById('fileInput').files[0];
  await uploader.upload(file, {
    onSuccess: (d) => console.log('Berhasil:', d.file),
    onError:   (msg) => console.error(msg),
  });
</script>
```

Atau via auto-bind form (hapus `data-app-key`):

```html
<form data-uploader data-sign-url="/api/request-token">
  <input type="file" name="file" />
  <button type="submit">Upload</button>
</form>
```

Template lengkap proxy endpoint tersedia di `public/example/proxy.php`.

---

## PHP SDK — `UploaderSDK.php`

File `sdk/UploaderSDK.php` adalah PHP client untuk berkomunikasi dengan uploader server dari sisi server. Digunakan untuk membangun proxy endpoint seperti pada Cara 4 di atas.

**Instalasi:** Salin `sdk/UploaderSDK.php` ke proyek Anda, lalu `require_once`.

### Constructor

```php
$sdk = new UploaderSDK(
    signUrl:  'https://uploader.example.com/public/sign-url.php',
    appKey:   getenv('UPLOADER_APP_KEY'),
    defaults: [                     // opsional — nilai default tiap request
        'folder'          => 'files/umum',
        'filename_prefix' => 'upload',
    ],
    timeout:  10,                   // opsional — timeout HTTP (detik)
);
```

### `requestToken(array $options = [])`

Meminta signed token ke uploader server. `$options` meng-override `defaults` yang ditentukan di constructor.

```php
$tokenData = $sdk->requestToken([
    'folder'          => 'files/invoice',
    'allowed_types'   => 'pdf,jpg',
    'max_size'        => 5 * 1024 * 1024,
    'filename_prefix' => 'inv',
    'filename_suffix' => 'draft',
]);
// $tokenData['token']      — signed token
// $tokenData['upload_url'] — URL upload.php
// $tokenData['expires_at'] — waktu kedaluarsa (ISO 8601)
// $tokenData['config']     — konfigurasi aktif
```

Melempar `UploaderSDKException` jika request gagal atau uploader server merespons error.

### `UploaderSDK::publicPayload(array $tokenData)`

Method statis — memfilter array response sehingga hanya field yang aman dikembalikan ke browser (tanpa App Key atau data internal).

```php
echo json_encode(UploaderSDK::publicPayload($tokenData));
```

---

Contoh HTML lengkap tersedia di `public/example/index.html`.

---

## Lisensi

MIT License

Copyright (c) 2026

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Atribusi

Pembuatan kode pada proyek ini dibantu oleh **GitHub Copilot** menggunakan model **Claude Sonnet 4.6**.
