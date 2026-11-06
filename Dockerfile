FROM php:8.4-apache


# Kích hoạt Apache mod_rewrite
RUN a2enmod rewrite

# Cài đặt công cụ hỗ trợ cài đặt PHP Extension hiện đại siêu chuẩn (mlocati)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Tự động cài đặt & cấu hình SQLite, ZIP, Imagick chỉ với 1 dòng
# (Công cụ này tự xử lý luôn apt-get, libzip, libmagickwand, và PECL)
RUN install-php-extensions zip pdo_sqlite imagick

# Ghi đè cấu hình PHP: hỗ trợ upload file lớn + hardening (không hiển thị lỗi, ẩn phiên bản PHP)
RUN printf '%s\n' \
    'upload_max_filesize = 5G' \
    'post_max_size = 5G' \
    'memory_limit = 512M' \
    'max_execution_time = 600' \
    'max_input_time = 600' \
    'display_errors = Off' \
    'display_startup_errors = Off' \
    'log_errors = On' \
    'expose_php = Off' \
    > /usr/local/etc/php/conf.d/uploads.ini

# Ẩn thông tin phiên bản Apache
RUN printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' > /etc/apache2/conf-enabled/zz-hardening.conf

WORKDIR /var/www/html

# Copy mã nguồn vào container (.git, config.php, storage... bị loại qua .dockerignore)
COPY . /var/www/html/

# Phân quyền cho thư mục storage để Apache (www-data) có thể đọc/ghi
RUN mkdir -p storage && chown -R www-data:www-data storage && chmod -R 750 storage
