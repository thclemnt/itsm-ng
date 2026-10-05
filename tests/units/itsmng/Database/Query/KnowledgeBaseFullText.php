<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Query;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use itsmng\Database\Entity\KnowbaseItem;
use itsmng\Database\Orm;
use itsmng\Database\Query\KnowledgeBaseFullText as FullText;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 4) . '/fixtures/DisconnectedSchemaConnection.php';

class KnowledgeBaseFullText extends \atoum\atoum\test
{
    public function testBoundDqlPreservesParameterOccurrencesAndProviderPredicates(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            $manager = new EntityManager($connection, Orm::configuration($platform));
            $query = $manager->createQuery('SELECT k.id, KB_SCORE(k.name, k.answer, :terms) AS HIDDEN relevance FROM '
                . KnowbaseItem::class . ' k WHERE KB_MATCH(k.name, k.answer, :terms) = true'
                . ' AND KB_MATCH(k.name, :title) = true ORDER BY relevance DESC, k.id ASC')
                ->setParameter('terms', 'bound_only_terms')->setParameter('title', 'bound_only_title');
            $parsed = (new Parser($query))->parse();
            $this->array($parsed->getParameterMappings())->isIdenticalTo(['terms' => [0, 1], 'title' => [2]]);
            $sql = $query->getSQL();
            $this->integer(substr_count($sql, '?'))->isIdenticalTo(3);
            $this->string($sql)->notContains('bound_only_terms')->notContains('bound_only_title');
            if ($platform instanceof PostgreSQLPlatform) {
                $this->string($sql)->contains("to_tsvector('simple', COALESCE(")
                    ->contains(" || ' ' || COALESCE(")->contains("@@ to_tsquery('simple', ?)")
                    ->contains('ts_rank(')->notContains('CASE')->notContains('MATCH(');
            } else {
                $this->string($sql)->contains('MATCH(')->contains('AGAINST(? IN BOOLEAN MODE) > 0)')
                    ->notContains('to_tsvector');
            }
            $this->boolean($connection->isConnected())->isFalse();
        }
    }

    public function testActualRepositoryPageQueriesCompileWithoutConnecting(): void
    {
        if (!defined('GLPI_ROOT')) {
            define('GLPI_ROOT', dirname(__DIR__, 5));
        }
        require_once GLPI_ROOT . '/inc/toolbox.class.php';
        require_once GLPI_ROOT . '/inc/search.class.php';

        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            foreach ([['browse', '', null, [1]], ['browse', '', 'fr_FR', [1]],
                ['search', 'article', null, [1]], ['search', 'article', 'fr_FR', [1]],
                ['search', 'interior', 'fr_FR', [0, 1]]] as [$type, $text, $language, $counts]) {
                $strict = new DisconnectedSchemaConnection($platform);
                // Only the driver boundary is synthetic. The actual repository,
                // parser, SQL walker, parameter mapping and hydrators execute.
                $connection = new class ([], $strict->getDriver()) extends \Doctrine\DBAL\Connection {
                    public array $counts = [];
                    public array $statements = [];

                    public function executeQuery(string $sql, array $params = [], array $types = [],
                        ?\Doctrine\DBAL\Cache\QueryCacheProfile $qcp = null): \Doctrine\DBAL\Result
                    {
                        $this->statements[] = [$sql, $params];
                        $rows = [];
                        if (preg_match('/^SELECT COUNT\(/i', $sql)) {
                            if ($this->counts === [] || !preg_match('/\bAS\s+(sclr_\d+)/i', $sql, $alias)) {
                                throw new \LogicException('Unexpected repository count projection.');
                            }
                            $rows = [[$alias[1] => array_shift($this->counts)]];
                        }
                        // Counts select the production branch; the empty page
                        // supplies no fake application data or native proof.
                        return new class ($rows) extends \Doctrine\DBAL\Result {
                            public function __construct(private array $rows)
                            {
                            }
                            public function fetchAllAssociative(): array
                            {
                                return $this->rows;
                            }
                            public function fetchAssociative(): array|false
                            {
                                return array_shift($this->rows) ?? false;
                            }
                            public function free(): void
                            {
                                $this->rows = [];
                            }
                        };
                    }
                };
                $connection->counts = $counts;
                $manager = new EntityManager($connection, Orm::configuration($platform));
                $access = new \itsmng\Database\KnowledgeBaseAccess(3, false, true, true, true, [4], 2, [1], [0]);
                $page = (new \itsmng\Database\Repository\KnowledgeBaseRepository($manager))->listPage($access, [
                    'type' => $type, 'contains' => $text, 'category' => 1, 'faq' => false,
                    'language' => $language, 'offset' => 1, 'limit' => 2,
                ]);
                $this->array($page)->isIdenticalTo(['total' => 1, 'rows' => []]);
                $this->array($connection->counts)->isEmpty();
                $this->array($connection->statements)->hasSize(count($counts) + 1);
                foreach ($connection->statements as [$sql, $params]) {
                    $this->integer(substr_count($sql, '?'))->isIdenticalTo(count($params));
                    if ($text !== '') {
                        $this->string($sql)->notContains("'" . $text . "'");
                    }
                }
                $pageSql = $connection->statements[array_key_last($connection->statements)][0];
                $this->string($pageSql)->contains('LIMIT 2')->contains('OFFSET 1');
                if ($language !== null) {
                    $this->string($pageSql)->contains('NOT EXISTS')->contains('glpi_knowbaseitemtranslations');
                }
                if ($type === 'search' && $language !== null && count($counts) === 1) {
                    $this->string($pageSql)->contains('MAX(')->contains('COALESCE(')->contains('ORDER BY');
                }
                $this->boolean($strict->isConnected())->isFalse();
                $this->boolean($connection->isConnected())->isFalse();
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
        }
    }

    public function testCompatibilitySqlRetainsIndexedVectorAndBooleanMatch(): void
    {
        $platform = new PostgreSQLPlatform();
        $vector = 'to_tsvector(\'simple\', COALESCE(k."name", \'\') || \' \' || COALESCE(k."answer", \'\'))';
        $this->string(FullText::sql($platform, ['k."name"', 'k."answer"'], ':terms'))
            ->isIdenticalTo('(' . $vector . " @@ to_tsquery('simple', :terms))");
        $this->string(FullText::sql($platform, ['k."name"', 'k."answer"'], ':terms', true))
            ->isIdenticalTo('ts_rank(' . $vector . ", to_tsquery('simple', :terms))");
        $this->string(FullText::sql($platform, ['t."name"'], ':terms'))
            ->isIdenticalTo('(to_tsvector(\'simple\', COALESCE(t."name", \'\')) @@ to_tsquery(\'simple\', :terms))');
        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $mysql) {
            $this->string(FullText::sql($mysql, ['k.`name`', 'k.`answer`'], ':terms'))
                ->isIdenticalTo('(MATCH(k.`name`, k.`answer`) AGAINST(:terms IN BOOLEAN MODE) > 0)');
            $this->string(FullText::sql($mysql, ['t.`answer`'], ':terms', true))
                ->isIdenticalTo('MATCH(t.`answer`) AGAINST(:terms IN BOOLEAN MODE)');
        }
    }

    public function testDqlRequiresMappedFieldsAndBoundSearchText(): void
    {
        $platform = new PostgreSQLPlatform();
        $manager = new EntityManager(new DisconnectedSchemaConnection($platform), Orm::configuration($platform));
        foreach ([
            "KB_MATCH(k.name, 'literal')",
            'KB_MATCH(k.name, k.answer, k.comment, :terms)',
            'KB_MATCH(k.unknown_field, :terms)',
        ] as $expression) {
            $this->exception(static fn () => $manager->createQuery('SELECT k.id FROM ' . KnowbaseItem::class
                . ' k WHERE ' . $expression . ' = true')->getSQL())->isInstanceOf(QueryException::class);
        }
    }
}
