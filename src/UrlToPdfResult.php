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

namespace App\Helpers;

/**
 * Estrutura de retorno dos métodos públicos.
 */
class UrlToPdfResult
{
    public $success = false;
    public $pdf = null;     // string binária do PDF
    public $error = '';     // mensagem de erro
    public $meta = [];     // infos adicionais (ex.: 'source' => 'chrome')

    public static function ok($pdf, array $meta = [])
    {
        $r = new self;
        $r->success = true;
        $r->pdf = $pdf;
        $r->meta = $meta;
        return $r;
    }

    public static function fail($msg, array $meta = [])
    {
        $r = new self;
        $r->success = false;
        $r->error = $msg;
        $r->meta = $meta;
        return $r;
    }
}
