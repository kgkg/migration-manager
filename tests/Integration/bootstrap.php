<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Never fall back to application credentials or an implicit local database.
if (getenv('MIGRATION_MANAGER_TEST_ALLOW_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Integration tests require MIGRATION_MANAGER_TEST_ALLOW_DESTRUCTIVE=1.');
}

foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $setting) {
    $value = getenv('MIGRATION_MANAGER_TEST_' . $setting);
    if ($value === false || ($value === '' && $setting !== 'PASSWORD')) {
        throw new RuntimeException('Missing integration setting: MIGRATION_MANAGER_TEST_' . $setting);
    }
}

if (preg_match('/^migration_manager_test_[a-zA-Z0-9_]+$/D', getenv('MIGRATION_MANAGER_TEST_DATABASE')) !== 1) {
    throw new RuntimeException('Use a dedicated database named migration_manager_test_<suffix>.');
}

$testPort = filter_var(getenv('MIGRATION_MANAGER_TEST_PORT'), FILTER_VALIDATE_INT);
if ($testPort === false || $testPort < 1 || $testPort > 65535) {
    throw new RuntimeException('Integration database port must be an integer between 1 and 65535.');
}
