<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;

/** Canonical connection lifetime, with a separate unit of work for reentrant operations. */
trait ApplicationOrmOwnership
{
    private ?EntityManager $applicationEntityManager = null;
    private ?ArrayAdapter $applicationQueryCache = null;
    private bool $applicationEntityManagerActive = false;
    /** @var array<string, Type> Actual authoritative registry objects used by this manager. */
    private array $applicationTypes = [];

    /** @internal Application operations return values, never managed entities or repositories. */
    public function withApplicationEntityManager(callable $operation): mixed
    {
        if ($this->applicationEntityManagerActive) {
            $manager = Orm::forConnection($this);
            $primary = null;
            try {
                return $operation($manager);
            } catch (Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                try {
                    $manager->clear();
                } catch (Throwable $cleanup) {
                    throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
                }
            }
        }
        if ($this->applicationTypes !== Type::getTypeRegistry()->getMap()) {
            $this->resetApplicationEntityManager();
        }
        if ($this->applicationEntityManager === null || !$this->applicationEntityManager->isOpen()) {
            $this->applicationEntityManager = Orm::forConnection($this);
            $this->applicationQueryCache = new ArrayAdapter(storeSerialized: true);
            $this->applicationEntityManager->getConfiguration()->setQueryCache($this->applicationQueryCache);
            // Configuration may register the application's two native types.
            $this->applicationTypes = Type::getTypeRegistry()->getMap();
        }
        $manager = $this->applicationEntityManager;
        $this->applicationEntityManagerActive = true;
        $primary = null;
        try {
            return $operation($manager);
        } catch (Throwable $error) {
            $primary = $error;
            $this->resetApplicationEntityManager();
            throw $error;
        } finally {
            try {
                // Legacy DBAL producers do not maintain an ORM identity map.
                // Only this completed operation owns the state being detached.
                $manager->clear();
            } catch (Throwable $cleanup) {
                $this->resetApplicationEntityManager();
                throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
            } finally {
                $this->applicationEntityManagerActive = false;
                if (!$manager->isOpen()) {
                    $this->resetApplicationEntityManager();
                }
            }
        }
    }

    /** @internal A staged reader begun inside another scope retains its independent owner. */
    public function isApplicationEntityManagerActive(): bool
    {
        return $this->applicationEntityManagerActive;
    }

    /** Admit only the private manager currently owned by this application scope. */
    public function ownsApplicationEntityManager(EntityManager $manager): bool
    {
        return $this->applicationEntityManagerActive && $this->applicationEntityManager === $manager;
    }

    /** @internal Only the original serialized cache of the active private manager may be shared. */
    public function getApplicationQueryCache(EntityManager $manager): ?ArrayAdapter
    {
        return $this->ownsApplicationEntityManager($manager)
            && $manager->getConfiguration()->getQueryCache() === $this->applicationQueryCache
                ? $this->applicationQueryCache
                : null;
    }

    private function resetApplicationEntityManager(): void
    {
        // An in-flight callback retains its local manager until its own finally.
        $this->applicationEntityManager = null;
        $this->applicationQueryCache = null;
        $this->applicationTypes = [];
    }
}
