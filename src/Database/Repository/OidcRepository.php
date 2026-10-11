<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Auth;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use InvalidArgumentException;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\Entity\OidcConfig;
use itsmng\Database\Entity\OidcMapping;
use itsmng\Database\Entity\OidcUser;
use itsmng\Database\Entity\User;
use LogicException;

/** Local OIDC configuration and profile persistence; no identity-provider traffic. */
final class OidcRepository
{
    public const PROFILE_FIELDS = ['name' => 'name', 'given_name' => 'firstname', 'family_name' => 'realname', 'picture' => 'picture', 'locale' => 'language', 'phone_number' => 'phone'];

    public function __construct(private EntityManager $em)
    {
    }

    public function configuration(): array
    {
        $records = new RecordRepository($this->em);
        return $records->find('glpi_oidc_config', 'id', 0) ?? $records->toRow(new OidcConfig());
    }

    public function mapping(): array
    {
        return (new RecordRepository($this->em))->find('glpi_oidc_mapping', 'id', 0) ?? [];
    }

    public function saveConfiguration(array $values): void
    {
        $this->saveSingleton('glpi_oidc_config', $values);
    }

    public function saveMapping(array $values): void
    {
        $this->saveSingleton('glpi_oidc_mapping', $values);
    }

    private function saveSingleton(string $table, array $values): void
    {
        unset($values['id']);
        $this->em->getConnection()->transactional(function () use ($table, $values): void {
            $records = new RecordRepository($this->em);
            $writer = new RecordWriter($this->em);
            if ($records->find($table, 'id', 0) === null) {
                $writer->insert($table, ['id' => 0] + $values);
            } else {
                $writer->update($table, 0, $values);
            }
        });
    }

