<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Major QR target (Phase 7). The LED-screen QR encodes the stable
 * "{APP_URL}/major-form" route; this endpoint redirects to MAJOR_FORM_URL,
 * which only server configuration can set. Changing the client's form link
 * therefore never requires a new QR.
 */
final class MajorFormController
{
    /** GET /major-form - public redirect to the configured external form */
    public static function redirect(Request $request): Response
    {
        $url = self::formUrl();
        if ($url === null) {
            return Response::raw(503, self::page(
                'Form not available yet',
                'The Major draw form is not available yet. Please check with the event staff.'
            ), 'text/html; charset=utf-8');
        }

        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return Response::raw(302, self::page('Opening the form…', "If nothing happens, <a href=\"{$safe}\">tap here to open the form</a>.", true), 'text/html; charset=utf-8', [
            'Location' => $url,
        ]);
    }

    /** GET /major-form/info - for the Major QR display page (admin + event operator) */
    public static function info(Request $request): Response
    {
        $appUrl = rtrim((string) Config::get('app.url', ''), '/');

        return Response::success([
            'configured' => self::formUrl() !== null,
            'formUrl' => self::formUrl(),
            // The QR always encodes the stable route, never the external form URL.
            'qrUrl' => $appUrl !== '' ? $appUrl . '/major-form' : null,
        ]);
    }

    /** Valid absolute http(s) URL from MAJOR_FORM_URL, or null. */
    public static function formUrl(): ?string
    {
        $url = trim((string) Config::get('app.major_form_url', ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private static function page(string $title, string $htmlMessage, bool $messageIsHtml = false): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $message = $messageIsHtml ? $htmlMessage : htmlspecialchars($htmlMessage, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>{$title}</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f1f5f9;color:#0f172a;padding:24px;box-sizing:border-box}
main{max-width:420px;background:#fff;border-radius:16px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-align:center}h1{font-size:20px;margin:0 0 8px}p{margin:0;color:#475569;line-height:1.5}a{color:#0f766e;font-weight:600}</style>
</head><body><main><h1>{$title}</h1><p>{$message}</p></main></body></html>
HTML;
    }
}
