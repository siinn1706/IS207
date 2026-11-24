<?php

use Dotenv\Dotenv;

/*
 * KhoHang runtime settings. Secrets keep living in KhoHang_API/.env (the file the
 * FastAPI backend already used) so both backends share JWT secret, SMTP and Gemini
 * credentials. php/.env values take precedence when set.
 */

$legacyEnv = [];
$legacyEnvFile = dirname(__DIR__, 2).'/.env';
if (is_file($legacyEnvFile)) {
    try {
        $legacyEnv = Dotenv::parse((string) file_get_contents($legacyEnvFile));
    } catch (Throwable) {
        $legacyEnv = [];
    }
}

$get = function (string $key, $default = null) use ($legacyEnv) {
    $v = env($key);
    if ($v !== null && $v !== '') {
        return $v;
    }

    return $legacyEnv[$key] ?? $default;
};

$dataDir = $get('KHOHANG_DATA_DIR', dirname(__DIR__, 2).'/data');

return [
    'data_dir' => $dataDir,
    'uploads_dir' => $dataDir.'/uploads',
    'templates_dir' => $get('KHOHANG_TEMPLATES_DIR', dirname(__DIR__, 2).'/templates'),

    'jwt_secret' => (string) $get('JWT_SECRET', ''),
    'jwt_exp_days' => (int) $get('JWT_EXP_DAYS', 7),

    'smtp' => [
        'host' => $get('SMTP_HOST', 'smtp.gmail.com'),
        'port' => (int) $get('SMTP_PORT', 587),
        'user' => (string) $get('SMTP_USER', ''),
        'pass' => (string) $get('SMTP_PASS', ''),
        'from' => (string) $get('FROM_EMAIL', (string) $get('SMTP_USER', '')),
    ],

    'gemini_api_key' => (string) $get('GEMINI_API_KEY', ''),
    'gemini_model' => 'gemini-2.5-pro',
    'gemini_fallback_model' => 'gemini-2.5-flash',

    // Realtime (Workerman) server
    'ws_host' => $get('RT_WS_HOST', '0.0.0.0'),
    'ws_port' => (int) $get('RT_WS_PORT', 8001),
    // Loopback-only port the HTTP app uses to push system events into the WS process
    'ws_internal_port' => (int) $get('RT_INTERNAL_PORT', 8002),
];
