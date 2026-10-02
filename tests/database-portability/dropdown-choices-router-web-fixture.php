<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// PHP's private loopback test server only. This file is never an application route.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['SERVER_NAME'], ['127.0.0.1', 'localhost'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
define('PLUGINS_DIRECTORIES', [$root . '/plugins', $root . '/tests/fixtures/plugins']);
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/front/__dropdown_fixture.php') {
    // The built-in server otherwise reports index.php PATH_INFO for the virtual route.
    $_SERVER['PHP_SELF'] = '/front/__dropdown_fixture.php';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
    chdir($root . '/front');
    define('GLPI_DROPDOWN_TEST_ROUTE', true);
    require __DIR__ . '/dropdown-choices-web-fixture.php';
    return true;
}
return false;
