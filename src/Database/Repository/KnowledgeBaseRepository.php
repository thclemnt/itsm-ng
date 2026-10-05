<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\DocumentItem;
use itsmng\Database\Entity\EntityKnowbaseItem;
use itsmng\Database\Entity\GroupKnowbaseItem;
use itsmng\Database\Entity\KnowbaseItem;
use itsmng\Database\Entity\KnowbaseItemComment;
use itsmng\Database\Entity\KnowbaseItemProfile;
use itsmng\Database\Entity\KnowbaseItemRevision;
use itsmng\Database\Entity\KnowbaseItemTranslation;
use itsmng\Database\Entity\KnowbaseItemUser;
use itsmng\Database\KnowledgeBaseAccess;
use itsmng\Database\LegacyValues;

/** Mapped article persistence and visibility; model callers retain lifecycle hooks. */
final class KnowledgeBaseRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Prefix terms retain the legacy OR search, without accepting query operators. */
    public static function fullTextQuery(string $text, AbstractPlatform $platform): string
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }
        return $platform instanceof PostgreSQLPlatform
            ? implode(' | ', array_map(static fn (string $word): string => "'" . $word . "':*", $words))
            : implode(' ', array_map(static fn (string $word): string => $word . '*', $words));
    }

    /** The existing fallback removes these search operators before substring matching. */
    public static function fallbackText(string $text): string
    {
        return str_replace(['\\"', '+', '*', '~', '<', '>', '(', ')', '-'], '', $text);
    }

    /**
     * A current scalar page, with no managed articles or per-row audience hydration.
     *
     * @param array{type:string, contains:string, category:int, faq:bool, language:?string, offset:int, limit:?int} $options
     * @return array{total:int, rows:array}
     */
    public function listPage(KnowledgeBaseAccess $access, array $options): array
    {
        $type = $options['type'];
        $own = in_array($type, ['allmy', 'myunpublished'], true);
        if ($own || $type === 'allunpublished') {
            $query = $this->em->createQueryBuilder()->from(KnowbaseItem::class, 'k');
            if ($own) {
                $query->where('IDENTITY(k.users) = :author')->setParameter('author', $access->user, Types::BIGINT);
            } elseif (!$access->administrator) {
                $query->where('1 = 0');
            }
        } else {
            $query = $this->visible($access);
        }
        $audiences = [
            'EXISTS (SELECT publishedUser.id FROM ' . KnowbaseItemUser::class . ' publishedUser WHERE IDENTITY(publishedUser.knowbaseitems) = k.id)',
            'EXISTS (SELECT publishedGroup.id FROM ' . GroupKnowbaseItem::class . ' publishedGroup WHERE IDENTITY(publishedGroup.knowbaseitems) = k.id)',
            'EXISTS (SELECT publishedProfile.id FROM ' . KnowbaseItemProfile::class . ' publishedProfile WHERE IDENTITY(publishedProfile.knowbaseitems) = k.id)',
            'EXISTS (SELECT publishedEntity.id FROM ' . EntityKnowbaseItem::class . ' publishedEntity WHERE IDENTITY(publishedEntity.knowbaseitems) = k.id)',
        ];
        if (in_array($type, ['myunpublished', 'allunpublished'], true)) {
            $query->andWhere('NOT (' . implode(' OR ', $audiences) . ')');
        }
        if ($options['faq']) {
            $query->andWhere('k.is_faq = true');
        }
        if ($type === 'browse') {
            if ($options['category'] === 0) {
                $query->andWhere('k.knowbaseitemcategories IS NULL');
            } else {
                $query->andWhere('IDENTITY(k.knowbaseitemcategories) = :category')
                    ->setParameter('category', $options['category'], Types::BIGINT);
            }
        }
        $search = $type === 'search' && $options['contains'] !== '';
        if ($search || ($type === 'browse' && !$access->administrator)) {
            $query->andWhere('(k.begin_date IS NULL OR k.begin_date < CURRENT_TIMESTAMP())')
                ->andWhere('(k.end_date IS NULL OR k.end_date > CURRENT_TIMESTAMP())');
        }
        $translated = $options['language'] !== null;
        if ($translated && $search) {
            $query->setParameter('language', $options['language'], Types::STRING);
        }
        $eligibleTranslation = null;
        $score = '0';
        $total = null;
        if ($search) {
            $terms = self::fullTextQuery(\Toolbox::unclean_cross_side_scripting_deep($options['contains']),
                $this->em->getConnection()->getDatabasePlatform());
            if ($terms !== '') {
                $fullText = clone $query;
                $matches = ['KB_MATCH(k.name, k.answer, :terms) = true'];
                $fullTextScore = 'KB_SCORE(k.name, k.answer, :terms)';
                if ($translated) {
                    $matches[] = 'EXISTS (SELECT matchingTranslation.id FROM ' . KnowbaseItemTranslation::class
                        . ' matchingTranslation WHERE IDENTITY(matchingTranslation.knowbaseitems) = k.id '
                        . 'AND matchingTranslation.language = :language AND ('
                        . 'KB_MATCH(matchingTranslation.name, :terms) = true OR KB_MATCH(matchingTranslation.answer, :terms) = true))';
                    // DQL accepts a whole scalar subselect, not a subselect inside
                    // COALESCE/arithmetic. The aggregate returns one row even without translations.
                    $fullTextScore = 'SELECT KB_SCORE(k.name, k.answer, :terms) '
                        . '+ COALESCE(MAX(COALESCE(KB_SCORE(rankedTranslation.name, :terms), 0) '
                        . '+ COALESCE(KB_SCORE(rankedTranslation.answer, :terms), 0)), 0) FROM ' . KnowbaseItemTranslation::class
                        . ' rankedTranslation WHERE IDENTITY(rankedTranslation.knowbaseitems) = k.id '
                        . 'AND rankedTranslation.language = :language';
                }
                $fullText->andWhere('(' . implode(' OR ', $matches) . ')')->setParameter('terms', $terms, Types::STRING);
                $total = (int)(clone $fullText)->select('COUNT(k.id)')->getQuery()->getSingleScalarResult();
                if ($total > 0) {
                    $query = $fullText;
                    $score = $fullTextScore;
                    $eligibleTranslation = static fn (string $alias): string =>
                        'KB_MATCH(k.name, k.answer, :terms) = true OR KB_MATCH(' . $alias . '.name, :terms) = true '
                        . 'OR KB_MATCH(' . $alias . '.answer, :terms) = true';
                }
            }
            if (!$total) {
                $pattern = LegacyValues::decode(\Search::makeTextSearchValue(self::fallbackText($options['contains'])));
                $fields = ['k.name', 'k.answer'];
                $postgres = $this->em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform;
                $likes = array_map(static fn (string $field): string => $postgres
                    ? 'LOWER(' . $field . ') LIKE LOWER(:fallback)'
                    : $field . ' LIKE :fallback', $fields);
                $baseLikes = implode(' OR ', $likes);
                $eligibleTranslation = static function (string $alias) use ($postgres, $baseLikes): string {
                    $translatedLikes = array_map(static fn (string $field): string => $postgres
                        ? 'LOWER(' . $alias . '.' . $field . ') LIKE LOWER(:fallback)'
                        : $alias . '.' . $field . ' LIKE :fallback', ['name', 'answer']);
                    return $baseLikes . ' OR ' . implode(' OR ', $translatedLikes);
                };
                if ($translated) {
                    $translationLikes = array_map(static fn (string $field): string => $postgres
                        ? 'LOWER(fallbackTranslation.' . $field . ') LIKE LOWER(:fallback)'
                        : 'fallbackTranslation.' . $field . ' LIKE :fallback', ['name', 'answer']);
                    $likes[] = 'EXISTS (SELECT fallbackTranslation.id FROM ' . KnowbaseItemTranslation::class
                        . ' fallbackTranslation WHERE IDENTITY(fallbackTranslation.knowbaseitems) = k.id '
                        . 'AND fallbackTranslation.language = :language AND (' . implode(' OR ', $translationLikes) . '))';
                }
                $query->andWhere('(' . implode(' OR ', $likes) . ')')->setParameter('fallback', $pattern, Types::STRING);
                $total = null;
            }
        }
        $total ??= (int)(clone $query)->select('COUNT(k.id)')->getQuery()->getSingleScalarResult();
        $query->leftJoin('k.knowbaseitemcategories', 'kbCategory');
        if ($translated) {
            // Historical schemas permit duplicates. Choose the first eligible row,
            // after article-level search/count, so a later matching translation survives.
            $eligible = $eligibleTranslation === null ? '' : ' AND (' . $eligibleTranslation('translation') . ')';
            $earlierEligible = $eligibleTranslation === null ? '' : ' AND (' . $eligibleTranslation('earlierTranslation') . ')';
            $query->leftJoin(KnowbaseItemTranslation::class, 'translation', 'WITH',
                'IDENTITY(translation.knowbaseitems) = k.id AND translation.language = :language' . $eligible
                . ' AND NOT EXISTS (SELECT earlierTranslation.id FROM ' . KnowbaseItemTranslation::class . ' earlierTranslation '
                . 'WHERE IDENTITY(earlierTranslation.knowbaseitems) = k.id AND earlierTranslation.language = :language '
                . 'AND earlierTranslation.id < translation.id' . $earlierEligible . ')')
                ->setParameter('language', $options['language'], Types::STRING);
        }
        $published = str_replace('published', 'visibility', implode(' OR ', $audiences));
        $query->select('k.id', 'k.name', 'k.answer', 'k.is_faq', 'IDENTITY(k.users) AS users_id',
            'IDENTITY(k.knowbaseitemcategories) AS knowbaseitemcategories_id', 'kbCategory.completename AS category',
            'CASE WHEN (' . $published . ') THEN 1 ELSE 0 END AS visibility_count');
        if ($translated) {
            $query->addSelect('translation.name AS transname', 'translation.answer AS transanswer');
        }
        if ($search) {
            $query->addSelect('(' . $score . ') AS HIDDEN relevance')->orderBy('relevance', 'DESC');
        } elseif ($type === 'browse') {
            $query->orderBy('k.name', 'ASC');
        }
        $query->addOrderBy('k.id', 'ASC')->setFirstResult(max(0, $options['offset']));
        if ($options['limit'] !== null) {
            $query->setMaxResults(max(1, $options['limit']));
        }
        $rows = $query->getQuery()->getArrayResult();
        $metadata = $this->em->getClassMetadata(KnowbaseItem::class);
        foreach ($rows as &$row) {
            foreach ($row as $field => &$value) {
                if ($metadata->hasField($field)) {
                    $value = RecordRepository::legacyScalarValue($value, $metadata->getTypeOfField($field));
                } elseif (in_array($field, ['users_id', 'knowbaseitemcategories_id'], true)) {
                    $value = RecordRepository::legacyScalarValue($value, Types::BIGINT);
                }
            }
            unset($value);
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
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

    public function hasDocument(int $document, KnowledgeBaseAccess $access): bool
    {
        return $this->visible($access)->select('k.id')
            ->andWhere("EXISTS (SELECT d.id FROM " . DocumentItem::class . " d WHERE IDENTITY(d.documents) = :document AND d.itemtype = 'KnowbaseItem' AND d.items_id = k.id)")
            ->setParameter('document', $document, Types::INTEGER)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Reuse the article audience policy without a multiplying join in choice lists. */
    public function restrictDropdownChoices(QueryBuilder $query, KnowledgeBaseAccess $access): void
    {
        $visible = $this->visible($access)->select('k.id');
        $query->andWhere('r.id IN (' . $visible->getDQL() . ')');
        foreach ($visible->getParameters() as $parameter) {
            $query->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }
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
                . ' g WHERE IDENTITY(g.knowbaseitems) = k.id AND IDENTITY(g.groups) IN (:groups) AND (IDENTITY(g.entities) IS NULL OR ' . $entityScope('IDENTITY(g.entities)', 'g') . '))';
        }
        if ($access->profile > 0) {
            $query->setParameter('profile', $access->profile, Types::INTEGER);
            $predicates[] = 'EXISTS (SELECT p.id FROM ' . KnowbaseItemProfile::class
                . ' p WHERE IDENTITY(p.knowbaseitems) = k.id AND IDENTITY(p.profiles) = :profile AND (IDENTITY(p.entities) IS NULL OR ' . $entityScope('IDENTITY(p.entities)', 'p') . '))';
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
