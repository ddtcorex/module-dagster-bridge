<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Bootstrap for the module's own unit tests.
 *
 * They run without a Magento installation: the module's classes and
 * magento/framework come from Composer, and every application service the
 * tests touch is mocked.
 */
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException("Run 'composer install' before the unit tests.");
}

require $autoload;
