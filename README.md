# 🚀 Easy Upload v3.0

**Easy Upload v3.0** là một hệ thống backend API quản lý và lưu trữ tệp tin (File Manager) siêu nhẹ, tốc độ cao, được thiết kế chuyên biệt cho cá nhân hoặc doanh nghiệp muốn tự host dịch vụ lưu trữ riêng. Xây dựng hoàn toàn bằng **PHP 8.4 hiện đại** và cơ sở dữ liệu **SQLite**, hệ thống không yêu cầu thiết lập máy chủ phức tạp.

---

## ✨ Tính Năng Nổi Bật

- 🛡️ **Bảo mật:** Fail-closed (không cấu hình key → từ chối mọi API quản trị), storage không truy cập trực tiếp, API key & mật khẩu share lưu dạng hash, chống brute-force, chống XSS khi phục vụ file, phân quyền Admin/User theo chủ sở hữu file. Xem mục [Bảo mật](#-bảo-mật).
- 🧩 **Upload Chunking & Deduplication:** Hỗ trợ tải lên file siêu lớn (lên đến hàng chục GB) thông qua cơ chế chia nhỏ (chunk). Hệ thống tự động phát hiện file trùng lặp (Deduplication) để tiết kiệm ổ cứng.
- 🖼️ **On-the-fly Image Processing:** Tự động chuyển đổi ảnh sang **WebP** để tiết kiệm băng thông. Cung cấp API thay đổi kích thước ảnh thời gian thực (real-time resize).
- 🔥 **Burn After Reading:** Tính năng tạo link chia sẻ "tự huỷ sau N lượt tải" hoặc "tự huỷ sau N ngày".
- 📁 **Thư mục Ảo (Virtual Folders):** Tổ chức file theo thư mục ảo một cách trực quan.
- 🤖 **Telegram Webhooks:** Tự động báo cáo mỗi khi có tệp được tải lên thành công, hoặc cảnh báo ngay lập tức nếu máy chủ có lỗi (Exception).

---

## 🛠 Cài Đặt Dễ Dàng (Docker)

Cách tốt nhất và an toàn nhất để chạy Easy Upload là sử dụng **Docker Compose**. Chúng tôi đã chuẩn bị sẵn mọi thứ!

1. Clone mã nguồn về máy:
   ```bash
   git clone https://github.com/your-username/easy-upload.git
   cd easy-upload
   ```

2. **(Bắt buộc)** Tạo Master API Key trong file `.env` (đã nằm trong `.gitignore`):
   ```bash
   echo "API_KEY=$(openssl rand -hex 24)" > .env
   ```
   Hoặc copy `config.example.php` thành `config.php` và điền `API_KEY` (cùng các tuỳ chọn khác như Telegram, `TRUSTED_PROXIES`, `PUBLIC_BASE_URL`).
   Key phải dài ít nhất 20 ký tự và không được bắt đầu bằng `CHANGE_ME`. Nếu không có key hợp lệ, server trả `503` cho mọi API quản trị.

3. Khởi động hệ thống:
   ```bash
   docker compose up -d --build
   ```

Server của bạn sẽ chạy tại địa chỉ: `http://localhost:8080`. Toàn bộ dữ liệu sẽ được lưu trữ an toàn tại thư mục `./storage/` trên máy tính thật của bạn.

---

## 📚 Tài Liệu API (API Documentation)

Mọi thao tác quản trị đều yêu cầu xác thực bằng Header (không nhận key qua URL):
`X-Api-Key: YOUR_SECRET_KEY`

Key role `user` chỉ xem/chia sẻ/tải được file do chính key đó upload. Key `admin` (và Master Key) thấy toàn bộ.

### 1. Upload File (`POST /?action=upload`)
- **Body (form-data):** Truyền mảng các file qua key `files[]`.
- **Tuỳ chọn:**
  - `expires_in` (int): Số giây file tự động xoá (VD: `3600` = 1 tiếng).
  - `folder` (string): Tên thư mục ảo để phân loại.
  - `private` (0/1): File private không truy cập được qua `/file/{id}`, chỉ qua share link (để mật khẩu/hạn dùng/burn-after-reading thực sự có hiệu lực).
- Hỗ trợ Chunked Upload từ Dropzone.js hoặc Resumable.js (bắt buộc gửi kèm `dztotalfilesize` / `resumableTotalSize`).
- `raw_url` trong response đã **deprecated** (trỏ về `/file/{id}`) vì thư mục `storage/` không còn truy cập trực tiếp.

### 2. Danh sách File (`GET /?action=list`)
- **Tuỳ chọn Query:** 
  - `q`: Tìm kiếm tên file.
  - `folder`: Lọc theo thư mục.
  - `page`: Trang hiện tại (mặc định 20 file/trang).

### 3. Tạo Link Chia Sẻ (`POST /?action=share&id={public_id}`)
- **Body (JSON / Form):**
  - `expires_in` (int): Thời gian tự hủy (giây).
  - `max_views` (int): Số lần truy cập tối đa trước khi link tự hủy (Burn After Reading). Mỗi request (kể cả request Range khi xem video) đều được tính.
  - `password` (string): Đặt mật khẩu bảo vệ link (chỉ nhận trong body, được lưu dạng hash).

### 4. Quản lý Thư mục (`GET /?action=folders`)
- Trả về danh sách tất cả các thư mục ảo kèm theo số lượng file và tổng dung lượng lưu trữ.

### 5. Dọn rác & Bảo trì (`GET /?action=maintenance`)
- Quét và xoá vật lý các file/link đã hết hạn. Dọn dẹp các mảnh file rác bị treo do người dùng huỷ upload giữa chừng. *(Nên thiết lập Cronjob gọi API này mỗi giờ)*:
  ```bash
  curl -s -H "X-Api-Key: $API_KEY" "https://your-domain/?action=maintenance"
  ```

### 6. Quản lý API Keys (`GET/POST /?action=api_keys`) - *Chỉ Admin*
- Hỗ trợ xem danh sách (`sub=list`), tạo mới key (`POST sub=create`, gửi lên `name`, `role=admin|user`), đổi trạng thái (`POST sub=toggle`) hoặc xóa (`POST sub=delete`).
- Key chỉ hiển thị **một lần** khi tạo; DB chỉ lưu SHA-256 của key.

---

## 🌍 Các Endpoint Công Khai (Public Endpoints)

Đây là các endpoint mà Trình duyệt hoặc Người lạ có thể truy cập mà không cần API Key:

- **Xem File Trực Tiếp:** `GET /file/{public_id}` *(chỉ file không private và chưa hết hạn)*
  - Hỗ trợ params resize: `?w=200` (chiều rộng 200px), `?h=200` (chiều cao), `?s=200` (crop hình vuông).
  - Tự động serve định dạng WebP nếu trình duyệt hỗ trợ.
  - Hỗ trợ tính năng phát luồng Video mượt mà (HTTP Range Request).

- **Tải File Chia Sẻ:** `GET /share/{token}`
  - Nếu link có mật khẩu: gửi Header `X-Share-Password` hoặc `POST` field `password`. Mở bằng trình duyệt sẽ hiện form nhập mật khẩu. *(Không còn nhận `?password=` trên URL để tránh lộ mật khẩu trong log.)*
  - Sai mật khẩu quá `SHARE_PASSWORD_FAIL_LIMIT` lần/phút → `429`.

---

## 🔐 Bảo Mật

- **Fail-closed:** không có `API_KEY` hợp lệ và chưa có key nào trong DB → mọi API quản trị trả `503`.
- **Storage bị chặn hoàn toàn:** `storage/` (DB, file WAL/SHM, file upload, cache) không truy cập trực tiếp được; mọi file đi qua `/file/` hoặc `/share/`. Nếu hosting cho phép, nên đặt `STORAGE_DIR` ra ngoài web root.
- **Chống XSS:** chỉ ảnh raster, video, audio, PDF được hiển thị inline; mọi loại khác (SVG, HTML...) luôn là file tải về, kèm `X-Content-Type-Options: nosniff` và CSP `sandbox`.
- **Rate limit chống giả mạo IP:** header `X-Forwarded-For` / `CF-Connecting-IP` chỉ được tin khi request đến từ `TRUSTED_PROXIES`. Có giới hạn riêng cho sai API key và sai mật khẩu share.
- **Bí mật được hash:** API key (SHA-256), mật khẩu share (bcrypt). Dữ liệu cũ dạng plaintext được tự động chuyển đổi.
- **Xử lý ảnh an toàn:** chỉ resize PNG/JPEG/GIF/WebP với decoder chỉ định rõ, giới hạn bộ nhớ và số pixel (`MAX_IMAGE_PIXELS`), xoá EXIF/GPS khỏi thumbnail.
- **Giới hạn tài nguyên:** `MAX_UPLOAD_SIZE`, `MAX_ZIP_FILES`, `MAX_ZIP_SIZE`; chunk upload được kiểm tra offset/kích thước và gắn với key người upload.

> ⚠️ **Thay đổi không tương thích ngược (v3.1):** link trực tiếp `storage/uploads/...` không còn hoạt động (dùng `/file/{id}`); `?api_key=` và `?password=` trên URL không còn được chấp nhận; key role `user` chỉ thấy file của chính mình.

---

## 🛡️ License & Copyright

**Easy Upload v3.0** được phát hành dưới giấy phép **MIT License**.

© 2026 Bản quyền thuộc về bạn (Người đã phát triển và nâng cấp dự án).
Bạn hoàn toàn có quyền sử dụng, sao chép, sửa đổi, hợp nhất, xuất bản, phân phối và bán các bản sao của Phần mềm mà không gặp bất kỳ rào cản pháp lý nào. 

> *Sản phẩm được tối ưu hóa kiến trúc, thiết kế API, bảo mật và Dockerization bởi DeepMind Antigravity AI Assistant.*
