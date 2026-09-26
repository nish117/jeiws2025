<?php
/**
 * Google reCAPTCHA v2 server-side verification — shared by every form
 * handler (send_email.php, send_application.php) so the curl/file_get_contents
 * fallback logic lives in exactly one place.
 */
function verifyRecaptcha(string $secretKey, string $response, string $remoteIp): bool {
    if ($response === '') return false;
    $payload = http_build_query([
        'secret'   => $secretKey,
        'response' => $response,
        'remoteip' => $remoteIp,
    ]);
    $result = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
    }
    if ($result === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);
        $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    }
    if ($result === false) return false;
    $data = json_decode($result, true);
    return (bool) ($data['success'] ?? false);
}
