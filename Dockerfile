FROM php:8.2-cli-alpine

RUN docker-php-ext-install curl 2>/dev/null || true \
 && apk add --no-cache curl-dev \
 && docker-php-ext-enable curl 2>/dev/null || true

WORKDIR /app
COPY sync.php .
COPY categories.json .

CMD ["php", "sync.php"]
