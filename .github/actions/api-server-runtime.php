<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Included by the owned CI router before application bootstrap, in the HTTP SAPI.
$runtimePath = getenv('ITSM_API_SERVER_RUNTIME');
if (is_string($runtimePath) && $runtimePath !== '' && !file_exists($runtimePath)) {
    $settings = [];
    foreach (['memory_limit', 'opcache.enable', 'opcache.enable_cli', 'opcache.jit',
        'opcache.jit_buffer_size', 'zend.max_allowed_stack_size', 'zend.reserved_stack_size'] as $name) {
        $settings[$name] = ini_get($name);
    }
    $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $jit = [];
    foreach (['enabled', 'on', 'kind', 'opt_level', 'opt_flags', 'buffer_size', 'buffer_free'] as $name) {
        if (is_array($status) && isset($status['jit'][$name]) && is_scalar($status['jit'][$name])) {
            $jit[$name] = $status['jit'][$name];
        }
    }
    file_put_contents($runtimePath, json_encode(['sapi' => PHP_SAPI, 'php' => PHP_VERSION,
        'ini' => $settings, 'opcache_enabled' => is_array($status) ? ($status['opcache_enabled'] ?? null) : false,
        'jit' => $jit], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}
unset($runtimePath, $settings, $status, $jit, $name);
