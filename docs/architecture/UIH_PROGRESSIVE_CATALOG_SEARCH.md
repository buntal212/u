# UIH Progressive Catalog Search Specification

**Status:** Implementation Baseline v1.0  
**Tujuan:** Spesifikasi teknis arsitektur pengiriman pencarian katalog progresif (*progressive catalog search*) dari UIH ke Temuka Backend.

## 1. Arsitektur Komponen

```text
Temuka Backend (S2S Client)
         │
         │ POST /api/v1/catalog/search (Accept: text/event-stream)
         ▼
UIH CatalogSearchController
         │
         ▼
CatalogSearchCoordinator
         │
         ├───────────────────┬───────────────────┐
         ▼                   ▼                   ▼
MockFastSourceAdapter  MockSlowSourceAdapter  MockFlakySourceAdapter
 (mock_larissama)       (mock_laundry)         (mock_aset)
```

## 2. Kontrak Endpoint

- **Metode:** `POST`
- **Path:** `/api/v1/catalog/search`
- **Header:**
  - `Accept: text/event-stream`
  - `Content-Type: application/json`
  - `Authorization: Bearer <UIH_SERVER_TOKEN>`
- **Request Body:**
  ```json
  {
    "search_id": "search_1730700000_abcde",
    "query": "nasi goreng",
    "limit": 20
  }
  ```

## 3. Format Event SSE

Setiap event dikirim dalam format:
```text
event: <nama_event>
data: <json_string>

```

### Daftar Event:
1. `search_init`:
   ```json
   {
     "search_id": "search_1730700000_abcde",
     "targets": ["mock_larissama", "mock_laundry", "mock_aset"]
   }
   ```
2. `source_result`:
   ```json
   {
     "search_id": "search_1730700000_abcde",
     "source": "mock_larissama",
     "status": "completed",
     "items_count": 2,
     "data": [
       {
         "source": { "code": "mock_larissama" },
         "offer": {
           "id": "larissama-1",
           "name": "Nasi Goreng Spesial",
           "type": "product",
           "summary": "Nasi goreng spesial dengan telur dan ayam suwir"
         },
         "provider": {
           "id": "tenant-01",
           "name": "Warung Bu Rina"
         },
         "image": "https://images.unsplash.com/photo-1512058564366-18510be2db19?w=600&auto=format&fit=crop&q=80",
         "contact": {
           "phone": "+6281234567890",
           "whatsapp": "+6281234567890",
           "email": null
         },
         "location": {
           "address": "Jl. Kaliurang KM 5, Sleman",
           "latitude": -7.7654,
           "longitude": 110.3812,
           "type": "outlet"
         },
         "availability": "available",
         "operational_status": "open"
       }
     ]
   }
   ```
3. `source_failed`:
   ```json
   {
     "search_id": "search_1730700000_abcde",
     "source": "mock_aset",
     "status": "failed",
     "error_code": "SOURCE_TIMEOUT",
     "message": "Aplikasi sumber tidak merespons dalam batas waktu."
   }
   ```
4. `search_done`:
   ```json
   {
     "search_id": "search_1730700000_abcde",
     "total_items": 3,
     "summary": {
       "mock_larissama": "completed",
       "mock_laundry": "completed",
       "mock_aset": "failed"
     }
   }
   ```

## 4. Cara Menambahkan Adapter Sumber Baru

1. Buat class baru yang mengimplementasikan `App\Contracts\Catalog\SourceAdapterInterface`.
2. Implementasikan method `getSourceCode()`, `getSourceName()`, dan `search(string $query, int $limit): array`.
3. Daftarkan class adapter tersebut pada `CatalogSearchCoordinator` (atau melalui Service Provider container tagging).
