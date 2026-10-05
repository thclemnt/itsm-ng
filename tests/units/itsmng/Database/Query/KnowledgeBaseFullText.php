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
