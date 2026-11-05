# Rencana Fondasi Integrasi Real ASU UIH–Temuka (Real ASU Integration Foundation Plan)

**Dokumen Status:** Approved Technical Implementation Plan  
**Target Repository Utama:** `/home/duro/projects/AIPrijects/uih/api.uih`  
**Konsumen Integrasi:**
- Temuka Backend (`/home/duro/projects/AIPrijects/temuka/api.temuka`)
- Temuka Frontend (`/home/duro/projects/AIPrijects/temuka/web.temuka`)  
**Kandidat Integrasi Nyata Pertama:** Larissama Backend (`/home/duro/projects/AIPrijects/larissama/api.larissama`)  
**Dokumen Acuan:**
- [`docs/architecture/UIH_CANONICAL_CATALOG_CONTRACT_V1.md`](../architecture/UIH_CANONICAL_CATALOG_CONTRACT_V1.md)
- [`docs/architecture/UIH_CANONICAL_CATALOG_V1_IMPLEMENTATION_PLAN.md`](../architecture/UIH_CANONICAL_CATALOG_V1_IMPLEMENTATION_PLAN.md)
- Dokumen Spesifikasi ASU: `larissama/api.larissama/docs/integrations/UIH_INTEGRATION_REQUIREMENTS.md`

---

## 1. Tujuan dan Batas Repository

### 1.1. Tujuan
Dokumen ini menetapkan rencana kerja arsitektural dan teknis untuk mentransisikan Udumbara Internal Hub (UIH) dari penggunaan *mock adapters* menuju **Real Server-to-Server (S2S) HTTP Adapters**. Integrasi perdana difokuskan pada Larissama sebagai penyedia katalog kuliner (makanan dan minuman) pertama di ekosistem Udumbara.

Rencana ini dirancang secara modular dan *generic* sehingga penambahan Aplikasi Sumber Udumbara (ASU) berikutnya (misalnya Laundry, Aset/Properti, Jasa, dsb.) tidak memerlukan perombakan arsitektur inti UIH.

### 1.2. Batas-Batas Repository (Repository Boundaries)

| Repository | Peran & Ruang Lingkup | Batasan Tegas |
| :--- | :--- | :--- |
| **UIH Backend** (`api.uih`) | - Pusat orkestrasi & agregasi katalog multi-sumber.<br>- Normalisasi ke Canonical Catalog Contract v1.<br>- Penyedia stream SSE server-to-server ke Temuka.<br>- Pengelola registry dan komunikasi S2S HTTP ke ASU. | **DILARANG** mengakses database ASU secara langsung.<br>**DILARANG** menyimpan cache katalog pada fase v1. |
| **Temuka Backend** (`api.temuka`) | - Gateway & reverse proxy streaming bagi Temuka Web.<br>- Meneruskan request SSE dari browser ke UIH.<br>- Tracing korelasi (`X-Correlation-ID`). | **DILARANG** berkomunikasi langsung dengan ASU tanpa melalui UIH. |
| **Temuka Frontend** (`web.temuka`) | - Antarmuka publik pengguna akhir (Quasar SSR).<br>- Konsumen agregasi pencarian progresif SSE.<br>- Menampilkan kartu produk sesuai kontrak kanonik. | Mengonsumsi API hanya melalui `api.temuka`. |
| **Larissama Backend** (`api.larissama`) | - Penyedia data katalog menu dan warung makan.<br>- Implementasi endpoint inbound `GET /api/v1/s2s/catalog/search`.<br>- Penegakan filter kelayakan publik (*public eligibility*). | Dikerjakan secara independen oleh tim Larissama.<br>Repositori ini **TIDAK BOLEH** dimodifikasi oleh agent UIH/Temuka. |

---

## 2. Prinsip Arsitektur

1. **Zero Direct Database Access:**
   UIH berkomunikasi dengan ASU murni melalui protokol HTTP REST Server-to-Server (S2S). UIH tidak pernah melakukan query SQL langsung ke database aplikasi sumber.
2. **Satu Kontrak Kanonik (Canonical Catalog Contract v1):**
   Seluruh data katalog dari semua ASU wajib dinormalisasi ke struktur kanonik yang seragam (`id`, `name`, `category`, `price`, `image`, `availability`, `provider`, `source`).
