<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;

/** @internal Private implementation shared only by final read operation owners. */
trait PrivateReadOwnership
{
    private EntityManager $manager;
    private bool $suppliedManager = false;
    private bool $cleanupRequired = true;
    private bool $sharedManager = false;
    private readonly Connection $connection;
    private ?SerializedMetadataCache $queryCache = null;
    private array $identifiers = [];
    private bool $ownedMapping;
    private bool $readCachesInitialized = false;
    private mixed $pool;
    private ?string $context = null;

    /** @internal A supplied manager belongs to the enclosing value-only application scope. */
    public function __construct(Connection $connection, ?EntityManager $manager = null)
    {
        $this->initializeReadManager($connection, $manager);
        $this->initializeReadCaches();
    }

    private function initializeReadManager(Connection $connection, ?EntityManager $manager = null): void
    {
        $this->connection = $connection;
        if ($manager !== null && $manager->getConnection() !== $connection) {
            throw new LogicException('A read operation must use its selected physical connection.');
        }
        $this->suppliedManager = $manager !== null;
        $this->sharedManager = $this->suppliedManager
            && ($connection instanceof MySQLManagedConnection || $connection instanceof PostgresConnection)
            && $connection->ownsApplicationEntityManager($manager);
        $this->manager = $manager ?? Orm::forConnection($connection);
        $this->ownedMapping = !$this->suppliedManager || $this->sharedManager;
    }

    private function initializeReadCaches(?Configuration $configuration = null): void
    {
        if ($this->readCachesInitialized) {
            return;
        }
        $this->readCachesInitialized = true;
        $connection = $this->connection;
        $configuration ??= $this->manager->getConfiguration();
        $this->pool = $this->ownedMapping ? ($GLOBALS['GLPI_CACHE'] ?? null) : null;
        $this->identifiers = $this->ownedMapping ? EntityRegistry::scalarIdentifiers() : [];
        if ($this->pool instanceof CacheInterface) {
            $this->context = hash('sha256', MappingFingerprint::current() . "\0" . $connection->getDatabasePlatform()::class
                . "\0" . $configuration->getMetadataDriverImpl()::class . "\0" . $configuration->getProxyDir());
        }
    }

    private function metadata(string $table, ?string $lookupColumn = null): ClassMetadata
    {
        $class = EntityRegistry::tables()[$table];
        if ($this->suppliedManager && !$this->sharedManager) {
            return $this->manager->getClassMetadata($class);
        }
        // Only canonical source declarations enter the private persistent namespace.
        // Custom/composite roots continue with the independently mutable local cache.
        $persistent = isset($this->identifiers[$class]) && $this->context !== null
            && ($lookupColumn === null || $this->identifiers[$class]['column'] === $lookupColumn);
        $cache = $persistent
            ? new SerializedMetadataCache($this->pool, 'orm_record_metadata_' . $this->context)
            : new ArrayAdapter(storeSerialized: true);
        $this->manager->getConfiguration()->setMetadataCache($cache);
        $this->manager->getMetadataFactory()->setCache($cache);
        return $this->manager->getClassMetadata($class);
    }

    private function defaultIdentifiers(ClassMetadata $metadata): ?array
    {
        if (count($metadata->identifier) !== 1 || !$metadata->hasField($metadata->identifier[0])) {
            return null;
        }
        $property = $metadata->identifier[0];
        return ($this->identifiers[$metadata->name] ?? null) === [
            'property' => $property,
            'column' => $metadata->getColumnName($property),
            'type' => $metadata->getTypeOfField($property),
        ] ? $this->identifiers : null;
    }

    /** Internal repository seam: an arbitrary supplied manager cannot borrow this plan cache. */
    public function prepareQuery(Query $query, ClassMetadata $metadata): void
    {
        if ($query->getEntityManager() !== $this->manager) {
            throw new LogicException('A compiled read plan belongs to its private operation.');
        }
        if ($this->context === null || $this->defaultIdentifiers($metadata) === null) {
            return;
        }
        $cache = $this->queryCache ??= new SerializedMetadataCache($this->pool, 'orm_record_query_' . $this->context);
        $local = $this->sharedManager
            && ($this->connection instanceof MySQLManagedConnection || $this->connection instanceof PostgresConnection)
                ? $this->connection->getApplicationQueryCache($this->manager)
                : null;
        if ($local !== null) {
            // Valid private plans live with this manager; backend changes affect cold reads.
            $cache = new ChainAdapter([
                new ProxyAdapter($local, 'orm_record_query_' . $this->context),
                $cache,
            ]);
        }
        $query->setQueryCache($cache);
    }

    /** A retained private reader starts a fresh cleanup obligation on every read. */
    private function beginRead(): void
    {
        if (!$this->suppliedManager) {
            $this->cleanupRequired = true;
        }
    }

    public function close(): void
    {
        if (!$this->suppliedManager && isset($this->manager)) {
            $manager = $this->manager;
            unset($this->manager);
            if ($this->cleanupRequired) {
                $this->cleanupRequired = false;
                $manager->clear();
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
