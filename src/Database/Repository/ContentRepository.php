<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

final class ContentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function notes(string $type, int $id): array
    {
        $query = $this->em->createQueryBuilder()->select('n', 'u.picture AS picture', 'CASE WHEN n.date_mod IS NULL THEN 0 ELSE 1 END AS HIDDEN dated')
            ->from(Entity\Notepad::class, 'n')->leftJoin('n.lastupdater', 'u')
            ->where('n.itemtype = :type AND n.items_id = :id')
            ->setParameter('type', $type)->setParameter('id', $id, Types::INTEGER)
            ->orderBy('dated', 'DESC')->addOrderBy('n.date_mod', 'DESC')->addOrderBy('n.id', 'DESC');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $row) {
            $rows[] = $records->toRow($row[0]) + ['picture' => $row['picture']];
        }
        return $rows;
    }

    public function documentIds(string $type, int $id): array
    {
        try {
            $association = Entity\DocumentItem::referenceAssociation($type);
        } catch (InvalidArgumentException) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(a.documents) AS id')->from(Entity\DocumentItem::class, 'a')
            ->where('IDENTITY(a.' . $association . ') = :id')->setParameter('id', $id, Types::BIGINT)
            ->orderBy('a.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function documentCount(array $scope): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_documents', ['is_deleted' => false, $scope]);
    }

    public function documentHeadings(): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT c.id', 'c.name')
            ->from(Entity\Document::class, 'd')->join('d.documentcategories', 'c')
            ->orderBy('c.name')->addOrderBy('c.id')->getQuery()->getScalarResult();
    }

    /** Each association stays a separate row, including multiple timeline positions. */
    public function documents(string $type, int $id, array $scope, string $sort, string $order): array
    {
        $columns = ['name' => 'r.name', 'entity' => 'e.completename', 'filename' => 'r.filename', 'link' => 'r.link',
            'headings' => 'c.completename', 'mime' => 'r.mime', 'tag' => 'r.tag', 'assocdate' => 'a.date_creation'];
        if (!isset($columns[$sort]) || !in_array($order, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Unsupported document ordering');
        }
        try {
            $association = Entity\DocumentItem::referenceAssociation($type);
        } catch (InvalidArgumentException) {
            return [];
        }
        $subject = 'IDENTITY(a.' . $association . ')';
        $link = 'IDENTITY(a.documents) = r.id AND ' . $subject . ' = :id';
        if ($type === 'Document') {
            $link = '(' . $link . ') OR (IDENTITY(a.documents) = :id AND ' . $subject . ' = r.id)';
        }
        $query = $this->em->createQueryBuilder()->select(
            'r.id',
            'r.name',
            'r.filename',
            'r.link',
            'r.mime',
            'r.tag',
            'a.id AS assocID',
            'a.date_creation AS assocdate',
            'e.id AS entityID',
            'e.completename AS entity',
            'c.completename AS headings'
        )
            ->from(Entity\Document::class, 'r')
            ->join(Entity\DocumentItem::class, 'a', 'WITH', 'a.itemtype = :type AND (' . $link . ')')
            ->join('r.entities', 'e')->leftJoin('r.documentcategories', 'c');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Document::class)))->where($scope))
            ->setParameter('type', $type)->setParameter('id', $id, Types::BIGINT)
            ->addSelect('CASE WHEN ' . $columns[$sort] . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN populated')
            ->orderBy('populated', $order)->addOrderBy($columns[$sort], $order)->addOrderBy('a.id');
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            $row['assocdate'] = $row['assocdate']?->format('Y-m-d H:i:s');
        }
        return $rows;
    }
}
