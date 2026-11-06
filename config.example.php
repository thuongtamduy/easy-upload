<?php
/**
 * Easy Upload — config
 * Copy file này thành config.php và điền giá trị thật.
 * Mọi giá trị cũng có thể đặt qua biến môi trường cùng tên (config.php được ưu tiên).
 */

// Master API Key (admin) — BẮT BUỘC, chuỗi ngẫu nhiên >= 20 ký tự.
// Key bắt đầu bằng "CHANGE_ME" hoặc ngắn hơn 20 ký tự sẽ bị BỎ QUA (server từ chối mọi API quản trị).
// Generate: php -r "echo bin2hex(random_bytes(24));"
define('API_KEY', 'CHANGE_ME_TO_A_RANDOM_SECRET_KEY');

// CORS — danh sách domain được phép gọi API từ browser
// ['*'] = cho phép tất cả  |  ['https://yourdomain.com'] = chỉ domain cụ thể
define('ALLOWED_ORIGINS', ['*']);

// Rate limit (mỗi phút, mỗi IP)
define('RATE_LIMIT', 100);                 // tổng số request
define('AUTH_FAIL_LIMIT', 10);             // số lần sai API key
define('SHARE_PASSWORD_FAIL_LIMIT', 10);   // số lần sai mật khẩu share

// Reverse proxy tin cậy (IP hoặc CIDR). Chỉ khi request đến từ các IP này,
// header X-Forwarded-For / CF-Connecting-IP / X-Forwarded-Proto mới được tin.
// VD: ['127.0.0.1', '172.16.0.0/12'] hoặc danh sách IP của Cloudflare.
define('TRUSTED_PROXIES', []);

// URL công khai của dịch vụ (khuyến nghị đặt để tránh Host header injection)
// VD: 'https://files.example.com'
define('PUBLIC_BASE_URL', '');

// Thư mục lưu trữ (DB + file). Khuyến nghị đặt NGOÀI web root nếu hosting cho phép.
// define('STORAGE_DIR', '/home/user/easy-upload-storage');

// Giới hạn
define('MAX_UPLOAD_SIZE', 5 * 1024 ** 3);  // 5 GB / file
define('MAX_ZIP_FILES', 200);
define('MAX_ZIP_SIZE', 2 * 1024 ** 3);     // 2 GB / file zip
define('MAX_IMAGE_PIXELS', 40000000);      // ảnh lớn hơn 40MP sẽ không resize

// Mặc định file upload là private (chỉ truy cập qua share link, không qua /file/{id})
define('DEFAULT_PRIVATE', false);

// Telegram Webhooks — Bắn thông báo khi có file upload hoặc khi server lỗi
define('TG_BOT_TOKEN', '');
define('TG_CHAT_ID', '');
