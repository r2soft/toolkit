<?php
/**
 * @author usuario11
 * @date 29/09/2025 11:23
 *
 * MIT License (c) 2025 R2 Soft
 */

declare(strict_types=1);

namespace R2Soft\Toolkit;

class PdfMerge
{
    /**
     * Gera e mescla PDFs a partir de URLs.
     *
     * @param string[] $urls
     * @param bool $returnBase64
     * @param array{
     *   return_base64?: bool,
     *   chrome?: string|null,
     *   engine?: string|null,
     *   timeout?: int,
     *   chrome_scale?: float|null,
     *   headers?: array<string,string>,
     *   cookies?: string|null,
     *   try_direct_pdf?: bool,
     *   cache_dir?: string|null,
     *   cache_ttl?: int
     * } $options
     *
     * @return UrlToPdfResult
     */
    public static function fromUrls(array $urls, bool $returnBase64 = true, array $options = []): UrlToPdfResult
    {
        $urls = array_values(array_filter($urls, static fn($u) => is_string($u) && trim($u) !== ''));
        if (empty($urls)) {
            return UrlToPdfResult::fail('Lista de URLs vazia.');
        }

        $engine = $options['engine'] ?? 'gs';
        $tmpFiles = [];
        $notes = [];

        try {
            foreach ($urls as $u) {
                $r = UrlToPdf::make($u, false, $options);
                if ($r->success === false || empty($r->pdf)) {
                    $notes[] = '[' . $u . '] ' . (!empty($r->error ?? '') ? $r->error : 'Falha ao obter PDF');
                    continue;
                }

                if (strpos($r->pdf, '%PDF') !== 0) {
                    $notes[] = '[' . $u . '] Conteúdo não parece PDF válido (não inicia com %PDF).';
                    continue;
                }

                $tmp = self::tempFile('.pdf');
                if (@file_put_contents($tmp, $r->pdf) === false) {
                    $notes[] = '[' . $u . '] Falha ao gravar PDF temporário.';
                    continue;
                }
                $tmpFiles[] = $tmp;
            }

            if (empty($tmpFiles)) {
                $msg = 'Nenhum PDF válido gerado para mesclar.';
                if (!empty($notes)) $msg .= ' Motivos: ' . implode(' | ', $notes);
                return UrlToPdfResult::fail($msg, ['engine' => $engine, 'notes' => $notes]);
            }

            $mergeRes = self::mergeFiles($tmpFiles, $engine);
            if ($mergeRes->success === false) {
                // Propaga o erro de mescla, preservando notes coletadas
                $meta = $mergeRes->meta ?? [];
                $meta['notes'] = array_values(array_unique(array_merge($notes, $meta['notes'] ?? [])));
                return UrlToPdfResult::fail($mergeRes->error ?: 'Erro ao mesclar PDFs.', $meta);
            }

            $bin = $mergeRes->pdf;
            if ($returnBase64) {
                return UrlToPdfResult::ok('data:application/pdf;base64,' . base64_encode($bin), [
                    'engine' => $engine,
                    'count' => count($tmpFiles),
                    'notes' => $notes,
                ]);
            }

            return UrlToPdfResult::ok($bin, [
                'engine' => $engine,
                'count' => count($tmpFiles),
                'notes' => $notes,
            ]);
        } finally {
            foreach ($tmpFiles as $f) {
                @is_file($f) && @unlink($f);
            }
        }
    }

    /**
     * Mescla uma lista de PDFs existentes em disco e retorna BINÁRIO do PDF final.
     *
     * @param string[] $pdfPaths
     * @param string $engine
     * @return UrlToPdfResult
     */
    public static function mergeFiles(array $pdfPaths, string $engine = 'gs'): UrlToPdfResult
    {
        $pdfPaths = array_values(array_filter($pdfPaths, static function ($p) {
            return is_string($p) && $p !== '' && is_file($p) && filesize($p) > 0;
        }));

        if (empty($pdfPaths)) {
            return UrlToPdfResult::fail('Nenhum PDF válido para mesclar.');
        }

        $out = self::tempFile('.pdf');

        if ($engine === 'pdfunite') {
            if (!self::binExists('pdfunite')) {
                return UrlToPdfResult::fail('pdfunite não encontrado. Instale com: apt-get install -y poppler-utils');
            }
            $cmd = 'pdfunite ' . implode(' ', array_map('escapeshellarg', $pdfPaths)) . ' ' . escapeshellarg($out);
            @exec($cmd, $o, $ret);
            if (($ret ?? 1) !== 0 || !is_file($out) || (filesize($out) ?: 0) === 0) {
                @is_file($out) && @unlink($out);
                return UrlToPdfResult::fail('Erro ao mesclar PDFs com pdfunite.');
            }
        } else {
            if (!self::binExists('gs')) {
                return UrlToPdfResult::fail('Ghostscript (gs) não encontrado. Instale com: apt-get install -y ghostscript');
            }
            $input = implode(' ', array_map('escapeshellarg', $pdfPaths));
            $cmd = 'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($out) . ' ' . $input;
            @exec($cmd, $o, $ret);
            if (($ret ?? 1) !== 0 || !is_file($out) || (filesize($out) ?: 0) === 0) {
                @is_file($out) && @unlink($out);
                return UrlToPdfResult::fail('Erro ao mesclar PDFs via Ghostscript.');
            }
        }

        $bin = @file_get_contents($out);
        @unlink($out);

        if ($bin === false || $bin === '') {
            return UrlToPdfResult::fail('Falha ao ler PDF mesclado.');
        }

        return UrlToPdfResult::ok($bin, ['engine' => $engine, 'notes' => []]);
    }

    protected static function binExists(string $bin): bool
    {
        $cmd = (stripos(PHP_OS, 'WIN') === 0)
            ? 'where ' . escapeshellarg($bin)
            : 'bash -lc ' . escapeshellarg('command -v ' . $bin);
        $out = [];
        $ret = 1;
        @exec($cmd, $out, $ret);
        return ($ret === 0) && !empty($out);
    }

    protected static function tempFile(string $suffix = ''): string
    {
        $f = tempnam(sys_get_temp_dir(), 'r2tk_');
        if ($suffix !== '' && $f) {
            $new = $f . $suffix;
            @unlink($f);
            $f = $new;
        }
        return $f;
    }
}
