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
$app = $private.'/app';
if (!is_readable($app.'/digiops-runtime.php') || !is_readable($app.'/digiops-runtime.sha256')) {
    gate(503, 'FreightSlip runtime metadata unavailable.');
}
require $app.'/digiops-runtime.php';
$runtime = trim((string) file_get_contents($app.'/digiops-runtime.sha256'));
$autoload = null;
if (preg_match('/^[a-f0-9]{64}$/D', $runtime)) {
    foreach ([$app.'/runtimes/'.$runtime, $app.'/vendor'] as $vendor) {
        $packages = freightRuntimePackages($vendor);
        if ($packages !== null && hash_equals($runtime, freightRuntimeHash($packages))) {
            $autoload = $vendor.'/autoload.php';
            break;
        }
    }
}
if ($autoload === null) gate(503, 'Matching FreightSlip runtime unavailable. Deploy a package with bundle_runtime enabled.');
define('FREIGHTSLIP_VENDOR_AUTOLOAD', $autoload);
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
