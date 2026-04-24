FROM php:8.4-apache


# Kích hoạt Apache mod_rewrite
RUN a2enmod rewrite

# Cài đặt công cụ hỗ trợ cài đặt PHP Extension hiện đại siêu chuẩn (mlocati)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Tự động cài đặt & cấu hình SQLite, ZIP, Imagick chỉ với 1 dòng
# (Công cụ này tự xử lý luôn apt-get, libzip, libmagickwand, và PECL)
RUN install-php-extensions zip pdo_sqlite imagick

# Ghi đè cấu hình PHP để hỗ trợ Upload File lớn
RUN echo "upload_max_filesize = 5G\n\
post_max_size = 5G\n\
memory_limit = 512M\n\
max_execution_time = 600\n\
max_input_time = 600" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# Copy mã nguồn vào container
COPY . /var/www/html/

# Phân quyền cho thư mục storage để Apache (www-data) có thể đọc/ghi
RUN mkdir -p storage && chown -R www-data:www-data storage && chmod -R 775 storage
