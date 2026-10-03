<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\Repository\SoftwareAssignmentRepository;
use itsmng\Database\Repository\SoftwareRepository;

/** Installation ownership and allocation eligibility share their aggregate writer. */
final class SoftwareAssignmentService
{
    private SoftwareAssignmentRepository $assignments;
    private SoftwareRepository $software;

    public function __construct(private \DBAdapter $database)
    {
        $manager = Orm::create($database);
        $this->assignments = new SoftwareAssignmentRepository($manager);
        $this->software = new SoftwareRepository($manager);
    }

    public static function forConnection(\Doctrine\DBAL\Connection $connection): self
    {
        $database = $GLOBALS['DB'] ?? null;
        if (!$database instanceof \DBAdapter || $database->isSlave() || $database->getDoctrineConnection() !== $connection) {
            throw new SoftwareAssignmentCancelled('Software mutations require their supplied active writer.');
        }
        SoftwareMutation::assertSupportedIsolation($database);
        return new self($database);
    }

    /** Bulk owning changes acquire the same aggregate/row/subject graph. */
    public function lockSoftwareAssignments(array $software): void
    {
        SoftwareMutation::assertTransactionalStorage($this->database, [\Software::getTable(), \SoftwareLicense::getTable(), \Item_SoftwareLicense::getTable()]);
        $this->assignments->lockSoftware($software);
        $licenses = $this->assignments->licensesForSoftware($software);
        $this->assignments->lockLicenses($licenses);
        $this->lockAllocationSubjects($licenses);
    }

    public function lockTransferSubject(string $kind, int $id): void
    {
        $this->lockSubjectAssignments($kind, $id);
    }

    private function lockSubjectAssignments(string $kind, int $id): array
    {
        SoftwareMutation::assertSupportedIsolation($this->database);
        $subjects = [[$kind, $id]];
        $installations = new \itsmng\Database\Repository\SoftwareInstallationRepository(Orm::create($this->database));
        $versions = $installations->installationsForTransfer($kind, $id, []);
        $software = [];
        foreach ($versions as $version) {
            $software[] = $this->assignments->softwareForVersion((int)$version['softwareversions_id']);
        }
        $licenses = $this->assignments->licensesForSubject($kind, $id, current: false);
        $this->lockAllocations($licenses, [\Item_SoftwareVersion::getTable()], $subjects, $software);
        if ($this->assignments->licensesForSubject($kind, $id) !== $licenses) {
            throw new SoftwareAssignmentCancelled('Transfer allocation membership changed before locking; retry the command.');
        }
        foreach ($installations->installationsForTransfer($kind, $id, [], currentRead: true) as $version) {
            if (!in_array($this->assignments->softwareForVersion((int)$version['softwareversions_id'], current: true), $software, true)) {
                throw new SoftwareAssignmentCancelled('Transfer installation membership changed before locking; retry the command.');
            }
        }
        return $licenses;
    }

