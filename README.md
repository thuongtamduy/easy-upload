# 🚀 Easy Upload v3.0

**Easy Upload v3.0** là một hệ thống backend API quản lý và lưu trữ tệp tin (File Manager) siêu nhẹ, tốc độ cao, được thiết kế chuyên biệt cho cá nhân hoặc doanh nghiệp muốn tự host dịch vụ lưu trữ riêng. Xây dựng hoàn toàn bằng **PHP 8.4 hiện đại** và cơ sở dữ liệu **SQLite**, hệ thống không yêu cầu thiết lập máy chủ phức tạp.

---

## ✨ Tính Năng Nổi Bật

- 🛡️ **Bảo mật Tối đa:** Vá toàn bộ các lỗ hổng RCE, XSS, Path Traversal, DOS và Zip Slip. Hệ thống phân quyền API Keys đa lớp (Admin/User).
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

2. *(Tuỳ chọn)* Cấu hình bảo mật:
   Copy file `config.example.php` thành `config.php` và điền `API_KEY` (Master Key) cùng thông tin Telegram Webhooks của bạn.

3. Khởi động hệ thống:
   ```bash
   docker compose up -d --build
   ```

Server của bạn sẽ chạy tại địa chỉ: `http://localhost:8080`. Toàn bộ dữ liệu sẽ được lưu trữ an toàn tại thư mục `./storage/` trên máy tính thật của bạn.

---

## 📚 Tài Liệu API (API Documentation)

Hầu hết các thao tác ghi/đọc dữ liệu quản trị đều yêu cầu xác thực bằng Header:
`X-Api-Key: YOUR_SECRET_KEY`

### 1. Upload File (`POST /?action=upload`)
- **Body (form-data):** Truyền mảng các file qua key `files[]`.
- **Tuỳ chọn:**
  - `expires_in` (int): Số giây file tự động xoá (VD: `3600` = 1 tiếng).
  - `folder` (string): Tên thư mục ảo để phân loại.
- Hỗ trợ Chunked Upload từ các thư viện Dropzone.js hoặc Resumable.js.

### 2. Danh sách File (`GET /?action=list`)
- **Tuỳ chọn Query:** 
  - `q`: Tìm kiếm tên file.
  - `folder`: Lọc theo thư mục.
  - `page`: Trang hiện tại (mặc định 20 file/trang).

### 3. Tạo Link Chia Sẻ (`POST /?action=share&id={public_id}`)
- **Body (JSON / Form):**
  - `expires_in` (int): Thời gian tự hủy (giây).
  - `max_views` (int): Số lần tải tối đa trước khi link tự hủy (Burn After Reading).
  - `password` (string): Đặt mật khẩu bảo vệ link.

### 4. Quản lý Thư mục (`GET /?action=folders`)
- Trả về danh sách tất cả các thư mục ảo kèm theo số lượng file và tổng dung lượng lưu trữ.

### 5. Dọn rác & Bảo trì (`GET /?action=maintenance`)
- Quét và xoá vật lý các file/link đã hết hạn. Dọn dẹp các mảnh file rác bị treo do người dùng huỷ upload giữa chừng. *(Nên thiết lập Cronjob gọi API này mỗi giờ)*.

### 6. Quản lý API Keys (`GET/POST /?action=api_keys`) - *Chỉ Admin*
- Hỗ trợ xem danh sách (`sub=list`), tạo mới key (`sub=create`, gửi lên `name`, `role=admin|user`), đổi trạng thái (`sub=toggle`) hoặc xóa (`sub=delete`).

---

## 🌍 Các Endpoint Công Khai (Public Endpoints)

Đây là các endpoint mà Trình duyệt hoặc Người lạ có thể truy cập mà không cần API Key:

- **Xem File Trực Tiếp:** `GET /file/{public_id}`
  - Hỗ trợ params resize: `?w=200` (chiều rộng 200px), `?h=200` (chiều cao), `?s=200` (crop hình vuông).
  - Tự động serve định dạng WebP nếu trình duyệt hỗ trợ.
  - Hỗ trợ tính năng phát luồng Video mượt mà (HTTP Range Request).

- **Tải File Chia Sẻ:** `GET /share/{token}`
  - Nếu link có mật khẩu, truyền thêm `?password=YOUR_PASS` hoặc truyền Header `X-Share-Password`.

---

## 🛡️ License & Copyright

**Easy Upload v3.0** được phát hành dưới giấy phép **MIT License**.

© 2026 Bản quyền thuộc về bạn (Người đã phát triển và nâng cấp dự án).
Bạn hoàn toàn có quyền sử dụng, sao chép, sửa đổi, hợp nhất, xuất bản, phân phối và bán các bản sao của Phần mềm mà không gặp bất kỳ rào cản pháp lý nào. 

> *Sản phẩm được tối ưu hóa kiến trúc, thiết kế API, bảo mật và Dockerization bởi DeepMind Antigravity AI Assistant.*
