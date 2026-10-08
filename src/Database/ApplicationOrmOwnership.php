<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Throwable;

/** Canonical connection lifetime, with a separate unit of work for reentrant operations. */
trait ApplicationOrmOwnership
{
    private ?EntityManager $applicationEntityManager = null;
    private bool $applicationEntityManagerActive = false;
    /** @var array<string, Type> Actual authoritative registry objects used by this manager. */
    private array $applicationTypes = [];

    /** @internal Application operations return values, never managed entities or repositories. */
    public function withApplicationEntityManager(callable $operation): mixed
    {
        if ($this->applicationEntityManagerActive) {
            $manager = Orm::forConnection($this);
            try {
                return $operation($manager);
            } finally {
                $manager->clear();
            }
        }
        if ($this->applicationTypes !== Type::getTypeRegistry()->getMap()) {
            $this->resetApplicationEntityManager();
        }
        if ($this->applicationEntityManager === null || !$this->applicationEntityManager->isOpen()) {
            $this->applicationEntityManager = Orm::forConnection($this);
            // Configuration may register the application's two native types.
            $this->applicationTypes = Type::getTypeRegistry()->getMap();
        }
        $manager = $this->applicationEntityManager;
        $this->applicationEntityManagerActive = true;
        try {
            return $operation($manager);
        } catch (Throwable $error) {
            $this->resetApplicationEntityManager();
            throw $error;
        } finally {
            try {
                // Legacy DBAL producers do not maintain an ORM identity map.
                // Only this completed operation owns the state being detached.
                $manager->clear();
            } catch (Throwable $error) {
                $this->resetApplicationEntityManager();
                throw $error;
            } finally {
                $this->applicationEntityManagerActive = false;
                if (!$manager->isOpen()) {
                    $this->resetApplicationEntityManager();
                }
            }
        }
    }

    /** Admit only the private manager currently owned by this application scope. */
    public function ownsApplicationEntityManager(EntityManager $manager): bool
    {
        return $this->applicationEntityManagerActive && $this->applicationEntityManager === $manager;
    }

    private function resetApplicationEntityManager(): void
    {
        // An in-flight callback retains its local manager until its own finally.
        $this->applicationEntityManager = null;
        $this->applicationTypes = [];
    }
}