    /** Copies remain public lifecycle operations; decisions and locking belong here. */
    public function transferAllocation(int $assignmentId, int $destinationEntity, callable $copySoftware, callable $copyVersion, callable $add, callable $update, callable $delete): void
    {
        SoftwareMutation::assertSupportedIsolation($this->database);
        $assignment = new \Item_SoftwareLicense();
        $source = new \SoftwareLicense();
        if (!$assignment->getFromDB($assignmentId) || !$source->getFromDB($assignment->fields['softwarelicenses_id'])) {
            throw new SoftwareAssignmentCancelled('A selected software allocation or licence is missing.');
        }
        if (!$assignment->getFromDB($assignmentId) || !$source->getFromDB($assignment->fields['softwarelicenses_id'])) {
            throw new SoftwareAssignmentCancelled('The allocation changed before its subject lock.');
        }
        $sourceFields = $source->fields;
        $targetSoftware = SoftwareAssignmentCancelled::requireIdentifier($copySoftware($sourceFields['softwares_id']), 'Software allocation destination');
        $this->assignments->lockSoftware(SoftwareAssignmentRepository::identifiers([(int)$sourceFields['softwares_id'], $targetSoftware]));
        SoftwareMutation::assertTransactionalStorage($this->database, [\Software::getTable(), \SoftwareLicense::getTable(), \Item_SoftwareLicense::getTable()]);
        $currentSource = $this->assignments->licenseRecord((int)$source->getID());
        if ($currentSource === null || (int)$currentSource['softwares_id'] !== (int)$sourceFields['softwares_id']) {
            throw new SoftwareAssignmentCancelled('The selected source licence owner changed; retry the command.');
        }
        $sourceFields = $currentSource;
        $destination = $this->software->licenseForTransfer($targetSoftware, (string)$sourceFields['name'], (string)$sourceFields['serial'], currentRead: true);
        $targetId = $destination['id'] ?? null;
        $this->assignments->lockLicenses(SoftwareAssignmentRepository::identifiers([(int)$source->getID(), $targetId ?? 0]));
        $this->lockAllocationSubjects(SoftwareAssignmentRepository::identifiers([(int)$source->getID(), $targetId ?? 0]), [[$assignment->fields['itemtype'], (int)$assignment->fields['items_id']]]);
        $currentOwner = $this->assignments->allocationOwner($assignmentId);
        if ($currentOwner === null || (int)$currentOwner['license'] !== (int)$source->getID()
            || $currentOwner['kind'] !== $assignment->fields['itemtype'] || (int)$currentOwner['subject'] !== (int)$assignment->fields['items_id']) {
            throw new SoftwareAssignmentCancelled('The selected allocation owner changed before its complete lock set; retry the command.');
        }
        if ($targetId === (int)$source->getID()) {
            return;
        }
        // Re-read quantities under the writer locks rather than trusting a selection cache.
        if (!$source->getFromDB($sourceFields['id'])) {
            throw new SoftwareAssignmentCancelled('The source allocation licence disappeared.');
        }
        $sourceFields = $this->assignments->licenseRecord((int)$source->getID());
        if ($sourceFields === null) {
            throw new SoftwareAssignmentCancelled('The selected source licence disappeared.');
        }
        if ($targetId !== null) {
            $target = new \SoftwareLicense();
            if (!$target->getFromDB($targetId)) {
                throw new SoftwareAssignmentCancelled('The destination allocation licence disappeared.');
            }
            $quantity = $this->assignments->license($targetId)->number;
            if ($quantity >= 0) {
                SoftwareAssignmentCancelled::requireSuccess($update($target, ['id' => $targetId, 'number' => $quantity + 1]), 'Destination allocation quantity');
            }
        } else {
            $input = $sourceFields;
            unset($input['id']);
            foreach (['softwareversions_id_buy', 'softwareversions_id_use'] as $role) {
                if ((int)$input[$role] > 0) {
                    $input[$role] = SoftwareAssignmentCancelled::requireIdentifier($copyVersion($input[$role]), 'Allocation version ' . $role);
                    if ($this->assignments->softwareForVersion($input[$role], current: true) !== $targetSoftware) {
                        throw new SoftwareAssignmentCancelled('Copied licence version belongs to a different Software.');
                    }
                }
            }
            $input['softwares_id'] = $targetSoftware;
            $input['entities_id'] = $destinationEntity;
            $input['number'] = 1;
            $target = new \SoftwareLicense();
            $targetId = SoftwareAssignmentCancelled::requireIdentifier($add($target, \Toolbox::addslashes_deep($input)), 'Destination allocation licence');
        }
        SoftwareAssignmentCancelled::requireSuccess($update($assignment, ['id' => $assignmentId, 'softwarelicenses_id' => $targetId]), 'Allocation reassignment');
        if ((int)$sourceFields['number'] > 1) {
            SoftwareAssignmentCancelled::requireSuccess($update($source, ['id' => $sourceFields['id'], 'number' => (int)$sourceFields['number'] - 1]), 'Source allocation quantity');
        } elseif ((int)$sourceFields['number'] === 1) {
            SoftwareAssignmentCancelled::requireSuccess($delete($source, ['id' => $sourceFields['id']], false), 'Last source allocation quantity');
        }
    }

