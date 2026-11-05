# Kontrak Katalog Kanonik UIH v1

**Status:** Draft untuk persetujuan arsitektur  
**Ruang lingkup:** Katalog publik dari Aplikasi Sumber Udumbara (ASU) ke UIH, lalu ditampilkan oleh Temuka  
**Tidak termasuk:** Implementasi API Larissama, transaksi/pemesanan aktif, penyesuaian harga UIH/Temuka, dan data yang memerlukan login

## 1. Tujuan

UIH menyediakan satu struktur data katalog yang konsisten untuk seluruh ASU. Temuka tidak perlu memahami struktur database atau format API masing-masing ASU.

Alur:

`Temuka → Temuka Backend → UIH → Adapter ASU → API ASU`

UIH berkomunikasi dengan ASU melalui HTTP API. UIH tidak mengakses database ASU secara langsung.

## 2. Prinsip Kontrak

1. **Satu struktur kanonik** untuk seluruh ASU.
2. Field kanonik yang tidak tersedia pada ASU dikirim sebagai `null`, bukan dihilangkan atau diganti dengan struktur berbeda.
3. Data katalog hanya mencakup informasi yang boleh ditampilkan secara publik di Temuka.
4. Harga yang diterima dari ASU adalah **harga jual publik**, bukan HPP atau harga modal.
5. Identitas sumber, provider, dan item harus cukup untuk merutekan permintaan ke ASU yang tepat.
6. UIH menjadi lapisan normalisasi; Temuka tidak bergantung pada format respons asli ASU.
7. Kontrak ini tidak mengaktifkan transaksi atau perubahan harga.

## 3. Status Keputusan

### Disepakati

| Keputusan | Ketetapan |
|---|---|
| Kode ASU | `source.code`, contoh `larissama` |
| Identitas provider Larissama | `warungs.id` |
| Identitas item Larissama | `menus.id` |
| Kode bisnis | Boleh disertakan sebagai field tambahan; bukan identitas utama |
| Harga | Harga jual publik dari ASU |
| HPP/harga modal | Tidak boleh dikirim ke UIH |
| Data privat | Tidak masuk katalog publik |
| Field tidak tersedia | Tetap ada dengan nilai `null` |
| Summary | Boleh `null` atau kosong |
| Transaksi | Belum menjadi bagian implementasi v1 |

### Usulan

| Keputusan | Usulan |
|---|---|
| Harga kanonik | Objek `{ amount, currency }` |
| Mata uang | ISO 4217, untuk Larissama `IDR` |
| ID provider/item | String pada kontrak kanonik, walaupun sumber menggunakan integer |
| Availability | Enum `available` atau `unavailable` |
| Category | String nullable, untuk kebutuhan tampilan/filter Temuka |
| Source identity | Diletakkan di root respons dan tetap tersedia pada setiap hasil |
| Image | Objek nullable agar URL dan metadata gambar dapat dikembangkan tanpa mengubah tipe field |

### Belum diputuskan

- Bentuk final endpoint S2S Larissama.
- Apakah UIH melakukan caching katalog dan berapa lama.
- Apakah `category` hanya nama kategori atau nantinya memiliki ID/code kanonik.
- Apakah v1 membutuhkan pagination, selain batas jumlah hasil.
- Kebijakan dan mekanisme perubahan harga di UIH/Temuka pada versi mendatang.

## 4. Struktur Kanonik

```json
{
  "source": {
    "code": "larissama",
    "name": "Larissama"
  },
  "data": [
    {
      "id": "101",
      "code": "MKN-001",
      "name": "Nasi Goreng",
      "category": "Makanan",
      "summary": null,
      "price": {
        "amount": 25000,
        "currency": "IDR"
      },
      "image": null,
      "availability": "available",
      "provider": {
        "id": "10",
        "code": "WRG-001",
        "name": "Warung Contoh",
        "image": null,
        "contact": {
          "phone": null,
          "whatsapp": null
        },
        "location": {
          "address": null,
          "latitude": null,
          "longitude": null
        }
      }
    }
  ],
  "meta": {
    "total": 1
  }
}
```