    public function linkableUser(string $name, bool $allowLocal): ?int
    {
        $query = $this->em->createQueryBuilder()
            ->select('u.id')
            ->from(User::class, 'u')
            ->where('u.name = :name')
            ->setParameter('name', $name)
            ->orderBy('u.id')
            ->setMaxResults(1);
        if (!$allowLocal) {
            $query->andWhere('u.authtype = :type')
                ->setParameter('type', Auth::EXTERNAL, Types::INTEGER);
        }
        $row = $query->getQuery()
            ->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    public function needsRefresh(int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        return $this->em->createQueryBuilder()
            ->select('o.id')
            ->from(OidcUser::class, 'o')
            ->where('IDENTITY(o.users) = :user AND o.update = :pending')
            ->setParameter('user', $user, Types::INTEGER)
            ->setParameter('pending', false, Types::BOOLEAN)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /** Fixed private read; profile synchronization and its transaction remain separate. */
    public function nativeNeedsRefresh(int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        $metadata = $this->em->getClassMetadata(OidcUser::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $reference = $metadata->associationMappings['users'];
        if (!$reference->isToOneOwningSide() || count($reference->joinColumns) !== 1) {
            throw new LogicException('OIDC refresh requires a single owning user reference.');
        }
        $idType = Type::getType($metadata->getTypeOfField('id'));
        $userType = Type::getType(Types::INTEGER);
        $pendingType = Type::getType(Types::BOOLEAN);
        $rows = $connection->createQueryBuilder()
            ->select(
                $idType->convertToPHPValueSQL(
                    'o.' . $quote->getColumnName('id', $metadata, $platform),
                    $platform
                ) . ' AS id'
            )
            ->from($quote->getTableName($metadata, $platform), 'o')
            ->where('o.' . $quote->getJoinColumnName($reference->joinColumns[0], $metadata, $platform)
                . ' = ' . $userType->convertToDatabaseValueSQL(':user', $platform))
            ->andWhere('o.' . $quote->getColumnName('update', $metadata, $platform)
                . ' = ' . $pendingType->convertToDatabaseValueSQL(':pending', $platform))
            ->setParameter('user', $user, Types::INTEGER)
            ->setParameter('pending', false, Types::BOOLEAN)
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            // getOneOrNullResult uses object hydration even for this scalar row;
            // its mapped PHP conversion is observable to custom DBAL types.
            $idType->convertToPHPValue($row['id'], $platform);
        }
        if (count($rows) > 1) {
            throw new NonUniqueResultException();
        }
        return $rows !== [];
    }

    public function requestRefresh(): int
    {
        return $this->em->createQueryBuilder()
            ->update(OidcUser::class, 'o')
            ->set('o.update', ':pending')
            ->setParameter('pending', false, Types::BOOLEAN)
            ->getQuery()
            ->execute();
    }

    public function deleteUserState(int $user): void
    {
        $this->em->createQueryBuilder()
            ->delete(OidcUser::class, 'o')
            ->where('IDENTITY(o.users) = :user')
            ->setParameter('user', $user, Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    /** Return a mapped email for the existing UserEmail lifecycle to validate and save. */
    public function synchronizeProfile(int $id, array $claims, DateTimeImmutable $at): ?string
    {
        return $this->em->wrapInTransaction(function () use ($id, $claims, $at): ?string {
            // Serialize OIDC group creation through its singleton mapping row, then
            // serialize per-user state changes. This also protects initial inserts.
            $mapping = $this->em->find(OidcMapping::class, 0, LockMode::PESSIMISTIC_WRITE);
            $user = $id > 0 ? $this->em->find(User::class, $id, LockMode::PESSIMISTIC_WRITE) : null;
            if ($user === null) {
                throw new InvalidArgumentException('OIDC profile requires an existing user');
            }
            $email = null;
            if ($mapping !== null) {
                foreach (self::PROFILE_FIELDS as $source => $field) {
                    $value = $this->claim($claims, $mapping->$source);
                    if ($value !== null) {
                        $user->$field = $value;
                    }
                }
                $user->date_mod = DateTime::createFromImmutable($at);
                $email = $this->claim($claims, $mapping->email);
                $groups = $mapping->group ? ($claims[$mapping->group] ?? []) : [];
                if (!is_array($groups) || array_filter($groups, static fn ($name) => !is_string($name))) {
                    throw new InvalidArgumentException('OIDC groups must be a list of names');
                }
                $seenGroups = [];
                foreach (array_unique($groups) as $name) {
                    if ($name === '') {
                        continue;
                    }
                    $group = $this->em->getRepository(Group::class)
                        ->findOneBy(['name' => $name], ['id' => 'ASC']);
                    if ($group === null) {
                        $group = new Group();
                        $group->name = $group->completename = $name;
                        $group->entities = $this->em->getReference(Entity::class, 0);
                        $this->em->persist($group);
                        $this->em->flush();
                    }
                    // Database collations can resolve distinct claim names to the same group.
                    if (isset($seenGroups[$group->id])) {
                        continue;
                    }
                    $seenGroups[$group->id] = true;
                    if ($this->em->getRepository(GroupMembership::class)
                        ->findOneBy(['users' => $user, 'groups' => $group]) === null) {
                        $membership = new GroupMembership();
                        $membership->users = $user;
                        $membership->groups = $group;
                        $this->em->persist($membership);
                    }
                }
            }
            $state = $this->em->getRepository(OidcUser::class)
                ->findOneBy(['users' => $user]);
            if ($state === null) {
                $state = new OidcUser();
                $state->users = $user;
                $this->em->persist($state);
            }
            $state->update = true;
            $this->em->flush();
            return $email === null ? null : trim($email);
        });
    }

    private function claim(array $claims, ?string $key): ?string
    {
        if ($key === null || $key === '' || !isset($claims[$key])) {
            return null;
        }
        if (!is_scalar($claims[$key])) {
            throw new InvalidArgumentException('OIDC mapped profile claims must be scalar values');
        }
        return (string)$claims[$key];
    }
}