    public function mutateAllocation(\Item_SoftwareLicense $model, array $checkpoint, callable $operation, string $mode, ?callable $guard = null): mixed
    {
        return $this->mutate($model, $checkpoint, $mode, function () use ($model, $checkpoint, $operation, $mode, $guard) {
            $licenses = SoftwareAssignmentRepository::identifiers([
                ($mode === 'add' ? 0 : ($checkpoint['fields']['softwarelicenses_id'] ?? 0)), $model->fields['softwarelicenses_id'] ?? 0,
            ]);
            $subjects = [[$model->fields['itemtype'], (int)$model->fields['items_id']]];
            if ($mode !== 'add') {
                $subjects[] = [$checkpoint['fields']['itemtype'], (int)$checkpoint['fields']['items_id']];
            }
            $context = $this->assignments->subjectContexts($subjects);
            SoftwareMutation::assertTransactionalStorage($this->database, $this->assignments->subjectTables($subjects));
            $this->lockAllocations($licenses, [$model->getTable()], $subjects);
            if ($mode !== 'add') {
                $owner = $this->assignments->allocationOwner((int)$model->getID());
                $stored = $checkpoint['fields'];
                if ($owner === null || $owner['kind'] !== $stored['itemtype']
                    || (int)$owner['subject'] !== (int)$stored['items_id']
                    || (int)$owner['license'] !== (int)$stored['softwarelicenses_id']) {
                    throw new SoftwareAssignmentCancelled('The allocation owner changed before its subject lock; retry the command.');
                }
            }
            if ($this->assignments->subjectContexts($subjects, current: true) != $context || ($guard !== null && !$guard($this->assignments->subjectContexts($subjects, current: true)))) {
                throw new SoftwareAssignmentCancelled('Allocation scope changed before persistence; retry the command.');
            }
            $changed = $mode !== 'update' || (bool)array_intersect($model->updates, [
                'softwarelicenses_id', 'itemtype', 'items_id', 'is_deleted',
                ...\itsmng\Database\ConnexityInput::endpointFields($model),
            ]);
            $result = $this->complete($operation, $mode);
            if ($mode === 'restore') {
                $model->fields['is_deleted'] = 0;
            }
            if ($changed) {
                foreach ($licenses as $license) {
                    SoftwareAssignmentCancelled::requireSuccess($this->refreshLicenseValidity($license), 'Allocation licence validity');
                }
            }
            return $result;
        });
    }

