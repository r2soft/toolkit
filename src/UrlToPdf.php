<?php
/**
 * @author usuario11
 * @date 26/09/2025 15:05
 *
 *  MIT License
 *  (c) 2025 R2 Soft
 *
 *  Classe pura PHP para gerar PDF a partir de uma URL:
 *  1) Tenta baixar PDF nativo (Accept: application/pdf)
 *  2) Tenta renderizar com wkhtmltopdf
 *  3) Tenta renderizar com Chrome/Chromium headless
 *
 *  Não usa exceptions. Sempre retorna um objeto Resultado.
 */

namespace App\Helpers;

class UrlToPdf
{
    /** @var string|null nome/caminho do Chrome preferido (opcional) */
    protected $chrome;
    /** @var int timeout (s) */
    protected $timeout;
    /** @var float|null fator de escala do Chrome (ex.: 1.25) */
    protected $chromeScale;
    /** @var array headers HTTP p/ tentativa de PDF nativo */
    protected $headers;
    /** @var string|null cookies no request */
    protected $cookies;
    /** @var bool tentar PDF nativo antes do Chrome */
    protected $tryDirectPdfFirst;
    /** @var string|null diretório de cache */
    protected $cacheDir;
    /** @var int TTL cache (s) */
    protected $cacheTtl;

    public function __construct(array $options = [])
    {
        $this->chrome = isset($options['chrome']) ? (string)$options['chrome'] : null;
        $this->timeout = isset($options['timeout']) ? (int)$options['timeout'] : 60;
        $this->page_size = isset($options['timeout']) ? (int)$options['timeout'] : 60;
        $this->chromeScale = isset($options['chrome_scale']) ? (float)$options['chrome_scale'] : null;
        $this->headers = isset($options['headers']) ? (array)$options['headers'] : [];
        $this->cookies = isset($options['cookies']) ? (string)$options['cookies'] : null;
        $this->tryDirectPdfFirst = isset($options['try_direct_pdf']) ? (bool)$options['try_direct_pdf'] : true;
        $this->cacheDir = isset($options['cache_dir']) ? (string)$options['cache_dir'] : null;
        $this->cacheTtl = isset($options['cache_ttl']) ? (int)$options['cache_ttl'] : 86400;

        // headers padrão (mantém Accept preferindo PDF)
        $this->headers = array_replace([
            'Accept' => 'application/pdf, */*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; R2SoftBot/1.0)',
        ], $this->headers);
    }

    public function getPdf($url)
    {
        $url = trim((string)$url);
        if ($url === '') return UrlToPdfResult::fail('URL vazia.');

        // cache
        if ($this->cacheDir) {
            $cached = $this->getFromCache($url);
            if ($cached !== null) return UrlToPdfResult::ok($cached, ['source' => 'cache']);
        }

        // 1) PDF nativo (rápido quando existir)
        if ($this->tryDirectPdfFirst) {
            $direct = $this->tryDirectPdf($url);
            if ($direct['ok']) {
                $this->putIntoCache($url, $direct['body']);
                return UrlToPdfResult::ok($direct['body'], [
                    'source' => 'direct',
                    'content_type' => $direct['content_type'],
                    'http_status' => $direct['status'],
                ]);
            }
        }

        // 2) Chrome headless
        $chr = $this->renderWithChrome($url);
        if ($chr['ok']) {
            $this->putIntoCache($url, $chr['body']);
            return UrlToPdfResult::ok($chr['body'], ['source' => 'chrome']);
        }

        // erro consolidado
        $msg = $direct['err'] ?? '';
        if (!empty($chr['err'])) $msg .= ($msg ? ' | ' : '') . $chr['err'];
        return UrlToPdfResult::fail($msg ?: 'Falha ao gerar PDF.');
    }

