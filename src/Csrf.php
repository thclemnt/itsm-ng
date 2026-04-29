<?php

namespace itsmng;

class Csrf
{
    public static function generate(bool $standalone = false): string
    {
        self::clean();

        $token = bin2hex(random_bytes(32));
        $expires = time() + (defined('GLPI_CSRF_EXPIRES') ? GLPI_CSRF_EXPIRES : 7200);

        if (!isset($_SESSION['glpicsrftokens']) || !is_array($_SESSION['glpicsrftokens'])) {
            $_SESSION['glpicsrftokens'] = [];
        }

        $_SESSION['glpicsrftokens'][$token] = $expires;

        if (!$standalone) {
            $_SESSION['_glpi_csrf_token'] = $token;
            $_SESSION['csrf_token_time'] = $expires;
        }

        self::clean();

        return $token;
    }

    public static function verify(?array $data = null): bool
    {
        $data ??= $_POST;
        self::clean();

        if (!isset($data['_glpi_csrf_token']) || !is_scalar($data['_glpi_csrf_token'])) {
            return false;
        }

        $requestToken = (string) $data['_glpi_csrf_token'];
        $now = time();

        if (
            isset($_SESSION['glpicsrftokens'][$requestToken])
            && $_SESSION['glpicsrftokens'][$requestToken] >= $now
        ) {
            self::generate();
            return true;
        }

        if (
            isset($_SESSION['_glpi_csrf_token'], $_SESSION['csrf_token_time'])
            && hash_equals((string) $_SESSION['_glpi_csrf_token'], $requestToken)
            && $_SESSION['csrf_token_time'] >= $now
        ) {
            self::generate();
            return true;
        }

        return false;
    }

    public static function clean(): void
    {
        if (!isset($_SESSION['glpicsrftokens']) || !is_array($_SESSION['glpicsrftokens'])) {
            return;
        }

        $now = time();
        foreach ($_SESSION['glpicsrftokens'] as $token => $expires) {
            if ($expires < $now) {
                unset($_SESSION['glpicsrftokens'][$token]);
            }
        }

        $maxTokens = defined('GLPI_CSRF_MAX_TOKENS') ? GLPI_CSRF_MAX_TOKENS : 100;
        $overflow = count($_SESSION['glpicsrftokens']) - $maxTokens;
        if ($overflow > 0) {
            $_SESSION['glpicsrftokens'] = array_slice(
                $_SESSION['glpicsrftokens'],
                $overflow,
                null,
                true
            );
        }
    }
}