    public function mutateInstallation(\Item_SoftwareVersion $model, array $checkpoint, callable $operation, string $mode, ?callable $guard = null): mixed
    {
        return $this->mutate($model, $checkpoint, $mode, function () use ($model, $checkpoint, $operation, $mode, $guard) {
            $subjects = [[$model->fields['itemtype'], (int)$model->fields['items_id']]];
            if ($mode !== 'add') {
                $subjects[] = [$checkpoint['fields']['itemtype'], (int)$checkpoint['fields']['items_id']];
            }
            $context = $this->assignments->subjectContexts($subjects);
            SoftwareMutation::assertTransactionalStorage($this->database, $this->assignments->subjectTables($subjects));
            $versions = SoftwareAssignmentRepository::identifiers([
                ($mode === 'add' ? 0 : ($checkpoint['fields']['softwareversions_id'] ?? 0)), $model->fields['softwareversions_id'] ?? 0,
            ]);
            $software = [];
            foreach ($versions as $version) {
                $software[] = $this->assignments->softwareForVersion($version);
            }
            $software = SoftwareAssignmentRepository::identifiers($software);
            $softwareScopes = [];
            foreach ($software as $id) {
                $softwareScopes[$id] = $this->assignments->software($id, current: false)?->allocationScope();
            }
            SoftwareMutation::assertTransactionalStorage($this->database, [$model->getTable(), \Software::getTable()]);
            $this->assignments->lockSoftware($software);
            foreach ($softwareScopes as $id => $scope) {
                if ($this->assignments->software($id)->allocationScope() !== $scope) {
                    throw new SoftwareAssignmentCancelled('Installation Software scope changed before locking; retry the command.');
                }
            }
            $this->assignments->lockSubjects($subjects);
            foreach ($versions as $version) {
                if (!in_array($this->assignments->softwareForVersion($version, current: true), $software, true)) {
                    throw new SoftwareAssignmentCancelled('The installation version owner changed before locking; retry the command.');
                }
            }
            if ($mode !== 'add') {
                $owner = $this->assignments->installationOwner((int)$model->getID());
                $stored = $checkpoint['fields'];
                if ($owner === null || $owner['kind'] !== $stored['itemtype']
                    || (int)$owner['subject'] !== (int)$stored['items_id']
                    || (int)$owner['version'] !== (int)$stored['softwareversions_id']) {
                    throw new SoftwareAssignmentCancelled('The installation owner changed before its complete lock set; retry the command.');
                }
            }
            $currentContext = $this->assignments->subjectContexts($subjects, current: true);
            $targetContext = $currentContext[$model->fields['itemtype'] . ':' . $model->fields['items_id']];
            if ((int)$model->fields['entities_id'] !== (int)$targetContext['entity']
                || $currentContext != $context || ($guard !== null && !$guard($currentContext))) {
                throw new SoftwareAssignmentCancelled('Installation context changed before persistence; retry the command.');
            }
            $result = $this->complete($operation, $mode);
            if ($mode === 'restore') {
                $model->fields['is_deleted'] = 0;
            }
            return $result;
        });
    }

    public function mutateSubject(\CommonDBTM $model, array $checkpoint, callable $operation, string $mode): mixed
    {
        return $this->mutate($model, $checkpoint, $mode, function () use ($model, $operation, $mode) {
            SoftwareMutation::assertTransactionalStorage($this->database, [$model->getTable()]);
            $licenses = $this->lockSubjectAssignments($model->getType(), (int)$model->getID());
            $result = $this->complete($operation, $mode);
            if ($mode === 'restore') {
                $model->fields['is_deleted'] = 0;
            }
            foreach ($licenses as $license) {
                SoftwareAssignmentCancelled::requireSuccess($this->refreshLicenseValidity($license), 'Asset allocation validity');
            }
            return $result;
        });
    }

