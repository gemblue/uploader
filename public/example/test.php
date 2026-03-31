<?php
$scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Upload Tester</title>
  <style>*, *::before, *::after{ box-sizing: border-box; margin: 0; padding: 0;} body{ font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; padding: 2rem 1rem;} .container{ max-width: 720px; margin: 0 auto;} h1{ font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem;} .subtitle{ font-size: 0.875rem; color: #64748b; margin-bottom: 2rem;} .card{ background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;} .card h2{ font-size: 1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;} .badge{ font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #e0f2fe; color: #0369a1;} .badge.post{ background: #fef9c3; color: #854d0e;} .form-group{ margin-bottom: 1rem;} label{ display: block; font-size: 0.8rem; font-weight: 600; color: #475569; margin-bottom: 0.35rem;} input[type="text"], input[type="url"], input[type="file"], select{ width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem; background: #f8fafc; transition: border-color 0.15s; outline: none;} input:focus, select:focus{ border-color: #6366f1; background: #fff;} .row{ display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;} @media (max-width: 560px){ .row{ grid-template-columns: 1fr;}} button{ display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1.25rem; border: none; border-radius: 8px; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: opacity 0.15s, background 0.15s;} button:disabled{ opacity: 0.5; cursor: not-allowed;} .btn-primary{ background: #6366f1; color: #fff;} .btn-primary:hover:not(:disabled){ background: #4f46e5;} .btn-success{ background: #16a34a; color: #fff;} .btn-success:hover:not(:disabled){ background: #15803d;} .btn-ghost{ background: none; color: #64748b; border: 1px solid #e2e8f0; padding: 0.35rem 0.75rem; font-size: 0.75rem;} .btn-ghost:hover{ background: #f1f5f9;} .token-box{ background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.75rem; font-family: monospace; color: #475569; word-break: break-all; margin-top: 0.75rem; display: none;} .token-meta{ display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; font-size: 0.75rem; color: #64748b;} .meta-chip{ background: #f1f5f9; border-radius: 6px; padding: 2px 8px;} .log{ background: #0f172a; color: #94a3b8; border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.8rem; min-height: 120px; max-height: 320px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;} .log .ok { color: #4ade80;} .log .err{ color: #f87171;} .log .info{ color: #60a5fa;} .log .dim { color: #475569;} .progress-wrap{ margin-top: 0.75rem; display: none;} progress{ width: 100%; height: 8px; border-radius: 4px; overflow: hidden; appearance: none;} progress::-webkit-progress-bar { background: #e2e8f0; border-radius: 4px;} progress::-webkit-progress-value{ background: #16a34a; border-radius: 4px;} .progress-label{ font-size: 0.75rem; color: #64748b; text-align: right; margin-top: 0.25rem;} .divider{ border: none; border-top: 1px solid #e2e8f0; margin: 1rem 0;} .actions{ display: flex; gap: 0.5rem; align-items: center; margin-top: 0.75rem;} </style>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="uploadApp()" @keydown.ctrl.enter.window="currentToken ? doUpload() : getToken()">
<div class="container">

  <h1>⬆ Upload Tester</h1>
  <p class="subtitle">Simulasi alur: Minta Sign URL → Upload File</p>

  <!-- ── STEP 1: Konfigurasi & Sign URL ─────────────────────────── -->
  <div class="card">
    <h2><span class="badge">POST</span> Step 1 — Minta Sign URL / Token</h2>

    <div class="form-group">
      <label>Base URL Server</label>
      <input type="url" x-model="baseUrl" placeholder="http://localhost/uploader" />
    </div>

    <div class="row">
      <div class="form-group">
        <label>App Key</label>
        <input type="text" x-model="appKey" placeholder="APP_KEY_CONTOH_1" />
      </div>
      <div class="form-group">
        <label>Folder (opsional)</label>
        <input type="text" x-model="folder" placeholder="Dikosongkan = default app key" />
      </div>
    </div>

    <div class="row">
      <div class="form-group">
        <label>Tipe File Diizinkan (opsional)</label>
        <input type="text" x-model="allowedTypes" placeholder="pdf,jpg,png (kosong = default)" />
      </div>
      <div class="form-group">
        <label>Max Size Bytes (opsional)</label>
        <input type="text" x-model="maxSize" placeholder="Kosong = default app key" />
      </div>
    </div>

    <div class="row">
      <div class="form-group">
        <label>Prefix Nama File (opsional)</label>
        <input type="text" x-model="filenamePrefix" placeholder="Contoh: invoice" />
      </div>
      <div class="form-group">
        <label>Suffix Nama File (opsional)</label>
        <input type="text" x-model="filenameSuffix" placeholder="Contoh: draft" />
      </div>
    </div>

    <div class="actions">
      <button class="btn-primary" :disabled="loadingToken" @click="getToken()">
        <span x-text="loadingToken ? 'Memuat…' : '⚡ Minta Token'"></span>
      </button>
    </div>

    <div class="token-box" x-show="tokenValue" x-text="tokenValue" style="display:none"></div>
    <div class="token-meta" x-show="tokenMeta" style="display:none">
      <span class="meta-chip" x-text="tokenMeta ? '⏰ Expired: ' + new Date(tokenMeta.expires_at).toLocaleString('id') : ''"></span>
      <span class="meta-chip" x-text="tokenMeta ? '📁 Folder: ' + tokenMeta.folder : ''"></span>
      <span class="meta-chip" x-text="tokenMeta ? '📄 Types: ' + tokenMeta.allowed_types.join(', ') : ''"></span>
      <span class="meta-chip" x-text="tokenMeta ? '📦 Max: ' + fmtBytes(tokenMeta.max_size) : ''"></span>
      <template x-if="tokenMeta && tokenMeta.filename_prefix">
        <span class="meta-chip" x-text="'🔤 Prefix: ' + tokenMeta.filename_prefix"></span>
      </template>
      <template x-if="tokenMeta && tokenMeta.filename_suffix">
        <span class="meta-chip" x-text="'🔤 Suffix: ' + tokenMeta.filename_suffix"></span>
      </template>
    </div>
  </div>

  <!-- ── STEP 2: Upload File ─────────────────────────────────────── -->
  <div class="card">
    <h2><span class="badge post">POST</span> Step 2 — Upload File</h2>

    <div class="form-group">
      <label>Pilih File</label>
      <input type="file" id="fileInput" />
    </div>

    <div class="actions">
      <button class="btn-success" :disabled="!currentToken" @click="doUpload()">⬆ Upload</button>
      <span x-text="uploadStatus" style="font-size:0.8rem; color:#64748b;"></span>
    </div>

    <div class="progress-wrap" x-show="showProgress" style="display:none">
      <progress :value="progress" max="100"></progress>
      <div class="progress-label" x-text="progress + '%'"></div>
    </div>
  </div>

  <!-- ── LOG ────────────────────────────────────────────────────── -->
  <div class="card">
    <h2>
      📋 Log
      <button class="btn-ghost" style="margin-left:auto" @click="clearLog()">Hapus</button>
    </h2>
    <div class="log" x-ref="logEl">
      <template x-if="logs.length === 0">
        <span class="dim">Belum ada aktivitas.</span>
      </template>
      <template x-for="(entry, i) in logs" :key="i">
        <span :class="entry.type" x-text="entry.text + '\n'"></span>
      </template>
    </div>
  </div>

</div>

<script>
  function uploadApp() {
    return {
      baseUrl:      '<?= htmlspecialchars($baseUrl) ?>',
      appKey:       'APP_KEY_CONTOH_1',
      folder:       '',
      allowedTypes: '',
      maxSize:      '',
      filenamePrefix: '',
      filenameSuffix: '',

      currentToken:  null,
      currentUpload: null,
      loadingToken:  false,

      tokenValue: '',
      tokenMeta:  null,

      uploadStatus: '',
      progress:     0,
      showProgress: false,

      logs: [],

      /* ── Utilities ──────────────────────────────────────── */
      appendLog(msg, type = '') {
        this.logs.push({ text: msg, type });
        this.$nextTick(() => {
          const el = this.$refs.logEl;
          if (el) el.scrollTop = el.scrollHeight;
        });
      },

      clearLog() {
        this.logs = [];
      },

      fmtBytes(bytes) {
        if (bytes < 1024)    return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
      },

      /* ── Step 1: Minta Token ────────────────────────────── */
      async getToken() {
        this.currentToken = null;
        this.tokenValue   = '';
        this.tokenMeta    = null;
        this.loadingToken = true;

        const appKey = this.appKey.trim();
        if (!appKey) {
          this.appendLog('✖ App Key tidak boleh kosong.', 'err');
          this.loadingToken = false;
          return;
        }

        const body = new FormData();
        body.append('app_key', appKey);
        if (this.folder.trim())         body.append('folder',          this.folder.trim());
        if (this.allowedTypes.trim())   body.append('allowed_types',   this.allowedTypes.trim());
        if (this.maxSize.trim())        body.append('max_size',        this.maxSize.trim());
        if (this.filenamePrefix.trim()) body.append('filename_prefix', this.filenamePrefix.trim());
        if (this.filenameSuffix.trim()) body.append('filename_suffix', this.filenameSuffix.trim());

        const url = this.baseUrl.replace(/\/$/, '') + '/sign-url.php';
        this.appendLog(`→ POST ${url}`, 'info');
        this.appendLog(`  app_key: ${appKey}`);

        try {
          const res  = await fetch(url, { method: 'POST', body });
          const data = await res.json();

          this.appendLog(`← HTTP ${res.status}`, res.ok ? 'ok' : 'err');
          this.appendLog(JSON.stringify(data, null, 2));

          if (data.status === 'success') {
            this.currentToken  = data.token;
            this.currentUpload = data.upload_url;
            this.tokenValue    = data.token;
            this.tokenMeta     = {
              expires_at:      data.expires_at,
              folder:          data.config.folder,
              allowed_types:   data.config.allowed_types,
              max_size:        data.config.max_size,
              filename_prefix: data.config.filename_prefix || '',
              filename_suffix: data.config.filename_suffix || '',
            };
            this.appendLog('✔ Token berhasil didapat. Lanjutkan ke Step 2.', 'ok');
          }
        } catch (e) {
          this.appendLog('✖ Gagal menghubungi server: ' + e.message, 'err');
        } finally {
          this.loadingToken = false;
        }
      },

      /* ── Step 2: Upload File ────────────────────────────── */
      doUpload() {
        if (!this.currentToken) {
          this.appendLog('✖ Minta token terlebih dahulu.', 'err');
          return;
        }

        const fileInput = document.getElementById('fileInput');
        if (!fileInput.files.length) {
          this.appendLog('✖ Pilih file terlebih dahulu.', 'err');
          return;
        }

        const file       = fileInput.files[0];
        const url        = this.currentUpload || (this.baseUrl.replace(/\/$/, '') + '/upload-v2.php');
        const savedToken = this.currentToken;

        this.appendLog(`\n→ POST ${url}`, 'info');
        this.appendLog(`  file  : ${file.name} (${this.fmtBytes(file.size)})`);
        this.appendLog(`  token : ${this.currentToken.substring(0, 40)}…`);

        const body = new FormData();
        body.append('token', this.currentToken);
        body.append('file', file);

        this.currentToken  = null; // invalidasi segera
        this.uploadStatus  = 'Mengunggah…';
        this.showProgress  = true;
        this.progress      = 0;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);

        xhr.upload.addEventListener('progress', (e) => {
          if (e.lengthComputable) {
            this.progress     = Math.round((e.loaded / e.total) * 100);
            this.uploadStatus = `Mengunggah… ${this.progress}%`;
          }
        });

        xhr.addEventListener('load', () => {
          this.uploadStatus = '';
          this.progress     = 100;

          try {
            const data = JSON.parse(xhr.responseText);
            this.appendLog(`← HTTP ${xhr.status}`, xhr.status === 200 ? 'ok' : 'err');
            this.appendLog(JSON.stringify(data, null, 2));

            if (data.status === 'success') {
              this.appendLog(`✔ Upload berhasil! File: ${data.file}`, 'ok');
            } else {
              this.appendLog(`✖ Upload gagal: ${data.message}`, 'err');
            }
          } catch {
            this.appendLog(`← HTTP ${xhr.status} (respons bukan JSON):\n${xhr.responseText}`, 'err');
          }

          this.tokenValue = '';
          this.tokenMeta  = null;
          this.appendLog('\nToken dikonsumsi. Minta token baru untuk upload berikutnya.', 'dim');
        });

        xhr.addEventListener('error', () => {
          this.appendLog('✖ Koneksi gagal.', 'err');
          this.uploadStatus = '';
          this.currentToken = savedToken; // kembalikan token agar bisa retry
        });

        xhr.send(body);
      },
    };
  }
</script>
</body>
</html>