3. **Reusable Generic HTTP Adapter:**
   UIH tidak membuat class adapter terpisah untuk setiap nama aplikasi sumber, melainkan menggunakan `HttpSourceAdapter` generik yang dapat dikonfigurasi melalui parameters (`code`, `name`, `baseUrl`, `token`, `timeout`).
4. **Dynamic Source Registry & Driver Switcher:**
   Pendaftaran sumber dikelola via konfigurasi (`config/catalog.php`) dan ENV. Setiap sumber dapat beralih antara driver `mock` dan `http` tanpa mengubah kode aplikasi.
5. **Kebijakan Harga Ketat (Strict Zero-Fallback Price Policy):**
   Item dengan harga invalid, negatif, atau hilang **wajib ditolak** oleh normalizer UIH. UIH **dilarang mengubah harga invalid menjadi 0** guna menghindari persepsi produk gratis.
6. **Strict Timeout Enforcement pada Level HTTP Client:**
   Karena pemanggilan PHP bersifat sinkron, batas waktu koneksi dan respon (`timeout` & `connectTimeout`) wajib ditegakkan langsung pada level HTTP Client adapter untuk mencegah *thread blocking*.
7. **Isolasi Kegagalan Sumber (Failure Isolation):**
   Kegagalan atau keterlambatan satu sumber (misal `source_failed` atau timeout) tidak boleh menghentikan pemrosesan atau menghilangkan hasil dari sumber lain.
8. **Pengiriman Progresif via SSE (Progressive Delivery):**
   Event SSE (`search_init`, `source_result`, `source_failed`, `search_done`) dipancarkan secara bertahap begitu data dari masing-masing adapter tersedia.
9. **Tanpa Caching pada v1 (Zero Caching):**
   UIH bertindak sebagai agregator murni *real-time* untuk menjaga kesegaran data harga dan ketersediaan stok menu.
10. **Penegakan Kelayakan Publik di Sisi ASU:**
    ASU bertanggung jawab penuh memastikan hanya entitas aktif, terverifikasi, dan layak jual yang dikembalikan ke UIH.

---

## 3. Rencana Bertahap (Phases 0–7)

```
[Fase 0: Docker Networking Baseline]
                  │
                  ▼
[Fase 1: Reusable HTTP Adapter] ───────────────┐
                  │                             │
                  ▼                             │
[Fase 2: Dynamic Source Registry]               │
                  │                             │
                  ▼                             │
[Fase 3: Strict Timeout & Resilience]           │
                  │                             │
                  ▼                             ▼
[Fase 4: Temuka Backend Tracing]   [Fase 5: Temuka Frontend Robustness]
                  │                             │
                  └──────────────┬──────────────┘
                                 │
                                 ▼
[Fase 6: Integrasi Real Adapter Larissama (End-to-End)]  <-- BLOCKED (Menunggu Teammate)
                                 │
                                 ▼
[Fase 7: Verifikasi Komprehensif & Observabilitas]
```

---

### Fase 0: Docker Networking Baseline & Multi-Container Preflight
- **Tujuan:** Memastikan seluruh container dev (`uih-dev`, `temuka-dev`, `temuka-web-dev`, dan `larissama-backend-dev`) dapat saling berkomunikasi melalui bridge network bersama.
- **Ruang Lingkup:**
  - Standardisasi konfigurasi `compose.yaml` untuk bergabung ke network `temuka-uih-network`.
  - Pemetaan alias domain internal (`uih-dev-app`, `temuka-dev-app`, `larissama-backend-dev-local-app`).
  - Skrip verifikasi preflight konektivitas DNS dan health check antar-container.
- **Kriteria Selesai:**
  - `curl` dari container UIH ke Larissama dan Temuka berhasil menjangkau port lokal.
  - Dokumentasi instruksi menjalankan multi-container tercatat jelas.
- **Status:** `Planned`

---

