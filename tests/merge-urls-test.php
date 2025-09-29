<?php
/**
 * @author usuario11
 * @date 29/09/2025 11:30
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use R2Soft\Toolkit\UrlToPdf;

$args = $argv;
array_shift($args);

$engine = 'gs';
$asBase64 = false;
$urls = [];

// parse args
foreach ($args as $a) {
    if ($a === '--base64') {
        $asBase64 = true;
        continue;
    }
    if (strpos($a, '--engine=') === 0) {
        $engine = substr($a, 9) ?: 'gs';
        continue;
    }
    if (strpos($a, '--') === 0) {
        // ignora flags desconhecidas
        continue;
    }
    $urls[] = $a;
}

if (empty($urls)) {
    // valores padrão só para teste manual
    $urls = [
        'https://example.com',
        'https://iana.org',
    ];
}

$chrome = getenv('CHROME_BIN') ?: '/usr/bin/google-chrome';

$res = UrlToPdf::mergeUrls($urls, $asBase64, [
    'chrome' => $chrome,
    'timeout' => 60,
    'chrome_scale' => 1.0,
    'try_direct_pdf' => true,
    // 'headers' => ['Accept-Language' => 'pt-BR,pt;q=0.9'],
    // 'cookies' => 'a=1; b=2',
]);

//debug($res);
if (!$res->success) {
    fwrite(STDERR, "[ERRO] Falha ao mesclar PDFs das URLs.\n");
    if (!empty($res->error)) {
        fwrite(STDERR, "       Mensagem: {$res->error}\n");
    }
    if (!empty($res->meta)) {
        fwrite(STDERR, '       Meta: ' . json_encode($res->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(1);
}

$outPath = __DIR__ . '/out-merged.pdf';

if ($asBase64) {
    $explodeMimetype = explode('base64,', $res->pdf);
    $bin = base64_decode($explodeMimetype[1] ?? '', true);

    if ($bin === false) {
        fwrite(STDERR, "[ERRO] Base64 inválido retornado pela mescla.\n");
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

// validação rápida
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

echo "[OK] PDF mesclado com sucesso: {$outPath} (" . number_format($size) . " bytes)\n";
if (!empty($res->meta)) {
    echo 'Meta: ' . json_encode($res->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
exit(0);
