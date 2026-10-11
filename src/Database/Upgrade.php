<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Config;
use DBAdapter;
use GLPIKey;
use itsmng\Database\Migration\History;
use RuntimeException;

/** Supported upgrade entrypoints share canonical history and release publication. */
final class Upgrade
{
    public function __construct(private DBAdapter $database)
    {
    }

    public function plan(): array
    {
        $this->assertSupportedSchema();
        return (new History())->plan($this->database->getDoctrineConnection()) + [
            'release' => $this->release(),
            'security_key' => $this->expectedSecurityKeyPath(),
            'security_key_missing' => $this->isSecurityKeyMissing(),
        ];
    }

    /** Never regenerate a lost key: existing encrypted data needs its original key. */
    public function expectedSecurityKeyPath(): ?string
    {
        // Structural adoption supports the frozen ITSM baseline descended from
        // GLPI 9.5. Missing aliases cannot turn ITSM 2.x into pre-key GLPI 2.x.
        return (new GLPIKey())->getExpectedKeyPath(GLPI_VERSION);
    }

    public function isSecurityKeyMissing(): bool
    {
        $path = $this->expectedSecurityKeyPath();
        if ($path !== null) {
            clearstatcache(true, $path);
        }
        return $path !== null && (!is_file($path) || !is_readable($path) || filesize($path) === 0);
    }

    public function apply(?callable $progress = null): void
    {
        if ($this->database->isSlave()) {
            throw new RuntimeException('Apply canonical history using the configured write connection; a read connection cannot run upgrades.');
        }
        $this->assertSupportedSchema();
        if ($this->isSecurityKeyMissing()) {
            throw new RuntimeException('The original encryption key is missing or unreadable: ' . $this->expectedSecurityKeyPath() . '. Restore it from this installation before upgrading; a new key cannot decrypt existing data.');
        }
        $connection = $this->database->getDoctrineConnection();
        ReleasePublication::assertStorage($connection);
        // The schema and identifier allocation have converged. Publish release
        // metadata together; a failed publication can be retried without replaying history.
        (new History())->upgrade($connection, $progress, function (): void {
            ReleasePublication::publish($this->database);
        });
        $this->database->clearSchemaCache();
        if (isset($GLOBALS['GLPI_CACHE'])) {
            $GLOBALS['GLPI_CACHE']->clear();
        }
        if (($GLOBALS['DB'] ?? null) === $this->database) {
            Config::loadLegacyConfiguration(false);
        }
    }

    /** Inspect configuration through DBAL before current ORM mappings can be used. */
    public function release(): array
    {
        return LegacyAdoptionEligibility::release($this->database->getDoctrineConnection());
    }

    private function assertSupportedSchema(): void
    {
        $connection = $this->database->getDoctrineConnection();
        LegacyAdoptionEligibility::assertConnection($connection);
        // Every pending release admits its own frozen source. Current mappings
        // may describe tables/columns introduced or removed by later releases.
    }
}
