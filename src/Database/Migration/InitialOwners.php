<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use itsmng\Database\CheckConstraintSupport;
use itsmng\Database\LegacyAdoptionEligibility;
use itsmng\Database\Migration\Frozen\InitialOwnersV1\ComputerItem;
use itsmng\Database\Migration\Frozen\InitialOwnersV1\NetworkPort;
use itsmng\Database\Migration\Frozen\InitialOwnersV1\RegisteredID;
use RuntimeException;

/** One internal stage of canonical adoption, never a separately published release. */
final class InitialOwners
{
    public const PHASE = '2.2.0.initial_multi_owners_v1';
    public const DEFINITIONS = [NetworkPort::class, RegisteredID::class, ComputerItem::class];

    public function plan(Connection $connection): array
    {
        CheckConstraintSupport::assertSupported($connection);
        LegacyAdoptionEligibility::assertConnection($connection);
        $receipt = $this->receipt($connection);
        $plans = [];
        foreach (self::DEFINITIONS as $class) {
            $state = (new $class())->prepare($connection, $receipt['tables'][$class::TABLE] ?? null);
            $plans[$class::TABLE] = ['complete' => $state['complete'], 'checkpoint' => $state['phase'],
                'adopted_kinds' => array_keys($class::OWNERS), 'opaque_other_kinds' => true,
                'requires_stopped_and_drained_writers' => true,
                'mysql_hidden_incoming_references' => 'Final atomic native MODIFY admission; visible metadata is not absence proof'];
        }
        return $plans;
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        CheckConstraintSupport::assertSupported($connection);
        LegacyAdoptionEligibility::assertConnection($connection);
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && $connection->isTransactionActive()) {
            throw new RuntimeException('Initial multi-owner adoption requires no MySQL application transaction and drained writers.');
        }
        $run = function () use ($connection, $progress): void {
            $receipt = $this->receipt($connection);
            $tables = [];
            // Every incomplete family is admitted before the first receipt or owned DDL/COPY.
            foreach (self::DEFINITIONS as $class) {
                $tables[$class::TABLE] = (new $class())->prepare($connection, $receipt['tables'][$class::TABLE] ?? null);
            }
            if ($receipt === null) {
                $receipt = ['complete' => false, 'tables' => $tables];
                Ledger::save($connection, self::PHASE, $receipt);
            }
            foreach (self::DEFINITIONS as $class) {
                $save = static function (array $state) use ($connection, $class, &$receipt): void {
                    $receipt['tables'][$class::TABLE] = $state;
                    Ledger::save($connection, self::PHASE, $receipt);
                };
                (new $class())->applyPrepared(
                    $connection,
                    $receipt['tables'][$class::TABLE],
                    $save,
                    $progress === null ? null : static fn (string $phase, string $sql) => $progress($class::TABLE . '.' . $phase, $sql)
                );
            }
            $this->verify($connection);
            if (($receipt['complete'] ?? false) !== true) {
                $receipt['complete'] = true;
                Ledger::save($connection, self::PHASE, $receipt);
            }
        };
        if ($mysql) {
            $run();
        } else {
            $connection->transactional($run);
        }
    }

    private function receipt(Connection $connection): ?array
    {
        $receipt = Ledger::state($connection, self::PHASE);
        if ($receipt === null) {
            return null;
        }
        if (!is_bool($receipt['complete'] ?? null) || !is_array($receipt['tables'] ?? null)
            || array_keys($receipt['tables']) !== array_map(static fn (string $class): string => $class::TABLE, self::DEFINITIONS)) {
            throw new RuntimeException('Invalid initial multi-owner coordinator receipt.');
        }
        if ($receipt['complete']) {
            foreach ($receipt['tables'] as $state) {
                if (($state['complete'] ?? false) !== true) {
                    throw new RuntimeException('Completed initial stage contains an incomplete family.');
                }
            }
        }
        return $receipt;
    }

    public function verify(Connection $connection): void
    {
        $receipt = $this->receipt($connection);
        foreach (self::DEFINITIONS as $class) {
            $state = $receipt['tables'][$class::TABLE] ?? null;
            if (!is_array($state) || ($state['complete'] ?? false) !== true) {
                throw new RuntimeException('Missing completed initial owning table receipt: ' . $class::TABLE);
            }
            (new $class())->verify($connection, $state);
        }
    }
}
