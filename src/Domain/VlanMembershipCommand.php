<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\ManagedTransactionScope;
use itsmng\Database\Repository\NetworkPortVlanRepository;
use itsmng\Database\TransactionOwnershipMismatch;

/** Private model continuation for one real prepared membership on one owned writer frame. */
final class VlanMembershipCommand
{
    private ?VlanMembershipSelection $selected = null;
    private ?int $identity = null;
    private ?array $portScope = null;
    private ?array $vlanScope = null;
    private bool $prepared = false;

    public function __construct(
        private readonly \DBAdapter $database,
        private readonly Connection $connection,
        private readonly ManagedTransactionScope $scope,
        private readonly \NetworkPort_Vlan $model,
        private readonly NetworkPortVlanRepository $memberships,
        private readonly ClassMetadata $metadata,
        private readonly bool $removing
    ) {
    }

    public function assertActive(): void
    {
        $this->scope->assertActive();
        if ($this->database !== ($GLOBALS['DB'] ?? null) || $this->database->getDoctrineConnection() !== $this->connection) {
            throw new TransactionOwnershipMismatch('A VLAN membership callback replaced its supplied writer.');
        }
    }

    public function prepareAdd(): bool
    {
        $this->assertActive();
        $this->selected = VlanMembershipSelection::fromFields($this->model->fields, $this->metadata);
        $this->prepared = true;
        $identity = filter_var($this->model->fields['id'] ?? 0, FILTER_VALIDATE_INT);
        $this->identity = $identity !== false && $identity > 0 ? $identity : null;
        return $this->lockSelectedParents() && $this->inputMatches();
    }

    public function prepareUpdate(array $storedFields): bool
    {
        $this->assertActive();
        $this->identity = (int)$storedFields['id'];
        $old = VlanMembershipSelection::fromFields($storedFields, $this->metadata);
        $this->selected = VlanMembershipSelection::fromFields($this->model->fields, $this->metadata);
        $this->prepared = true;
        // Reserve known old/new parents before the membership row. A concurrent
        // reassignment outside that graph refuses instead of acquiring its parents.
        $ports = array_unique([$old->port, $this->selected->port]);
        $vlans = array_unique([$old->vlan, $this->selected->vlan]);
        sort($ports, SORT_NUMERIC);
        sort($vlans, SORT_NUMERIC);
        foreach ($ports as $port) {
            if ($this->memberships->currentPort($port) === null) {
                return false;
            }
            $this->assertActive();
        }
        foreach ($vlans as $vlan) {
            if ($this->memberships->currentVlan($vlan) === null) {
                return false;
            }
            $this->assertActive();
        }
        $stored = $this->memberships->membership($this->identity, current: true);
        $this->assertActive();
        return $stored !== null && $old->matches($stored, $this->metadata)
            && $this->model->getID() === $this->identity && $this->lockSelectedParents() && $this->inputMatches();
    }

    public function prepareRemoval(): bool
    {
        $this->assertActive();
        $identity = (int)$this->model->getID();
        $selected = VlanMembershipSelection::fromFields($this->model->fields, $this->metadata);
        if (($this->identity !== null && $this->identity !== $identity)
            || ($this->selected !== null && !$this->selected->matches($this->model->fields, $this->metadata))) {
            return false;
        }
        $this->identity = $identity;
        $this->selected ??= $selected;
        $this->prepared = true;
        if (!$this->lockSelectedParents()) {
            return false;
        }
        $stored = $this->memberships->membership($this->identity, current: true);
        $this->assertActive();
        return $stored !== null && $this->selected->matches($stored, $this->metadata);
    }

    /** Select the requested natural key inside the actual owned frame, not from a stale model. */
    public function selectRemovalPair(int $port, int $vlan): ?int
    {
        $this->assertActive();
        if ($port <= 0 || $vlan <= 0) {
            return null;
        }
        $parentPort = $this->memberships->currentPort($port);
        $this->assertActive();
        $parentVlan = $this->memberships->currentVlan($vlan);
        $this->assertActive();
        if ($parentPort === null || $parentVlan === null) {
            return null;
        }
        $row = $this->memberships->selectedPair($port, $vlan, current: true);
        $this->assertActive();
        if ($row === null) {
            return null;
        }
        $this->identity = (int)$row['id'];
        $this->selected = VlanMembershipSelection::fromFields($row, $this->metadata);
        return $this->identity;
    }

