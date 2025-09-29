# Dockerfile
FROM r2soft/php8.1

WORKDIR /app

# 1) Dependências básicas + fontes (PT-BR)
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        git \
        unzip \
        fonts-dejavu \
        fonts-liberation \
        fonts-noto \
        fonts-noto-cjk; \
    rm -rf /var/lib/apt/lists/*

# 2) Repositório oficial do Google Chrome + instalação
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    mkdir -p /usr/share/keyrings; \
    curl -fsSL https://dl-ssl.google.com/linux/linux_signing_key.pub \
      | gpg --dearmor -o /usr/share/keyrings/google-linux.gpg; \
    echo "deb [arch=${arch} signed-by=/usr/share/keyrings/google-linux.gpg] http://dl.google.com/linux/chrome/deb/ stable main" \
      > /etc/apt/sources.list.d/google-chrome.list; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        google-chrome-stable; \
    ln -sf /usr/bin/google-chrome-stable /usr/bin/google-chrome; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# 3) Ambiente seguro pro Chrome em container
ENV HOME=/tmp \
    XDG_CACHE_HOME=/tmp/.cache \
    XDG_CONFIG_HOME=/tmp/.config \
    XDG_RUNTIME_DIR=/tmp/runtime \
    CHROME_BIN=/usr/bin/google-chrome

RUN set -eux; \
    mkdir -p /tmp/.cache /tmp/.config /tmp/runtime /tmp/chrome-user-data /tmp/chrome-crash; \
    chmod -R 777 /tmp/.cache /tmp/.config /tmp/runtime /tmp/chrome-user-data /tmp/chrome-crash

# 4) Instalar Composer (se a base não tiver)
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm composer-setup.php

# 5) Entrypoint padrão: nada
CMD ["bash", "-lc", "php -v && composer --version && tail -f /dev/null"]
