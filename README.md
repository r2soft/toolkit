# R2Soft Toolkit (PHP)

Biblioteca utilitária em PHP para gerar PDFs a partir de URLs usando Google Chrome/Chromium em modo headless e para mesclar múltiplos PDFs usando Ghostscript. Foco em simplicidade: sem exceptions; sempre retorna um objeto de resultado com sucesso/erro e metadados.

Principais recursos:
- URL → PDF com fallback: tenta baixar PDF nativo (Content-Type: application/pdf) e, se não for, renderiza a página com Chrome headless.
- Mescla de PDFs: junta vários PDFs em um único arquivo usando Ghostscript (ou pdfunite opcionalmente).
- API simples via classes R2Soft\Toolkit\UrlToPdf e R2Soft\Toolkit\PdfMerge.
- Scripts de teste/uso prático em tests/.


## Requisitos

Ambiente mínimo:
- PHP 7.4+ com extensão cURL
- Sistema operacional Linux (recomendado) ou compatível
- Google Chrome ou Chromium instalados (para gerar PDF a partir de HTML/URL)
- Ghostscript (gs) instalado (para mesclar PDFs)

Opcional:
- pdfunite (pacote poppler-utils) caso prefira usar esse engine de mescla em vez de Ghostscript

Pacotes em Debian/Ubuntu (exemplo):
- Chrome: via repositório oficial do Google (veja Dockerfile para referência) ou instale chromium.
- Ghostscript: apt-get install -y ghostscript
- pdfunite (opcional): apt-get install -y poppler-utils

Observação: O Dockerfile incluído já prepara um ambiente com Chrome e Ghostscript prontos para uso.


## Instalação

Via Composer (em um projeto seu):
1. composer require r2soft/toolkit
2. Inclua o autoload do Composer: require 'vendor/autoload.php';

Neste repositório (local):
- composer install


## Como funciona (visão geral)

- UrlToPdf::make($url, $returnBase64, $options) → gera um PDF a partir da URL informada.
  - Primeiro tenta baixar diretamente um PDF (Content-Type application/pdf) respeitando headers/cookies opcionais.
  - Se não for PDF, chama o Google Chrome/Chromium em modo headless para imprimir a página em PDF.
  - Retorna UrlToPdfResult com:
    - success: bool
    - pdf: string (binário) ou base64, conforme parâmetro $returnBase64
    - error: string (mensagem de erro, quando falha)
    - meta: array (metadados como source: direct|chrome, status HTTP etc.)

- PdfMerge::fromUrls($urls, $returnBase64, $options) → gera PDFs individuais a partir de cada URL e depois mescla em um único PDF usando Ghostscript (por padrão).
  - Também retorna UrlToPdfResult com o PDF final (binário ou base64) e metadados.


## Uso rápido (PHP)

Gerar PDF de uma URL:

```php
use R2Soft\Toolkit\UrlToPdf;

$res = UrlToPdf::make('https://example.com', false, [
    'chrome'          => '/usr/bin/google-chrome', // opcional; também pode usar CHROME_BIN
    'timeout'         => 60,                        // em segundos
    'chrome_scale'    => 1.0,                       // fator de escala do Chrome (opcional)
    'try_direct_pdf'  => true,                      // tenta Content-Type: application/pdf antes do Chrome
    // 'headers'      => ['Accept-Language' => 'pt-BR,pt;q=0.9'],
    // 'cookies'      => 'a=1; b=2',
]);

if ($res->success) {
    file_put_contents('saida.pdf', $res->pdf); // binário
} else {
    error_log('Falha: ' . $res->error);
}
```

Mesclar PDFs a partir de várias URLs:

```php
use R2Soft\Toolkit\UrlToPdf;

$urls = [
  'https://example.com',
  'https://iana.org',
];

$res = UrlToPdf::mergeUrls($urls, false, [
    'chrome'         => '/usr/bin/google-chrome',
    'timeout'        => 60,
    'try_direct_pdf' => true,
]);

if ($res->success) {
    file_put_contents('mesclado.pdf', $res->pdf); // binário do PDF final
} else {
    error_log('Falha: ' . $res->error);
}
```


## Opções suportadas (chaves do array $options)

- chrome: string|null
  - Caminho para o binário do Chrome/Chromium. Se não informar, a biblioteca tenta achar automaticamente.
  - Também é possível definir via variável de ambiente CHROME_BIN.
- timeout: int (padrão: 60)
  - Tempo máximo, em segundos, para cada operação (HTTP/Chrome).
- chrome_scale: float|null
  - Fator de escala do Chrome (ex.: 1.25). Opcional.
