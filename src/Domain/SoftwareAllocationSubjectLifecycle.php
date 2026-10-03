<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\LifecycleModelJournal;

/** Asset eligibility and its existing completed lifecycle share the allocation writer. */
trait SoftwareAllocationSubjectLifecycle
{
    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        global $DB;

        $allocationContextChanged = false;
        foreach (array_intersect(['entities_id', 'is_recursive', 'is_deleted', 'is_template'], $this->updates) as $column) {
            if (array_key_exists($column, $this->fields) && array_key_exists($column, $storedFields)
                && (($this->fields[$column] === null) !== ($storedFields[$column] === null)
                    || $this->fields[$column] != $storedFields[$column])) {
                $allocationContextChanged = true;
                break;
            }
        }
        if (!$allocationContextChanged) {
            return parent::executePreparedUpdate($operation, $storedFields);
        }
        $storedState = LifecycleModelJournal::state($this);
        $storedState['fields'] = $storedFields;
        return (new SoftwareAssignmentService($DB))->mutateSubject(
            $this,
            $storedState,
            fn () => parent::executePreparedUpdate($operation, $storedFields),
            'update'
        );
    }

    protected function executePreparedRestore(callable $operation, array $storedFields): bool
    {
        global $DB;

        $storedState = LifecycleModelJournal::state($this);
        $storedState['fields'] = $storedFields;
        return (new SoftwareAssignmentService($DB))->mutateSubject(
            $this,
            $storedState,
            fn () => parent::executePreparedRestore($operation, $storedFields),
            'restore'
        );
    }

    public function delete(array $input, $force = 0, $history = 1)
    {
        global $DB;

        if ($DB->isSlave() || !array_key_exists(static::getIndexName(), $input)
            || !$this->getFromDB($input[static::getIndexName()])) {
            return false;
        }
        return (new SoftwareAssignmentService($DB))->mutateSubject(
            $this,
            LifecycleModelJournal::state($this),
            fn () => parent::delete($input, $force, $history),
            'delete'
        );
    }
}
