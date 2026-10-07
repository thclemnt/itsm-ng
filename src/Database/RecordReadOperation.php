<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use itsmng\Database\Repository\RecordRepository;

/** Private default mappings and query plans; only current rows/counts leave this operation. */
final class RecordReadOperation
{
    private EntityManager $manager;
    private ?SerializedMetadataCache $queryCache = null;
    private array $identifiers = [];
    private bool $ownedMapping;
    private mixed $pool;
    private ?string $context = null;

    public function __construct(private readonly Connection $connection)
    {
        $this->manager = Orm::forConnection($connection);
        $configuration = $this->manager->getConfiguration();
        $platformFile = (new \ReflectionClass($connection->getDatabasePlatform()))->getFileName();
        $dbalPath = \Composer\InstalledVersions::getInstallPath('doctrine/dbal');
        $this->ownedMapping = $platformFile !== false && $dbalPath !== null
            && ($platformFile = realpath($platformFile)) !== false
            && ($dbalPath = realpath($dbalPath)) !== false
            && str_starts_with($platformFile, $dbalPath . '/src/Platforms/')
            // A connection-provided EventManager remains externally mutable, even
            // before its first listener is registered. Its mappings and target
            // identifier facts must therefore remain local from the outset.
            && !method_exists($connection, 'getEventManager');
        $this->pool = $this->ownedMapping ? ($GLOBALS['GLPI_CACHE'] ?? null) : null;
        $this->identifiers = $this->ownedMapping ? EntityRegistry::scalarIdentifiers() : [];
        if ($this->pool instanceof \Psr\SimpleCache\CacheInterface && ($fingerprint = MappingFingerprint::current()) !== null) {
            $this->context = hash('sha256', $fingerprint . "\0" . $connection->getDatabasePlatform()::class
                . "\0" . $configuration->getMetadataDriverImpl()::class . "\0" . $configuration->getProxyDir());
            $this->queryCache = new SerializedMetadataCache($this->pool, 'orm_record_query_' . $this->context);
        }
    }

    private function metadata(string $table): ClassMetadata
    {
        $class = EntityRegistry::tables()[$table];
        // Only canonical source declarations enter the private persistent namespace.
        // Custom/composite roots continue with the independently mutable local cache.
        $cache = isset($this->identifiers[$class]) && $this->context !== null
            ? new SerializedMetadataCache($this->pool, 'orm_record_metadata_' . $this->context)
            : new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: true);
        $this->manager->getConfiguration()->setMetadataCache($cache);
        $this->manager->getMetadataFactory()->setCache($cache);
        return $this->manager->getClassMetadata($class);
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
            throw new \LogicException('A compiled read plan belongs to its private operation.');
        }
        if ($this->queryCache === null || $this->defaultIdentifiers($metadata) === null) {
            return;
        }
        $types = array_column($metadata->fieldMappings, 'type');
        foreach ($query->getParameters() as $parameter) {
            if (is_string($parameter->getType())) {
                $types[] = $parameter->getType();
            }
        }
        // Check the actual selected and bound SQL conversions, including integer
        // association parameters that need not occur among the root scalar fields.
        foreach (array_unique($types) as $name) {
            $type = DbalType::getType($name);
            if ($name === Type\FixedStringType::NAME && $type::class === Type\FixedStringType::class) {
                continue;
            }
            foreach (['convertToPHPValueSQL', 'convertToDatabaseValueSQL'] as $method) {
                if ((new \ReflectionMethod($type, $method))->getDeclaringClass()->getName() !== DbalType::class) {
                    return;
                }
            }
        }
        $query->setQueryCache($this->queryCache);
    }

    public function row(string $table, string $column, int $id): ?array
    {
        $metadata = $this->metadata($table);
        if ($this->scalar($metadata) && $metadata->getColumnName($metadata->identifier[0]) === $column) {
            return (new RecordRepository($this->manager))->scalarRow(
                $metadata->name,
                $id,
                null,
                $this->defaultIdentifiers($metadata),
                $this,
            );
        }
        // Entity callbacks must receive wholly local metadata, not private cached
        // metadata whose backend alone was detached after it had already loaded.
        $fallback = $this->ownedMapping ? Orm::forConnection($this->connection) : $this->manager;
        try {
            return (new RecordRepository($fallback))->find($table, $column, $id);
        } finally {
            $fallback->clear();
        }
    }

    public function matching(string $table, array $criteria, array|string $order, ?int $limit, int $offset): array
    {
        $metadata = $this->metadata($table);
        if ($this->scalar($metadata)) {
            return (new RecordRepository($this->manager))->matching(
                $table, $criteria, $order, $limit, $offset, true, $this->defaultIdentifiers($metadata), $this,
            );
        }
        $fallback = $this->ownedMapping ? Orm::forConnection($this->connection) : $this->manager;
        try {
            return (new RecordRepository($fallback))->matching($table, $criteria, $order, $limit, $offset);
        } finally {
            $fallback->clear();
        }
    }

    /** The actor domain keeps its own validated projection and parameter contract. */
    public function actorRows(string $actorClass, int $item): array
    {
        if (!Repository\ITILActorRepository::supports($actorClass)) {
            throw new \InvalidArgumentException('Unsupported ITIL actor relation');
        }
        $this->metadata($actorClass::getTable());
        return (new Repository\ITILActorRepository($this->manager))->rows($actorClass, $item, $this);
    }

    public function countMatching(string $table, array $criteria): int
    {
        $this->metadata($table);
        return (new RecordRepository($this->manager))->countMatching($table, $criteria, true, $this);
    }

    public function close(): void
    {
        $this->manager->clear();
    }

    public function __destruct()
    {
        $this->close();
    }
}
