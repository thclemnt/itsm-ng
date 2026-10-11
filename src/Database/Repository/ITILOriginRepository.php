<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Historical origins do not replay content updates, timestamps or ITIL state transitions. */
final class ITILOriginRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function reassignTicket(int $id, ?int $replacement): void
    {
        $this->reassign(Entity\ITILFollowup::class, 'sourceTicket', $id, $replacement);
        $this->reassign(Entity\ITILFollowup::class, 'promotedTicket', $id, $replacement);
        $this->reassign(Entity\TicketTask::class, 'sourceTicket', $id, $replacement);
    }

    public function reassignFollowup(int $id, ?int $replacement): void
    {
        $this->reassign(Entity\ITILSolution::class, 'followup', $id, $replacement);
    }

    private function reassign(string $entity, string $association, int $id, ?int $replacement): void
    {
        $this->em->createQueryBuilder()->update($entity, 'r')->set('r.' . $association, ':replacement')
            ->where('IDENTITY(r.' . $association . ') = :id')->setParameter('id', $id, Types::INTEGER)
            ->setParameter('replacement', $replacement, Types::INTEGER)->getQuery()->execute();
    }

    public function promotionSource(int $ticket): ?array
    {
        return $this->em->createQueryBuilder()->select('f.id', 'f.itemtype', 'IDENTITY(f.ticket) AS items_id')
            ->from(Entity\ITILFollowup::class, 'f')
            ->where('f.itemtype = :type AND IDENTITY(f.promotedTicket) = :ticket')
            ->setParameter('type', 'Ticket')->setParameter('ticket', $ticket, Types::INTEGER)
            ->orderBy('f.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @internal Fixed scalar compiler for the private core timeline owner. */
    public static function projectedPromotionSource(Connection $connection, array $mapping, int $ticket): ?array
    {
        $platform = $connection->getDatabasePlatform();
        $quote = static fn (array $name): string => $name[1] ? $platform->quoteSingleIdentifier($name[0]) : $name[0];
        $types = [];
        $select = [];
        foreach (['id', 'itemtype'] as $property) {
            $field = $mapping['fields'][$property];
            $types[$property] = $field[2];
            $select[] = Type::getType($field[2])->convertToPHPValueSQL('f.' . $quote($field), $platform)
                . ' AS ' . $platform->quoteIdentifier($property);
        }
        // IDENTITY has no mapped scalar SQL conversion and hydrates as a string.
        $types['items_id'] = Types::STRING;
        $select[] = 'f.' . $quote($mapping['references']['ticket']) . ' AS items_id';
        $identifier = Type::getType(Types::INTEGER);
        $row = (new QueryBuilder($connection))
            ->select(...$select)
            ->from($quote($mapping['table']), 'f')
            ->where('f.' . $quote($mapping['fields']['itemtype']) . ' = ?')
            ->andWhere('f.' . $quote($mapping['references']['promotedTicket']) . ' = ' . $identifier->convertToDatabaseValueSQL('?', $platform))
            ->setParameter(0, 'Ticket', ParameterType::STRING)
            ->setParameter(1, $ticket, Types::INTEGER)
            ->orderBy('f.' . $quote($mapping['fields']['id']))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        foreach ($types as $field => $type) {
            $row[$field] = Type::getType($type)->convertToPHPValue($row[$field], $platform);
        }
        return $row;
    }
}
