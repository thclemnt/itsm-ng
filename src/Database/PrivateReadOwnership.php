<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** @internal Private implementation shared only by final read operation owners. */
trait PrivateReadOwnership
{
    private EntityManager $manager;
    private bool $suppliedManager = false;
    private bool $sharedManager = false;
    private readonly Connection $connection;
    private ?SerializedMetadataCache $queryCache = null;
    private array $identifiers = [];
    private bool $ownedMapping;
    private bool $persistentMetadataLoaded = false;
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
        $this->ownedMapping = (!$this->suppliedManager || $this->sharedManager) && Orm::ownsReadMapping($connection);
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
        if ($this->pool instanceof CacheInterface && ($fingerprint = MappingFingerprint::current()) !== null) {
            $this->context = hash('sha256', $fingerprint . "\0" . $connection->getDatabasePlatform()::class
                . "\0" . $configuration->getMetadataDriverImpl()::class . "\0" . $configuration->getProxyDir());
            $this->queryCache = new SerializedMetadataCache($this->pool, 'orm_record_query_' . $this->context);
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
        $this->persistentMetadataLoaded = $this->persistentMetadataLoaded || $persistent;
        $cache = $persistent
            ? new SerializedMetadataCache($this->pool, 'orm_record_metadata_' . $this->context)
            : new ArrayAdapter(storeSerialized: true);
        $this->manager->getConfiguration()->setMetadataCache($cache);
        $this->manager->getMetadataFactory()->setCache($cache);
        return $this->manager->getClassMetadata($class);
    }

    private function fallbackManager(): EntityManager
    {
        if ($this->sharedManager || $this->persistentMetadataLoaded) {
            return Orm::forConnection($this->connection);
        }
        // This manager has only local metadata. Retire private cache eligibility
        // before callbacks can observe it, including on later operation reads.
        $this->readCachesInitialized = true;
        $this->context = null;
        $this->queryCache = null;
        $this->identifiers = [];
        return $this->manager;
    }

    private function scalar(ClassMetadata $metadata): bool
    {
        return count($metadata->identifier) === 1
            && $metadata->hasField($metadata->getSingleIdentifierFieldName())
            && !$metadata->hasLifecycleCallbacks(Events::postLoad)
            && empty($metadata->entityListeners[Events::postLoad])
            && !$this->manager->getEventManager()->hasListeners(Events::postLoad);
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
        if ($this->queryCache === null || $this->defaultIdentifiers($metadata) === null) {
            return;
        }
        // Every metadata class already used by a fixed query participates,
        // including joined translation fields. Domain owners admit no opaque DQL.
        $types = [];
        foreach ($this->manager->getMetadataFactory()->getLoadedMetadata() as $loaded) {
            $types = array_merge($types, array_column($loaded->fieldMappings, 'type'));
        }
        foreach ($query->getParameters() as $parameter) {
            if (is_string($parameter->getType())) {
                $types[] = $parameter->getType();
            }
        }
        // Check the actual selected and bound SQL conversions, including integer
        // association parameters that need not occur among the root scalar fields.
        foreach (array_unique($types) as $name) {
            if (!Orm::stableSqlConversion($name, DbalType::getType($name))) {
                return;
            }
        }
        $query->setQueryCache($this->queryCache);
    }

    public function close(): void
    {
        if (!$this->suppliedManager) {
            $this->manager->clear();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
