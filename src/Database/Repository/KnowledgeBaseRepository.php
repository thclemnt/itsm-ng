<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\KnowbaseItem;
use itsmng\Database\Entity\KnowbaseItemComment;
use itsmng\Database\Entity\KnowbaseItemRevision;
use itsmng\Database\Entity\KnowbaseItemTranslation;

/** Persistence operations; callers retain article access checks and lifecycle hooks. */
final class KnowledgeBaseRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function publish(int $id): void
    {
        $this->em->createQueryBuilder()->update(KnowbaseItem::class, 'k')
            ->set('k.is_faq', ':published')->setParameter('published', true, Types::BOOLEAN)
            ->where('k.id = :id')->setParameter('id', $id)->getQuery()->execute();
    }

    public function incrementViews(int $id): void
    {
        // Atomic in the database: concurrent readers cannot lose increments.
        $this->em->createQueryBuilder()->update(KnowbaseItem::class, 'k')
            ->set('k.view', 'k.view + 1')->where('k.id = :id')->setParameter('id', $id)
            ->getQuery()->execute();
    }

    public function comments(int $article, ?string $language, ?int $parent = null): array
    {
        $rows = (new RecordRepository($this->em))->matching('glpi_knowbaseitems_comments', [
            'knowbaseitems_id' => $article, 'language' => $language,
        ], 'id ASC', legacyValues: false);
        $children = [];
        foreach ($rows as $row) {
            $children[$row['parent_comment_id'] ?? 'root'][] = $row;
        }
        // One mapped read replaces the recursive query for every comment.
        $build = static function (?int $parent, array $ancestors = []) use (&$build, $children): array {
            $branch = [];
            foreach ($children[$parent ?? 'root'] ?? [] as $row) {
                if (isset($ancestors[$row['id']])) {
                    throw new \UnexpectedValueException('Cycle in knowledge-base comment ancestry.');
                }
                $row['answers'] = $build($row['id'], $ancestors + [$row['id'] => true]);
                $branch[] = $row;
            }
            return $branch;
        };
        return $build($parent);
    }

    public function preserveReplies(int $comment, ?int $parent): void
    {
        $this->em->createQueryBuilder()->update(KnowbaseItemComment::class, 'c')
            ->set('c.parent_comment', ':parent')->setParameter('parent', $parent === $comment ? null : $parent)
            ->where('IDENTITY(c.parent_comment) = :comment')->setParameter('comment', $comment)
            ->getQuery()->execute();
    }

    public function revisionCount(int $article, ?string $language): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_knowbaseitems_revisions', [
            'knowbaseitems_id' => $article, 'language' => $language,
        ], legacyValues: false);
    }

    public function commentCount(int $article, ?string $language): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_knowbaseitems_comments', [
            'knowbaseitems_id' => $article, 'language' => $language,
        ], legacyValues: false);
    }

    public function translationCount(int $article): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_knowbaseitemtranslations', ['knowbaseitems_id' => $article]);
    }

    public function revisions(int $article, ?string $language, int $limit, int $offset): array
    {
        return (new RecordRepository($this->em))->matching('glpi_knowbaseitems_revisions', [
            'knowbaseitems_id' => $article, 'language' => $language,
        ], 'id DESC', $limit, $offset, legacyValues: false);
    }

    public function nextRevision(int $article, ?string $language): int
    {
        $query = $this->em->createQueryBuilder()->select('MAX(r.revision)')->from(KnowbaseItemRevision::class, 'r')
            ->where('IDENTITY(r.knowbaseitems) = :article')->setParameter('article', $article);
        if ($language === null) {
            $query->andWhere('r.language IS NULL');
        } else {
            $query->andWhere('r.language = :language')->setParameter('language', $language);
        }
        return (int)$query->getQuery()->getSingleScalarResult() + 1;
    }

    public function translatedLanguages(int $article): array
    {
        $rows = $this->em->createQueryBuilder()->select('t.language')->from(KnowbaseItemTranslation::class, 't')
            ->where('IDENTITY(t.knowbaseitems) = :article')->setParameter('article', $article)
            ->getQuery()->getArrayResult();
        return array_column($rows, 'language', 'language');
    }
}
