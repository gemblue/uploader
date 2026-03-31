/**
 * Uploader.js
 * Plugin upload file dengan mekanisme signed token one-time-use.
 *
 * ── Penggunaan yang DIREKOMENDASIKAN (via proxy — App Key di server) ──
 *
 *   const uploader = new Uploader({
 *     signUrl: '/api/minta-token',   // endpoint proxy di server aplikasi Anda
 *     // Tidak ada appKey di sini — App Key disimpan di server, tidak pernah ke browser
 *   });
 *
 *   uploader.upload(fileInput.files[0], {
 *     onProgress: (pct) => console.log(pct + '%'),
 *     onSuccess:  (data) => console.log('File:', data.file),
 *     onError:    (msg)  => console.error(msg),
 *   });
 *
 * ── Atau via form HTML (auto-bind) ────────────────────────────────────
 *
 *   <form data-uploader data-sign-url="/api/minta-token">
 *     <input type="file" name="file" />
 *     <button type="submit">Upload</button>
 *   </form>
 *
 *   <script src="uploader.js"></script>
 *   <script>Uploader.bindForms();</script>
 *
 * ── Development / testing (langsung ke sign-url.php, App Key di client) ─
 *
 *   const uploader = new Uploader({
 *     signUrl: 'https://uploader.example.com/public/sign-url.php',
 *     appKey:  'APP_KEY_...',   // ⚠ JANGAN digunakan di produksi!
 *   });
 */

class Uploader {
  /**
   * @param {object} config
   * @param {string}   config.signUrl          - URL endpoint sign-url.php ATAU URL proxy server Anda
   * @param {string}  [config.appKey]          - App key (OPSIONAL — hanya untuk dev/testing langsung;
   *                                             pada produksi gunakan proxy sehingga appKey tidak perlu
   *                                             dikirim dari browser)
   * @param {string}  [config.folder]          - Override folder tujuan
   * @param {string}  [config.allowedTypes]    - Override tipe file (koma-separated)
   * @param {number}  [config.maxSize]         - Override max size (bytes)
   * @param {string}  [config.filenamePrefix]  - Prefix nama file
   * @param {string}  [config.filenameSuffix]  - Suffix nama file
   */
  constructor(config = {}) {
    if (!config.signUrl) throw new Error('[Uploader] signUrl wajib diisi.');
    this.config = config;
  }

  // ─────────────────────────────────────────────────────────────
  // Public API
  // ─────────────────────────────────────────────────────────────

  /**
   * Minta token lalu upload file.
   *
   * @param {File}   file
   * @param {object} [callbacks]
   * @param {function} [callbacks.onProgress]  - (percent: number) => void
   * @param {function} [callbacks.onSuccess]   - (data: object) => void
   * @param {function} [callbacks.onError]     - (message: string, detail?: object) => void
   * @param {function} [callbacks.onSign]      - (signData: object) => void  (token berhasil didapat)
   * @returns {Promise<object>} response data upload
   */
  async upload(file, callbacks = {}) {
    const { onProgress, onSuccess, onError, onSign } = callbacks;

    let signData;
    try {
      signData = await this._requestToken();
    } catch (err) {
      const msg = '[Uploader] Gagal mendapatkan token: ' + err.message;
      onError?.(msg, err);
      throw new Error(msg);
    }

    onSign?.(signData);

    return new Promise((resolve, reject) => {
      const xhr  = new XMLHttpRequest();
      const body = new FormData();
      body.append('token', signData.token);
      body.append('file', file);

      xhr.open('POST', signData.upload_url);

      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          onProgress?.(Math.round((e.loaded / e.total) * 100));
        }
      });

      xhr.addEventListener('load', () => {
        let data;
        try {
          data = JSON.parse(xhr.responseText);
        } catch {
          const msg = '[Uploader] Respons bukan JSON (HTTP ' + xhr.status + ')';
          onError?.(msg, { status: xhr.status, body: xhr.responseText });
          return reject(new Error(msg));
        }

        if (data.status === 'success') {
          onSuccess?.(data);
          resolve(data);
        } else {
          const msg = data.message || 'Upload gagal.';
          onError?.(msg, data);
          reject(new Error(msg));
        }
      });