### Fase 1: Abstraksi Reusable Generic HTTP Adapter di UIH
- **Tujuan:** Membangun fondasi komunikasi keluar (*outbound*) berbasis HTTP Client Laravel yang *reusable* untuk semua ASU.
- **Ruang Lingkup:**
  - Membuat class `App\Services\Catalog\Adapters\HttpSourceAdapter` yang mengimplementasikan `App\Contracts\Catalog\SourceAdapterInterface`.
  - Mengonfigurasi parameter: `code`, `name`, `baseUrl`, `token`, `timeout`, `searchPath`.
  - Mengimplementasikan pemetaan response status HTTP:
    - 200 OK $\rightarrow$ status `completed` dengan payload `data`.
    - 401/403 $\rightarrow$ `SOURCE_AUTH_ERROR`.
    - 422 $\rightarrow$ `SOURCE_INVALID_REQUEST`.
    - 5xx $\rightarrow$ `SOURCE_UNAVAILABLE`.
    - JSON Corrupt $\rightarrow$ `SOURCE_MALFORMED_RESPONSE`.
    - Connection/Read Timeout $\rightarrow$ `SOURCE_TIMEOUT`.
  - Pengujian unit komprehensif menggunakan `Http::fake()`.
- **Kriteria Selesai:**
  - Seluruh status HTTP dan skenario error teruji 100% via unit test tanpa ketergantungan jaringan eksternal.
  - `SourceAdapterInterface` tetap bersih dan tidak mengalami perubahan *breaking*.
- **Status:** `Planned`

---

### Fase 2: Dynamic Source Registry & Configuration di UIH
- **Tujuan:** Menghilangkan *hardcoding* instansiasi mock di `CatalogSearchCoordinator` dan menggantinya dengan registrasi dinamis berbasis konfigurasi.
- **Ruang Lingkup:**
  - Membuat konfigurasi `config/catalog.php` yang mendefinisikan daftar sumber, nama, driver (`mock` atau `http`), base URL, token, dan timeout.
  - Memperbarui `.env.example` UIH dengan variabel konfigurasi sumber:
    - `SOURCE_LARISSAMA_DRIVER=mock`
    - `SOURCE_LARISSAMA_BASE_URL=http://larissama-backend-dev-local-app:8000`
    - `SOURCE_LARISSAMA_TOKEN=...`
    - `SOURCE_LARISSAMA_TIMEOUT=2.0`
  - Mendaftarkan binding `CatalogSearchCoordinator` di Service Provider (`AppServiceProvider` atau `CatalogServiceProvider`) agar meng-instansiasi adapter sesuai konfigurasi aktif.
- **Kriteria Selesai:**
  - Sumber dapat dialihkan antara `mock` dan `http` hanya melalui perubahan file `.env`.
  - Jika driver diset ke `mock`, mock adapter lama (`MockFastSourceAdapter`, dll.) tetap berjalan mulus.
- **Status:** `Planned`

---

### Fase 3: Strict Timeout Enforcement & Resilience di UIH
- **Tujuan:** Menjamin keandalan proses agregasi katalog dari ancaman *thread blocking* dan *hang* pada request jaringan.
- **Ruang Lingkup:**
  - Menerapkan batas waktu ketat pada adapter HTTP:
    - `connectTimeout`: maksimal 1.0 detik.
    - `timeout`: maksimal 2.0 detik.
  - Menyelaraskan timeout adapter dengan batas waktu per-source (`perSourceTimeoutSeconds: 2.0`) dan global (`globalTimeoutSeconds: 5.0`) pada coordinator.
  - Memastikan isolasi kegagalan: jika satu adapter terkena timeout, event `source_failed` dipancarkan, dan iterasi langsung berlanjut ke sumber berikutnya.
- **Kriteria Selesai:**
  - Unit test membuktikan adapter yang lambat dipotong tepat pada batas waktu tanpa menahan thread melebihi batas toleransi.
  - Event `search_done` tetap dipancarkan dengan ringkasan status kegagalan yang akurat.
- **Status:** `Planned`

---

