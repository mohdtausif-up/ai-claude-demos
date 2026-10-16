<?php

// Dev tool: which performance-relevant extensions/settings are active in this container?
echo 'PHP ' . PHP_VERSION . "\n";
echo 'opcache extension loaded: ' . (extension_loaded('Zend OPcache') ? 'yes' : 'NO') . "\n";
foreach (['opcache.enable', 'opcache.enable_cli', 'opcache.validate_timestamps', 'opcache.revalidate_freq', 'opcache.memory_consumption', 'realpath_cache_size', 'realpath_cache_ttl'] as $k) {
    echo str_pad($k, 30) . var_export(ini_get($k), true) . "\n";
}
echo 'argon2id: ' . (defined('PASSWORD_ARGON2ID') ? 'yes' : 'no') . ', pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . ', dom: ' . (extension_loaded('dom') ? 'yes' : 'no') . ', mbstring: ' . (extension_loaded('mbstring') ? 'yes' : 'no') . "\n";
