<?php
declare(strict_types=1);

function elevaro_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'elevaro.app';
    return $scheme . '://' . $host;
}

function elevaro_send_mail(string $to, string $subject, string $html): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: Elevaro <noreply@elevaro.app>',
        'Reply-To: noreply@elevaro.app',
    ];

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
}

function elevaro_mail_layout(string $headline, string $body, string $buttonText, string $buttonUrl): string
{
    $headlineEsc = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $buttonTextEsc = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
    $buttonUrlEsc = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;background:#f7f6ff;font-family:Arial,sans-serif;color:#172033;">'
        . '<div style="max-width:620px;margin:0 auto;padding:32px 18px;">'
        . '<div style="background:#fff;border-radius:24px;padding:28px;box-shadow:0 18px 50px rgba(23,32,51,.10);">'
        . '<div style="font-weight:800;color:#5a4ff3;margin-bottom:22px;font-size:20px;">Elevaro</div>'
        . '<h1 style="font-size:32px;line-height:1;margin:0 0 14px;letter-spacing:-.04em;">' . $headlineEsc . '</h1>'
        . '<div style="font-size:16px;line-height:1.55;color:#6c7482;">' . $body . '</div>'
        . '<p style="margin:26px 0 0;"><a href="' . $buttonUrlEsc . '" style="display:inline-block;background:#5a4ff3;color:#fff;text-decoration:none;border-radius:999px;padding:13px 20px;font-weight:800;">' . $buttonTextEsc . '</a></p>'
        . '<p style="margin-top:22px;font-size:12px;color:#9aa1ad;">Falls der Button nicht funktioniert, kopiere diesen Link:<br>' . $buttonUrlEsc . '</p>'
        . '</div></div></body></html>';
}
