<?php
declare(strict_types=1);
spl_autoload_register(static function (string $name): void {
    $prefix = 'KMM\\';
    if (!str_starts_with($name, $prefix)) { return; }
    $relative = substr($name, strlen($prefix));
    if ($relative === '' || str_contains($relative, '..')) { return; }
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) { require $path; }
});
