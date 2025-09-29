<?php
/**
 * @author usuario11
 * @date 26/09/2025 15:05
 *
 * MIT License (c) 2025 R2 Soft
 * Gera PDF de uma URL:
 * 1) tenta PDF nativo (Content-Type)
 * 2) cai para Chrome headless
 * Sem exceptions; retorna UrlToPdfResult. Sem cache.
 */

namespace R2Soft\Toolkit;

class UrlToPdf
{
    /** @var string|null nome/caminho do Chrome preferido (opcional) */
    protected ?string $chrome;
    /** @var int timeout (s) */
    protected int $timeout;
    /** @var float|null fator de escala do Chrome (ex.: 1.25) */
    protected ?float $chromeScale;
    /** @var array headers HTTP p/ tentativa de PDF nativo */
    protected array $headers;
    /** @var string|null cookies no request */
    protected ?string $cookies;
    /** @var bool tentar PDF nativo antes do Chrome */
    protected bool $tryDirectPdfFirst;

    /**
     * @param array $options array|null array de opções
     */
    public function __construct(array $options = [])
    {
        $this->chrome = isset($options['chrome']) ? (string)$options['chrome'] : null;
        $this->timeout = isset($options['timeout']) ? (int)$options['timeout'] : 60;
        $this->chromeScale = isset($options['chrome_scale']) ? (float)$options['chrome_scale'] : null;
        $this->headers = isset($options['headers']) ? (array)$options['headers'] : [];
        $this->cookies = isset($options['cookies']) ? (string)$options['cookies'] : null;
        $this->tryDirectPdfFirst = isset($options['try_direct_pdf']) ? (bool)$options['try_direct_pdf'] : true;

        // headers padrão (mantém Accept preferindo PDF)
        $this->headers = array_replace([
            'Accept' => 'application/pdf, */*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; R2SoftBot/1.0)',
        ], $this->headers);
    }

    /**
     * API “direta”: retorna UrlToPdfResult. Se $returnBase64=true, devolve PDF em base64.
     */
    public function convert(?string $url, bool $returnBase64 = true): UrlToPdfResult
    {
        $url = trim((string)$url);
        if ($url === '') return UrlToPdfResult::fail('URL vazia.');

        // 1) PDF nativo
        if ($this->tryDirectPdfFirst) {
            $direct = $this->tryDirectPdf($url);
            if ($direct['ok']) {
                $pdf = $direct['body'];
                if ($returnBase64) $pdf = base64_encode($pdf);
                return UrlToPdfResult::ok($pdf, [
                    'source' => 'direct',
                    'content_type' => $direct['content_type'],
                    'http_status' => $direct['status'],
                ]);
            }
        }

        // 2) Chrome headless
        $chr = $this->renderWithChrome($url);
        if ($chr['ok']) {
            $pdf = $chr['body'];
            if ($returnBase64) $pdf = 'data:application/pdf;base64,' . base64_encode($pdf);
            return UrlToPdfResult::ok($pdf, ['source' => 'chrome']);
        }

        $msg = $direct['err'] ?? '';
        if (!empty($chr['err'])) $msg .= ($msg ? ' | ' : '') . $chr['err'];
        return UrlToPdfResult::fail($msg ?: 'Falha ao gerar PDF.');
    }

    // ======== Internos ========
    protected function tryDirectPdf(string $url): array
    {
        $out = ['ok' => false, 'body' => null, 'status' => null, 'content_type' => null, 'err' => null];
        $http = $this->httpGet($url, $this->timeout, $this->headers, $this->cookies);
        if (!$http['ok']) {
            $out['err'] = $http['err'];
            return $out;
        }

        $body = $http['body'];
        $isPdf = (stripos((string)$http['content_type'], 'application/pdf') !== false) || (strpos($body, '%PDF') === 0);
        if ($isPdf) {
            $out['ok'] = true;
            $out['body'] = $body;
            $out['status'] = $http['status'];
            $out['content_type'] = $http['content_type'];
            return $out;
        }

        $out['err'] = 'Não é PDF nativo (Content-Type: ' . ($http['content_type'] ?: 'desconhecido') . ')';
        return $out;
    }

