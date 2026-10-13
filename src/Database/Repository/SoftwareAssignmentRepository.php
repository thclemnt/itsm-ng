<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use itsmng\Database\Entity\ItemSoftwareLicense;
use itsmng\Database\Entity\ItemSoftwareVersion;
use itsmng\Database\Entity\Software;
use itsmng\Database\Entity\SoftwareLicense;
use itsmng\Database\Entity\SoftwareVersion;
use itsmng\Database\MySQLConnection;
use itsmng\Domain\AllocationSubject;
use itsmng\Domain\SoftwareAssignmentCancelled;

/** Owning software aggregates, allocation eligibility and ordered writer locks. */
final class SoftwareAssignmentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function installationOwner(int $id): ?array
    {
        MySQLConnection::assertCurrentReads($this->em->getConnection());
        $rows = $this->em->createQueryBuilder()
            ->select('i.itemtype AS kind, i.items_id AS subject, IDENTITY(i.softwareversions) AS version')
            ->from(ItemSoftwareVersion::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->getScalarResult();
        return $rows[0] ?? null;
    }

    public function allocationOwner(int $id, bool $current = true): ?array
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('a.itemtype AS kind, a.items_id AS subject, IDENTITY(a.softwarelicenses) AS license')
            ->from(ItemSoftwareLicense::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        return $rows[0] ?? null;
    }

    public function license(int $id, bool $current = true): ?SoftwareLicense
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        return $this->em->createQueryBuilder()
            ->select('l')
            ->from(SoftwareLicense::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getOneOrNullResult();
    }

    public function licenseRecord(int $id): ?array
    {
        $license = $this->license($id);
        return $license === null ? null : (new RecordRepository($this->em))->toRow($license);
    }

    public function software(int $id, bool $current = true): ?Software
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(Software::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getOneOrNullResult();
    }

    public function softwareIdsForLicenses(array $licenses, bool $current = false): array
    {
        $licenses = self::identifiers($licenses);
        if (!$licenses) {
            return [];
        }
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('l.id AS id, IDENTITY(l.softwares) AS software')
            ->from(SoftwareLicense::class, 'l')
            ->where('l.id IN (:ids)')
            ->setParameter('ids', $licenses)
            ->orderBy('l.id')
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        if (count($rows) !== count($licenses)) {
            throw new SoftwareAssignmentCancelled('A required owning software licence is missing.');
        }
        return self::identifiers(array_column($rows, 'software'));
    }

    public function subjectContexts(array $subjects, bool $current = false): array
    {
        if ($current && $subjects !== []) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $contexts = [];
        $metadata = $this->em->getClassMetadata(ItemSoftwareLicense::class);
        foreach ($subjects as [$kind, $id]) {
            $property = ItemSoftwareLicense::referenceAssociation($kind);
            $target = $metadata->getAssociationMapping($property)->targetEntity;
            $subject = $this->em->getClassMetadata($target);
            $select = ['r.id AS id', 'IDENTITY(r.entities) AS entity'];
            foreach (['is_recursive', 'is_deleted', 'is_template'] as $flag) {
                if ($subject->hasField($flag)) {
                    $select[] = 'r.' . $flag . ' AS ' . $flag;
                }
            }
            $rows = $this->em->createQueryBuilder()
                ->select(...$select)
                ->from($target, 'r')
                ->where('r.id = :id')
                ->setParameter('id', (int)$id, Types::BIGINT)
                ->getQuery()
                ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
                ->getScalarResult();
            if (!$rows) {
                throw new SoftwareAssignmentCancelled('A required allocation subject disappeared.');
            }
            $contexts[$kind . ':' . $id] = $rows[0];
        }
        ksort($contexts);
        return $contexts;
    }

    /** Current actual subject objects implement their declared allocation scope. */
    public function allocationSubject(string $kind, int $id): AllocationSubject
    {
        MySQLConnection::assertCurrentReads($this->em->getConnection());
        $metadata = $this->em->getClassMetadata(ItemSoftwareLicense::class);
        $property = ItemSoftwareLicense::referenceAssociation($kind);
        $target = $metadata->getAssociationMapping($property)->targetEntity;
        $subject = $this->em->createQueryBuilder()
            ->select('s')
            ->from($target, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->getOneOrNullResult();
        if (!$subject instanceof AllocationSubject) {
            throw new SoftwareAssignmentCancelled('The selected allocation subject has no declared domain scope.');
        }
        return $subject;
    }

    /** Discover selected ancestry without taking any aggregate or subject locks. */
    public function hierarchyRoots(
        array $subjects = [],
        array $licenses = [],
        array $software = [],
        array $extra = [],
        bool $includeInstallations = false
    ): array {
        if ($includeInstallations) {
            // Bulk version/Software removal and transfer invoke installation
            // lifecycles. Scalar aggregate validity updates do not.
            $software = [...$software, ...$this->softwareForSubjectInstallations($subjects)];
            $subjects = [...$subjects, ...$this->subjectsForSoftwareInstallations($software)];
        }
        foreach ($subjects as [$kind, $id]) {
            $licenses = [...$licenses, ...$this->licensesForSubject($kind, $id, current: false)];
        }
        $licenses = self::identifiers($licenses);
        // Licence validity invokes its owning Software's public lifecycle. That
        // lifecycle requires its other licence subjects before any graph lock.
        $software = self::identifiers([...$software, ...$this->softwareIdsForLicenses($licenses)]);
        $licenses = self::identifiers([...$licenses, ...$this->licensesForSoftware($software, current: false)]);
        $subjects = [...$subjects, ...$this->subjectsForLicenses($licenses, current: false)];
        $roots = $extra;
        foreach ($this->subjectContexts($subjects) as $context) {
            $roots[] = (int)$context['entity'];
        }
        foreach ($licenses as $id) {
            $license = $this->license($id, current: false);
            if ($license === null || $license->entities === null || $license->softwares === null) {
                throw new SoftwareAssignmentCancelled('The selected allocation licence has no owning scope.');
            }
            $roots[] = $license->entities->id;
            $software[] = $license->softwares->id;
        }
        foreach (self::identifiers($software) as $id) {
            $owner = $this->software($id, current: false);
            if ($owner === null || $owner->entities === null) {
                throw new SoftwareAssignmentCancelled('The selected Software has no owning entity.');
            }
            $roots[] = $owner->entities->id;
        }
        $roots = array_values(array_unique(array_map('intval', $roots)));
        sort($roots);
        return $roots;
    }

    public function subjectTables(array $subjects): array
    {
        $metadata = $this->em->getClassMetadata(ItemSoftwareLicense::class);
        $tables = [];
        foreach ($subjects as [$kind, $id]) {
            $property = ItemSoftwareLicense::referenceAssociation($kind);
            $target = $metadata->getAssociationMapping($property)->targetEntity;
            $tables[] = $this->em->getClassMetadata($target)->getTableName();
        }
        return array_values(array_unique($tables));
    }

    /** Lock declared subjects in stable order after their owning aggregates. */
    public function lockSubjects(array $subjects): void
    {
        $owners = [];
        $metadata = $this->em->getClassMetadata(ItemSoftwareLicense::class);
        foreach ($subjects as [$kind, $id]) {
            if ($id <= 0) {
                continue;
            }
            $property = ItemSoftwareLicense::referenceAssociation($kind);
            $entity = $metadata->getAssociationMapping($property)->targetEntity;
            $owners[$entity][] = (int)$id;
        }
        ksort($owners);
        foreach ($owners as $entity => $ids) {
            $this->lock($entity, self::identifiers($ids));
        }
    }

    public function lockSoftware(array $ids): void
    {
        $this->lock(Software::class, self::identifiers($ids));
    }

    public function lockLicenses(array $ids): void
    {
        $this->lock(SoftwareLicense::class, self::identifiers($ids));
    }

    private function lock(string $entity, array $ids): void
    {
        if (!$ids) {
            return;
        }
        MySQLConnection::assertCurrentReads($this->em->getConnection());
        $query = $this->em->createQueryBuilder()
            ->select('r.id AS id')
            ->from($entity, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('r.id')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        if (count($query->getScalarResult()) !== count($ids)) {
            throw new SoftwareAssignmentCancelled('A required software aggregate disappeared before mutation.');
        }
    }

    public function eligibleAllocationCount(int $license): int
    {
        $installations = new SoftwareInstallationRepository($this->em);
        $count = 0;
        foreach ($installations->itemTypes(true, $license, currentRead: true) as $kind) {
            $property = ItemSoftwareLicense::referenceAssociation($kind);
            $mapping = $this->em->getClassMetadata(ItemSoftwareLicense::class)->getAssociationMapping($property);
            $table = $this->em->getClassMetadata($mapping->targetEntity)->getTableName();
            // Preserve active allocation count semantics, including duplicate rows,
            // excluding deleted assignments and deleted/template subjects globally.
            $count += $installations->count(true, $license, false, $kind, $table, [], currentRead: true);
        }
        return $count;
    }

    public function licensesForSubject(string $kind, int $id, bool $current = true): array
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $property = ItemSoftwareLicense::referenceAssociation($kind);
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.softwarelicenses) AS id')
            ->from(ItemSoftwareLicense::class, 'a')
            ->where('IDENTITY(a.' . $property . ') = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->orderBy('a.id')
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        return self::identifiers(array_column($rows, 'id'));
    }

    public function licensesForSoftware(array $software, bool $current = true): array
    {
        $software = self::identifiers($software);
        if (!$software) {
            return [];
        }
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('l.id AS id')
            ->from(SoftwareLicense::class, 'l')
            ->where('l.softwares IN (:software)')
            ->setParameter('software', $software)
            ->orderBy('l.id')
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        return self::identifiers(array_column($rows, 'id'));
    }

    /** Current allocation identities in one stable row-lock order. */
    public function subjectsForLicenses(array $licenses, bool $current = true): array
    {
        $licenses = self::identifiers($licenses);
        if (!$licenses) {
            return [];
        }
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('a.id AS id, a.itemtype AS kind, a.items_id AS subject')
            ->from(ItemSoftwareLicense::class, 'a')
            ->where('a.softwarelicenses IN (:ids)')
            ->setParameter('ids', $licenses)
            ->orderBy('a.id')
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        return array_map(static fn (array $row): array => [$row['kind'], (int)$row['subject']], $rows);
    }

    /** Selected asset purge/transfer invokes public lifecycles for its installed owners. */
    private function softwareForSubjectInstallations(array $subjects): array
    {
        $software = [];
        foreach ($subjects as [$kind, $id]) {
            $property = ItemSoftwareVersion::referenceAssociation($kind);
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(v.softwares) AS id')
                ->from(ItemSoftwareVersion::class, 'i')
                ->innerJoin('i.softwareversions', 'v')
                ->where('IDENTITY(i.' . $property . ') = :subject')
                ->setParameter('subject', $id, Types::BIGINT)
                ->orderBy('i.id')
                ->getQuery()
                ->getScalarResult();
            $software = [...$software, ...array_column($rows, 'id')];
        }
        return self::identifiers($software);
    }

    /** Installation purge invokes public allocation validity work for its actual subjects. */
    private function subjectsForSoftwareInstallations(array $software): array
    {
        $software = self::identifiers($software);
        if (!$software) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('i.itemtype AS kind, i.items_id AS subject')
            ->from(ItemSoftwareVersion::class, 'i')
            ->innerJoin('i.softwareversions', 'v')
            ->where('v.softwares IN (:software)')
            ->setParameter('software', $software)
            ->orderBy('i.id')
            ->getQuery()
            ->getScalarResult();
        return array_map(static fn (array $row): array => [$row['kind'], (int)$row['subject']], $rows);
    }

    public function softwareForVersion(int $version, bool $current = false): int
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(v.softwares) AS id')
            ->from(SoftwareVersion::class, 'v')
            ->where('v.id = :id')
            ->setParameter('id', $version, Types::BIGINT)
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        if (!$rows) {
            throw new SoftwareAssignmentCancelled('A required owning software version is missing.');
        }
        return SoftwareAssignmentCancelled::requireIdentifier($rows[0]['id'], 'Owning software version');
    }

    /** Preserve separate version-row locks while resolving the installation owner graph in bulk. */
    public function softwareIdsForVersions(array $versions, bool $current = false): array
    {
        $versions = self::identifiers(array_map(static fn (mixed $id): int => SoftwareAssignmentCancelled::requireIdentifier($id, 'Owning software version'), $versions));
        if ($versions === []) {
            return [];
        }
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('v.id AS id, IDENTITY(v.softwares) AS software')
            ->from(SoftwareVersion::class, 'v')
            ->where('v.id IN (:ids)')
            ->setParameter('ids', $versions)
            ->orderBy('v.id')
            ->getQuery()
            ->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        if (count($rows) !== count($versions)) {
            throw new SoftwareAssignmentCancelled('A required owning software version is missing.');
        }
        return self::identifiers(array_map(static fn (array $row): int => SoftwareAssignmentCancelled::requireIdentifier($row['software'], 'Owning software version'), $rows));
    }

    public static function identifiers(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        sort($ids);
        return $ids;
    }
}
