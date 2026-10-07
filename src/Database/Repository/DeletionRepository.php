<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\RecordCriteria;

/** Locks and validates replacement ownership using the actual owning entity properties. */
final class DeletionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function validate(\CommonDBTM $model, array $input): bool
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$model->getTable()]);
        $identifier = $input[$model->getIndexName()] ?? null;
        if ((!is_int($identifier) && !is_string($identifier)) || filter_var($identifier, FILTER_VALIDATE_INT) === false) {
            return false;
        }
        $locked = $this->loadCurrent($model, $metadata, (int)$identifier);
        if ($locked === null
            || (int)$model->fields['id'] !== (int)$locked->id || (int)$model->getID() !== (int)$identifier) {
            return false;
        }
        return $this->validateReplacement($model, $input);
    }

    public function validateReplacement(\CommonDBTM $model, array $input): bool
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$model->getTable()]);
        $replacement = $input['_replace_by'] ?? null;
        if ($replacement === null || $replacement === '' || $replacement === 0 || $replacement === '0') {
            return true; // Empty/root behavior remains with the property-local lifecycle policy.
        }
        if ((!is_int($replacement) && !is_string($replacement)) || filter_var($replacement, FILTER_VALIDATE_INT) === false || (int)$replacement < 0) {
            return false;
        }
        if ((int)$replacement === (int)$model->getID()) {
            return false;
        }
        $replacementModel = clone $model;
        $target = $this->loadCurrent($replacementModel, $metadata, (int)$replacement);
        if ($target === null || (int)$target->id === (int)$model->fields['id']
            || ($replacementModel->maybeDeleted() && !empty($replacementModel->fields['is_deleted']))
            || $replacementModel->isTemplate()) {
            return false;
        }
        if ($model instanceof \CommonTreeDropdown) {
            $parent = $this->selfParent($metadata, $model->getForeignKeyField());
            if ($parent === null || $this->hasAncestor($metadata, $parent, (int)$target->id, (int)$model->fields['id'])) {
                return false;
            }
        }
        $rootTree = $model instanceof \CommonTreeDropdown
            && EntityRegistry::hasPolicy($model->getTable(), $model->getForeignKeyField(), ReferenceKind::RootParent);
        return !$model->isEntityAssign() || $rootTree || $this->replacementEntityScope(
            $model->getTable(),
            $model->getEntityID(),
            $replacementModel->getEntityID(),
            $replacementModel->maybeRecursive() && !empty($replacementModel->fields['is_recursive'])
        );
    }

    /** Shared structural policy, also usable with refreshed owning-record scopes. */
    public function replacementEntityScope(string $table, ?int $sourceEntity, ?int $targetEntity, bool $targetRecursive): bool
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $globalScope = EntityRegistry::hasPolicy($table, 'entities_id', ReferenceKind::GlobalScope);
        if ($globalScope && $targetEntity === null) {
            return true;
        }
        if ($sourceEntity === $targetEntity) {
            return true;
        }
        $sourceEntities = $globalScope && $sourceEntity === null
            ? ($_SESSION['glpiactiveentities'] ?? [0]) : [$sourceEntity];
        if (in_array($targetEntity, $sourceEntities, true)) {
            return true;
        }
        if (!$targetRecursive) {
            return false;
        }
        $scope = null;
        foreach ($metadata->associationMappings as $property => $association) {
            $attributes = (new \ReflectionProperty($metadata->name, $property))->getAttributes(ReferencePolicy::class);
            if ($attributes && in_array($attributes[0]->newInstance()->kind, [ReferenceKind::RootEntity, ReferenceKind::GlobalScope], true)) {
                $scope = $this->em->getClassMetadata($association->targetEntity);
                break;
            }
        }
        if ($scope === null) {
            return false;
        }
        $parent = null;
        foreach ($scope->associationMappings as $property => $association) {
            $attributes = (new \ReflectionProperty($scope->name, $property))->getAttributes(ReferencePolicy::class);
            if ($attributes && $attributes[0]->newInstance()->kind === ReferenceKind::RootParent) {
                $parent = $property;
                break;
            }
        }
        if ($parent === null) {
            return false;
        }
        foreach ($sourceEntities as $entity) {
            if ($this->hasAncestor($scope, $parent, (int)$entity, (int)$targetEntity)) {
                return true;
            }
        }
        return false;
    }

    /** Invoke the public model boundary once, then reconcile its authority with the current owner. */
    private function loadCurrent(\CommonDBTM $model, ClassMetadata $metadata, int $id): ?object
    {
        $connection = $this->em->getConnection();
        $writer = $GLOBALS['DB'];
        if ($writer->getDoctrineConnection() !== $connection) {
            throw new \itsmng\Database\TransactionOwnershipMismatch('The deletion model must use its supplied writer.');
        }
        $scope = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        if (!$model->getFromDBForUpdate($id, $connection)) {
            return null;
        }
        // A post-load callback can write through this same transaction. Refresh
        // the owning record before accepting the model's entity/state/relations.
        $record = $this->lock($metadata, $model->getIndexName(), $id);
        $scope->assertActive();
        if ($writer !== ($GLOBALS['DB'] ?? null) || $writer->getDoctrineConnection() !== $connection
            || $connection->getTransactionNestingLevel() !== $level) {
            throw new \itsmng\Database\TransactionOwnershipMismatch('The deletion load replaced its supplied writer or frame.');
        }
        if ($record === null || !$this->matchesAuthority($model, $metadata, $record)) {
            return null;
        }
        return $record;
    }

    private function matchesAuthority(\CommonDBTM $model, ClassMetadata $metadata, object $record): bool
    {
        $row = \itsmng\Database\ReferenceValues::normalizeLegacy(
            $model->getTable(),
            (new RecordRepository($this->em))->toRow($record)
        );
        try {
            // Models may expose inherited/empty selection sentinels and native
            // booleans. Compare their declared semantics without altering hooks'
            // complete loaded fields or collapsing nullable flags into false.
            $values = \itsmng\Database\ReferenceValues::normalizeLegacy($model->getTable(), $model->fields);
            $values = \itsmng\Database\BooleanValue::normalizeLegacyInput($model->getTable(), $values);
        } catch (\InvalidArgumentException | \ValueError | \TypeError) {
            return false;
        }
        $columns = [...$metadata->getIdentifierColumnNames(), $model->getIndexName()];
        // Mapped flags may change lifecycle decisions. Their real declarations
        // remain authoritative while callbacks can retain decorative/derived fields.
        foreach ($metadata->fieldMappings as $mapping) {
            if ($mapping->type === \Doctrine\DBAL\Types\Types::BOOLEAN) {
                $columns[] = $mapping->columnName;
            }
        }
        if ($model instanceof \CommonDBChild || $model instanceof \CommonDBRelation) {
            // This existing model contract also covers unconverted scalar
            // recipients such as Item_Disk, without inventing a column registry.
            array_push($columns, ...\itsmng\Database\ConnexityInput::endpointFields($model));
        }
        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping->isToOneOwningSide()) {
                foreach ($mapping->joinColumns as $join) {
                    $columns[] = $join->name;
                }
            }
        }
        foreach ([...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings)] as $property) {
            $reflection = new \ReflectionProperty($metadata->name, $property);
            foreach ($reflection->getAttributes(\itsmng\Database\Mapping\DiscriminatedBy::class) as $attribute) {
                $policy = $attribute->newInstance();
                $columns[] = $metadata->hasField($policy->discriminator)
                    ? $metadata->getColumnName($policy->discriminator) : $policy->discriminator;
                $columns[] = $policy->legacyColumn;
            }
            foreach ($reflection->getAttributes(\itsmng\Database\Mapping\PolymorphicReference::class) as $attribute) {
                $policy = $attribute->newInstance();
                $columns[] = $metadata->getColumnName($property);
                $columns[] = $metadata->hasField($policy->discriminator)
                    ? $metadata->getColumnName($policy->discriminator) : $policy->discriminator;
            }
            foreach ($reflection->getAttributes(ReferencePolicy::class) as $attribute) {
                $mode = $attribute->newInstance()->modeProperty;
                if ($mode !== null) {
                    $columns[] = $metadata->getColumnName($mode);
                }
            }
        }
        foreach (array_unique($columns) as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            if (!array_key_exists($column, $values)
                || ($row[$column] === null ? $values[$column] !== null
                    : (!is_scalar($values[$column]) || (string)$values[$column] !== (string)$row[$column]))) {
                return false;
            }
        }
        return true;
    }

    private function lock(ClassMetadata $metadata, string $column, int $id): ?object
    {
        $query = $this->em->createQueryBuilder()->select('r')->from($metadata->name, 'r');
        $query->where((new RecordCriteria($query, $metadata, false))->where([$column => $id]));
        return $query->getQuery()->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    private function selfParent(ClassMetadata $metadata, string $column): ?string
    {
        foreach ($metadata->associationMappings as $property => $association) {
            if ($association->isToOneOwningSide() && $association->targetEntity === $metadata->name
                && $association->joinColumns[0]->name === $column) {
                return $property;
            }
        }
        return null;
    }

    /** Do not use possibly stale descendants caches to authorize a tree replacement. */
    private function hasAncestor(ClassMetadata $metadata, string $parent, int $id, int $ancestor): bool
    {
        $seen = [];
        while (!isset($seen[$id])) {
            if ($id === $ancestor) {
                return true;
            }
            $seen[$id] = true;
            $row = $this->lock($metadata, 'id', $id);
            if ($row === null || $row->$parent === null) {
                return false;
            }
            $id = (int)$this->em->getUnitOfWork()->getEntityIdentifier($row->$parent)['id'];
        }
        throw new \UnexpectedValueException('Cyclic mapped parent relationship: ' . $metadata->name);
    }
}
