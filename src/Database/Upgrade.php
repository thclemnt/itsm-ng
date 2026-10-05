<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\History;

/** Supported upgrade entrypoints share canonical history and release publication. */
final class Upgrade
{
    public function __construct(private \DBAdapter $database)
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
        return (new \GLPIKey())->getExpectedKeyPath(GLPI_VERSION);
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
            throw new \RuntimeException('Apply canonical history using the configured write connection; a read connection cannot run upgrades.');
        }
        $this->assertSupportedSchema();
        if ($this->isSecurityKeyMissing()) {
            throw new \RuntimeException('The original encryption key is missing or unreadable: ' . $this->expectedSecurityKeyPath() . '. Restore it from this installation before upgrading; a new key cannot decrypt existing data.');
        }
        $connection = $this->database->getDoctrineConnection();
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            // Config lifecycle writes and audit records must share rollback.
            foreach (['glpi_configs', 'glpi_logs'] as $table) {
                $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
                if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                    throw new \RuntimeException('Canonical release publication requires ' . $table . ' to use InnoDB; found ' . $engine . '. Stop application writers and restore the supported transactional table engine before retrying the upgrade.');
                }
            }
        }
        // The schema and identifier allocation have converged. Publish release
        // metadata together; a failed publication can be retried without replaying history.
        (new History())->upgrade($connection, $progress, function () use ($connection): void {
            $connection->transactional(function (): void {
                $this->publishRelease();
            });
        });
        $this->database->clearSchemaCache();
        if (isset($GLOBALS['GLPI_CACHE'])) {
            $GLOBALS['GLPI_CACHE']->clear();
        }
        if (($GLOBALS['DB'] ?? null) === $this->database) {
            \Config::loadLegacyConfiguration(false);
        }
    }

    /** Publish under History's existing advisory lock, retaining Config hooks. */
    private function publishRelease(): void
    {
        $target = ['version' => ITSM_VERSION, 'itsmversion' => ITSM_VERSION, 'dbversion' => ITSM_SCHEMA_VERSION, 'itsmdbversion' => ITSM_SCHEMA_VERSION];
        $current = $this->release();
        $values = [];
        foreach ($target as $name => $value) {
            if (($current[$name] ?? null) !== $value) {
                $values[$name] = $value;
            }
        }
        // Config's mapped writes retain its update/add hooks and audit
        // history. An idempotent retry does not create duplicate audit entries.
        $configuredDatabase = $GLOBALS['DB'] ?? null;
        try {
            $GLOBALS['DB'] = $this->database;
            \Config::setConfigurationValues('core', $values);
            $published = $this->release();
            foreach ($target as $name => $value) {
                if (($published[$name] ?? null) !== $value) {
                    throw new \RuntimeException('Canonical history is complete, but release publication was rejected for ' . $name . '. Resolve the configuration lifecycle veto and retry db:migrate --apply.');
                }
            }
        } finally {
            $GLOBALS['DB'] = $configuredDatabase;
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
        $platform = $connection->getDatabasePlatform();
        $historical = (new Baseline())->build($platform);
        $required = (new BaselineSchema())->build($platform, false);
        $actual = $connection->createSchemaManager()->introspectSchema();
        $missing = [];
        foreach ($historical->getTables() as $table) {
            if (!$actual->hasTable($table->getName())) {
                $missing[] = 'Missing table: ' . $table->getName();
                continue;
            }
            // Legitimate adoption stages remove historical columns. Later added
            // columns must not become prerequisites for replaying those stages.
            foreach ($table->getColumns() as $column) {
                if ($required->getTable($table->getName())->hasColumn($column->getName()) && !$actual->getTable($table->getName())->hasColumn($column->getName())) {
                    $missing[] = 'Missing column: ' . $table->getName() . '.' . $column->getName();
                }
            }
        }
        if ($missing) {
            throw new \RuntimeException($this->prerequisiteMessage(implode("\n", $missing)));
        }
    }

    private function prerequisiteMessage(string $detail): string
    {
        return 'This schema predates or differs from the frozen ITSM-NG adoption baseline. Upgrade older releases using their matching historical application to the ITSM-NG 2.1.3 schema before switching to this application, then run db:migrate --apply. Historical MySQL scripts cannot run against the canonical ORM schema.' . "\n" . $detail;
    }
}
