# Dockerfile
FROM r2soft/php7.4

WORKDIR /app

# 1) Pacotes base + Ghostscript + fontes
RUN set -eux; \
  apt-get update; \
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg git unzip \
    ghostscript \
    fonts-dejavu fonts-liberation fonts-noto fonts-noto-cjk \
    libasound2 libatk-bridge2.0-0 libatk1.0-0 libatspi2.0-0 \
    libdrm2 libgbm1 libgtk-3-0 libnss3 libnspr4 \
    libx11-6 libx11-xcb1 libxcomposite1 libxdamage1 libxext6 libxfixes3 libxrandr2 \
    libxshmfence1 xdg-utils; \
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

# 3) Ambiente: diretórios temporários e variáveis úteis
# Observação: NÃO fixamos um user-data-dir aqui para evitar SingletonLock.
ENV HOME=/tmp \
    XDG_CACHE_HOME=/tmp/.cache \
    XDG_CONFIG_HOME=/tmp/.config \
    XDG_RUNTIME_DIR=/tmp/runtime \
    CHROME_BIN=/usr/bin/google-chrome

RUN set -eux; \
  mkdir -p /tmp/.cache /tmp/.config /tmp/runtime /tmp/chrome-crash; \
  chmod -R 777 /tmp/.cache /tmp/.config /tmp/runtime /tmp/chrome-crash

# 4) Composer (se a base não trouxer)
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm composer-setup.php

# 5) Entrypoint padrão
CMD ["bash", "-lc", "php -v && composer --version && tail -f /dev/null"]