      xhr.addEventListener('error', () => {
        const msg = '[Uploader] Koneksi gagal.';
        onError?.(msg);
        reject(new Error(msg));
      });

      xhr.addEventListener('abort', () => {
        const msg = '[Uploader] Upload dibatalkan.';
        onError?.(msg);
        reject(new Error(msg));
      });

      xhr.send(body);
    });
  }

  /**
   * Hanya minta token (tanpa upload).
   * Berguna jika ingin mengontrol proses upload secara manual.
   * @returns {Promise<object>} signData (token, upload_url, expires_at, config)
   */
  async requestToken() {
    return this._requestToken();
  }

  // ─────────────────────────────────────────────────────────────
  // Static helpers
  // ─────────────────────────────────────────────────────────────

  /**
   * Auto-bind semua <form data-uploader> di halaman.
   *
   * Atribut yang didukung:
   *   data-sign-url          (wajib)
   *   data-app-key           (wajib)
   *   data-folder
   *   data-allowed-types
   *   data-max-size
   *   data-filename-prefix
   *   data-filename-suffix
   *   data-file-input        selector input file (default: input[type=file])
   *
   * Event yang di-dispatch ke form element:
   *   uploader:sign     - token berhasil didapat   (event.detail = signData)
   *   uploader:progress - sedang upload            (event.detail = { percent })
   *   uploader:success  - upload berhasil          (event.detail = responseData)
   *   uploader:error    - terjadi error            (event.detail = { message })
   */
  static bindForms(root = document) {
    root.querySelectorAll('form[data-uploader]').forEach((form) => {
      if (form._uploaderBound) return;
      form._uploaderBound = true;

      const d = form.dataset;

      const uploader = new Uploader({
        signUrl:         d.signUrl,
        appKey:          d.appKey,
        folder:          d.folder          || undefined,
        allowedTypes:    d.allowedTypes    || undefined,
        maxSize:         d.maxSize         ? Number(d.maxSize) : undefined,
        filenamePrefix:  d.filenamePrefix  || undefined,
        filenameSuffix:  d.filenameSuffix  || undefined,
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const selector = d.fileInput || 'input[type="file"]';
        const input    = form.querySelector(selector);
        if (!input || !input.files.length) {
          form.dispatchEvent(new CustomEvent('uploader:error', {
            bubbles: true,
            detail: { message: 'Tidak ada file yang dipilih.' },
          }));
          return;
        }

        try {
          await uploader.upload(input.files[0], {
            onSign: (signData) => form.dispatchEvent(new CustomEvent('uploader:sign', {
              bubbles: true, detail: signData,
            })),
            onProgress: (percent) => form.dispatchEvent(new CustomEvent('uploader:progress', {
              bubbles: true, detail: { percent },
            })),
            onSuccess: (data) => form.dispatchEvent(new CustomEvent('uploader:success', {
              bubbles: true, detail: data,
            })),
            onError: (message, detail) => form.dispatchEvent(new CustomEvent('uploader:error', {
              bubbles: true, detail: { message, detail },
            })),
          });
        } catch (_) {
          // error sudah di-dispatch via onError
        }
      });
    });
  }

  // ─────────────────────────────────────────────────────────────
  // Private
  // ─────────────────────────────────────────────────────────────

  async _requestToken() {
    const cfg  = this.config;
    const body = new FormData();

    if (cfg.appKey)         body.append('app_key',         cfg.appKey); // hanya dikirim jika ada (mode dev langsung)
    if (cfg.folder)         body.append('folder',          cfg.folder);
    if (cfg.allowedTypes)   body.append('allowed_types',   cfg.allowedTypes);
    if (cfg.maxSize)        body.append('max_size',        String(cfg.maxSize));
    if (cfg.filenamePrefix) body.append('filename_prefix', cfg.filenamePrefix);
    if (cfg.filenameSuffix) body.append('filename_suffix', cfg.filenameSuffix);

    const res = await fetch(cfg.signUrl, { method: 'POST', body });

    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('Respons sign-url bukan JSON (HTTP ' + res.status + ')');
    }

    if (data.status !== 'success') {
      throw new Error(data.message || 'sign-url gagal (HTTP ' + res.status + ')');
    }

    return data;
  }
}
