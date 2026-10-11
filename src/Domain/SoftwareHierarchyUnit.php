<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DBAdapter;
use Doctrine\DBAL\Connection;
use WeakMap;
use itsmng\Database\Entity\Entity;
use itsmng\Database\ManagedTransactionScope;
use itsmng\Database\Orm;
use itsmng\Database\Repository\EntityHierarchyRepository;
use itsmng\Database\TransactionOwnership;

/** Selected ancestry belongs to the actual managed frame of the outer command. */
final class SoftwareHierarchyUnit
{
    /** @var \WeakMap<Connection, array{hierarchy: EntityHierarchy, scope: ManagedTransactionScope}>|null */
    private static ?WeakMap $active = null;

    public static function acceptsPair(DBAdapter $database, EntityScope $subject, EntityScope $license): bool
    {
        $reservation = self::$active[$database->getDoctrineConnection()] ?? null;
        if ($reservation === null) {
            throw new SoftwareAssignmentCancelled('The final allocation has no current owning hierarchy reservation.');
        }
        $reservation['scope']->assertActive();
        if (!$reservation['hierarchy']->contains([$subject->entity, $license->entity])) {
            throw new SoftwareAssignmentCancelled('The final allocation requires an unreserved entity.');
        }
        return $subject->isCompatibleWith($license, $reservation['hierarchy']);
    }

    public static function run(DBAdapter $database, array $entities, callable $operation): mixed
    {
        $connection = $database->getDoctrineConnection();
        if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave()
            || $connection->getTransactionNestingLevel() === 0) {
            throw new SoftwareAssignmentCancelled('Hierarchy reservation requires the supplied active mutation writer.');
        }
        TransactionOwnership::assertManaged($connection);
        self::$active ??= new WeakMap();
        if (isset(self::$active[$connection])) {
            $reservation = self::$active[$connection];
            $reservation['scope']->assertActive();
            if (!$reservation['hierarchy']->contains($entities)) {
                // Never acquire new hierarchy locks behind an outer graph lock.
                throw new SoftwareAssignmentCancelled('Nested allocation needs an unreserved entity; retry the whole outer command.');
            }
            return $operation($reservation['hierarchy']);
        }
        $scope = $connection->captureManagedTransactionScope();
        $manager = Orm::create($database);
        SoftwareMutation::assertTransactionalStorage($database, [$manager->getClassMetadata(Entity::class)->getTableName()]);
        $repository = new EntityHierarchyRepository($manager);
        $hierarchy = $repository->reserve($entities);
        $scope->assertActive();
        self::$active[$connection] = ['hierarchy' => $hierarchy, 'scope' => $scope];
        try {
            $result = $operation($hierarchy);
            if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave()
                || $database->getDoctrineConnection() !== $connection) {
                throw new SoftwareAssignmentCancelled('A lifecycle callback replaced the hierarchy writer.');
            }
            $scope->assertActive();
            $repository->validate($hierarchy);
            $scope->assertActive();
            return $result;
        } finally {
            // This unit owns the reservation only. Its caller owns rollback,
            // and must never rewind an ended or replaced managed frame.
            unset(self::$active[$connection]);
        }
    }
}
