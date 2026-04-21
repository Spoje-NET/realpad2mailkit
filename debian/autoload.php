<?php

declare(strict_types=1);

// Ease framework (from php-vitexsoftware-ease-core)
require_once '/usr/share/php/Ease/autoload.php';

// SpojeNet\Realpad namespace (from php-spojenet-realpad-takeout)
spl_autoload_register(function (string $class): void {
    $prefix = 'SpojeNet\\Realpad\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $file = '/usr/share/php/RealpadTakeout/' . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// PhpOffice\PhpSpreadsheet (from php-phpoffice-phpspreadsheet)
require_once '/usr/share/php/PhpOffice/PhpSpreadsheet/autoload.php';

// Igloonet\MailkitApi (from php-meditorial-mailkit-api)
require_once '/usr/share/php/Igloonet/MailkitApi/autoload.php';

// Spojenet\Realpad2mailkit (this package)
spl_autoload_register(function (string $class): void {
    $prefix = 'Spojenet\\Realpad2mailkit\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $file = '/usr/share/realpad2mailkit/' . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

require_once '/usr/share/php/Composer/InstalledVersions.php';

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = 'unknown';
    $version = '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
