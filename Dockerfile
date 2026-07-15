FROM php:8.4-fpm-alpine

# Instalar dependências do sistema e extensões do PHP necessárias para o Laravel
RUN apk add --no-cache \
    nginx \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    bash \
    git \
    curl \
    zip \
    unzip

# Configurar e instalar extensões do PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql bcmath zip opcache gd

# Copiar Composer da imagem oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar diretório de trabalho
WORKDIR /var/www/html

# Copiar apenas os arquivos do Composer primeiro para aproveitar o cache de camada do Docker
COPY composer.json composer.lock ./

# Instalar as dependências do PHP sem rodar scripts (como key:generate e migrations que precisam do .env completo)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copiar o restante do código da aplicação
COPY . .

# Rodar o autoload otimizado do Composer
RUN composer dump-autoload --no-dev --classmap-authoritative

# Configurar o Nginx para rodar como o usuário www-data (o mesmo do PHP-FPM)
RUN sed -i 's/user nginx;/user www-data;/g' /etc/nginx/nginx.conf

# Copiar a configuração personalizada do Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configurar permissões para o Laravel
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Configurar o script de entrada (entrypoint)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expor a porta 80 para tráfego HTTP
EXPOSE 80

# Definir o entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
