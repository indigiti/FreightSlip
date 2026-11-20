<?php
declare(strict_types=1);

function freightRuntimePackages(string $vendor): ?array {
    $file = $vendor.'/composer/installed.json';
    if (!is_readable($vendor.'/autoload.php') || !is_readable($file)) return null;
    $manifest = json_decode((string) file_get_contents($file), true);
    if (!is_array($manifest['packages']??null) || !$manifest['packages']) return null;
    $packages = [];
    foreach ($manifest['packages'] as $package) {
        if (!is_string($package['name']??null) || !is_string($package['version']??null)) return null;
        $packages[$package['name']] = [$package['version'], $package['source']['reference']??null, $package['dist']['reference']??null];
    }
    ksort($packages);
    return $packages;
}

function freightRuntimeHash(array $packages): string {
    return hash('sha256', json_encode($packages, JSON_THROW_ON_ERROR));
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    $packages = freightRuntimePackages(dirname(__DIR__).'/php/vendor');
    if (!$packages) throw new RuntimeException('Composer runtime is missing.');
    $baseline = json_decode((string) file_get_contents(__DIR__.'/runtime-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
    ksort($baseline);
    $full = getenv('FREIGHTSLIP_FULL_RUNTIME') === 'true' || array_map(static fn($p) => $p[0], $packages) !== $baseline;
    echo freightRuntimeHash($packages), ' ', ($full ? 'full' : 'update'), PHP_EOL;
}
