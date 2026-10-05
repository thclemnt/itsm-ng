<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\CheckConstraintSupport;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;
use itsmng\Database\Migration\V220\Baseline;

/** Ordered ORM releases; internal checkpoints are not application releases. */
final class History
{
    /** Append subsequent ORM releases here, in dependency order. */
    private const MIGRATIONS = [Version220::class];

    /** @var list<ReleaseMigration> */
    private array $migrations;

    /** An explicit chain also permits focused release-order contracts. */
    public function __construct(?array $migrations = null)
    {
        $this->migrations = $migrations ?? array_map(static fn (string $class): ReleaseMigration => new $class(), self::MIGRATIONS);
    }

    public static function versions(): array
    {
        return array_map(static fn (ReleaseMigration $migration): string => $migration->version(), (new self())->migrations);
    }

    public static function pendingVersions(Connection $connection): array
    {
        $states = Ledger::states($connection);
        return array_values(array_filter(self::versions(), static fn (string $version): bool => !self::complete($version, $states)));
    }

    private static function complete(string $version, array $states): bool
    {
        return ($states[$version]['complete'] ?? false) === true
            && ($version !== Version220::VERSION || Version220::pendingPhases($states) === []);
    }

    public static function isInstalling(Connection $connection): bool
    {
        return Version220::isInstalling($connection);
    }

    public function plan(Connection $connection): array
    {
        $states = Ledger::states($connection);
        $pending = [];
        $details = [];
        $deferred = [];
        foreach ($this->migrations as $migration) {
            $version = $migration->version();
            if (self::complete($version, $states)) {
                continue;
            }
            $pending[] = $version;
            if (($states[$version]['applied'] ?? false) === true) {
                continue;
            }
            if ($details) {
                $deferred[] = $version;
                continue;
            }
            // Later plans can require tables introduced by this one. A read-only
            // preview reports that dependency without executing its predecessor.
            $details = $migration->plan($connection);
            $details['planned_release'] = $version;
        }
        return ['complete' => !$pending, 'pending' => $pending, 'deferred_releases' => $deferred] + $details;
    }

    public function baseline(Connection $connection, ?callable $progress = null): void
    {
        (new Version220())->baseline($connection, $progress);
    }

    public function install(\DBAdapter $database, string $language, ?callable $progress = null): void
    {
        $connection = $database->getDoctrineConnection();
        $this->locked($connection, function () use ($database, $connection, $language, $progress): void {
            (new Version220())->install($database, $language, $progress);
            $this->replay($connection, $progress, null);
            $database->clearSchemaCache();
        });
    }

    public function upgrade(Connection $connection, ?callable $progress = null, ?callable $onComplete = null): void
    {
        $this->locked($connection, fn () => $this->replay($connection, $progress, $onComplete));
    }

    private function replay(Connection $connection, ?callable $progress, ?callable $onComplete): void
    {
        CheckConstraintSupport::assertSupported($connection);
        $states = Ledger::states($connection);
        foreach ($this->migrations as $migration) {
            $version = $migration->version();
            if (self::complete($version, $states) || ($states[$version]['applied'] ?? false) === true) {
                continue;
            }
            $migration->apply($connection, $progress);
            $migration->verify($connection);
            // MySQL later DDL may commit before a subsequent release fails. Its
            // verified predecessor must not be revalidated against that newer
            // partial schema. This is progress, not release publication.
            Ledger::save($connection, $version, ['complete' => false, 'applied' => true]);
        }
        $differences = (new SchemaCheck())->differences($connection);
        if ($differences) {
            throw new \RuntimeException("Migration history did not converge:\n" . implode("\n", $differences));
        }
        SequenceSynchronizer::synchronize($connection);
        $frame = OwnedMutationFrame::begin($connection);
        try {
            if ($onComplete !== null) {
                $onComplete();
                $frame->assertActive();
            }
            foreach ($this->migrations as $migration) {
                if ((Ledger::state($connection, $migration->version())['complete'] ?? false) !== true) {
                    Ledger::save($connection, $migration->version(), ['complete' => true]);
                }
            }
            $baseline = Ledger::state($connection, Baseline::PHASE);
            if (($baseline['origin'] ?? null) === 'installed' && ($baseline['installation_complete'] ?? false) !== true) {
                $baseline['installation_complete'] = true;
                Ledger::save($connection, Baseline::PHASE, $baseline);
            }
            $frame->commit();
        } catch (\Throwable $error) {
            try {
                $frame->rollBack();
            } catch (\Throwable $cleanup) {
                throw new \itsmng\Database\MutationRollbackFailure($error, $cleanup);
            }
            throw $error;
        }
    }

    private function locked(Connection $connection, callable $operation): void
    {
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            OwnedMutationFrame::run($connection, static function () use ($connection, $operation): void {
                $connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('itsmng_migration_history'))");
                $operation();
            });
            return;
        }
        if ($connection->isTransactionActive()) {
            throw new \RuntimeException('Run migration history outside a MySQL application transaction.');
        }
        $lock = 'itsmng_history_' . sha1($connection->getDatabase());
        if ((int)$connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new \RuntimeException('Another migration history operation is running.');
        }
        try {
            $operation();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
