<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonITILObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use ReflectionMethod;
use itsmng\Database\Entity;
use itsmng\Database\Expressions;
use itsmng\Database\ITILDocumentAccess;
use itsmng\Database\RecordCriteria;

final class DocumentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function contentId(int $entity, string $hash): ?int
    {
        $row = $this->em->createQueryBuilder()->select('d.id')->from(Entity\Document::class, 'd')
            ->where('IDENTITY(d.entities) = :entity AND d.sha1sum = :hash')->setParameter('entity', $entity, Types::INTEGER)
            ->setParameter('hash', $hash)->orderBy('d.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    public function icon(string $extension): ?string
    {
        $row = $this->em->createQueryBuilder()->select('t.icon')->from(Entity\DocumentType::class, 't')
            ->where("LOWER(t.ext) LIKE :extension AND t.icon <> ''")->setParameter('extension', strtolower($extension))
            ->orderBy('t.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : $row['icon'];
    }

    public function uploadableTypes(string $pattern): array
    {
        return $this->em->createQueryBuilder()->select('t.ext')->from(Entity\DocumentType::class, 't')
            ->where('LOWER(t.ext) LIKE :pattern AND t.is_uploadable = :yes')->setParameter('pattern', strtolower($pattern))
            ->setParameter('yes', true, Types::BOOLEAN)->orderBy('t.id')->getQuery()->getScalarResult();
    }

    public function categories(array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT c.id', 'c.name')->from(Entity\Document::class, 'r')
            ->join('r.documentcategories', 'c')->orderBy('c.name')->addOrderBy('c.id');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Document::class)))->where($criteria));
        return array_column($query->getQuery()->getScalarResult(), 'name', 'id');
    }

    /** Snapshot before the model purge lifecycle mutates document bindings. */
    public function orphanIds(): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()->select('d.id')->from(Entity\Document::class, 'd')
            ->where('NOT EXISTS (SELECT binding.id FROM ' . Entity\DocumentItem::class . ' binding WHERE IDENTITY(binding.documents) = d.id)')
            ->orderBy('d.id')->getQuery()->getScalarResult(), 'id'));
    }

    public function linkedToITIL(int $document, string $type, int $item, ITILDocumentAccess $access): bool
    {
        if ($access->user <= 0) {
            return false;
        }
        return $this->itilBindings($type, $item, $access)->andWhere('IDENTITY(d.documents) = :document')
            ->setParameter('document', $document, Types::INTEGER)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Count rendered document/date identities without loading attachment content. */
    public function countTimelineDocuments(string $type, int $item, ITILDocumentAccess $access): int
    {
        // Calendar text matches the timeline keys, including repeated DST-fold hours.
        $query = $this->itilBindings($type, $item, $access)
            ->select("DISTINCT IDENTITY(d.documents) AS document_id, TEMPORAL_TEXT(COALESCE(d.date, d.date_creation), 'datetime') AS event_date")
            ->andWhere('d.timeline_position > :inline')
            ->setParameter('inline', CommonITILObject::NO_TIMELINE, Types::INTEGER);
        return count($query->getQuery()->getScalarResult());
    }

    /** Only the private, canonical timeline read owner admits this fixed projection. */
    public function nativeTimelineDocumentCount(string $type, int $item, ITILDocumentAccess $access): int
    {
        $connection = $this->em->getConnection();
        foreach (['getDatabasePlatform', 'createQueryBuilder', 'quote'] as $method) {
            if ((new ReflectionMethod($connection, $method))->getDeclaringClass()->getName() !== Connection::class) {
                return $this->countTimelineDocuments($type, $item, $access);
            }
        }
        $platform = $connection->getDatabasePlatform();
        // Repeated predicates must retain the ORM's SQL callback order and multiplicity.
        foreach ([Types::INTEGER, Types::BOOLEAN] as $name) {
            if ((new ReflectionMethod(Type::getType($name), 'convertToDatabaseValueSQL'))->getDeclaringClass()->getName() !== Type::class) {
                return $this->countTimelineDocuments($type, $item, $access);
            }
        }
        $subjects = $this->itilSubjects($type, $access);
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $document = $this->em->getClassMetadata(Entity\DocumentItem::class);
        $column = static fn (ClassMetadata $metadata, string $field, string $alias): string =>
            $alias . '.' . $quote->getColumnName($field, $metadata, $platform);
        $identity = function (ClassMetadata $metadata, string $field, string $alias) use ($quote, $platform): string {
            $association = $metadata->associationMappings[$field];
            return $alias . '.' . $quote->getJoinColumnName(
                $association->joinColumns[0],
                $this->em->getClassMetadata($association->targetEntity),
                $platform
            );
        };
        // The ORM binds explicit integers/booleans through their live SQL converters;
        // inferred string parameters and fixed discriminator literals stay strings.
        $integer = Type::getType(Types::INTEGER);
        $itemParameter = $integer->convertToDatabaseValueSQL(':item', $platform);
        $inline = $integer->convertToDatabaseValueSQL(':inline', $platform);
        $eventDate = (new Expressions($platform))->temporalText(
            'COALESCE(' . $column($document, 'date', 'd') . ', ' . $column($document, 'date_creation', 'd') . ')',
            'datetime'
        );
        $query = $connection->createQueryBuilder()
            ->select($identity($document, 'documents', 'd') . ' AS document_id', $eventDate . ' AS event_date')
            ->distinct()
            ->from($quote->getTableName($document, $platform), 'd')
            ->setParameter('type', $type)
            ->setParameter('item', $item, Types::INTEGER)
            ->setParameter('inline', CommonITILObject::NO_TIMELINE, Types::INTEGER);
        $kind = $column($document, 'itemtype', 'd');
        $subjectId = $column($document, 'items_id', 'd');
        $conditions = ['(' . $kind . ' = :type AND ' . $subjectId . ' = ' . $itemParameter . ')'];
        foreach ($subjects as [$subjectType, $class, $parent, $restricted, $alias]) {
            $metadata = $this->em->getClassMetadata($class);
            $discriminator = $connection->quote($subjectType);
            if ($alias === 't') {
                $discriminator = ':taskType';
                $query->setParameter('taskType', $subjectType);
            }
            $predicate = $column($metadata, 'id', $alias) . ' = ' . $subjectId
                . ' AND ' . $identity($metadata, $parent, $alias) . ' = ' . $itemParameter;
            if ($restricted) {
                $public = Type::getType(Types::BOOLEAN)->convertToDatabaseValueSQL(':public', $platform);
                $viewer = $integer->convertToDatabaseValueSQL(':viewer', $platform);
                $predicate .= ' AND (' . $column($metadata, 'is_private', $alias) . ' = ' . $public
                    . ' OR ' . $identity($metadata, 'author', $alias) . ' = ' . $viewer . ')';
                $query->setParameter('public', false, Types::BOOLEAN)
                    ->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $conditions[] = '(' . $kind . ' = ' . $discriminator . ' AND EXISTS (SELECT '
                . $column($metadata, 'id', $alias) . ' FROM ' . $quote->getTableName($metadata, $platform)
                . ' ' . $alias . ' WHERE ' . $predicate . '))';
        }
        $query->where('(' . implode(' OR ', $conditions) . ')')
            ->andWhere($column($document, 'timeline_position', 'd') . ' > ' . $inline);
        // Counting the distinct pair retains NULL calendar keys and repeated documents.
        // COUNT(DISTINCT document_id) or a multi-column MySQL COUNT drops valid events.
        return (int)$connection->executeQuery(
            'SELECT COUNT(*) FROM (' . $query->getSQL() . ') document_events',
            $query->getParameters(),
            $query->getParameterTypes()
        )->fetchOne();
    }

    /** Template attachments retain one row per visible timeline binding. */
    public function notificationDocuments(string $type, int $item, ITILDocumentAccess $access): array
    {
        $query = $this->itilBindings($type, $item, $access)->select('d', 'document')->join('d.documents', 'document')
            ->andWhere('d.timeline_position > :inline')->setParameter('inline', CommonITILObject::NO_TIMELINE, Types::INTEGER)
            ->orderBy('d.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $rows[] = $records->toRow($binding->documents);
            $this->em->detach($binding);
        }
        return $rows;
    }

    /** One document row per binding, scoped to the complete legacy item identity. */
    public function documentsForItem(string $type, int $item): array
    {
        $query = $this->itemBindings($type, $item)->select('binding', 'document')->join('binding.documents', 'document');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $rows[] = $records->toRow($binding->documents);
            $this->em->detach($binding);
        }
        return $rows;
    }

    /** Binding identities for callers that must load documents through their model hooks. */
    public function bindingsForItem(string $type, int $item): array
    {
        return $this->itemBindings($type, $item)->select('binding.id', 'IDENTITY(binding.documents) AS documents_id')
            ->getQuery()->getScalarResult();
    }

    private function itemBindings(string $type, int $item): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\DocumentItem::class, 'binding')->orderBy('binding.id');
        try {
            $association = Entity\DocumentItem::referenceAssociation($type);
        } catch (InvalidArgumentException) {
            // No mapped subject can own a binding for an unknown item kind.
            return $query->where('1 = 0');
        }
        return $query->where('IDENTITY(binding.' . $association . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)->orderBy('binding.id');
    }

    /** The document consumers share one subject/privacy selection and owning associations. */
    private function itilSubjects(string $type, ITILDocumentAccess $access): array
    {
        [$task, , $taskAssociation] = (new ITILTaskRepository($this->em))->definition($type . 'Task');
        $subjects = [];
        if ($access->followups) {
            $subjects[] = ['ITILFollowup', Entity\ITILFollowup::class, Entity\ITILFollowup::subjectAssociation($type), !$access->privateFollowups, 'f'];
        }
        if ($access->solutions) {
            $subjects[] = ['ITILSolution', Entity\ITILSolution::class, Entity\ITILSolution::subjectAssociation($type), false, 's'];
        }
        if ($access->tasks) {
            $subjects[] = [$type . 'Task', $task, $taskAssociation, !$access->privateTasks, 't'];
        }
        return $subjects;
    }

    private function itilBindings(string $type, int $item, ITILDocumentAccess $access): QueryBuilder
    {
        $subjects = $this->itilSubjects($type, $access);
        $query = $this->em->createQueryBuilder()->select('d.id')->from(Entity\DocumentItem::class, 'd')
            ->setParameter('type', $type)->setParameter('item', $item, Types::INTEGER);
        $conditions = ['(d.itemtype = :type AND d.items_id = :item)'];
        foreach ($subjects as [$subjectType, $class, $parent, $restricted, $alias]) {
            $discriminator = "'" . $subjectType . "'";
            if ($alias === 't') {
                $discriminator = ':taskType';
                $query->setParameter('taskType', $subjectType);
            }
            $private = '';
            if ($restricted) {
                $private = ' AND (' . $alias . '.is_private = :public OR IDENTITY(' . $alias . '.author) = :viewer)';
                $query->setParameter('public', false, Types::BOOLEAN)->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $conditions[] = '(d.itemtype = ' . $discriminator . ' AND EXISTS (SELECT ' . $alias . '.id FROM '
                . $class . ' ' . $alias . ' WHERE ' . $alias . '.id = d.items_id AND IDENTITY('
                . $alias . '.' . $parent . ') = :item' . $private . '))';
        }
        return $query->where('(' . implode(' OR ', $conditions) . ')');
    }
}