- headers: array<string,string>
  - Headers adicionais para a tentativa de PDF nativo (HTTP GET). O Accept padrão já privilegia PDF.
- cookies: string|null
  - Cabeçalho Cookie enviado no HTTP GET (ex.: "a=1; b=2").
- try_direct_pdf: bool (padrão: true)
  - Se true, tenta baixar PDF nativo antes de renderizar com Chrome.
- cache_dir: string|null e cache_ttl: int
  - Se definidos, habilita cache simples de PDFs por URL por cache_ttl segundos.


## Dependências externas em destaque

- Google Chrome/Chromium: necessário para renderizar HTML→PDF quando a URL não retorna PDF nativo.
  - Pode apontar o binário via options['chrome'] ou variável de ambiente CHROME_BIN.
  - Em containers, normalmente em /usr/bin/google-chrome.
- Ghostscript (gs): necessário para mesclar vários PDFs em um só.
  - Usado por padrão em PdfMerge::mergeFiles e em UrlToPdf::mergeUrls (indiretamente).
- pdfunite (opcional): engine alternativo de mescla. Para usá-lo diretamente, chame PdfMerge::mergeFiles($paths, 'pdfunite').


## Uso via scripts de teste (CLI)

O repositório inclui scripts simples em tests/ para facilitar testes rápidos:

1) Gerar um único PDF de uma URL:

```bash
php tests/url-to-pdf-test.php "https://example.com"
```

- Saída padrão: tests/out.pdf
- Define o Chrome com a variável de ambiente CHROME_BIN, se necessário:

```bash
CHROME_BIN=/usr/bin/google-chrome php tests/url-to-pdf-test.php "https://example.com"
```

2) Mesclar PDFs de várias URLs:

```bash
php tests/merge-urls-test.php --engine=gs https://example.com https://iana.org
```

- Engines aceitas: gs (padrão) ou pdfunite
- Saída padrão: tests/out-merged.pdf
- Opção --base64 retorna o PDF final em base64 (o script já salva em binário para facilitar)


## Execução com Docker

Você pode usar o docker-compose incluído, que já prepara um ambiente com Chrome e Ghostscript:

- Build e subir serviço:

```bash
docker compose up -d --build
```

- Instalar dependências do Composer (já é feito pelo command do compose) e executar os testes:

```bash
docker compose exec app bash -lc "php -v && composer install && php tests/url-to-pdf-test.php https://example.com && php tests/merge-urls-test.php https://example.com https://iana.org"
```

- Os arquivos gerados (tests/out.pdf e tests/out-merged.pdf) ficam mapeados no diretório local.

Dicas para Chrome no Docker:
- O projeto configura variáveis HOME/XDG_* para diretórios temporários e evita user-data-dir fixo para reduzir travas SingletonLock.
- Flags usadas: --headless=new, --no-sandbox, --disable-dev-shm-usage, etc.


## Solução de problemas (troubleshooting)

- Chrome/Chromium não encontrado:
  - Defina CHROME_BIN com o caminho do binário (ex.: /usr/bin/google-chrome).
  - No Linux, verifique também google-chrome-stable, chromium ou chromium-browser.
- Erro ao mesclar PDFs: "Ghostscript (gs) não encontrado":
  - Instale: apt-get install -y ghostscript.
  - Alternativa: use pdfunite (apt-get install -y poppler-utils) e chame o engine 'pdfunite'.
- PDF muito pequeno ou inválido:
  - Verifique se a página requer autenticação ou precisa de headers/cookies específicos.
  - Ajuste 'timeout' e teste novamente.
- Ambiente de container travando Chrome:
  - Garanta que as libs listadas no Dockerfile estão presentes (libnss3, libgbm1, libx11, etc.).
  - Use --no-sandbox e --disable-dev-shm-usage (o projeto já aplica).


## Estrutura das classes principais

- R2Soft\Toolkit\UrlToPdf
  - make($url, $returnBase64 = true, array $options = []): UrlToPdfResult
  - mergeUrls(array $urls, $returnBase64 = true, array $options = []): UrlToPdfResult (atalho para PdfMerge::fromUrls)

- R2Soft\Toolkit\PdfMerge
  - fromUrls(array $urls, $returnBase64 = true, array $options = []): UrlToPdfResult
  - mergeFiles(array $pdfPaths, string $engine = 'gs'): UrlToPdfResult

- R2Soft\Toolkit\UrlToPdfResult
  - success: bool
  - pdf: string|null (binário ou base64)
  - error: string
  - meta: array


## Licença

MIT License (c) 2025 R2 Soft
