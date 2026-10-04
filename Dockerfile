# ใช้ Image PHP 8.2 พร้อม Apache (อัปเกรดเพื่อรองรับ Web Push)
FROM php:8.2-apache

# 1. ติดตั้ง Extension สำหรับ MySQL, Curl และ GD Library (สำหรับประมวลผลรูปภาพ)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. เปิดใช้งาน mod_rewrite และตั้งค่า Apache อนุญาตให้ใช้ .htaccess (แก้ไขปัญหา 403 Forbidden)
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 3. ปรับแต่งค่า PHP สำหรับการอัปโหลดไฟล์จำนวนมาก
RUN { \
    echo 'upload_max_filesize = 500M'; \
    echo 'post_max_size = 500M'; \
    echo 'max_file_uploads = 250'; \
    echo 'max_execution_time = 180'; \
    echo 'memory_limit = 512M'; \
} > /usr/local/etc/php/conf.d/uploads.ini

# 4. ก๊อปปี้ไฟล์งานทั้งหมดไปไว้ในโฟลเดอร์เว็บ
COPY . /var/www/html/

# 5. จัดการ Permission และสร้างโฟลเดอร์ Uploads
RUN mkdir -p /var/www/html/gallery/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/gallery/uploads

# 6. เปิดพอร์ต 80
EXPOSE 80
