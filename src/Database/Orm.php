<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;
use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Query\AutoNameNumber;
use itsmng\Database\Query\BitCount;
use itsmng\Database\Query\CurrentEpochSeconds;
use itsmng\Database\Query\EpochSeconds;
use itsmng\Database\Query\KnowledgeBaseFullText;
use itsmng\Database\Query\Replace;
use itsmng\Database\Query\TemporalText;
use itsmng\Database\Query\YearMonth;
use itsmng\Database\Repository\DropdownChoiceRepository;
use itsmng\Database\Type\ClockTimeType;
use itsmng\Database\Type\FixedStringType;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;

final class Orm
{
    private static int $unitsOfWork = 0;
    /**
     * A unit of work never outlives an application operation: legacy writers do
     * not notify Doctrine's identity map. The connection and transaction are shared.
     */
    public static function create(DBAdapter $db): EntityManager
    {
        $connection = $db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($db, $connection);
        return self::forConnection($connection);
    }

    /** @internal Read fully materialized values; never return entities, repositories or lazy iterators. */
    public static function read(DBAdapter $db, callable $operation, bool $clearCustomManager = false): mixed
    {
        $connection = $db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($db, $connection);
        if ($clearCustomManager) {
            return self::withConnection($connection, $operation);
        }
        return self::withReadConnection($connection, static fn (?EntityManager $manager): mixed =>
            $operation($manager ?? self::forConnection($connection)));
    }

    /** @internal Prepare values before a materialized read; never return entities, repositories or lazy iterators. */
    public static function readPrepared(DBAdapter $db, callable $prepare, callable $operation): mixed
    {
        $connection = $db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($db, $connection);
        $manager = self::prepareReadProjection($connection) && self::canShareReadManager($connection)
            ? null : self::forConnection($connection);
        $prepared = $prepare();
        if ($manager === null && self::canShareReadManager($connection)) {
            return $connection->withApplicationEntityManager(static fn (EntityManager $manager): mixed =>
                $operation($manager, $prepared));
        }
        return $operation($manager ?? self::forConnection($connection), $prepared);
    }

    /** @internal Interleaved preparation and materialized reads on one already selected route. */
    public static function readSession(DBAdapter $db): MaterializedReadSession
    {
        $connection = $db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($db, $connection);
        $shared = self::prepareReadProjection($connection) && self::canShareReadManager($connection)
            && !$connection->isApplicationEntityManagerActive();
        return new MaterializedReadSession($connection, $shared ? null : self::forConnection($connection));
    }

    private static function canShareReadManager(Connection $connection): bool
    {
        if ((!$connection instanceof MySQLManagedConnection && !$connection instanceof PostgresConnection)
            || !self::ownsReadMapping($connection)) {
            return false;
        }
        static $previousTypes = null;
        static $stableTypes = false;
        $types = DbalType::getTypeRegistry()->getMap();
        if ($previousTypes !== $types) {
            $stableTypes = !array_filter($types, static fn (DbalType $type, string $name): bool =>
                !self::stableSqlConversion($name, $type), ARRAY_FILTER_USE_BOTH);
            $previousTypes = $types;
        }
        return $stableTypes;
    }

    /** @internal Value-only application work; custom configurations use create()/forConnection(). */
    public static function withConnection(Connection $connection, callable $operation): mixed
    {
        if (self::canShareReadManager($connection)) {
            return $connection->withApplicationEntityManager($operation);
        }
        // Supplied/custom connections retain independently mutable configuration.
        $manager = self::forConnection($connection);
        $primary = null;
        try {
            return $operation($manager);
        } catch (Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            try {
                $manager->clear();
            } catch (Throwable $cleanup) {
                throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
            }
        }
    }

    /** @internal Completed value operations may write; custom callers construct their independent manager. */
    public static function withOperation(Connection $connection, callable $operation): mixed
    {
        if (!self::canShareReadManager($connection)) {
            return $operation(null);
        }
        return $connection->withApplicationEntityManager($operation);
    }

    /** @internal Custom readers retain their original constructor and clear callbacks. */
    public static function withReadConnection(Connection $connection, callable $operation): mixed
    {
        return self::withOperation($connection, $operation);
    }

