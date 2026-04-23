# Easy Upload

Single-file PHP file storage API. Upload → nhận CDN URL → dùng trực tiếp.

**Base URL:** `https://example.com`  
**Test:** Import `docs/Easy-Upload.postman_collection.json` vào Postman.

---

## Bắt đầu (Bảo mật)

1. Copy file `config.example.php` thành `config.php`.
2. Tạo 1 random `API_KEY` mới trong file config:
   - Các hành động ghi (Upload, Delete, Share) **BẮT BUỘC** truyền header `X-Api-Key: {API_KEY}` (hoặc query `?api_key=`).
   - Các đường link đọc (`/file/{id}`, `/share/{token}`) **VẪN PUBLIC** để end-user dùng bình thường.
3. Cấu hình `ALLOWED_ORIGINS` nếu muốn giới hạn domain gọi API từ Web Browser (CORS).
4. `RATE_LIMIT` được tích hợp sẵn bằng IP để chống scraping.

---

## Endpoints

| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | `/?action=stats` | Thống kê |
| GET | `/?action=maintenance` | Dọn dẹp cache, db, rác (Cron) |
| GET | `/?action=list` | Danh sách file |
| POST | `/?action=upload` | Upload file |
| GET | `/?action=delete&id={id}` | Xóa file |
| POST | `/?action=bulk_delete` | Xóa nhiều file |
| GET/POST | `/?action=zip&ids=id1,id2` | Tải một lúc nhiều file (Zipped) |
| GET | `/?action=share&id={id}` | Tạo share link |
| GET | `/?action=shares&id={id}` | Danh sách share của file |
| GET | `/?action=revoke&token={token}` | Hủy share |
| GET | `/file/{token}` | Truy cập file qua CDN URL |
| GET | `/share/{token}` | Truy cập file qua Share Link |


---

## Upload

```http
POST /?action=upload
Content-Type: multipart/form-data

files[]: <file>
```

Response trả về `url` là CDN URL sẵn dùng ngay (share link vĩnh viễn tự tạo):

```json
{
  "count": 1,
  "uploaded": [{
    "id": "A2ytaqJa9Xk",
    "original_name": "photo.jpg",
    "url": "https://example.com/file/ce52ba3bb1b6f5d4",
    "raw_url": "storage/uploads/2026/04/01/abc_123.jpg",
    "size_fmt": "200.0 KB"
  }]
}
```

> **Tích hợp Chunked Upload (Tải file lớn gigabytes. Hệ thống tự nhận diện tự động):**
> Hỗ trợ nguyên bản cho Resumable.js, Dropzone.js, Uppy. 
> Chỉ cần truyền thêm mảng payload chuẩn: `dztotalchunkcount` (để biết là chunk), `dzchunkindex` (thứ tự), `dzchunksize` hoặc `resumableTotalChunks`, `resumableChunkNumber`... Hệ thống sẽ gộp file tự động!


---

## CDN URL

```
/file/{token}                 → serve file gốc
/file/{token}?w=800           → resize width 800px
/file/{token}?s=300           → crop vuông 300×300
/file/{token}?password=secret → file có mật khẩu
```

Hỗ trợ: HTTP Range (video seek), inline preview (ảnh/video/PDF), image resize + cache.  
Kích thước resize hợp lệ: `100 150 200 300 400 500 600 800 1000 1200`

---

## Share Link

```http
GET /?action=share&id={id}&expires=3600&password=secret
```

- `expires`: giây. `0` = vĩnh viễn
- `password`: tuỳ chọn

---

## List

```http
GET /?action=list&page=1&q=photo&from=2026-01-01&to=2026-12-31
```

---

## Yêu cầu

- PHP ≥ 8.1, extension `pdo_sqlite`, `imagick`, `zip`
- Apache + mod_rewrite

---

## Tự Động Dọn Rác (Garbage Collector / Maintenance)

Hệ thống có một cơ chế tự kiểm tra để dọn dẹp các cache, links quá hạn và tối ưu dung lượng DB.
Để tự động chạy, bạn có thể thiết lập Cronjob trên VPS/Server gọi API `/?action=maintenance` định kỳ (vd: mỗi ngày vào lúc 3h sáng):

```bash
0 3 * * * curl -s -H "X-Api-Key: YOUR_API_KEY_HERE" "https://your_domain.com/?action=maintenance" > /dev/null
```