### Fase 4: Temuka Backend Routing & Correlation Tracing
- **Tujuan:** Mengoptimalkan Temuka Backend (`api.temuka`) sebagai proxy streaming yang andal dan memiliki kemampuan observabilitas.
- **Ruang Lingkup:**
  - Memverifikasi konfigurasi `UIH_SEARCH_TIMEOUT_SECONDS` pada Temuka Backend agar proporsional terhadap durasi agregasi UIH.
  - Menerapkan penerusan header pelacakan `X-Correlation-ID` dari Temuka Backend ke UIH.
  - Menjamin koneksi SSE ditutup secara bersih (*clean socket shutdown*) ketika stream selesai.
- **Kriteria Selesai:**
  - Request pencarian dari Temuka memiliki `X-Correlation-ID` yang dapat dilacak pada log UIH.
  - Stream SSE diteruskan ke frontend tanpa pemotongan buffer atau lag.
- **Status:** `Planned`

---

### Fase 5: Temuka Frontend Source Handling & Robustness
- **Tujuan:** Menjamin Temuka Frontend (`web.temuka`) siap menampilkan data sumber nyata secara dinamis tanpa ketergantungan pada kode mock.
- **Ruang Lingkup:**
  - Memastikan chip indikator dan badge kartu produk membaca `source.name` asli (contoh: *"Larissama F&B"* atau *"Larissama"*) secara dinamis.
  - Mempertahankan seluruh invarian pencarian yang telah diverifikasi:
    - Debounce 600 ms.
    - *Latest Search Wins* selama debounce maupun saat request sedang berjalan.
    - Reset seketika ke tampilan awal saat input dikosongkan.
- **Kriteria Selesai:**
  - Seluruh pengujian frontend (`node --test`, ESLint, build SSR) tetap berstatus PASS.
  - Tidak ada kode yang melakukan *hardcode* pengecekan string `'mock_larissama'`.
- **Status:** `Planned`

---

### Fase 6: Integrasi Real Adapter Larissama (End-to-End Test)
- **Tujuan:** Menghubungkan UIH ke endpoint nyata Larissama (`GET /api/v1/s2s/catalog/search`) dan memvalidasi pertukaran data katalog kuliner secara nyata.
- **Ruang Lingkup:**
  - Mengubah `SOURCE_LARISSAMA_DRIVER=http` pada environment UIH.
  - Memasukkan token autentikasi S2S resmi dari Larissama.
  - Menjalankan pencarian katalog menu kuliner nyata dari database Larissama.
  - Memverifikasi tampilan menu dan warung makan Larissama pada browser Temuka.
- **Kriteria Selesai:**
  - Data menu kuliner dari database Larissama tampil utuh di Temuka Web dengan gambar, harga integer rupiah, kategori, alamat warung, dan status ketersediaan.
  - Mock adapter Laundry dan Aset tetap berjalan berdampingan secara harmonis.
- **Ketergantungan / Status:**
  - `Blocked / Menunggu Teammate` — Menunggu developer Larissama menyelesaikan implementasi endpoint inbound S2S dan menyerahkan token autentikasi development.
- **Status:** `Blocked / Menunggu Teammate`

---

### Fase 7: Verifikasi Komprehensif, Observabilitas & Dokumentasi Operasional
- **Tujuan:** Pengujian stabilitas menyeluruh, audit keamanan, dan finalisasi dokumentasi operasional sebelum rilis.
- **Ruang Lingkup:**
  - Pengujian performa multi-source (Larissama HTTP nyata + Mock Laundry + Mock Aset).
  - Audit log untuk memastikan token S2S tidak bocor ke log stack trace (*credential masking*).
  - Pemutakhiran status dokumen arsitektur dan panduan *troubleshooting* operasional.
- **Kriteria Selesai:**
  - Rangkaian pengujian otomatis seluruh repositori (UIH, Temuka Backend, Temuka Frontend) lulus 100%.
  - Laporan verifikasi akhir disetujui.
- **Status:** `Planned`

---

## 4. Status Tracking

