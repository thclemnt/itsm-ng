<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\NetworkPort;
use itsmng\Database\Entity\NetworkPortVlan;
use itsmng\Database\Entity\Vlan;
use itsmng\Database\Orm;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\NetworkPortVlanRepository;

/** Application membership commands and operation-local reads use the supplied owner. */
final class VlanMembershipService
{
    public function __construct(private readonly \DBAdapter $database)
    {
    }

    public function forPort(int $port): array
    {
        return $this->read(static fn (NetworkPortVlanRepository $memberships): array => $memberships->forPort($port));
    }

    public function forVlan(int $vlan): array
    {
        return $this->read(static fn (NetworkPortVlanRepository $memberships): array => $memberships->forVlan($vlan));
    }

    public function membershipsForPort(int $port): array
    {
        return $this->read(static fn (NetworkPortVlanRepository $memberships): array => $memberships->membershipsForPort($port));
    }

    public function countForPort(int $port): int
    {
        return $this->read(static fn (NetworkPortVlanRepository $memberships): int => $memberships->countForPort($port));
    }

    public function countForVlan(int $vlan): int
    {
        return $this->read(static fn (NetworkPortVlanRepository $memberships): int => $memberships->countForVlan($vlan));
    }

    /** The original public lifecycle prepares the immutable selected intent. */
    public function mutate(\NetworkPort_Vlan $model, callable $operation, bool $removing = false): mixed
    {
        if ($this->database->isSlave() || $this->database !== ($GLOBALS['DB'] ?? null)) {
            return false;
        }
        $this->database->assertManagedTransaction();
        $connection = $this->database->getDoctrineConnection();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $isolation = strtolower((string)$connection->fetchOne("SELECT current_setting('transaction_isolation')"));
            if (!in_array($isolation, ['read committed', 'read uncommitted'], true)) {
                throw new \RuntimeException('VLAN membership requires PostgreSQL READ COMMITTED; actual isolation is ' . $isolation . '. Retry outside the caller transaction.');
            }
        }
        $before = $model->fields ?? [];
        $result = false;
        $accepted = OwnershipUpdateUnit::run($this->database, $model, $before, function () use ($model, $operation, $removing, $connection, &$result): bool {
            $manager = Orm::create($this->database);
            $primary = null;
            try {
                foreach ([NetworkPortVlan::class, NetworkPort::class, Vlan::class, Entity::class] as $class) {
                    OwnershipUpdateUnit::assertTransactionalStorage($this->database, $manager->getClassMetadata($class)->getTableName());
                }
                foreach ([\Log::getTable(), \QueuedNotification::getTable()] as $table) {
                    OwnershipUpdateUnit::assertTransactionalStorage($this->database, $table);
                }
                $command = new VlanMembershipCommand($this->database, $connection, $this->database->captureManagedTransactionScope(), $model,
                    new NetworkPortVlanRepository($manager), $manager->getClassMetadata(NetworkPortVlan::class), $removing);
                $result = $operation($command);
                return $command->finish($result);
            } catch (\Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                try {
                    $manager->clear();
                } catch (\Throwable $cleanup) {
                    throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
                }
            }
        });
        return $accepted ? $result : false;
    }

    private function read(callable $operation): mixed
    {
        // Legacy writers do not synchronize Doctrine collections or identity maps.
        // A repeated call retains the connection, not a previous managed read view.
        $manager = Orm::create($this->database);
        $primary = null;
        try {
            return $operation(new NetworkPortVlanRepository($manager));
        } catch (\Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            try {
                $manager->clear();
            } catch (\Throwable $cleanup) {
                throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
            }
        }
    }
}
