<?php
declare(strict_types=1);

function elevaro_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'elevaro.app';
    return $scheme . '://' . $host;
}

function elevaro_mail_config(): array
{
    static $config = null;
    if ($config !== null) return $config;

    $default = [
        'transport' => 'smtp',
        'from_email' => 'noreply@elevaro.app',
        'from_name' => 'Elevaro',
        'reply_to' => 'noreply@elevaro.app',
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'timeout' => 12,
        ],
        'allow_mail_fallback' => false,
        'debug' => false,
    ];

    $path = dirname(__DIR__, 2) . '/config/mail.php';
    $loaded = is_file($path) ? require $path : [];
    $config = array_replace_recursive($default, is_array($loaded) ? $loaded : []);

    return $config;
}

function elevaro_mail_log(string $message): void
{
    error_log('[Elevaro Mail] ' . $message);
}

function elevaro_mail_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function elevaro_mail_text_from_html(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return $text;
}

function elevaro_mail_layout(string $headline, string $body, string $buttonText = '', string $buttonUrl = '', array $options = []): string
{
    $headlineEsc = elevaro_mail_escape($headline);
    $buttonTextEsc = elevaro_mail_escape($buttonText);
    $buttonUrlEsc = elevaro_mail_escape($buttonUrl);
    $preheader = elevaro_mail_escape((string)($options['preheader'] ?? $headline));
    $eyebrow = elevaro_mail_escape((string)($options['eyebrow'] ?? 'Elevaro'));
    $footer = (string)($options['footer'] ?? 'Du erhältst diese E-Mail, weil jemand Inhalte über Elevaro mit dir geteilt hat.');

    $button = '';
    if ($buttonText !== '' && $buttonUrl !== '') {
        $button = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 0;"><tr><td style="border-radius:999px;background:#5a4ff3;">'
            . '<a href="' . $buttonUrlEsc . '" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-weight:900;border-radius:999px;font-size:15px;">' . $buttonTextEsc . '</a>'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 0;color:#98a2b3;font-size:12px;line-height:1.45;">Falls der Button nicht funktioniert, kopiere diesen Link:<br><span style="word-break:break-all;">' . $buttonUrlEsc . '</span></p>';
    }

    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $headlineEsc . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f6ff;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#172033;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $preheader . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f6ff;"><tr><td align="center" style="padding:32px 14px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;">'
        . '<tr><td style="padding:0 4px 18px;"><div style="font-size:23px;font-weight:950;color:#5a4ff3;letter-spacing:-0.04em;">Elevaro</div></td></tr>'
        . '<tr><td style="background:#ffffff;border:1px solid #e7e8f5;border-radius:28px;overflow:hidden;box-shadow:0 24px 70px rgba(23,32,51,.10);">'
        . '<div style="height:7px;background:linear-gradient(90deg,#5a4ff3,#8b7cff,#22d3ee);"></div>'
        . '<div style="padding:30px 28px 28px;">'
        . '<div style="display:inline-block;margin:0 0 14px;padding:7px 10px;border-radius:999px;background:#f1f0ff;color:#5a4ff3;font-size:12px;font-weight:950;text-transform:uppercase;letter-spacing:.08em;">' . $eyebrow . '</div>'
        . '<h1 style="margin:0 0 14px;font-size:32px;line-height:1.02;letter-spacing:-.055em;color:#172033;font-weight:950;">' . $headlineEsc . '</h1>'
        . '<div style="font-size:16px;line-height:1.55;color:#475467;">' . $body . '</div>'
        . $button
        . '</div></td></tr>'
        . '<tr><td style="padding:18px 6px 0;color:#98a2b3;font-size:12px;line-height:1.5;text-align:center;">' . $footer . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function elevaro_mail_headers(string $to, string $subject, string $boundary, array $config): string
{
    $fromEmail = (string)$config['from_email'];
    $fromName = (string)$config['from_name'];
    $replyTo = (string)($config['reply_to'] ?: $fromEmail);
    $domain = substr(strrchr($fromEmail, '@') ?: '@elevaro.app', 1) ?: 'elevaro.app';

    $headers = [];
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . elevaro_mail_encode_header($fromName) . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . elevaro_mail_encode_header($subject);
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: Elevaro';
    return implode("\r\n", $headers);
}

function elevaro_mail_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function elevaro_mail_message(string $html, string $boundary): string
{
    $text = elevaro_mail_text_from_html($html);
    return '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n"
        . '--' . $boundary . '--';
}

function elevaro_send_mail(string $to, string $subject, string $html): bool
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        elevaro_mail_log('Invalid recipient: ' . $to);
        return false;
    }

    $config = elevaro_mail_config();
    $boundary = 'ev_' . bin2hex(random_bytes(12));
    $headers = elevaro_mail_headers($to, $subject, $boundary, $config);
    $message = elevaro_mail_message($html, $boundary);

    $transport = strtolower((string)$config['transport']);
    if ($transport === 'smtp') {
        $sent = elevaro_send_mail_smtp($to, $headers, $message, $config);
        if ($sent) return true;
        if (empty($config['allow_mail_fallback'])) return false;
        elevaro_mail_log('SMTP failed, trying PHP mail() fallback.');
    }

    return elevaro_send_mail_php($to, $subject, $headers, $message, $config);
}

