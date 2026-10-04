<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Connection;
use itsmng\Database\DeletionCancelled;
use itsmng\Database\DeletionUnit;
use itsmng\Database\ManagedTransactionScope;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ComponentDefinitionRepository;
use itsmng\Database\Repository\DeletionRepository;

/** The definition owner delegates only its validated replacement to its children. */
final class ComponentDefinitionReplacement
{
    private bool $active = true;

    private function __construct(
        private readonly \DBAdapter $database,
        private readonly Connection $connection,
        private readonly ManagedTransactionScope $scope,
        private readonly \CommonDevice $owner,
        public readonly int $source,
        public readonly int $replacement,
        private readonly array $sourceRecord,
        private readonly array $replacementRecord,
        private readonly mixed $actor
    ) {
    }

    public static function forPurge(\DBAdapter $database, \CommonDevice $owner): self
    {
        $database->assertManagedTransaction();
        $connection = $database->getDoctrineConnection();
        if ($database !== ($GLOBALS['DB'] ?? null) || $database->isSlave() || !DeletionUnit::isActive($connection)) {
            throw new DeletionCancelled('Definition replacement requires its actual owner deletion.');
        }
        $scope = $connection->captureManagedTransactionScope();
        $actor = \Session::getLoginUserID();
        $sourceId = (int)$owner->getID();
        $replacementId = (int)($owner->input['_replace_by'] ?? 0);
        if ($sourceId <= 0 || $replacementId <= 0 || $sourceId === $replacementId) {
            throw new DeletionCancelled('Definition replacement requires distinct real owners.');
        }
        $manager = Orm::create($database);
        try {
            if (!(new DeletionRepository($manager))->validateReplacement($owner, $owner->input)) {
                throw new DeletionCancelled('The definition replacement is invalid.');
            }
            $records = new ComponentDefinitionRepository($manager);
            $source = $records->current($owner->getTable(), $sourceId);
            $replacement = $records->current($owner->getTable(), $replacementId);
            if (\Session::getLoginUserID() !== $actor || $database !== ($GLOBALS['DB'] ?? null)
                || $database->getDoctrineConnection() !== $connection || (int)$owner->getID() !== $sourceId
                || (int)($owner->input['_replace_by'] ?? 0) !== $replacementId
                || $source === null || $replacement === null || $source['id'] === $replacement['id']
                || array_intersect_key($owner->fields, $source) !== $source
                || !empty($replacement['is_deleted']) || !empty($replacement['is_template'])) {
                throw new DeletionCancelled('The current definition owner or replacement changed.');
            }
            if (array_key_exists('entities_id', $source)
                && !(new DeletionRepository($manager))->replacementEntityScope(
                    $owner->getTable(),
                    $source['entities_id'],
                    $replacement['entities_id'],
                    !empty($replacement['is_recursive'])
                )) {
                throw new DeletionCancelled('The current replacement definition cannot reach its source owner.');
            }
            $scope->assertActive();
            return new self(
                $database,
                $connection,
                $scope,
                $owner,
                (int)$source['id'],
                (int)$replacement['id'],
                $source,
                $replacement,
                $actor
            );
        } finally {
            $manager->clear();
        }
    }

    public function assertActive(): void
    {
        $this->scope->assertActive();
        if (!$this->active || $this->database !== ($GLOBALS['DB'] ?? null)
            || $this->database->getDoctrineConnection() !== $this->connection
            || \Session::getLoginUserID() !== $this->actor
            || (int)$this->owner->getID() !== $this->source
            || (int)($this->owner->input['_replace_by'] ?? 0) !== $this->replacement) {
            throw new DeletionCancelled('The definition replacement command changed.');
        }
    }

    public function current(string $table, int $id): ?array
    {
        $this->assertActive();
        $manager = Orm::create($this->database);
        try {
            $row = (new ComponentDefinitionRepository($manager))->current($table, $id);
        } finally {
            $manager->clear();
        }
        $this->assertActive();
        return $row;
    }