| ID Fase | Nama Fase | Target Repositori | Status | Keterangan |
| :---: | :--- | :--- | :---: | :--- |
| **Fase 0** | Docker Networking Baseline & Preflight | Seluruh Repositori | `Planned` | Persiapan bridge network bersama |
| **Fase 1** | Reusable Generic HTTP Adapter | `api.uih` | `Planned` | Fondasi class `HttpSourceAdapter` |
| **Fase 2** | Dynamic Source Registry & Config | `api.uih` | `Planned` | Konfigurasi `config/catalog.php` & driver switcher |
| **Fase 3** | Strict Timeout Enforcement | `api.uih` | `Planned` | Batas connect & request timeout HTTP |
| **Fase 4** | Temuka Backend Routing & Tracing | `api.temuka` | `Planned` | Tracing `X-Correlation-ID` & stream buffer |
| **Fase 5** | Temuka Frontend Robustness | `web.temuka` | `Planned` | Penanganan dinamis identitas sumber |
| **Fase 6** | Integrasi Real Adapter Larissama | `api.uih` $\leftrightarrow$ `api.larissama` | `Blocked / Menunggu Teammate` | Menunggu penyerahan endpoint dari tim Larissama |
| **Fase 7** | Verifikasi Akhir & Observabilitas | Seluruh Repositori | `Planned` | Pengujian performa, audit log, & closing |

---

## 5. Aturan Kerja Agent (Agent Working Rules)

Setiap AI coding agent maupun pengembang yang melanjutkan pekerjaan berdasarkan dokumen ini **WAJIB** mematuhi aturan berikut:

1. **DISCOVER $\rightarrow$ PLAN $\rightarrow$ IMPLEMENT $\rightarrow$ TEST $\rightarrow$ REVIEW $\rightarrow$ REPORT:**
   Ikuti siklus kerja resmi sesuai [`AGENT_WORKFLOW.md`](../engineering/AGENT_WORKFLOW.md).
2. **ISOLASI SCOPE REPOSITORY:**
   Bekerja hanya pada repositori yang secara eksplisit ditugaskan. Dilarang mengubah file di repositori sibling atau parent tanpa otorisasi.
3. **LARANGAN MENYENTUH LARISSAMA:**
   Repositori `/home/duro/projects/AIPrijects/larissama/api.larissama` **DILARANG KERAS DIUBAH**. Seluruh implementasi endpoint Larissama dikerjakan oleh developer Larissama.
4. **DOCKER ENVIRONMENT ONLY:**
   Seluruh perintah PHP, Artisan, Composer, PHPUnit, Pint, Node, dan Quasar **hanya boleh dijalankan di dalam container Docker**.
5. **PRESERVE TEST DATABASE SAFETY:**
   Pastikan mekanisme pengamanan test database (`TestDatabaseSafety`) tetap aktif; jangan pernah menjalankan migration pengujian ke database development atau production.
6. **NO SPECULATIVE CODING:**
   Jangan membuat asumsi atau mock palsu baru di luar kontrak kanonik v1.
7. **LOCAL COMMIT ONLY & NEVER PUSH:**
   Lakukan git commit lokal hanya setelah seluruh verifikasi (test suite, linting, build) berstatus PASS. **Dilarang melakukan git push** tanpa instruksi eksplisit dari pengguna.

---

## 6. Instruksi Melanjutkan Pekerjaan (How to Resume)

Ketika pengguna memberikan instruksi untuk memulai pekerjaan:

1. **Periksa Status Tracking:**
   Buka dokumen ini dan periksa fase berikutnya yang berstatus `Planned` dan tidak terblokir.
2. **Mulai dari Fase 0 atau Fase 1:**
   - Awali dengan **Fase 0** jika konfigurasi multi-container Docker belum dipersiapkan.
   - Lanjutkan ke **Fase 1** untuk membangun `HttpSourceAdapter` di UIH secara *test-driven* menggunakan `Http::fake()`.
3. **Kondisi Fase 6 (Larissama):**
   - Jangan menyentuh Fase 6 sebelum ada konfirmasi resmi bahwa endpoint Larissama `GET /api/v1/s2s/catalog/search` telah aktif dan siap diuji.
   - Jika Fase 1 s.d. 5 telah selesai tetapi endpoint Larissama belum siap, sistem tetap dapat beroperasi normal menggunakan driver `mock`.
4. **Perbarui Status Tracking:**
   Setelah suatu fase selesai dan diverifikasi, perbarui status fase tersebut pada dokumen ini menjadi `Completed`, lalu sertakan ringkasan hasil uji pada laporan tugas.