function elevaro_send_mail_php(string $to, string $subject, string $headers, string $message, array $config): bool
{
    $subjectEncoded = elevaro_mail_encode_header($subject);
    $sent = mail($to, $subjectEncoded, $message, $headers);
    if (!$sent) elevaro_mail_log('PHP mail() failed for ' . $to);
    return $sent;
}

function elevaro_send_mail_smtp(string $to, string $headers, string $message, array $config): bool
{
    $smtp = $config['smtp'] ?? [];
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $username = (string)($smtp['username'] ?? '');
    $password = (string)($smtp['password'] ?? '');
    $timeout = (int)($smtp['timeout'] ?? 12);

    if ($host === '') {
        elevaro_mail_log('SMTP host is empty. Configure config/mail.php or ELEVARO_SMTP_HOST.');
        return false;
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        elevaro_mail_log('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = static function (string $command, array $okCodes) use ($fp, $read): bool {
        fwrite($fp, $command . "\r\n");
        $response = $read();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            elevaro_mail_log('SMTP command failed [' . trim($command) . ']: ' . trim($response));
            return false;
        }
        return true;
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220) {
        elevaro_mail_log('SMTP greeting failed: ' . trim($greeting));
        fclose($fp);
        return false;
    }

    $localhost = $_SERVER['SERVER_NAME'] ?? 'elevaro.app';
    if (!$cmd('EHLO ' . $localhost, [250])) { fclose($fp); return false; }

    if ($encryption === 'tls') {
        if (!$cmd('STARTTLS', [220])) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            elevaro_mail_log('SMTP STARTTLS crypto negotiation failed.');
            fclose($fp);
            return false;
        }
        if (!$cmd('EHLO ' . $localhost, [250])) { fclose($fp); return false; }
    }

    if ($username !== '') {
        if (!$cmd('AUTH LOGIN', [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($username), [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($password), [235])) { fclose($fp); return false; }
    }

    $from = (string)$config['from_email'];
    if (!$cmd('MAIL FROM:<' . $from . '>', [250])) { fclose($fp); return false; }
    if (!$cmd('RCPT TO:<' . $to . '>', [250, 251])) { fclose($fp); return false; }
    if (!$cmd('DATA', [354])) { fclose($fp); return false; }

    fwrite($fp, $headers . "\r\n\r\n" . $message . "\r\n.\r\n");
    $dataResponse = $read();
    $dataCode = (int)substr($dataResponse, 0, 3);
    if ($dataCode !== 250) {
        elevaro_mail_log('SMTP DATA failed: ' . trim($dataResponse));
        fclose($fp);
        return false;
    }

    $cmd('QUIT', [221, 250]);
    fclose($fp);
    return true;
}

function elevaro_mail_unit_share_html(array $unit, array $selectedItems, string $buttonUrl, array $options = []): string
{
    $senderName = trim((string)($options['sender_name'] ?? 'Eine Lehrkraft'));
    $unitTitle = elevaro_mail_escape((string)($unit['title'] ?? 'Elevaro-Unit'));
    $subjectLabel = trim((string)($unit['subject_label'] ?? ''));
    $grade = trim((string)($unit['grade'] ?? ''));
    $topic = trim((string)(($unit['curriculum_subtopic_label'] ?? '') ?: ($unit['curriculum_topic_label'] ?? '') ?: ($unit['subtopic_label'] ?? '') ?: ($unit['topic_label'] ?? '')));

    $metaParts = array_filter([
        $subjectLabel,
        $grade !== '' ? 'Klasse ' . $grade : '',
        $topic,
    ]);
    $meta = elevaro_mail_escape(implode(' · ', $metaParts));

    $coverPath = trim((string)($unit['image_path'] ?? ''));
    if ($coverPath === '') {
        foreach ($selectedItems as $item) {
            $candidate = trim((string)($item['image_path'] ?? ''));
            if ($candidate !== '') { $coverPath = $candidate; break; }
        }
    }
    $coverUrl = '';
    if ($coverPath !== '') {
        if (preg_match('~^https?://~i', $coverPath)) {
            $coverUrl = $coverPath;
        } else {
            $coverUrl = rtrim(elevaro_base_url(), '/') . '/' . ltrim($coverPath, '/');
        }
    }
    $coverHtml = $coverUrl !== ''
        ? '<div style="height:190px;background-image:url(' . elevaro_mail_escape($coverUrl) . ');background-size:cover;background-position:center;border-radius:22px;margin:-2px -2px 18px;overflow:hidden;"></div>'
        : '<div style="height:8px;background:linear-gradient(90deg,#5a4ff3,#8b7cff,#22d3ee);border-radius:999px;margin:0 0 18px;"></div>';

    $preview = '';
    foreach ($selectedItems as $item) {
        $type = (string)($item['type'] ?? $item['item_type'] ?? 'quiz');
        $icon = match ($type) {
            'worksheet' => '📄',
            'listening' => '🎧',
            'reading' => '📖',
            default => '🎮',
        };
        $label = match ($type) {
            'worksheet' => 'Arbeitsblatt',
            'listening' => 'Listening',
            'reading' => 'Leseverständnis',
            default => 'Quiz',
        };
        $preview .= '<div style="display:flex;gap:12px;align-items:center;background:#ffffff;border:1px solid #eceafe;border-radius:16px;padding:12px 14px;margin:9px 0;">'
            . '<div style="width:38px;height:38px;border-radius:14px;background:#f3f1ff;display:flex;align-items:center;justify-content:center;font-size:19px;">' . elevaro_mail_escape($icon) . '</div>'
            . '<div><div style="font-weight:950;color:#172033;line-height:1.22;">' . elevaro_mail_escape((string)($item['title'] ?? 'Unbenannter Inhalt')) . '</div>'
            . '<div style="font-size:12px;color:#667085;font-weight:800;margin-top:2px;">' . elevaro_mail_escape($label) . (!empty($item['question_count']) ? ' · ' . (int)$item['question_count'] . ' Fragen' : '') . '</div></div></div>';
    }

    if ($preview === '') {
        $preview = '<p style="margin:0;color:#667085;">Die freigegebenen Inhalte dieser Unit.</p>';
    }

    $body = '<p style="margin:0 0 18px;color:#172033;"><strong>' . elevaro_mail_escape($senderName) . '</strong> hat folgende Inhalte mit dir geteilt:</p>'
        . '<div style="border:1px solid #e6e2ff;background:linear-gradient(135deg,#f7f6ff,#ffffff);border-radius:24px;padding:20px;margin:14px 0 20px;">'
        . $coverHtml
        . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#5a4ff3;font-weight:950;margin-bottom:8px;">Geteilte Unit</div>'
        . '<div style="font-size:27px;line-height:1.07;color:#172033;font-weight:950;letter-spacing:-.04em;margin-bottom:8px;">' . $unitTitle . '</div>'
        . ($meta !== '' ? '<div style="color:#667085;font-weight:800;margin-bottom:14px;">' . $meta . '</div>' : '')
        . $preview
        . '</div>'
        . '<p style="color:#667085;line-height:1.55;margin:0;">Du kannst die Inhalte 24 Stunden ohne Registrierung ansehen. Mit einem kostenlosen Elevaro-Account speicherst du die Freigabe dauerhaft in deiner Bibliothek.</p>';

    return elevaro_mail_layout('Elevaro-Inhalte geteilt', $body, 'Inhalte anzeigen', $buttonUrl, [
        'eyebrow' => 'Freigabe',
        'preheader' => $senderName . ' hat Elevaro-Inhalte mit dir geteilt.',
    ]);
}
