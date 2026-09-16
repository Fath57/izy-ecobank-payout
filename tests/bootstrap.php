<?php

declare(strict_types=1);

/*
 * A four-line autoloader instead of Composer's.
 *
 * The package has no runtime dependencies, so requiring an install before the tests can
 * run would be the only reason to need one. Composer's autoloader is used when the
 * package is installed as a dependency; this one keeps `phpunit` working in a fresh
 * clone with nothing fetched.
 */
spl_autoload_register(static function (string $class): void {
    foreach ([
        'Izy\\EcobankPayout\\Tests\\' => __DIR__ . '/',
        'Izy\\EcobankPayout\\' => __DIR__ . '/../src/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;

                return;
            }
        }
    }
});
