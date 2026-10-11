<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Refuse unsupported software sessions before loading or preparing a public command. */
trait SoftwareLifecycleAdmission
{
    public function add(array $input, $options = [], $history = true)
    {
        return $this->admitSoftwareLifecycle() ? parent::add($input, $options, $history) : false;
    }

    public function update(array $input, $history = 1, $options = [])
    {
        return $this->admitSoftwareLifecycle() ? parent::update($input, $history, $options) : false;
    }

    public function restore(array $input, $history = 1)
    {
        return $this->admitSoftwareLifecycle() ? parent::restore($input, $history) : false;
    }

    private function admitSoftwareLifecycle(): bool
    {
        global $DB;

        if ($DB->isSlave()) {
            return false;
        }
        try {
            // The writer belongs to this invocation. Prepared domain frames and
            // individual current reads still recheck after model/plugin callbacks.
            SoftwareMutation::assertSupportedIsolation($DB);
            return true;
        } catch (SoftwareAssignmentCancelled) {
            return false;
        }
    }
}