    public function bind(\Item_Devices $model, array $input, string $column): ComponentDefinitionChange
    {
        $this->assertActive();
        $manager = Orm::create($this->database);
        try {
            if (!(new ComponentDefinitionRepository($manager))->ownsDefinition($model->getTable(), $column, $this->owner->getTable())) {
                throw new DeletionCancelled('The selected child does not own this definition reference.');
            }
        } finally {
            $manager->clear();
        }
        if ($model::getDeviceType() !== $this->owner->getType()
            || (int)($input[$column] ?? 0) !== $this->replacement) {
            throw new DeletionCancelled('The selected definition role changed.');
        }
        $this->assertDefinitions();
        $stored = $this->current($model->getTable(), (int)$input[$model->getIndexName()]);
        if ($stored === null || (int)$stored[$column] !== $this->source) {
            throw new DeletionCancelled('The selected binding owner changed.');
        }
        $declaredOwner = \itsmng\Database\EntityRegistry::entityScopeOwner($model->getTable());
        if ($declaredOwner !== null && ($declaredOwner['column'] !== $column || $declaredOwner['target'] !== $this->owner->getTable())) {
            throw new DeletionCancelled('This definition does not own the declared cached scope.');
        }
        $kind = $stored['itemtype'];
        $subjectTable = null;
        $subjectRecord = null;
        $scopeOwner = $this->replacementRecord;
        if (!($kind === null || $kind === '') || (int)$stored['items_id'] !== 0) {
            $subject = is_string($kind) ? getItemForItemtype($kind) : false;
            if (!$subject || !($subject instanceof \CommonDBTM)) {
                throw new DeletionCancelled('The attached component subject is invalid.');
            }
            $subjectTable = $subject->getTable();
            $subjectRecord = $this->current($subjectTable, (int)$stored['items_id']);
            if ($subjectRecord === null || !array_key_exists('entities_id', $subjectRecord)) {
                throw new DeletionCancelled('The attached component subject changed.');
            }
            // Preserve existing availability for an equivalent catalogue owner.
            // A changed owner scope must genuinely reach this unchanged asset.
            if (array_intersect_key($this->sourceRecord, array_flip(['entities_id', 'is_recursive']))
                !== array_intersect_key($this->replacementRecord, array_flip(['entities_id', 'is_recursive']))
                && !$this->definitionAvailable($subjectRecord)) {
                throw new DeletionCancelled('The replacement definition is unavailable to the attached asset.');
            }
            if ($declaredOwner === null && $model::$take_entity_1) {
                $scopeOwner = $subjectRecord;
            }
        }
        // Use the relation's existing declared forwarding role. Core component
        // bindings take their cached scope from the definition, including stock.
        $scope = $declaredOwner !== null || $model::$take_entity_1 || $model::$take_entity_2
            ? ['entities_id' => $scopeOwner['entities_id'] ?? 0, 'is_recursive' => $scopeOwner['is_recursive'] ?? 0]
            : [];
        $scope = array_intersect_key($scope, $stored);
        return new ComponentDefinitionChange($this, $model, $column, $stored, $scope, $subjectTable, $subjectRecord);
    }

    public function subjectUnchanged(string $table, array $expected): bool
    {
        if ($this->current($table, (int)$expected['id']) !== $expected) {
            return false;
        }
        return array_intersect_key($this->sourceRecord, array_flip(['entities_id', 'is_recursive']))
            === array_intersect_key($this->replacementRecord, array_flip(['entities_id', 'is_recursive']))
            || $this->definitionAvailable($expected);
    }

    private function definitionAvailable(array $asset): bool
    {
        if (($this->replacementRecord['entities_id'] ?? null) === null
            && \itsmng\Database\EntityRegistry::hasPolicy($this->owner->getTable(), 'entities_id', \itsmng\Database\Mapping\ReferenceKind::GlobalScope)) {
            return true;
        }
        $target = (int)($this->replacementRecord['entities_id'] ?? 0);
        $entity = (int)$asset['entities_id'];
        if ($entity === $target) {
            return true;
        }
        if (empty($this->replacementRecord['is_recursive'])) {
            return false;
        }
        $seen = [];
        while ($entity !== 0) {
            if (isset($seen[$entity])) {
                throw new DeletionCancelled('The current asset entity ancestry contains a cycle.');
            }
            $seen[$entity] = true;
            $record = $this->current(\Entity::getTable(), $entity);
            if ($record === null) {
                return false;
            }
            $entity = (int)($record['entities_id'] ?? 0);
            if ($entity === $target) {
                return true;
            }
        }
        return false;
    }

    public function assertDefinitions(): void
    {
        $this->assertActive();
        $manager = Orm::create($this->database);
        try {
            if (array_key_exists('entities_id', $this->sourceRecord)
                && !(new DeletionRepository($manager))->replacementEntityScope(
                    $this->owner->getTable(),
                    $this->sourceRecord['entities_id'],
                    $this->replacementRecord['entities_id'],
                    !empty($this->replacementRecord['is_recursive'])
                )) {
                throw new DeletionCancelled('The current replacement entity ancestry changed.');
            }
        } finally {
            $manager->clear();
        }
        if ($this->current($this->owner->getTable(), $this->source) !== $this->sourceRecord
            || $this->current($this->owner->getTable(), $this->replacement) !== $this->replacementRecord) {
            throw new DeletionCancelled('The current definition records changed during replacement.');
        }
    }

    public function release(): void
    {
        $this->active = false;
    }
}
