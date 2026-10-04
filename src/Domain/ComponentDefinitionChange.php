<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** One child write delegated by its actual definition owner, never an asset edit. */
final class ComponentDefinitionChange
{
    private bool $updating = false;
    private bool $persisted = false;
    private readonly array $expected;
    private readonly mixed $time;

    public function __construct(
        private readonly ComponentDefinitionReplacement $command,
        private readonly \Item_Devices $model,
        private readonly string $column,
        public readonly array $stored,
        private readonly array $scope,
        private readonly ?string $subjectTable,
        private readonly ?array $subjectRecord
    ) {
        $this->expected = array_replace($stored, [$column => $command->replacement], $scope);
        $this->time = $_SESSION['glpi_currenttime'] ?? null;
    }

    public function assertActive(): void
    {
        $this->command->assertActive();
    }

    public function didPersist(\Item_Devices $model): void
    {
        $this->persisted = true;
        if (!$this->verify($model)) {
            throw new \itsmng\Database\DeletionCancelled('The component producer changed its delegated write.');
        }
    }

    public function input(array $input): array
    {
        return array_replace($input, $this->scope);
    }

    public function enter(\Item_Devices $model): bool
    {
        $this->command->assertActive();
        if ($model !== $this->model || $this->updating) {
            return false;
        }
        $this->updating = true;
        return true;
    }

    public function leave(): void
    {
        $this->updating = false;
    }

    public function load(\Item_Devices $model, int $id): ?array
    {
        if ($model !== $this->model || $id !== (int)$this->stored['id']) {
            return null;
        }
        $row = $this->command->current($model->getTable(), $id);
        if (!$this->persisted) {
            return $row === $this->stored ? $row : null;
        }
        $this->command->assertDefinitions();
        if ($this->subjectTable !== null && !$this->command->subjectUnchanged($this->subjectTable, $this->subjectRecord)) {
            return null;
        }
        return $row !== null && $this->matches($row, prepared: false) ? $row : null;
    }

    /** The final canonical normalizer also calls this on its pure cloned probe. */
    public function authorize(\Item_Devices $model, array $input): bool
    {
        $this->command->assertActive();
        if ($model::class !== $this->model::class || (int)$model->getID() !== (int)$this->stored['id']) {
            return false;
        }
        $values = array_replace($model->fields, $input);
        return $this->matches($values, prepared: true);
    }

    public function verify(\Item_Devices $model): bool
    {
        $this->command->assertActive();
        if ($model !== $this->model || !is_array($model->input)
            || !$this->authorize($model, $model->input) || !$this->matches($model->fields, prepared: true)) {
            return false;
        }
        $this->command->assertDefinitions();
        if ($this->subjectTable !== null
            && !$this->command->subjectUnchanged($this->subjectTable, $this->subjectRecord)) {
            return false;
        }
        $row = $this->command->current($model->getTable(), (int)$this->stored['id']);
        return $row !== null && $this->matches($row, prepared: false);
    }

    public function ready(\Item_Devices $model): bool
    {
        if ($model !== $this->model || !$this->authorize($model, $model->fields)) {
            return false;
        }
        $this->command->assertDefinitions();
        if ($this->subjectTable !== null
            && !$this->command->subjectUnchanged($this->subjectTable, $this->subjectRecord)) {
            return false;
        }
        return $this->command->current($model->getTable(), (int)$this->stored['id']) === $this->stored;
    }

    private function matches(array $values, bool $prepared): bool
    {
        foreach ($this->expected as $field => $expected) {
            if (!array_key_exists($field, $values)) {
                return false;
            }
            if ($field === 'date_mod') {
                if ($values[$field] !== $this->stored[$field] && $values[$field] !== $this->time) {
                    return false;
                }
            } elseif ($prepared && $field === $this->column) {
                if (filter_var($values[$field], FILTER_VALIDATE_INT) === false || (int)$values[$field] !== (int)$expected) {
                    return false;
                }
            } elseif ($values[$field] !== $expected) {
                return false;
            }
        }
        return true;
    }
}