    public function savePdf($url, $destPath)
    {
        $r = $this->getPdf($url);
        if (!$r->success) return $r;

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return UrlToPdfResult::fail('Não foi possível criar diretório: ' . $dir);
        }
        if (@file_put_contents($destPath, $r->pdf) === false) {
            return UrlToPdfResult::fail('Falha ao salvar PDF em: ' . $destPath);
        }
        $r->meta['saved_to'] = $destPath;
        return $r;
    }

    // ========= Internos =========

    protected function tryDirectPdf($url)
    {
        $out = ['ok' => false, 'body' => null, 'status' => null, 'content_type' => null, 'err' => null];
        $http = $this->httpGet($url, $this->timeout, $this->headers, $this->cookies);
        if (!$http['ok']) {
            $out['err'] = $http['err'];
            return $out;
        }

        $out['status'] = $http['status'];
        $out['content_type'] = $http['content_type'];
        $body = $http['body'];
        $isPdf = (stripos((string)$http['content_type'], 'application/pdf') !== false) || (strpos($body, '%PDF') === 0);
        if ($isPdf) {
            $out['ok'] = true;
            $out['body'] = $body;
            return $out;
        }

        $out['err'] = 'Não é PDF nativo (Content-Type: ' . ($http['content_type'] ?: 'desconhecido') . ')';
        return $out;
    }

    protected function renderWithChrome($url)
    {
        $out = ['ok' => false, 'body' => null, 'err' => null];

        $chromeBin = $this->resolveChromeBinary();
        if ($chromeBin === null) {
            $out['err'] = 'Chrome/Chromium não encontrado.';
            return $out;
        }

        // sanear URL (único target)
        $url = trim((string)$url);
        if ($url === '' || preg_match('/\s/', $url)) {
            $out['err'] = 'URL inválida para impressão.';
            return $out;
        }

        // diretórios seguros por execução
        $tmpBase = rtrim(sys_get_temp_dir(), '/');
        $userDataDir = $tmpBase . '/chrome-user-data';
        $crashDir = $tmpBase . '/chrome-crash';
        @mkdir($userDataDir, 0777, true);
        @mkdir($crashDir, 0777, true);

        $env = [
            'HOME' => $tmpBase,
            'XDG_CACHE_HOME' => $tmpBase . '/.cache',
            'XDG_CONFIG_HOME' => $tmpBase . '/.config',
            'XDG_RUNTIME_DIR' => $tmpBase . '/runtime',
            'TMPDIR' => $tmpBase,
        ];
        @mkdir($env['XDG_CACHE_HOME'], 0777, true);
        @mkdir($env['XDG_CONFIG_HOME'], 0777, true);
        @mkdir($env['XDG_RUNTIME_DIR'], 0777, true);

        $tmpPdf = $this->tempFile('.pdf');

        // flags em DOIS TOKENS (evita "Multiple targets")
        $cmd = [
            $chromeBin,
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-software-rasterizer',
            '--disable-crash-reporter',
            '--no-first-run',
            '--no-default-browser-check',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget=10000',
            "--user-data-dir=$userDataDir",
            "--crash-dumps-dir=$crashDir",
            "--print-to-pdf=$tmpPdf",
            '--print-to-pdf-no-header',
        ];

        if ($this->chromeScale !== null) $cmd[] = "--force-device-scale-factor=$tmpPdf";

        $cmd[] = $url;

        $rp = $this->runProcess($cmd, $this->timeout, $env);

        // sucesso se arquivo existe (mesmo com warnings/exit code != 0)
        if (is_file($tmpPdf) && filesize($tmpPdf) > 1000) {
            $bin = (string)@file_get_contents($tmpPdf);
            @unlink($tmpPdf);
            if ($bin !== '') {
                $out['ok'] = true;
                $out['body'] = $bin;
                return $out;
            }
        }
        @unlink($tmpPdf);
        $out['err'] = 'Falha no Chrome headless' . (!empty($rp['stderr']) ? ' (' . $rp['stderr'] . ')' : '');
        return $out;
    }

    // ======= Utilitários =======

    protected function httpGet($url, $timeout, $headers = [], $cookies = null)
    {
        $out = ['ok' => false, 'status' => null, 'content_type' => null, 'body' => null, 'err' => null];

        if (!function_exists('curl_init')) {
            $out['err'] = 'Extensão cURL é necessária.';
            return $out;
        }

        $h = [];
        foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
        if ($cookies) $h[] = 'Cookie: ' . $cookies;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => true,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $out['err'] = 'HTTP erro: ' . curl_error($ch);
            curl_close($ch);
            return $out;
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headersRaw = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        curl_close($ch);

        $type = null;
        foreach (explode("\r\n", $headersRaw) as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $type = trim(substr($line, 13));
                break;
            }
        }

        if ($status < 200 || $status >= 400) {
            $out['err'] = 'HTTP status inesperado: ' . $status;
            $out['status'] = $status;
            $out['content_type'] = $type;
            return $out;
        }

        $out['ok'] = true;
        $out['status'] = $status;
        $out['content_type'] = $type;
        $out['body'] = $body;
        return $out;
    }

    /** localiza binário de Chrome automaticamente */
    protected function resolveChromeBinary()
    {
        $candidates = [];
        if (!empty($this->chrome)) $candidates[] = $this->chrome;
        $env = getenv('CHROME_BIN');
        if ($env) $candidates[] = $env;
        $candidates = array_merge($candidates, [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            'google-chrome',
            'google-chrome-stable',
            'chromium',
            'chromium-browser',
        ]);

        foreach ($candidates as $bin) {
            // caminho completo?
            if (strpos($bin, '/') !== false || strpos($bin, '\\') !== false) {
                if (@is_file($bin) && @is_executable($bin)) {
                    $probe = $this->runProcess([$bin, '--version'], 6);
                    if (stripos((string)$probe['stdout'], 'Chrome') !== false ||
                        stripos((string)$probe['stdout'], 'Chromium') !== false) {
                        return $bin;
                    }
                }
                continue;
            }
            // nome no PATH
            $resolved = $this->resolveFromPath($bin);
            if ($resolved) {
                $probe = $this->runProcess([$resolved, '--version'], 6);
                if (stripos((string)$probe['stdout'], 'Chrome') !== false ||
                    stripos((string)$probe['stdout'], 'Chromium') !== false) {
                    return $resolved;
                }
            }
        }
        return null;
    }

    protected function resolveFromPath($name)
    {
        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ['where', $name] : ['bash', '-lc', 'command -v ' . escapeshellarg($name)];
        $rp = $this->runProcess($cmd, 4);
        if ($rp['ok'] && !empty($rp['stdout'])) {
            $path = trim($rp['stdout']);
            if (@is_file($path) && @is_executable($path)) return $path;
        }
        return null;
    }

    protected function runProcess($cmd, $timeout, array $env = [])
    {
        // monta comando com flags separadas (sem juntar --flag=value)
        $final = '';
        foreach ($cmd as $i => $part) {
            if ($i === 0) {
                $final .= escapeshellcmd($part);
            } else {
                // parte começa com '--'? passa literal; valores/caminhos escapados
                if (substr($part, 0, 2) === '--') $final .= ' ' . $part;
                else $final .= ' ' . escapeshellarg($part);
            }
        }

        $procEnv = array_merge($_ENV, $_SERVER, $env);

        $des = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($final, $des, $pipes, null, $procEnv);
        if (!is_resource($p)) return ['ok' => false, 'stdout' => '', 'stderr' => 'proc_open falhou', 'code' => -1];

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $stdout = $stderr = '';

        while (true) {
            $status = proc_get_status($p);
            if (!$status['running']) break;

            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 200000); // 200ms

            foreach ($read as $r) {
                $buf = @fread($r, 8192);
                if ($buf !== false && $buf !== '') {
                    if ($r === $pipes[1]) $stdout .= $buf;
                    else $stderr .= $buf;
                }
            }

            if ((microtime(true) - $start) > (float)$timeout) {
                $stdout .= @stream_get_contents($pipes[1]) ?: '';
                $stderr .= @stream_get_contents($pipes[2]) ?: '';
                @proc_terminate($p, 9);
                break;
            }
        }

        $stdout .= @stream_get_contents($pipes[1]) ?: '';
        $stderr .= @stream_get_contents($pipes[2]) ?: '';
        @fclose($pipes[1]);
        @fclose($pipes[2]);

        $code = @proc_close($p);
        return ['ok' => ($code === 0), 'stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
    }

    protected function tempFile($suffix = '')
    {
        $f = @tempnam(sys_get_temp_dir(), 'r2pdf_');
        if ($suffix !== '' && $f) {
            $new = $f . $suffix;
            @unlink($f);
            $f = $new;
        }
        return $f;
    }

    // cache simples em disco
    protected function cacheKey($url)
    {
        return sha1($url);
    }

    protected function cachePath($url)
    {
        return rtrim((string)$this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->cacheKey($url) . '.pdf';
    }

    protected function getFromCache($url)
    {
        if (!$this->cacheDir) return null;
        $p = $this->cachePath($url);
        if (!@is_file($p)) return null;
        if ((time() - (int)@filemtime($p)) > $this->cacheTtl) return null;
        $bin = @file_get_contents($p);
        return ($bin !== false && $bin !== '') ? $bin : null;
    }

    protected function putIntoCache($url, $pdf)
    {
        if (!$this->cacheDir) return;
        $p = $this->cachePath($url);
        $d = dirname($p);
        if (!is_dir($d)) @mkdir($d, 0775, true);
        @file_put_contents($p, $pdf);
    }
}
