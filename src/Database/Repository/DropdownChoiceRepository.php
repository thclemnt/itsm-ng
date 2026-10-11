<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use ReflectionMethod;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\ReadQueryOwner;
use itsmng\Database\RecordCriteria;
use itsmng\Database\MappedRowProjection;

/** Typed, paginated choices; domain repositories own additional access and labels. */
class DropdownChoiceRepository extends EntityRepository
{
    public function choices(array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
    {
        return $this->readChoices($criteria, $order, $translations, $kind, $language, $limit, $offset);
    }

    /** Internal scalar entry; the operation admits repository implementations first. */
    public function ownedChoices(array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset, ?array $defaultIdentifiers, ReadQueryOwner $operation): array
    {
        return $this->readChoices($criteria, $order, $translations, $kind, $language, $limit, $offset, $defaultIdentifiers, $operation);
    }

    /** Translation roles are local to this request, bound to its exact kind/field/language. */
    private function readChoices(array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset, ?array $defaultIdentifiers = null, ?ReadQueryOwner $operation = null): array
    {
        $query = $this->choiceQuery();
        $compiler = $this->choiceCriteria($query);
        $metadata = $this->getClassMetadata();
        $projection = null;
        // Custom presenters may load or mutate managed entities between streamed rows.
        // Custom root selections and post-load hooks retain their ordinary ORM behavior.
        if ($this->getEntityManager()->getUnitOfWork()->size() === 0
            && count($metadata->identifier) === 1
            && $metadata->hasField($metadata->getSingleIdentifierFieldName())
            && !$metadata->hasLifecycleCallbacks(Events::postLoad)
            && empty($metadata->entityListeners[Events::postLoad])
            && !$this->getEntityManager()->getEventManager()->hasListeners(Events::postLoad)
            && (new ReflectionMethod($this, 'presentChoice'))->getDeclaringClass()->getName() === self::class
            && $query->getRootAliases() === ['r']
            && $query->getRootEntities() === [$metadata->name]
            && array_map('strval', $query->getDQLPart('select')) === ['r']
            && !array_filter($translations, static fn (array $translation): bool => preg_match('/^value[0-9]+$/i', $translation['output']) === 1)) {
            $projection = new MappedRowProjection($this->getEntityManager(), $metadata, $defaultIdentifiers);
            $projection->select($query);
        }
        foreach ($translations as $qualifier => $translation) {
            $alias = 'choiceTranslation' . count($query->getAllAliases());
            $fieldParameter = $alias . 'Field';
            $query->leftJoin(DropdownTranslation::class, $alias, 'WITH', $alias . '.items_id = r.id AND '
                . $alias . '.itemtype = :choiceKind AND ' . $alias . '.language = :choiceLanguage AND ' . $alias . '.field = :' . $fieldParameter)
                ->addSelect($alias . '.value AS ' . $translation['output'])->setParameter($fieldParameter, $translation['field'], Types::STRING);
            $compiler->withJoinedMetadata($this->getEntityManager()->getClassMetadata(DropdownTranslation::class), $alias, $qualifier);
        }
        if ($translations) {
            $query->setParameter('choiceKind', $kind, Types::STRING)->setParameter('choiceLanguage', $language, Types::STRING);
        }
        $query->andWhere($compiler->where($criteria));
        // Explicit null ordering and an identifier tie-breaker make both providers
        // return stable pages when names are empty or identical.
        foreach ($order as $index => $column) {
            $expression = $compiler->column($column);
            $query->addSelect('CASE WHEN ' . $expression . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN choiceNull' . $index)
                ->addOrderBy('choiceNull' . $index)->addOrderBy($expression);
        }
        $query->addOrderBy('r.id')->setFirstResult(max(0, $offset));
        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        $rows = [];
        $records = new RecordRepository($this->getEntityManager());
        $compiled = $query->getQuery();
        if ($projection !== null) {
            $operation?->prepareQuery($compiled, $metadata);
        }
        foreach ($compiled->toIterable([], $projection === null ? Query::HYDRATE_OBJECT : Query::HYDRATE_ARRAY) as $result) {
            $record = $projection === null ? ($translations ? $result[0] : $result) : null;
            $row = $projection === null ? $records->toRow($record) : $projection->toRow($result);
            foreach ($translations as $translation) {
                $row[$translation['output']] = $result[$translation['output']];
            }
            $rows[] = $this->presentChoice($row);
        }
        return $rows;
    }

    protected function choiceQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('r');
    }

    protected function choiceCriteria(QueryBuilder $query): RecordCriteria
    {
        return new RecordCriteria($query, $this->getClassMetadata());
    }

    protected function presentChoice(array $row): array
    {
        return $row;
    }
}
