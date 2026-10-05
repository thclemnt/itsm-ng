<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;

/** Frozen software assignment policy; runtime ownership remains entity-local. */
abstract class SoftwareAssignmentSubjects extends StagedTypedItemMigration
{
    abstract protected function licenses(): bool;

    protected static function targets(): array
    {
        return ['Computer' => 'computers', 'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals', 'Phone' => 'phones', 'Printer' => 'printers'];
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Plugin::registerClass can extend software_types, but a registered PHP kind is not a mapped owning association. '
            . 'Preserve these source rows and provide an explicit canonical plugin entity/migration, or resolve assignments in the compatible source application before adoption. '
            . 'This migration never deletes, renames or adopts unsupported plugin subjects.';
    }

    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        $plan = parent::plan($connection, $incomingReferences);
        if (!$plan) {
            return [];
        }
        $columns = $connection->createSchemaManager()->listTableColumns($this->table());
        $identity = isset($columns['items_id']) ? 'r.items_id' : '(' . static::identity('r.') . ')';
        $parentTable = $this->licenses() ? 'glpi_softwarelicenses' : 'glpi_softwareversions';
        $parentColumn = $this->licenses() ? 'softwarelicenses_id' : 'softwareversions_id';
        $select = ['r.id', 'r.itemtype', $identity . ' AS items_id', 'r.' . $parentColumn . ' AS parent_id',
            'p.id AS persisted_parent', 'p.entities_id AS parent_entity', 'p.is_recursive AS parent_recursive',
            's.id AS persisted_software', 's.is_recursive AS software_recursive'];
        if (!$this->licenses()) {
            $select[] = 'r.entities_id AS installation_entity';
        }
        $joins = ' LEFT JOIN ' . $parentTable . ' p ON p.id = r.' . $parentColumn
            . ' LEFT JOIN glpi_softwares s ON s.id = p.softwares_id';
        foreach (static::targets() as $kind => $target) {
            $select[] = $target . '.entities_id AS ' . $target . '_entity';
            $select[] = $target . '.is_recursive AS ' . $target . '_recursive';
            $joins .= ' LEFT JOIN glpi_' . $target . ' ' . $target . ' ON ' . $target . '.id = ' . $identity;
        }
        $parents = [];
        foreach ($connection->fetchAllAssociative('SELECT id, entities_id FROM glpi_entities') as $entity) {
            $parents[(int)$entity['id']] = $entity['entities_id'] === null ? null : (int)$entity['entities_id'];
        }
        $ancestor = static function (int $candidate, int $child) use ($parents): bool {
            $visited = [$child => true];
            while (isset($parents[$child]) && $parents[$child] !== $child) {
                $child = $parents[$child];
                if (isset($visited[$child])) {
                    break;
                }
                if ($child === $candidate) {
                    return true;
                }
                $visited[$child] = true;
            }
            return false;
        };
        $invalid = 0;
        $samples = [];
        foreach ($connection->iterateAssociative('SELECT ' . implode(', ', $select) . ' FROM ' . $this->table() . ' r' . $joins . ' ORDER BY r.id') as $row) {
            $target = static::targets()[$row['itemtype']] ?? null;
            $reason = null;
            if ($target === null) {
                $reason = 'The discriminator must exactly match a supported subject kind';
            } elseif ($row['persisted_parent'] === null || $row['persisted_software'] === null) {
                $reason = 'Missing version/licence or owning Software';
            } else {
                $owner = (int)$row[$target . '_entity'];
                $peer = (int)$row['parent_entity'];
                if ($row[$target . '_entity'] === null || $row['parent_entity'] === null
                    || !array_key_exists($owner, $parents) || !array_key_exists($peer, $parents)) {
                    $reason = 'Missing assignment end entity';
                } elseif (!$this->licenses() && ($row['installation_entity'] === null || (int)$row['installation_entity'] !== $owner)) {
                    $reason = 'Installation entity must retain its subject ownership';
                } else {
                    try {
                        $recursive = self::boolean($row['parent_recursive']);
                        $subjectRecursive = self::boolean($row[$target . '_recursive']);
                        // SoftwareLicense::maybeRecursive is owned by Software.
                        if ($this->licenses()) {
                            $softwareRecursive = self::boolean($row['software_recursive']);
                            $recursive = $recursive && $softwareRecursive;
                        }
                        if ($owner !== $peer && !($recursive && $ancestor($peer, $owner))
                            && !($subjectRecursive && $ancestor($owner, $peer))) {
                            $reason = 'Assignment ends are outside the public relation entity scope';
                        }
                    } catch (\RuntimeException $error) {
                        $reason = $error->getMessage();
                    }
                }
            }
            if ($reason !== null) {
                ++$invalid;
                if (count($samples) < 5) {
                    $samples[] = ['id' => $row['id'], 'itemtype' => $row['itemtype'], 'items_id' => $row['items_id'],
                        'parent_id' => $row['parent_id'], 'reason' => $reason];
                }
            }
        }
        if ($invalid) {
            throw new \RuntimeException('Invalid software assignment ownership: ' . $this->table() . ' (' . $invalid . '); samples: '
                . json_encode($samples, JSON_THROW_ON_ERROR) . '. Resolve these rows in the source application before adoption; no source row was changed.');
        }
        return $plan;
    }

    /** The historical declaration is independent of current entity attributes. */
    protected static function discriminatorSql(?AbstractPlatform $platform = null, string $alias = ''): string
    {
        $column = $alias . 'itemtype';
        return $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $column . ' AS BINARY)' : $column;
    }

    protected function configureCommentProjection(Table $table, AbstractPlatform $platform): void
    {
        static::configureTable($table, $platform);
    }

    private static function boolean(mixed $value): bool
    {
        if (!in_array($value, [false, true, 0, 1, '0', '1'], true)) {
            throw new \RuntimeException('Software assignment ownership requires actual zero/one recursive flags.');
        }
        return (bool)$value;
    }
}
