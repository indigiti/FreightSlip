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
// The private HTTP boundary owns login, sessions, CSRF, and all dispatch routes.
putenv('LR_DATA_DIR='.$storage);
require $private.'/app/index.php';
