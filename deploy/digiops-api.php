<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$private = dirname(__DIR__, 3).'/private_html/freightslip';
$storage = $private.'/storage';
function gate(int $status, string $error): never {
    http_response_code($status);
    echo json_encode(['error'=>$error], JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($private.'/app/vendor/autoload.php')) gate(503, 'FreightSlip runtime unavailable.');
$auth = is_readable($storage.'/auth.json') ? json_decode((string) file_get_contents($storage.'/auth.json'), true) : null;
$configured = is_array($auth) && is_string($auth['username']??null) && $auth['username']!==''
    && is_string($auth['password_hash']??null) && password_get_info($auth['password_hash'])['algo']!==null;
if (($_GET['action']??'') === 'health' && ($_SERVER['REQUEST_METHOD']??'') === 'GET') {
    if (!$configured || !is_writable($storage) || PHP_VERSION_ID < 80200) gate(503, 'FreightSlip runtime not ready.');
    foreach (['gd','zip','dom','mbstring','xml','xmlreader','xmlwriter','simplexml','ctype','iconv','fileinfo','filter','zlib'] as $ext) {
        if (!extension_loaded($ext)) gate(503, 'FreightSlip runtime not ready.');
    }
    echo '{"status":"ok"}';
    exit;
}
if (!$configured) gate(503, 'Configure private storage/auth.json before using FreightSlip.');
if (!hash_equals($auth['username'], $_SERVER['PHP_AUTH_USER']??'') || !password_verify($_SERVER['PHP_AUTH_PW']??'', $auth['password_hash'])) {
    header('WWW-Authenticate: Basic realm="FreightSlip", charset="UTF-8"');
    gate(401, 'Sign in at api/index.php, then reload the dashboard.');
}
header_remove('Content-Type');
putenv('LR_DATA_DIR='.$storage);
require $private.'/app/index.php';
