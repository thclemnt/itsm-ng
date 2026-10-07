<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;

/** Address lookup and default selection through the mapped user association. */
final class UserEmailRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function all(int $user): array
    {
        $rows = $this->em->createQueryBuilder()->select('e.email')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.id')->getQuery()->getScalarResult();
        return array_column($rows, 'email');
    }

    public function preferred(int $user): ?array
    {
        return $this->em->createQueryBuilder()->select('e.id, e.email')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.is_default', 'DESC')->addOrderBy('e.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Fixed private scalar projection; public supplied-manager reads remain ORM queries. */
    public function nativePreferred(int $user): ?array
    {
        $metadata = $this->em->getClassMetadata(UserEmail::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $reference = $metadata->associationMappings['users'];
        if (!$reference->isToOneOwningSide() || count($reference->joinColumns) !== 1) {
            throw new \LogicException('Preferred email requires a single owning user reference.');
        }
        $types = [];
        $select = [];
        foreach (['id', 'email'] as $field) {
            $types[$field] = \Doctrine\DBAL\Types\Type::getType($metadata->getTypeOfField($field));
            $select[] = $types[$field]->convertToPHPValueSQL('e.' . $quote->getColumnName($field, $metadata, $platform), $platform) . ' AS ' . $field;
        }
        $userType = \Doctrine\DBAL\Types\Type::getType(Types::INTEGER);
        $row = $connection->createQueryBuilder()->select(...$select)
            ->from($quote->getTableName($metadata, $platform), 'e')
            ->where('e.' . $quote->getJoinColumnName($reference->joinColumns[0], $metadata, $platform)
                . ' = ' . $userType->convertToDatabaseValueSQL(':user', $platform))
            ->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.' . $quote->getColumnName('is_default', $metadata, $platform), 'DESC')
            ->addOrderBy('e.' . $quote->getColumnName('id', $metadata, $platform), 'ASC')
            ->setMaxResults(1)->executeQuery()->fetchAssociative();
        if ($row === false) {
            return null;
        }
        // ObjectHydrator applies PHP conversions to these scalar selections.
        foreach ($types as $field => $type) {
            $row[$field] = $type->convertToPHPValue($row[$field], $platform);
        }
        return $row;
    }

    public function contains(int $user, string $email): bool
    {
        return $this->em->createQueryBuilder()->select('e.id')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user AND e.email = :email')->setParameter('user', $user, Types::INTEGER)
            ->setParameter('email', $email)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Choose an existing address of this user, or their preferred survivor after deletion. */
    public function selectDefault(int $user, ?int $address = null): bool
    {
        return $this->em->getConnection()->transactional(function () use ($user, $address): bool {
            if ($user <= 0 || $this->em->find(User::class, $user, LockMode::PESSIMISTIC_WRITE) === null) {
                return false;
            }
            $selection = $this->em->createQueryBuilder()->select('e.id')->from(UserEmail::class, 'e')
                ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER);
            if ($address === null) {
                $selection->orderBy('e.is_default', 'DESC')->addOrderBy('e.id')->setMaxResults(1);
            } else {
                $selection->andWhere('e.id = :id')->setParameter('id', $address, Types::INTEGER);
            }
            // Mutations need the current row, even when the caller already owns
            // an older repeatable-read snapshot. Keep this address locked until
            // both default updates finish so a concurrent deletion cannot remove it.
            $selected = $selection->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if ($selected === null) {
                return false;
            }
            $address = (int)$selected['id'];
            // Set both sides explicitly: a previous concurrent selection may have
            // cleared the selected address after its model was initially loaded.
            $query = $this->em->createQueryBuilder()->update(UserEmail::class, 'e')->set('e.is_default', ':default')
                ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER);
            (clone $query)->andWhere('e.id <> :id')->setParameter('id', $address, Types::INTEGER)
                ->setParameter('default', false, Types::BOOLEAN)->getQuery()->execute();
            $query->andWhere('e.id = :id')->setParameter('id', $address, Types::INTEGER)
                ->setParameter('default', true, Types::BOOLEAN)->getQuery()->execute();
            return true;
        });
    }
}
