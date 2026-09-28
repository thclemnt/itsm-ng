<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;

/** Persistence adapter beneath CommonDBTM's validation, hooks and history. */
final class MappedStorage
{
    public const TABLES = [
        'glpi_groups_users' => Entity\GroupMembership::class,
        'glpi_useremails' => Entity\UserEmail::class,
        'glpi_profilerights' => Entity\ProfileRight::class,
        'glpi_contractcosts' => Entity\ContractCost::class,
        'glpi_contracts_items' => Entity\ContractItem::class,
        'glpi_contracts_suppliers' => Entity\ContractSupplier::class,
        'glpi_contacts_suppliers' => Entity\ContactSupplier::class,
        'glpi_reservations' => Entity\Reservation::class,
    ];

    public function __construct(private \DBAdapter $db)
    {
    }

    public static function supports(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    public function insert(string $table, array $values): int
    {
        $em = Orm::create($this->db);
        try {
            $class = self::TABLES[$table];
            $record = new $class();
            if (!empty($values['id'])) {
                // Imports can assign IDs. Only this operation's metadata changes.
                $metadata = $em->getClassMetadata($class);
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
                $metadata->setIdGenerator(new AssignedGenerator());
            } else {
                unset($values['id']);
            }
            $this->assign($em, $record, $values);
            $em->persist($record);
            $em->flush();
            return (int)$record->id;
        } finally {
            $em->clear();
        }
    }

    /** @return string[] Columns changed by this unit of work. */
    public function update(string $table, int $id, array $values): array
    {
        $em = Orm::create($this->db);
        try {
            $record = $em->find(self::TABLES[$table], $id);
            if ($record === null) {
                return [];
            }
            unset($values['id']);
            $this->assign($em, $record, $values);
            $em->getUnitOfWork()->computeChangeSets();
            $metadata = $em->getClassMetadata($record::class);
            $changed = [];
            foreach (array_keys($em->getUnitOfWork()->getEntityChangeSet($record)) as $field) {
                $changed[] = $metadata->hasAssociation($field)
                    ? $metadata->getAssociationMapping($field)->joinColumns[0]->name
                    : $metadata->getColumnName($field);
            }
            $em->flush();
            return $changed;
        } finally {
            $em->clear();
        }
    }

    public function delete(string $table, int $id): bool
    {
        $em = Orm::create($this->db);
        try {
            $record = $em->find(self::TABLES[$table], $id);
            if ($record !== null) {
                $em->remove($record);
                $em->flush();
            }
            return true;
        } finally {
            $em->clear();
        }
    }

    private function assign(EntityManager $em, object $record, array $values): void
    {
        $metadata = $em->getClassMetadata($record::class);
        $associations = [];
        foreach ($metadata->associationMappings as $field => $mapping) {
            $associations[$mapping->joinColumns[0]->name] = $field;
        }
        foreach ($values as $column => $value) {
            if ($value instanceof \QueryExpression || $value instanceof \QueryParam) {
                throw new \InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            // CommonDBTM still supplies pre-escaped values. Decode once at this
            // boundary; all Doctrine operations use bound, typed parameters.
            $value = self::decode($value);
            if (isset($associations[$column])) {
                $field = $associations[$column];
                $record->$field = $value === null ? null : $em->getReference($metadata->getAssociationTargetClass($field), (int)$value);
                continue;
            }
            $field = $metadata->getFieldName($column);
            $mapping = $metadata->getFieldMapping($field);
            if ($value !== null) {
                $value = match ($mapping->type) {
                    'boolean' => (bool)(int)$value,
                    'integer', 'smallint' => (int)$value,
                    'float' => (float)$value,
                    'date', 'datetime', 'datetimetz', 'time' => $value instanceof \DateTimeInterface ? $value : new \DateTime((string)$value),
                    default => (string)$value,
                };
            }
            $record->$field = $value;
        }
    }

    private static function decode(mixed $value): mixed
    {
        if ($value === 'NULL' || $value === 'null') {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\\\\(.)/s', static fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", '0' => "\0", 'Z' => "\x1a",
            '%', '_' => $m[0], default => $m[1],
        }, $value);
    }
}