    public function mutateLicense(\SoftwareLicense $model, array $checkpoint, callable $operation, string $mode): mixed
    {
        return $this->mutate($model, $checkpoint, $mode, function () use ($model, $checkpoint, $operation, $mode) {
            $software = SoftwareAssignmentRepository::identifiers([
                ($mode === 'add' ? 0 : ($checkpoint['fields']['softwares_id'] ?? 0)), $model->fields['softwares_id'] ?? 0,
            ]);
            SoftwareMutation::assertTransactionalStorage($this->database, [$model->getTable(), \Software::getTable(), \Item_SoftwareLicense::getTable()]);
            $this->assignments->lockSoftware($software);
            if ($mode !== 'add') {
                $this->assignments->lockLicenses([(int)$model->getID()]);
                // The public lifecycle authorized and prepared this loaded row. Do
                // not silently replace that decision with a newer owner or scope.
                // Metadata supplies the canonical row, including nullable fields;
                // locking reads also bypass an older MariaDB caller snapshot.
                $persisted = $this->assignments->licenseRecord((int)$model->getID());
                if ($persisted === null) {
                    throw new SoftwareAssignmentCancelled('The licence changed before its aggregate lock; retry the command.');
                }
                foreach ($persisted as $column => $value) {
                    if (!array_key_exists($column, $checkpoint['fields'])
                        || $value !== $checkpoint['fields'][$column]) {
                        throw new SoftwareAssignmentCancelled('The licence changed before its aggregate lock; retry the command.');
                    }
                }
            }
            if ($mode !== 'add') {
                $this->lockAllocationSubjects([(int)$model->getID()]);
            }
            $quantityChanged = $mode === 'update' && in_array('number', $model->updates, true)
                && ($checkpoint['fields']['number'] ?? null) != $model->fields['number'];
            if ($quantityChanged) {
                $license = $this->assignments->license((int)$model->getID());
                if ($license->number !== (int)$checkpoint['fields']['number']) {
                    throw new SoftwareAssignmentCancelled('Licence quantity changed before its writer lock; retry the command.');
                }
                $candidate = clone $license;
                $candidate->number = (int)$model->fields['number'];
                $expected = $candidate->isValidForAllocationCount($this->assignments->eligibleAllocationCount((int)$model->getID()));
                $model->fields['is_valid'] = (int)$expected;
                if ((bool)$checkpoint['fields']['is_valid'] === $expected) {
                    $model->updates = array_values(array_diff($model->updates, ['is_valid']));
                    unset($model->oldvalues['is_valid']);
                }
                if ((bool)$checkpoint['fields']['is_valid'] !== $expected && !in_array('is_valid', $model->updates, true)) {
                    $model->updates[] = 'is_valid';
                    $model->oldvalues['is_valid'] = $checkpoint['fields']['is_valid'];
                }
            }
            $changed = $mode !== 'update' || (bool)array_intersect($model->updates, ['number', 'is_valid', 'softwares_id']);
            $result = $this->complete($operation, $mode);
            if ($quantityChanged) {
                $persisted = $this->assignments->license((int)$model->getID());
                if ($persisted === null || $persisted->is_valid !== $persisted->isValidForAllocationCount($this->assignments->eligibleAllocationCount((int)$model->getID()))) {
                    throw new SoftwareAssignmentCancelled('Licence quantity validity was refused.');
                }
            }
            if ($changed) {
                foreach ($software as $id) {
                    SoftwareAssignmentCancelled::requireSuccess($this->refreshSoftwareValidity($id), 'Owning software validity');
                }
            }
            return $result;
        });
    }

    public function mutateSoftware(\Software $model, array $checkpoint, callable $operation, string $mode): mixed
    {
        return $this->mutate($model, $checkpoint, $mode, function () use ($model, $operation, $mode) {
            SoftwareMutation::assertTransactionalStorage($this->database, [$model->getTable()]);
            if ($mode !== 'add') {
                $this->assignments->lockSoftware([(int)$model->getID()]);
                if ($mode === 'delete') {
                    $this->lockSoftwareAssignments([(int)$model->getID()]);
                }
            }
            return $this->complete($operation, $mode);
        });
    }

    public function refreshLicenseValidity(int $id): bool
    {
        $model = new \SoftwareLicense();
        if (!$model->getFromDB($id)) {
            return false;
        }
        return SoftwareMutation::run($this->database, $model, LifecycleModelJournal::state($model), function () use ($id, $model) {
            $this->lockAllocations([$id], []);
            $license = $this->assignments->license($id);
            $expected = $license->isValidForAllocationCount($this->assignments->eligibleAllocationCount($id));
            if ($license->is_valid !== $expected) {
                SoftwareAssignmentCancelled::requireSuccess($model->update(['id' => $id, 'is_valid' => (int)$expected]), 'Licence validity write');
            }
            $persisted = $this->assignments->license($id);
            if ($persisted === null || $persisted->is_valid !== $expected) {
                throw new SoftwareAssignmentCancelled('Required licence validity write was canceled.');
            }
            SoftwareAssignmentCancelled::requireSuccess($this->refreshSoftwareValidity((int)$persisted->softwares->id), 'Licence owning software validity');
            return true;
        });
    }