    /** Canonical declarations cannot depend on externally mutable mapping callbacks. */
    public static function ownsReadMapping(Connection $connection): bool
    {
        $platformFile = (new ReflectionClass($connection->getDatabasePlatform()))->getFileName();
        $dbalPath = InstalledVersions::getInstallPath('doctrine/dbal');
        return $platformFile !== false && $dbalPath !== null
            && ($platformFile = realpath($platformFile)) !== false
            && ($dbalPath = realpath($dbalPath)) !== false
            && str_starts_with($platformFile, $dbalPath . '/src/Platforms/')
            && !method_exists($connection, 'getEventManager');
    }

    /** @internal Eager projection admission; extension routes still construct their own manager. */
    public static function prepareReadProjection(Connection $connection): bool
    {
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')
            || !self::ownsReadMapping($connection)) {
            return false;
        }
        self::registerTypes();
        return true;
    }

    /** SQL retained by Doctrine persisters must not depend on a live custom converter. */
    public static function stableSqlConversion(string $name, DbalType $type): bool
    {
        static $classes = [];
        $class = $type::class;
        if ($name === FixedStringType::NAME || $class === FixedStringType::class) {
            return $name === FixedStringType::NAME && $class === FixedStringType::class;
        }
        return $classes[$class] ??= (new ReflectionMethod($type, 'convertToPHPValueSQL'))->getDeclaringClass()->getName() === DbalType::class
            && (new ReflectionMethod($type, 'convertToDatabaseValueSQL'))->getDeclaringClass()->getName() === DbalType::class;
    }

    /** Construct on the operation's already selected route without resolving it again. */
    public static function forConnection(Connection $connection): EntityManager
    {
        $configuration = self::configuration($connection->getDatabasePlatform());
        // A metadata factory and its unit of work refer back to their manager.
        // After bulk mapping inspection PHP raises its automatic GC threshold;
        // discarded managers can then accumulate faster than it collects them.
        // Collect in bounded batches without sharing or clearing live managers.
        if (++self::$unitsOfWork % 8 === 0) {
            gc_collect_cycles();
        }
        return new EntityManager($connection, $configuration);
    }

    /** One current model-row operation; only its scalar branch owns a private compiled plan. */
    public static function readRecord(DBAdapter $db, string $table, string $column, int $id): ?array
    {
        $connection = $db->getDoctrineConnection();
        return self::withReadConnection($connection, static function (?EntityManager $manager) use ($connection, $table, $column, $id): ?array {
            $operation = new RecordReadOperation($connection, $manager);
            try {
                return $operation->row($table, $column, $id);
            } finally {
                $operation->close();
            }
        });
    }

    /** @internal Scalar readers need the application types without allocating ORM configuration. */
    public static function registerTypes(): void
    {
        if (!DbalType::hasType(ClockTimeType::NAME)) {
            DbalType::addType(ClockTimeType::NAME, ClockTimeType::class);
        }
        if (!DbalType::hasType(FixedStringType::NAME)) {
            DbalType::addType(FixedStringType::NAME, FixedStringType::class);
        }
    }

    /** Independent mutable configuration; no caller can alter another operation. */
    public static function configuration(AbstractPlatform $platform): Configuration
    {
        $proxyDirectory = defined('GLPI_CACHE_DIR') ? GLPI_CACHE_DIR . '/orm' : sys_get_temp_dir() . '/itsm-orm';
        self::registerTypes();
        $config = new Configuration();
        $config->setDefaultRepositoryClassName(DropdownChoiceRepository::class);
        $config->addCustomStringFunction('REPLACE', Replace::class);
        $config->addCustomStringFunction('YEAR_MONTH', YearMonth::class);
        $config->addCustomStringFunction('TEMPORAL_TEXT', TemporalText::class);
        $config->addCustomNumericFunction('BIT_COUNT', BitCount::class);
        $config->addCustomNumericFunction('EPOCH_SECONDS', EpochSeconds::class);
        $config->addCustomNumericFunction('CURRENT_EPOCH_SECONDS', CurrentEpochSeconds::class);
        $config->addCustomNumericFunction('AUTO_NAME_NUMBER', AutoNameNumber::class);
        $config->addCustomNumericFunction('KB_MATCH', KnowledgeBaseFullText::class);
        $config->addCustomNumericFunction('KB_SCORE', KnowledgeBaseFullText::class);
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity'], $platform));
        // Serialization keeps mutable metadata (e.g. assigned-ID imports) local
        // to each metadata factory, rather than leaking changes between units of work.
        $config->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        $config->setProxyDir($proxyDirectory);
        $config->setProxyNamespace('itsmng\\Database\\Proxy');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        return $config;
    }
}
