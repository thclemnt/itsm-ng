<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Repository\RecordRepository;

/** Complete typed legacy rows from mapped scalar fields and owning reference identities. */
final class MappedRowProjection
{
    /** @var list<array{string, string, bool}> Physical column, DBAL type, untyped identity expression. */
    private array $columns = [];
    /** @var list<string> */
    private array $selections = [];

    public function __construct(private EntityManager $em, ClassMetadata $metadata, ?array $defaultIdentifiers = null)
    {
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $this->selections[] = 'r.' . $property . ' AS value' . count($this->columns);
            $this->columns[] = [$mapping->columnName, $mapping->type, false];
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            if (count($mapping->joinColumns) !== 1) {
                throw new \LogicException('Scalar record reads require single-column owning references');
            }
            // Only private default reads supply this canonical declaration view.
            // Custom/supplied managers continue to inspect their actual target metadata.
            $identifier = $defaultIdentifiers[$mapping->targetEntity] ?? null;
            if ($identifier !== null && $identifier['column'] === $mapping->joinColumns[0]->referencedColumnName) {
                $type = $identifier['type'];
            } else {
                $target = $this->em->getClassMetadata($mapping->targetEntity);
                $targetId = $target->getSingleIdentifierFieldName();
                if (!$target->hasField($targetId) || $target->getColumnName($targetId) !== $mapping->joinColumns[0]->referencedColumnName) {
                    throw new \LogicException('Scalar record references must target a scalar identifier');
                }
                $type = $target->getTypeOfField($targetId);
            }
            $this->selections[] = 'IDENTITY(r.' . $property . ') AS value' . count($this->columns);
            $this->columns[] = [$mapping->joinColumns[0]->name, $type, true];
        }
    }

    /** Replace the root selection before callers add request-specific scalar expressions. */
    public function select(QueryBuilder $query): void
    {
        $query->select(...$this->selections);
    }

    public function toRow(array $values): array
    {
        $row = [];
        foreach ($this->columns as $index => [$column, $type, $reference]) {
            $value = $values['value' . $index];
            if ($reference) {
                // IDENTITY is an untyped DQL function; use the referenced ID's type.
                $value = Type::getType($type)->convertToPHPValue($value, $this->em->getConnection()->getDatabasePlatform());
            }
            $row[$column] = RecordRepository::legacyScalarValue($value, $type);
        }
        return $row;
    }
}
