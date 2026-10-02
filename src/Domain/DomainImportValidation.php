<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\ReferenceValues;
use itsmng\Database\Repository\RecordRepository;

/** Current property metadata owns types and relationships; this service validates import input. */
final class DomainImportValidation
{
    public function __construct(private EntityManager $em)
    {
    }

    public function normalize(string $class, array $input): array
    {
        $metadata = $this->em->getClassMetadata($class);
        $values = ReferenceValues::normalizeLegacy($metadata->getTableName(), $input);
        $entity = new $class();
        if ($entity instanceof LegacyInput) {
            $values = $entity->normalizeInput($values);
        }
        $joins = [];
        foreach ($metadata->associationMappings as $association) {
            if ($association->isToOneOwningSide()) {
                $joins[$association->joinColumns[0]->name] = $association->joinColumns[0];
            }
        }
        foreach ($values as $column => &$value) {
            $label = $metadata->getTableName() . '.' . ($input['id'] ?? '?') . '.' . $column;
            if (isset($joins[$column])) {
                if ($value === null && $joins[$column]->nullable) {
                    continue;
                }
                $value = $this->integer($value, $label, 0);
                continue;
            }
            $mapping = $metadata->getFieldMapping($metadata->getFieldName($column));
            if ($value === null) {
                if (!$mapping->nullable) {
                    throw new \RuntimeException('NULL Domains import field: ' . $label);
                }
                continue;
            }
            if ($mapping->type === 'boolean') {
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    throw new \RuntimeException('Invalid Domains import boolean: ' . $label);
                }
                $value = in_array($value, [true, 1, '1'], true);
            } elseif (in_array($mapping->type, ['integer', 'smallint', 'bigint'], true)) {
                $value = $this->integer($value, $label, $column === 'id' ? 1 : 0);
            } elseif (in_array($mapping->type, ['date', 'datetime', 'datetimetz'], true)) {
                $calendar = $class === \itsmng\Database\Entity\Domain::class && in_array($column, ['date_creation', 'date_expiration'], true);
                if ($value === '' || $value === ($calendar ? '0000-00-00' : '0000-00-00 00:00:00')) {
                    $value = null;
                    continue;
                }
                $format = $calendar ? 'Y-m-d' : 'Y-m-d H:i:s';
                $date = \DateTimeImmutable::createFromFormat('!' . $format, (string)$value);
                if (!$date || $date->format($format) !== (string)$value) {
                    throw new \RuntimeException('Invalid Domains import calendar date: ' . $label . '=' . $value);
                }
                $connection = $this->em->getConnection();
                if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                    if ($connection->fetchOne('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$metadata->getTableName(), $column]) === 'timestamp') {
                        $instant = $connection->fetchOne('SELECT UNIX_TIMESTAMP(?)', [$date->format('Y-m-d H:i:s')]);
                        if ($instant === null || (float)$instant <= 0) {
                            throw new \RuntimeException('Domains import exceeds native TIMESTAMP range: ' . $label . '=' . $value);
                        }
                    }
                    $timezone = $connection->fetchOne('SELECT @@SESSION.time_zone');
                    if ($timezone === 'SYSTEM') {
                        $timezone = $connection->fetchOne('SELECT @@GLOBAL.system_time_zone');
                    }
                    try {
                        $date = new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone($timezone));
                    } catch (\Throwable $error) {
                        throw new \RuntimeException('Domains import requires an explicit supported database session timezone: ' . $timezone, previous: $error);
                    }
                } else {
                    // Calendar values are interpreted in the destination session timezone,
                    // matching normal application timestamp insertion on PostgreSQL.
                    // PostgreSQL accepts POSIX offset names whose sign differs from
                    // PHP's timezone parser. Let the actual provider interpret its
                    // own session zone rather than translating that name in PHP.
                    $native = $connection->fetchOne("SELECT CAST(? AS TIMESTAMP) AT TIME ZONE current_setting('TimeZone')", [$date->format('Y-m-d H:i:s')]);
                    $date = new \DateTimeImmutable($native);
                }
                $value = $date->format('Y-m-d H:i:sP');
            } else {
                if (!is_scalar($value) || ($mapping->length !== null && mb_strlen((string)$value) > $mapping->length)) {
                    throw new \RuntimeException('Invalid Domains import text: ' . $label);
                }
                $value = (string)$value;
            }
        }
        unset($value);
        foreach ($joins as $column => $join) {
            if (!$join->nullable && !array_key_exists($column, $values)) {
                throw new \RuntimeException('Missing Domains import ownership: ' . $metadata->getTableName() . '.' . $column);
            }
        }
        return $values;
    }

    public function integer(mixed $value, string $label, int $minimum): int
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $minimum) {
            throw new \RuntimeException('Invalid Domains import identifier: ' . $label);
        }
        return (int)$value;
    }

    public function graph(array $records): array
    {
        $incoming = [];
        foreach ($records as $record) {
            $id = $record['values']['id'];
            if (isset($incoming[$record['table']][$id])) {
                throw new \RuntimeException('Duplicate Domains plugin identifier: ' . $record['table'] . '.' . $id);
            }
            $incoming[$record['table']][$id] = true;
        }
        $seen = [];
        foreach ($records as $record) {
            $metadata = $this->em->getClassMetadata($record['class']);
            $id = $record['values']['id'];
            if ($this->em->find($record['class'], $id) !== null) {
                throw new \RuntimeException('Domains import ID collision: ' . $record['table'] . '.' . $id . '; existing core records are not owned by this export.');
            }
            foreach ($metadata->associationMappings as $association) {
                if (!$association->isToOneOwningSide()) {
                    continue;
                }
                $column = $association->joinColumns[0]->name;
                $targetId = $record['values'][$column] ?? null;
                $targetTable = $this->em->getClassMetadata($association->targetEntity)->getTableName();
                if ($targetId !== null && in_array($association->targetEntity, [\itsmng\Database\Entity\Domain::class, \itsmng\Database\Entity\DomainType::class], true)
                    && !isset($incoming[$targetTable][$targetId])) {
                    throw new \RuntimeException('Missing Domains source owner: ' . $record['table'] . '.' . $id . '.' . $column . ' -> ' . $targetTable . '.' . $targetId);
                }
                if ($targetId !== null && !isset($incoming[$targetTable][$targetId]) && $this->em->find($association->targetEntity, $targetId) === null) {
                    throw new \RuntimeException('Invalid Domains import target: ' . $record['table'] . '.' . $id . '.' . $column . ' -> ' . $targetTable . '.' . $targetId);
                }
            }
            foreach ($metadata->table['uniqueConstraints'] ?? [] as $name => $constraint) {
                $criteria = [];
                foreach ($constraint['columns'] as $column) {
                    $column = trim($column, '`');
                    $criteria[$column] = $record['values'][$column] ?? $record['input'][$column] ?? null;
                }
                if (in_array(null, $criteria, true)) {
                    continue;
                }
                $this->unique($record['table'], $name, $criteria, $seen[$record['table']][$name] ?? []);
                $seen[$record['table']][$name][] = $criteria;
            }
        }
        $this->scopes($records);
        return $incoming;
    }

    /** Source dropdown ownership: same entity or a recursive ancestor, never a same-number core type. */
    private function scopes(array $records): void
    {
        $types = [];
        foreach ($records as $record) {
            if ($record['class'] === \itsmng\Database\Entity\DomainType::class) {
                $types[$record['values']['id']] = $record['values'];
            }
        }
        foreach ($records as $record) {
            if ($record['class'] !== \itsmng\Database\Entity\Domain::class) {
                continue;
            }
            $domain = $record['values'];
            if (($domain['domaintypes_id'] ?? null) !== null) {
                $type = $types[$domain['domaintypes_id']];
                $this->scope($type['entities_id'], $type['is_recursive'], $domain['entities_id'], 'glpi_domains.' . $domain['id'] . '.domaintypes_id');
            }
            if (($domain['suppliers_id'] ?? null) !== null) {
                $supplier = $this->em->find(\itsmng\Database\Entity\Supplier::class, $domain['suppliers_id']);
                $this->scope($supplier->entities->id, $supplier->is_recursive, $domain['entities_id'], 'glpi_domains.' . $domain['id'] . '.suppliers_id');
            }
        }
        $domains = $this->domains($records);
        foreach ($records as $record) {
            if ($record['class'] !== Entity\DomainItem::class) {
                continue;
            }
            $values = $record['values'];
            $selection = EntityRegistry::discriminatedReferences($record['table'])['items_id']['selections'][$values['itemtype']];
            $asset = $this->em->find(EntityRegistry::tables()[$selection['target']], $values[$selection['column']]);
            $this->coherent($domains[$values['domains_id']], $this->ownership($asset), $record['table'] . '.' . $values['id'] . '.items_id');
        }
    }

    /** Retagging preserves the same ownership rule as the relation's normal public path. */
    public function bindings(array $records, array $bindings): void
    {
        $domains = $this->domains($records);
        foreach ($bindings as $binding) {
            if ($binding['class'] === Entity\ImpactRelation::class) {
                $row = $this->em->find($binding['class'], $binding['id']);
                $scopes = [];
                foreach (['source', 'impacted'] as $role) {
                    $kind = $row->{'itemtype_' . $role};
                    $id = $row->{'items_id_' . $role};
                    if ($kind === DomainPluginSource::ITEMTYPE) {
                        $scopes[] = $domains[$id];
                    } else {
                        $model = \getItemForItemtype($kind);
                        $class = $model instanceof \CommonDBTM ? (EntityRegistry::tables()[$model::getTable()] ?? null) : null;
                        $values = $class ? (new RecordRepository($this->em))->find($model::getTable(), 'id', $id) : null;
                        if ($values === null) {
                            throw new \RuntimeException('Unsupported Domains impact endpoint: ' . $binding['table'] . '.' . $binding['id'] . '.' . $role);
                        }
                        // Impact is still a scalar polymorphic relation. Read through
                        // ORM, then honor the actual public endpoint's capabilities
                        // (including models without entity ownership) rather than
                        // inventing an owning association for this historical role.
                        $model->fields = $values;
                        $scopes[] = $model->isEntityAssign()
                            ? ['entities_id' => (int)$model->getEntityID(), 'is_recursive' => (bool)$model->isRecursive()] : null;
                    }
                }
                $this->coherent($scopes[0], $scopes[1], $binding['table'] . '.' . $binding['id']);
                continue;
            }
            // These public CommonDBRelation models check owner coherence. Ticket has
            // a writable-ticket exception; knowledge-base audiences and financial
            // children have different policies and must not inherit this restriction.
            if (!in_array($binding['class'], [Entity\DocumentItem::class, Entity\ContractItem::class,
                Entity\CertificateItem::class, Entity\ItemProject::class, Entity\ItemProblem::class, Entity\ChangeItem::class], true)) {
                continue;
            }
            $subject = null;
            foreach ($binding['associations'] as $association) {
                if (($association['class'] ?? null) === Entity\Domain::class) {
                    $subject = $domains[$association['id']];
                }
            }
            if ($subject === null) {
                continue;
            }
            $metadata = $this->em->getClassMetadata($binding['class']);
            $row = $this->em->find($binding['class'], $binding['id']);
            foreach ($metadata->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide() || $association->joinColumns[0]->nullable
                    || $association->targetEntity === Entity\Entity::class
                    || (new \ReflectionProperty($metadata->name, $property))->getAttributes(DiscriminatedBy::class)) {
                    continue;
                }
                $this->coherent($subject, $this->ownership($row->$property), $binding['table'] . '.' . $binding['id'] . '.' . $association->joinColumns[0]->name);
            }
        }
    }

    private function domains(array $records): array
    {
        $domains = [];
        foreach ($records as $record) {
            if ($record['class'] === Entity\Domain::class) {
                $domains[$record['values']['id']] = $record['values'];
            }
        }
        return $domains;
    }

    /** Only an actual entity-assigned endpoint participates; an audience is not ownership. */
    private function ownership(object $owner): ?array
    {
        $metadata = $this->em->getClassMetadata($owner::class);
        if (!$metadata->hasAssociation('entities') || $metadata->getAssociationTargetClass('entities') !== Entity\Entity::class) {
            return null;
        }
        return ['entities_id' => $owner->entities->id, 'is_recursive' => $metadata->hasField('is_recursive') && $owner->is_recursive];
    }

    private function coherent(?array $first, ?array $second, string $label): void
    {
        if ($first === null || $second === null
            || $this->inScope($first['entities_id'], $first['is_recursive'], $second['entities_id'], $label)
            || $this->inScope($second['entities_id'], $second['is_recursive'], $first['entities_id'], $label)) {
            return;
        }
        throw new \RuntimeException('Domains import relation entity scope mismatch: ' . $label);
    }

    private function scope(int $owner, bool $recursive, int $subject, string $label): void
    {
        if (!$this->inScope($owner, $recursive, $subject, $label)) {
            throw new \RuntimeException('Domains import entity scope mismatch: ' . $label);
        }
    }

    private function inScope(int $owner, bool $recursive, int $subject, string $label): bool
    {
        if ($owner === $subject) {
            return true;
        }
        if ($recursive) {
            $entity = $this->em->find(\itsmng\Database\Entity\Entity::class, $subject);
            $seen = [];
            while ($entity->parent !== null) {
                $entity = $entity->parent;
                if (isset($seen[$entity->id])) {
                    throw new \RuntimeException('Domains scope contains an entity cycle: ' . $label);
                }
                $seen[$entity->id] = true;
                if ($entity->id === $owner) {
                    return true;
                }
            }
        }
        return false;
    }

    public function unique(string $table, string $name, array $criteria, array $incoming = []): void
    {
        $connection = $this->em->getConnection();
        foreach ($incoming as $earlier) {
            $parts = $params = [];
            foreach ($criteria as $column => $value) {
                if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                    $definition = $connection->fetchAssociative('SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
                    if ($definition['COLLATION_NAME'] !== null) {
                        $expression = 'CONVERT(? USING ' . $connection->quoteIdentifier($definition['CHARACTER_SET_NAME']) . ') COLLATE ' . $connection->quoteIdentifier($definition['COLLATION_NAME']);
                        $parts[] = '(' . $expression . '=' . $expression . ')';
                        array_push($params, $value, $earlier[$column]);
                        continue;
                    }
                }
                if ($value !== $earlier[$column]) {
                    continue 2;
                }
            }
            if (!$parts || (int)$connection->fetchOne('SELECT ' . implode(' AND ', $parts), $params) === 1) {
                throw new \RuntimeException('Domains import unique collision: ' . $table . '.' . $name);
            }
        }
        if ((new RecordRepository($this->em))->countMatching($table, $criteria, legacyValues: false)) {
            throw new \RuntimeException('Domains import unique collision: ' . $table . '.' . $name);
        }
    }
}
