# PHP + Apache का बेस इमेज
FROM php:8.2-apache

# MySQL (mysqli / pdo_mysql) एक्सटेंशन इंस्टॉल करें ताकि डेटाबेस कनेक्ट हो सके
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Apache में mod_rewrite को इनेबल करें (ABDM URL रूट्स के लिए ज़रूरी है)
RUN a2enmod rewrite

# प्रोजेक्ट की सभी फाइल्स को Apache के पब्लिक फोल्डर में कॉपी करें
COPY . /var/www/html/

# पोर्ट 80 को ओपन करें
EXPOSE 80