Contoh di atas adalah ilustrasi struktur, bukan data nyata Larissama.

## 5. Definisi Field

### 5.1 Root

| Field | Tipe | Wajib | Keterangan |
|---|---|---:|---|
| `source` | object | Ya | Identitas ASU asal data |
| `source.code` | string | Ya | Kode stabil ASU, contoh `larissama` |
| `source.name` | string | Ya | Nama tampilan ASU |
| `data` | array | Ya | Daftar item katalog |
| `meta` | object | Ya | Metadata respons |
| `meta.total` | integer | Ya | Jumlah item pada respons ini |

### 5.2 Item

| Field | Tipe | Wajib | Keterangan |
|---|---|---:|---|
| `id` | string | Ya | ID item unik dalam ASU |
| `code` | string/null | Ya | Kode bisnis item jika tersedia |
| `name` | string | Ya | Nama publik item |
| `category` | string/null | Ya | Nama kategori publik |
| `summary` | string/null | Ya | Ringkasan/deskripsi singkat |
| `price` | object | Ya | Harga jual publik |
| `price.amount` | number | Ya | Nilai numerik, bukan string berformat |
| `price.currency` | string | Ya | Kode mata uang ISO 4217 |
| `image` | object/null | Ya | Informasi gambar item |
| `availability` | enum | Ya | `available` atau `unavailable` |
| `provider` | object | Ya | Pemilik/penyedia item |

### 5.3 Image

Jika tersedia:

```json
{
  "url": "https://example.com/image.jpg"
}
```

Jika tidak tersedia:

```json
"image": null
```

UIH dan Temuka tidak boleh mengganti gambar item dengan logo provider secara otomatis. Gambar provider memiliki field tersendiri.

### 5.4 Provider

| Field | Tipe | Wajib | Keterangan |
|---|---|---:|---|
| `id` | string | Ya | ID provider dalam ASU |
| `code` | string/null | Ya | Kode bisnis provider |
| `name` | string | Ya | Nama publik provider |
| `image` | object/null | Ya | Logo/foto provider |
| `contact` | object | Ya | Informasi kontak publik |
| `contact.phone` | string/null | Ya | Nomor telepon publik |
| `contact.whatsapp` | string/null | Ya | Nomor WhatsApp jika tersedia dan disetujui untuk publik |
| `location` | object | Ya | Informasi lokasi publik |
| `location.address` | string/null | Ya | Alamat |
| `location.latitude` | number/null | Ya | Koordinat lintang |
| `location.longitude` | number/null | Ya | Koordinat bujur |

## 6. Identitas dan Routing

Identitas kanonik menggunakan kombinasi:

```json
{
  "source": "larissama",
  "provider_id": "10",
  "item_id": "101"
}
```

Ketentuan:

- `source` menentukan ASU tujuan.
- `provider_id` mengidentifikasi provider di dalam ASU tersebut.
- `item_id` mengidentifikasi item di dalam ASU tersebut.
- UIH menentukan adapter dan alamat ASU melalui registry internal.
- Temuka tidak boleh mengirim URL tujuan secara bebas untuk menentukan ASU.
- ID numerik dari database ASU dikonversi menjadi string pada kontrak kanonik.
- `menus.kode` tidak digunakan sebagai identitas utama karena hanya unik per-warung.

Tuple ini merupakan **kesiapan identitas/routing**, bukan kontrak transaksi final.

## 7. Aturan Availability

`availability` menggunakan nilai:

- `available`: item boleh ditampilkan sebagai tersedia untuk publik.
- `unavailable`: item dikenal, tetapi saat ini tidak tersedia.

Untuk Larissama, item hanya boleh berstatus `available` jika seluruh syarat publik terpenuhi:

- Warung aktif.
- Pendaftaran warung disetujui.
- Langganan warung berstatus aktif.
- Menu aktif.
- Kategori menu aktif.

Aturan ini merupakan mapping awal Larissama dan tidak boleh diasumsikan identik untuk semua ASU. Setiap ASU bertanggung jawab menentukan availability sesuai aturan bisnisnya.

## 8. Privasi dan Data yang Dilarang

Katalog publik tidak boleh mengandung:

- HPP atau `harga_modal`.
- Email atau data akun pengguna.
- Data transaksi penjualan.
- Data pembelian/modal.
- Data stok internal atau koreksi stok.
- Token autentikasi dan data sinkronisasi internal.
- Informasi langganan internal yang tidak ditujukan untuk publik.
- Data lain yang membutuhkan login dan tidak diperlukan oleh Temuka.

Jika suatu field publik belum tersedia pada ASU, gunakan `null`.

## 9. Harga dan Perubahan Harga Mendatang

Pada v1:

- ASU mengirim **harga jual publik**.
- UIH menormalisasi harga menjadi `price.amount` dan `price.currency`.
- UIH tidak menghitung atau mengubah harga.
- Temuka menampilkan harga hasil kanonisasi.
- Tidak ada mekanisme markup, diskon, komisi, atau override harga dalam kontrak v1.

Struktur ini tidak menutup kemungkinan adanya kebijakan harga UIH/Temuka di masa depan. Jika fitur tersebut disetujui, aturan sumber harga, harga tampil, audit, dan tanggung jawab perubahan harus dirancang sebagai keputusan terpisah.

## 10. Mapping Awal Larissama

| Field kanonik | Sumber Larissama | Status |
|---|---|---|
| `source.code` | Registry UIH: `larissama` | Tersedia melalui konfigurasi |
| `source.name` | Nama aplikasi | Tersedia |
| `id` | `menus.id` | Tersedia |
| `code` | `menus.kode` | Tersedia; unik per-warung |
| `name` | `menus.nama` | Tersedia |
| `category` | Relasi `kategori_menus` | Tersedia jika kategori aktif |
| `summary` | `menus.deskripsi` | Nullable |
| `price.amount` | `menus.harga` | Harga jual |
| `price.currency` | Konstanta/konfigurasi Larissama | Usulan `IDR` |
| `image` | `menus.gambar` | Umumnya nullable |
| `availability` | Status menu, kategori, warung, dan langganan | Perlu dihitung oleh Larissama |
| `provider.id` | `warungs.id` | Tersedia |
| `provider.code` | `warungs.kode` | Tersedia |
| `provider.name` | `warungs.nama` | Tersedia |
| `provider.image` | Logo warung | Nullable |
| `provider.contact.phone` | `warungs.telepon` | Nullable |
| `provider.contact.whatsapp` | Belum ada field terpisah | Null sampai kebijakan disepakati |
| `provider.location.address` | `warungs.alamat` | Nullable |
| `provider.location.latitude` | Belum tersedia | Null |
| `provider.location.longitude` | Belum tersedia | Null |

## 11. Keputusan yang Dibutuhkan Sebelum Implementasi

1. Setujui struktur kanonik `source`, `data`, `meta`, `item`, dan `provider`.
2. Setujui `price.amount` sebagai angka rupiah dan `price.currency` sebagai `IDR`.
3. Setujui apakah `category` wajib secara struktur tetapi nullable pada data.
4. Tetapkan format final `image`.
5. Tetapkan kebutuhan pagination dan batas hasil.
6. Tetapkan kebijakan caching UIH.
7. Setelah kontrak disetujui, buat implementasi UIH dan Larissama secara terpisah sesuai batas repository masing-masing.

**Status akhir:** Draft v1. Belum menjadi kontrak implementasi final sampai keputusan pada bagian 11 disetujui.
