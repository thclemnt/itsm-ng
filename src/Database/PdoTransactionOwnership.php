<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver\PDO\Exception;
use PDO;
use PDOException;
use stdClass;
use WeakReference;

/** Physical state stays inside the DBAL owner; never infer it from application SQL. */
trait PdoTransactionOwnership
{
    /** @var array<int, object> Opaque identities minted by this owner's actual begin API. */
    private array $managedFrames = [];

    public function assertManagedTransaction(): void
    {
        $native = $this->managedNative();
        try {
            $physical = $native->inTransaction();
        } catch (PDOException $error) {
            throw $this->convertException(Exception::new($error));
        }
        if ($physical !== ($this->getTransactionNestingLevel() > 0)) {
            throw new TransactionOwnershipMismatch('Physical transaction state disagrees with DBAL ownership; finish the caller transaction before this operation.');
        }
    }

    public function captureManagedTransactionScope(): ManagedTransactionScope
    {
        $this->assertManagedTransaction();
        $level = $this->getTransactionNestingLevel();
        $frame = $this->managedFrames[$level] ?? null;
        if ($level === 0 || $frame === null) {
            throw new TransactionOwnershipMismatch('Capture requires a frame begun through this managed DBAL owner.');
        }
        $owner = WeakReference::create($this);
        $native = WeakReference::create($this->managedNative());
        return new ManagedTransactionScope(static function () use ($owner, $native, $level, $frame): void {
            $connection = $owner->get();
            if ($connection === null) {
                throw new TransactionOwnershipMismatch('The captured transaction owner no longer exists.');
            }
            $connection->assertManagedFrame($level, $frame, $native);
        });
    }

    private function managedNative(): PDO
    {
        $native = $this->getNativeConnection();
        if (!$native instanceof PDO) {
            throw new TransactionOwnershipMismatch('The supplied transaction owner requires its current PDO connection.');
        }
        return $native;
    }

    private function recordManagedFrame(): void
    {
        $this->managedFrames[$this->getTransactionNestingLevel()] = new stdClass();
    }

    private function reconcileManagedFrames(): void
    {
        $level = $this->getTransactionNestingLevel();
        foreach (array_keys($this->managedFrames) as $frameLevel) {
            if ($frameLevel > $level) {
                unset($this->managedFrames[$frameLevel]);
            }
        }
    }

    private function resetManagedFrames(): void
    {
        $this->managedFrames = [];
    }

    private function assertManagedFrame(int $level, object $frame, WeakReference $native): void
    {
        // Check identities before obtaining a handle: a closed scope must not reconnect.
        if (($this->managedFrames[$level] ?? null) !== $frame || $this->getTransactionNestingLevel() < $level
            || $native->get() === null || !$this->isConnected()) {
            throw new TransactionOwnershipMismatch('The captured managed frame has ended or been replaced.');
        }
        $this->assertManagedTransaction();
        if ($native->get() !== $this->managedNative()) {
            throw new TransactionOwnershipMismatch('The captured managed frame belongs to a different physical owner.');
        }
    }
}
