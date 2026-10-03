<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;

/** Valid dependency graphs for FK tests; never catch fixture failures as rejection proof. */
final class FixtureRecords
{
    private ?Closure $created;

    public function __construct(private DBAdapter $database, ?callable $created = null)
    {
        $this->created = $created === null ? null : Closure::fromCallable($created);
    }

    /** Ordinary ownership accepts the root; discriminator subjects need a positive parent. */
    public static function referenceParent(string $table, string $column, string $target, callable $create): int
    {
        if ($target === 'glpi_entities') {
            foreach (\itsmng\Database\EntityRegistry::discriminatedReferences($table) as $definition) {
                foreach ($definition['selections'] as $selection) {
                    if ($selection['column'] === $column && $selection['empty_value'] === null) {
                        return $create($target);
                    }
                }
            }
            return 0;
        }
        return $create($target);
    }

    /** Select one valid discriminator branch when a generic FK fixture supplies all targets. */
    public static function selectReferenceKind(string $table, array $values, ?string $selectedColumn): array
    {
        foreach (\itsmng\Database\EntityRegistry::discriminatedReferences($table) as $definition) {
            $first = array_key_first($definition['selections']);
            $kind = is_string($first) ? $first : 0;
            foreach ($definition['selections'] as $type => $selection) {
                if ($selection['column'] === $selectedColumn) {
                    $kind = $type;
                }
            }
            $selected = $definition['selections'][$kind]['column'] ?? null;
            foreach ($definition['selections'] as $type => $selection) {
                if ($selection['column'] !== $selected) {
                    $values[$selection['column']] = null;
                }
            }
            $values[$definition['discriminator']] = $kind;
        }
        return $values;
    }

    /** Generated mandatory subjects require one real parent, including generic table-write tests. */
    public static function requiredSubjects(\Doctrine\ORM\Mapping\ClassMetadata $metadata, array $values, callable $create): array
    {
        foreach ($metadata->fieldMappings as $field => $mapping) {
            foreach ((new ReflectionProperty($metadata->name, $field))->getAttributes(\itsmng\Database\Mapping\DiscriminatorKey::class) as $attribute) {
                if ($attribute->newInstance()->fallbackProperty !== null || $attribute->newInstance()->emptyValue !== null) {
                    continue;
                }
                $definition = \itsmng\Database\EntityRegistry::discriminatedReferences($metadata->getTableName())[$mapping->columnName];
                $discriminator = $definition['discriminator'];
                $kind = $values[$discriminator] ?? array_key_first($definition['selections']);
                $selection = $definition['selections'][$kind] ?? null;
                if ($selection === null) {
                    continue; // The writer must reject explicit unsupported selections.
                }
                if (!array_key_exists($mapping->columnName, $values) && !isset($values[$selection['column']])) {
                    $values[$selection['column']] = $create($selection['target']);
                }
                $values[$discriminator] = $kind;
            }
        }
        return $values;
    }

    public function create(string $table, array $values = [], array $ancestors = []): int
    {
        if (isset($ancestors[$table])) {
            throw new LogicException('Required fixture cycle needs explicit values: ' . $table);
        }
        $ancestors[$table] = true;
        if ($table === 'glpi_crontasks' && !array_key_exists('name', $values)) {
            $values['name'] = 'Fixture cron ' . bin2hex(random_bytes(8));
        }
        $em = \itsmng\Database\Orm::create($this->database);
        $metadata = $em->getClassMetadata(\itsmng\Database\EntityRegistry::tables()[$table]);
        $values = self::requiredSubjects($metadata, $values, fn ($target) => $this->create($target, ancestors: $ancestors));
        $record = new $metadata->name();
        if ($record instanceof \itsmng\Database\Mapping\LegacyInput) {
            $values = $record->normalizeInput($values);
        }
        if ($table === 'glpi_entities' && !array_key_exists('id', $values)) {
            $values['id'] = 1 + (int)$em->createQueryBuilder()->select('MAX(e.id)')->from($metadata->name, 'e')->getQuery()->getSingleScalarResult();
        }
        foreach ($metadata->associationMappings as $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            $join = $mapping->joinColumns[0];
            if (array_key_exists($join->name, $values)) {
                continue;
            }
            $target = $em->getClassMetadata($mapping->targetEntity)->getTableName();
            $values[$join->name] = array_key_exists('default', $join->options ?? []) ? $join->options['default']
                : ($join->nullable ? null : ($target === 'glpi_entities' ? 0 : $this->create($target, ancestors: $ancestors)));
        }
        if ($table === 'glpi_entities') {
            foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
                if ($values[$column] !== null && !array_key_exists($definition['mode'], $values)) {
                    $values[$definition['mode']] = \itsmng\Database\ReferenceMode::Explicit;
                }
            }
        }
        $id = (new \itsmng\Database\Repository\RecordWriter($em))->insert($table, $values);
        $this->created && ($this->created)($table, $id);
        return $id;
    }
}
