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

// PhpOffice\PhpSpreadsheet (installed by php-spojenet-realpad-takeout via composer-debian)
if (file_exists('/var/lib/composer/realpad-takeout/autoload.php')) {
    require_once '/var/lib/composer/realpad-takeout/autoload.php';
}

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
