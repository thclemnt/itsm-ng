<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\ParameterTypeInferer;
use InvalidArgumentException;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use LogicException;
use ReflectionProperty;

/** Active ITIL objects linked through the selected owning asset association. */
final class ITILAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function active(string $type, string $kind, int $asset, array $finished): array
    {
        if ($asset <= 0) {
            return [];
        }
        [, $parent, , , , , $links] = ITILStatisticsType::definition($this->em, $type);
        return $this->linked($links, $parent, $kind, $asset, $finished);
    }

    /** A known asset-link domain needs only its own live owning association. */
    public function activeForLink(string $links, string $kind, int $asset, array $finished): array
    {
        if ($asset <= 0) {
            return [];
        }
        $metadata = $this->em->getClassMetadata($links);
        $parents = [];
        foreach ($metadata->associationMappings as $property => $association) {
            if (!$association->isToOneOwningSide()) {
                continue;
            }
            foreach ((new ReflectionProperty($metadata->name, $property))->getAttributes(ITILStatisticsRelation::class) as $attribute) {
                if ($attribute->newInstance()->role === ITILStatisticsRole::Items) {
                    $parents[] = $property;
                }
            }
        }
        if (count($parents) !== 1) {
            throw new LogicException('Expected one ITIL asset parent association: ' . $links);
        }
        return $this->linked($metadata->name, $parents[0], $kind, $asset, $finished);
    }

    private function linked(string $links, string $parent, string $kind, int $asset, array $finished): array
    {
        return $this->projectActiveForItem($links, $parent, $kind, $asset, $finished);
    }

    /** Completed active rows on the selected manager's connection and owning associations. */
    public function projectActiveForItem(string $links, string $parent, string $kind, int $asset, array $finished, ?int $ticketType = null): array
    {
        $connection = $this->em->getConnection();
        $configuration = $this->em->getConfiguration();
        try {
            $association = $links::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            return [];
        }
        if ($asset <= 0) {
            return [];
        }
        $link = $this->em->getClassMetadata($links);
        $owner = $link->getAssociationMapping($parent);
        $item = $link->getAssociationMapping($association);
        if (
            !$owner->isToOneOwningSide() || count($owner->joinColumns) !== 1
            || !$item->isToOneOwningSide() || count($item->joinColumns) !== 1
        ) {
            throw new LogicException('ITIL asset rows require single-column owning parent and asset associations.');
        }
        $record = $this->em->getClassMetadata($owner->targetEntity);
        $platform = $connection->getDatabasePlatform();
        $quote = $configuration->getQuoteStrategy();
        $column = static fn (string $field): string => 'r.' . $quote->getColumnName($field, $record, $platform);
        $parameter = static fn (string $name, string $type): string => Type::getType($type)->convertToDatabaseValueSQL(':' . $name, $platform);
        $query = $connection->createQueryBuilder()
            ->from($quote->getTableName($link, $platform), 'i')
            ->innerJoin(
                'i',
                $quote->getTableName($record, $platform),
                'r',
                'i.' . $quote->getJoinColumnName($owner->joinColumns[0], $link, $platform)
                . ' = r.' . $quote->getReferencedJoinColumnName($owner->joinColumns[0], $record, $platform)
            )
            ->where('i.' . $quote->getJoinColumnName($item->joinColumns[0], $link, $platform) . ' = ' . $parameter('asset', Types::BIGINT))
            ->setParameter('asset', $asset, Types::BIGINT)
            ->andWhere($column('is_deleted') . ' = ' . $parameter('no', Types::BOOLEAN))->setParameter('no', false, Types::BOOLEAN);
        foreach (['id', 'name', 'priority'] as $field) {
            $query->addSelect(Type::getType($record->getTypeOfField($field))->convertToPHPValueSQL($column($field), $platform) . ' AS ' . $field);
        }
        if ($ticketType !== null) {
            $query->andWhere($column('type') . ' = ' . $parameter('type', Types::INTEGER))->setParameter('type', $ticketType, Types::INTEGER);
        }
        if ($finished) {
            $query->andWhere($column('status') . ' NOT IN (:finished)')->setParameter('finished', $finished, ParameterTypeInferer::inferType($finished));
        }
        // DQL scalar hydration does not invoke PHP value conversion for these selected fields.
        $rows = $query->orderBy($column('id'))->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['priority'] = (int)$row['priority'];
        }
        return $rows;
    }
}
