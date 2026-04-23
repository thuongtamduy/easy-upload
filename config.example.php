<?php
/**
 * Easy Upload — config
 * Copy file này thành config.php và điền giá trị thật
 */

// API Key — dùng để xác thực write operations (upload, delete, share...)
// Generate: php -r "echo bin2hex(random_bytes(24));"
define('API_KEY', 'CHANGE_ME_TO_A_RANDOM_SECRET_KEY');

// CORS — danh sách domain được phép gọi API từ browser
// ['*'] = cho phép tất cả  |  ['https://yourdomain.com'] = chỉ domain cụ thể
define('ALLOWED_ORIGINS', ['*']);

// Rate limit — số request tối đa mỗi phút mỗi IP
define('RATE_LIMIT', 100);