    public function refreshSoftwareValidity(int $id): bool
    {
        $model = new \Software();
        if (!$model->getFromDB($id)) {
            return false;
        }
        return SoftwareMutation::run($this->database, $model, LifecycleModelJournal::state($model), function () use ($id, $model) {
            SoftwareMutation::assertTransactionalStorage($this->database, [\Software::getTable(), \SoftwareLicense::getTable()]);
            $this->assignments->lockSoftware([$id]);
            $expected = !$this->software->hasInvalidLicense($id, currentRead: true);
            $software = $this->assignments->software($id);
            if ($software->is_valid !== $expected) {
                SoftwareAssignmentCancelled::requireSuccess($model->update(['id' => $id, 'is_valid' => (int)$expected]), 'Software validity write');
            }
            $persisted = $this->assignments->software($id);
            if ($persisted === null || $persisted->is_valid !== $expected) {
                throw new SoftwareAssignmentCancelled('Required software validity write was canceled.');
            }
            return true;
        });
    }

    private function lockAllocations(array $licenses, array $tables, array $subjects = [], array $extraSoftware = []): void
    {
        SoftwareMutation::assertTransactionalStorage($this->database, [...$tables, \SoftwareLicense::getTable(), \Software::getTable(), \Item_SoftwareLicense::getTable()]);
        $licenseOwners = $this->assignments->softwareIdsForLicenses($licenses);
        $owners = SoftwareAssignmentRepository::identifiers([...$licenseOwners, ...$extraSoftware]);
        $licenseScopes = [];
        foreach ($licenses as $id) {
            $licenseScopes[$id] = $this->assignments->license($id, current: false)?->allocationScope();
        }
        $softwareScopes = [];
        foreach ($owners as $id) {
            $softwareScopes[$id] = $this->assignments->software($id, current: false)?->allocationScope();
        }
        $this->assignments->lockSoftware($owners);
        $this->assignments->lockLicenses($licenses);
        if ($this->assignments->softwareIdsForLicenses($licenses, current: true) !== $licenseOwners) {
            throw new SoftwareAssignmentCancelled('A licence owner changed while acquiring its aggregate locks; retry the command.');
        }
        foreach ($licenseScopes as $id => $scope) {
            if ($this->assignments->license($id)->allocationScope() !== $scope) {
                throw new SoftwareAssignmentCancelled('Licence scope changed before its aggregate lock; retry the command.');
            }
        }
        foreach ($softwareScopes as $id => $scope) {
            if ($this->assignments->software($id)->allocationScope() !== $scope) {
                throw new SoftwareAssignmentCancelled('Software scope changed before its aggregate lock; retry the command.');
            }
        }
        $this->lockAllocationSubjects($licenses, $subjects);
    }

    private function lockAllocationSubjects(array $licenses, array $additional = []): void
    {
        $subjects = [...$this->assignments->subjectsForLicenses($licenses), ...$additional];
        SoftwareMutation::assertTransactionalStorage($this->database, $this->assignments->subjectTables($subjects));
        $this->assignments->lockSubjects($subjects);
    }

    private function mutate(\CommonDBTM $model, array $checkpoint, string $mode, callable $operation): mixed
    {
        if ($mode !== 'add' && (int)($checkpoint['fields']['id'] ?? 0) !== (int)$model->getID()) {
            return SoftwareMutation::run($this->database, $model, $checkpoint, static function () {
                throw new SoftwareAssignmentCancelled('A software lifecycle hook changed the operation identity.');
            });
        }
        if ($mode === 'add') {
            $checkpoint['input'] = $model->input;
        } elseif ($mode === 'update' || $mode === 'restore') {
            $checkpoint['updates'] = $checkpoint['oldvalues'] = [];
        }
        return SoftwareMutation::run($this->database, $model, $checkpoint, $operation);
    }

    private function complete(callable $operation, string $mode): mixed
    {
        $result = $operation();
        if ($mode === 'add') {
            SoftwareAssignmentCancelled::requireIdentifier($result, 'Software lifecycle creation');
        } else {
            SoftwareAssignmentCancelled::requireSuccess($result, 'Software lifecycle ' . $mode);
        }
        return $result;
    }
}
