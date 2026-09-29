<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\EntityKnowbaseItem;
use itsmng\Database\Entity\GroupKnowbaseItem;
use itsmng\Database\Entity\KnowbaseItem;
use itsmng\Database\Entity\KnowbaseItemComment;
use itsmng\Database\Entity\KnowbaseItemProfile;
use itsmng\Database\Entity\KnowbaseItemRevision;
use itsmng\Database\Entity\KnowbaseItemTranslation;
use itsmng\Database\Entity\KnowbaseItemUser;
use itsmng\Database\KnowledgeBaseAccess;

/** Mapped article persistence and visibility; model callers retain lifecycle hooks. */
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

    /** Count articles once even when user, group, profile and entity grants overlap. */
    public function categoryCounts(KnowledgeBaseAccess $access): array
    {
        $query = $this->visible($access)->select('IDENTITY(k.knowbaseitemcategories) AS category_id', 'COUNT(k.id) AS articles')
            ->groupBy('k.knowbaseitemcategories');
        $counts = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $counts[(int)$row['category_id']] = (int)$row['articles'];
        }
        return $counts;
    }

    public function categories(KnowledgeBaseAccess $access): array
    {
        $counts = $this->categoryCounts($access);
        $categories = (new RecordRepository($this->em))->matching('glpi_knowbaseitemcategories', [], ['level DESC', 'name', 'id']);
        foreach ($categories as &$category) {
            $category['items_count'] = $counts[$category['id']] ?? 0;
        }
        return ['categories' => $categories, 'uncategorized' => $counts[0] ?? 0];
    }

    private function visible(KnowledgeBaseAccess $access): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(KnowbaseItem::class, 'k');
        if ($access->administrator) {
            return $query;
        }
        if ($access->user <= 0 && !$access->publicFaq) {
            return $query->where('1 = 0');
        }
        $query->setParameter('yes', true, Types::BOOLEAN);
        if ($access->user <= 0) {
            $query->where('k.is_faq = :yes');
            if ($access->multiEntity) {
                $query->andWhere('EXISTS (SELECT e.id FROM ' . EntityKnowbaseItem::class
                    . ' e WHERE IDENTITY(e.knowbaseitems) = k.id AND IDENTITY(e.entities) = 0 AND e.is_recursive = :yes)');
            }
            return $query;
        }
        $query->setParameter('viewer', $access->user, Types::INTEGER);
        $predicates = ['IDENTITY(k.users) = :viewer',
            'EXISTS (SELECT u.id FROM ' . KnowbaseItemUser::class . ' u WHERE IDENTITY(u.knowbaseitems) = k.id AND IDENTITY(u.users) = :viewer)'];
        $query->setParameter('entities', $access->entities ?: [-1])->setParameter('ancestors', $access->ancestors ?: [-1]);
        $entityScope = static fn (string $field, string $alias): string => '(' . $field . ' IN (:entities) OR (' . $alias . '.is_recursive = :yes AND ' . $field . ' IN (:ancestors)))';
        if ($access->groups) {
            $query->setParameter('groups', $access->groups);
            $predicates[] = 'EXISTS (SELECT g.id FROM ' . GroupKnowbaseItem::class
                . ' g WHERE IDENTITY(g.knowbaseitems) = k.id AND IDENTITY(g.groups) IN (:groups) AND (g.entities_id < 0 OR ' . $entityScope('g.entities_id', 'g') . '))';
        }
        if ($access->profile > 0) {
            $query->setParameter('profile', $access->profile, Types::INTEGER);
            $predicates[] = 'EXISTS (SELECT p.id FROM ' . KnowbaseItemProfile::class
                . ' p WHERE IDENTITY(p.knowbaseitems) = k.id AND IDENTITY(p.profiles) = :profile AND (p.entities_id < 0 OR ' . $entityScope('p.entities_id', 'p') . '))';
        }
        $predicates[] = 'EXISTS (SELECT e.id FROM ' . EntityKnowbaseItem::class
            . ' e WHERE IDENTITY(e.knowbaseitems) = k.id AND ' . $entityScope('IDENTITY(e.entities)', 'e') . ')';
        $query->where('(' . implode(' OR ', $predicates) . ')');
        if (!$access->readArticles) {
            $query->andWhere('k.is_faq = :yes');
        }
        return $query;
    }
}