    /** Generic legacy clone delegates to a new model's actual prepared add. */
    public function acceptDelegatedCreate(int $identity): bool
    {
        $this->assertActive();
        $row = $this->memberships->membership($identity, current: true);
        $this->assertActive();
        if ($row === null || $this->model->getID() !== $identity) {
            return false;
        }
        $this->identity = $identity;
        $this->selected = VlanMembershipSelection::fromFields($row, $this->metadata);
        return $this->selected->matches($this->model->fields, $this->metadata) && $this->lockSelectedParents();
    }

    public function acceptCreatedIdentity(int $identity): void
    {
        $this->assertActive();
        if ($identity <= 0 || ($this->identity !== null && $this->identity !== $identity)) {
            throw new \RuntimeException('A VLAN membership producer changed its selected identity.');
        }
        $this->identity = $identity;
        $this->assertModel();
    }

    /** Read callbacks may run, but cannot change the prepared membership or writer. */
    public function assertModel(): void
    {
        $this->assertActive();
        if ($this->selected !== null && (!$this->selected->matches($this->model->fields, $this->metadata)
            || ($this->identity !== null && (int)$this->model->getID() !== $this->identity)
            || ($this->prepared && !$this->inputMatches()))) {
            throw new \RuntimeException('A callback changed the prepared VLAN membership.');
        }
    }

    public function assertReadIdentity($identity): void
    {
        $this->assertActive();
        if ($this->selected !== null && $this->identity !== null && (int)$identity !== $this->identity) {
            throw new \RuntimeException('A callback loaded a different VLAN membership.');
        }
    }

    public function finish(mixed $result): bool
    {
        $this->assertActive();
        if (!$result) {
            return false;
        }
        if ($this->selected === null) {
            // CommonDBTM's legacy clone returns its newly added child's ID.
            // That child has its own command; there is no second direct writer.
            if (!is_int($result) || !$this->acceptDelegatedCreate($result)) {
                return false;
            }
        }
        $this->assertModel();
        $stored = $this->memberships->membership($this->identity, current: true);
        $this->assertActive();
        if ($this->removing) {
            $removed = $stored === null && $this->memberships->selectedPair($this->selected->port, $this->selected->vlan, current: true) === null;
            $this->assertModel();
            return $removed;
        }
        $port = $this->memberships->currentPort($this->selected->port);
        $this->assertActive();
        $vlan = $this->memberships->currentVlan($this->selected->vlan);
        $this->assertActive();
        $accepted = $stored !== null && $this->selected->matches($stored, $this->metadata)
            && $port === $this->portScope && $vlan === $this->vlanScope && $this->available();
        $this->assertModel();
        return $accepted;
    }

    private function lockSelectedParents(): bool
    {
        $this->assertActive();
        $this->portScope = $this->memberships->currentPort($this->selected->port);
        $this->assertActive();
        $this->vlanScope = $this->memberships->currentVlan($this->selected->vlan);
        $this->assertActive();
        // Cleanup of a stored membership is not an assignment permission check.
        $available = $this->portScope !== null && $this->vlanScope !== null && ($this->removing || $this->available());
        $this->assertActive();
        return $available;
    }

    private function available(): bool
    {
        $portEntity = (int)$this->portScope['entity'];
        $vlanEntity = (int)$this->vlanScope['entity'];
        return $portEntity === $vlanEntity
            || ($this->portScope['recursive'] && $this->memberships->containsEntity($portEntity, $vlanEntity, fn () => $this->assertActive()))
            || ($this->vlanScope['recursive'] && $this->memberships->containsEntity($vlanEntity, $portEntity, fn () => $this->assertActive()));
    }

    private function inputMatches(): bool
    {
        if (!is_array($this->model->input)) {
            return false;
        }
        $values = [];
        foreach (['networkports', 'vlans'] as $association) {
            $column = $this->metadata->getAssociationMapping($association)->joinColumns[0]->name;
            $values[$column] = array_key_exists($column, $this->model->input) ? $this->model->input[$column] : $this->model->fields[$column];
        }
        $column = $this->metadata->getFieldMapping('tagged')->columnName;
        if (array_key_exists($column, $this->model->input)) {
            $values[$column] = $this->model->input[$column];
        } elseif (array_key_exists($column, $this->model->fields)) {
            $values[$column] = $this->model->fields[$column];
        }
        return $this->selected === null || $this->selected->matches($values, $this->metadata);
    }
}
