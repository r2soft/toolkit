#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * @author usuario11
 * @date 26/09/2025 18:10
 *
 * Teste simples: converte uma URL em PDF usando R2Soft\Toolkit\UrlToPdf.
 *
 * Uso:
 *   php scripts/test-url-to-pdf.php [URL] [--base64]
 *
 * Exemplos:
 *   php scripts/test-url-to-pdf.php
 *   php scripts/test-url-to-pdf.php "https://example.com"
 *   php scripts/test-url-to-pdf.php "https://example.com" --base64
 */

require __DIR__ . '/../vendor/autoload.php';

use R2Soft\Toolkit\UrlToPdf;

$url = $argv[1] ?? 'https://app.r2atendemais.com.br';

$chrome = getenv('CHROME_BIN') ?: '/usr/bin/google-chrome';

$res = UrlToPdf::make($url, false, [
    'chrome' => $chrome,
    'timeout' => 60,
    'chrome_scale' => 1.0,
    'try_direct_pdf' => true,
    // headers/cookies extras se precisar:
    // 'headers'       => ['Accept-Language' => 'pt-BR,pt;q=0.9'],
    // 'cookies'       => 'a=1; b=2',
]);

if (!$res->success) {
    fwrite(STDERR, "[ERRO] Falha ao gerar PDF da URL: {$url}\n");
    if (!empty($res->error)) {
        fwrite(STDERR, "       Mensagem: {$res->error}\n");
    }
    if (!empty($res->meta)) {
        fwrite(STDERR, '       Meta: ' . json_encode($res->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(1);
}

$outPath = __DIR__ . '/out.pdf';

if ($asBase64) {
    $bin = base64_decode($res->pdf, true);
    if ($bin === false) {
        fwrite(STDERR, "[ERRO] Base64 inválido retornado pela conversão.\n");
        exit(1);
    }
    if (@file_put_contents($outPath, $bin) === false) {
        fwrite(STDERR, "[ERRO] Não consegui salvar o PDF em: {$outPath}\n");
        exit(1);
    }
} else {
    if (@file_put_contents($outPath, $res->pdf) === false) {
        fwrite(STDERR, "[ERRO] Não consegui salvar o PDF em: {$outPath}\n");
        exit(1);
    }
}

// validação rápida do arquivo gerado
$size = @filesize($outPath) ?: 0;
if ($size < 1024) {
    fwrite(STDERR, "[ERRO] PDF gerado com tamanho muito pequeno ({$size} bytes): {$outPath}\n");
    exit(1);
}
$fh = @fopen($outPath, 'rb');
$head = $fh ? fread($fh, 4) : '';
if ($fh) fclose($fh);
if ($head !== '%PDF') {
    fwrite(STDERR, "[ERRO] Assinatura inválida (esperado '%PDF'). Arquivo: {$outPath}\n");
    exit(1);
}

echo "[OK] PDF gerado com sucesso: {$outPath} (" . number_format($size) . " bytes)\n";
exit(0);
