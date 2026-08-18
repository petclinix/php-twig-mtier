<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Loaded only for PHPUnit runs (via phpunit.xml's bootstrap), never by
// public/index.php — so the real `php -S` subprocess spawned by the HTTP
// integration tests keeps using the real global header().
require __DIR__ . '/Support/header_overrides.php';