    protected function renderWithChrome(string $url): array
    {
        $out = ['ok' => false, 'body' => null, 'err' => null];

        $chromeBin = $this->resolveChromeBinary();
        if ($chromeBin === null) {
            $out['err'] = 'Chrome/Chromium não encontrado.';
            return $out;
        }

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

    protected function httpGet(string $url, int $timeout, array $headers = [], ?string $cookies = null): array
    {
        $out = ['ok' => false, 'status' => null, 'content_type' => null, 'body' => null, 'err' => null];

        if (!function_exists('curl_init')) {
            $out['err'] = 'Extensão cURL é necessária.';
            return $out;
        }

        $h = [];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($cookies) $h[] = "Cookie: $cookies";

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
    protected function resolveChromeBinary(): ?string
    {
        $c = [];
        if (!empty($this->chrome)) $c[] = $this->chrome;
        $env = getenv('CHROME_BIN');
        if ($env) $c[] = $env;
        $c = array_merge($c, [
            '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium', '/usr/bin/chromium-browser',
            'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser',
        ]);
        foreach ($c as $bin) {
            if (strpos($bin, '/') !== false || strpos($bin, '\\') !== false) {
                if (@is_file($bin) && @is_executable($bin)) {
                    $p = $this->runProcess([$bin, '--version'], 6);
                    if (stripos($p['stdout'] ?? '', 'Chrome') !== false || stripos($p['stdout'] ?? '', 'Chromium') !== false) return $bin;
                }
                continue;
            }
            $resolved = $this->resolveFromPath($bin);
            if ($resolved) {
                $p = $this->runProcess([$resolved, '--version'], 6);
                if (stripos($p['stdout'] ?? '', 'Chrome') !== false || stripos($p['stdout'] ?? '', 'Chromium') !== false) return $resolved;
            }
        }
        return null;
    }

    protected function resolveFromPath(string $name): ?string
    {
        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ['where', $name] : ['bash', '-lc', 'command -v ' . escapeshellarg($name)];
        $rp = $this->runProcess($cmd, 4);
        if (($rp['ok'] ?? false) && !empty($rp['stdout'])) {
            $path = trim($rp['stdout']);
            if (@is_file($path) && @is_executable($path)) return $path;
        }
        return null;
    }

    protected function runProcess(array $cmd, int $timeout, array $env = []): array
    {
        // monta comando, flags com dois tokens
        $final = '';
        foreach ($cmd as $i => $part) {
            if ($i === 0) $final .= escapeshellcmd($part);
            else {
                $isFlag = (substr($part, 0, 2) === '--');
                $final .= $isFlag ? ' '.$part : ' '.escapeshellarg($part);
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
        $stdout = '';
        $stderr = '';
        while (true) {
            $status = proc_get_status($p);
            if (!$status['running']) break;

            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $r) {
                $buf = @fread($r, 8192);
                if ($buf !== false && $buf !== '') {
                    if ($r === $pipes[1]) $stdout .= $buf; else $stderr .= $buf;
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

    protected function tempFile(string $suffix = ''): string
    {
        $f = @tempnam(sys_get_temp_dir(), 'r2pdf_');
        if ($suffix !== '' && $f) {
            $new = $f . $suffix;
            @unlink($f);
            $f = $new;
        }
        return $f;
    }

    public static function make(?string $url, bool $returnBase64 = true, array $options = []): UrlToPdfResult
    {
        $self = new self($options);
        return $self->convert($url, $returnBase64);
    }

    public static function mergeUrls(array $urls, $returnBase64 = true, array $options = []): UrlToPdfResult
    {
        return PdfMerge::fromUrls($urls, $returnBase64, $options);
    }
}
