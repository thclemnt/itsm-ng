<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Private loopback server configuration; every request uses an application route.
if (PHP_SAPI !== 'cli-server'
    || !in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 3);
define('PLUGINS_DIRECTORIES', [$root . '/plugins', $root . '/tests/fixtures/plugins']);
return false;
