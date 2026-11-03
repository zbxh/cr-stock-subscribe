<?php
// Resolve bundled classes without changing V10's include_path or Composer files.
spl_autoload_register(static function ($class) {
    if (strpos($class, 'Net_DNS2_') === 0) {
        $path = __DIR__ . '/' . str_replace('_', '/', $class) . '.php';
        if (is_file($path)) { require_once $path; }
    }
}, true, true);
require_once __DIR__ . '/Net/DNS2.php';
