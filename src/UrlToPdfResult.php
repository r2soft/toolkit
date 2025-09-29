<?php
/**
 * @author usuario11
 * @date 26/09/2025 14:58
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

namespace R2Soft\Toolkit;

/**
 * Estrutura de retorno dos métodos públicos.
 */
class UrlToPdfResult {
    public bool $success = false;
    public ?string $pdf = null;   // binário
    public string $error = '';
    public array $meta = [];
    public static function ok(string $pdf, array $meta = []): self { $r=new self; $r->success=true;$r->pdf=$pdf;$r->meta=$meta; return $r; }
    public static function fail(string $msg, array $meta = []): self { $r=new self; $r->error=$msg; $r->meta=$meta; return $r; }
}
