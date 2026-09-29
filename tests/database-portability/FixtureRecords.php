<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Valid dependency graphs for FK tests; never catch fixture failures as rejection proof. */
final class FixtureRecords
{
    public function __construct(private DBAdapter $database)
    {
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
        $metadata = $em->getClassMetadata(\itsmng\Database\EntityRegistry::TABLES[$table]);
        foreach ($metadata->associationMappings as $mapping) {
            $join = $mapping->joinColumns[0];
            if (array_key_exists($join->name, $values)) {
                continue;
            }
            $target = $em->getClassMetadata($mapping->targetEntity)->getTableName();
            $values[$join->name] = $join->nullable ? null : ($target === 'glpi_entities' ? 0 : $this->create($target, ancestors: $ancestors));
        }
        return (new \itsmng\Database\Repository\RecordWriter($em))->insert($table, $values);
    }
}
