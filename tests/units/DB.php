<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DB as LegacyDB;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use LogicException;
use ReflectionProperty;
use itsmng\Database\MySQLManagedConnection;
use itsmng\Database\Orm;
use mock\DBmysql as PreparedReadAdapter;
use InvalidArgumentException;
use itsmng\Database\PostgresParameters;

/* Test for inc/dbmysql.class.php */

class DB extends \GLPITestCase
{
    public function testPreparedReadRetainsCustomConstructionRouteAndClearPolicy(): void
    {
        $parameters = ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0', 'wrapperClass' => MySQLManagedConnection::class];
        $selected = DriverManager::getConnection($parameters);
        $other = DriverManager::getConnection($parameters);
        $registry = DbalType::getTypeRegistry();
        $original = $registry->get(Types::STRING);
        $custom = new class () extends StringType {
            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
            {
                return $sqlExpr;
            }
        };
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new PreparedReadAdapter();
        try {
            foreach ([true, false] as $customBeforePrepare) {
                $route = $selected;
                $registry->override(Types::STRING, $customBeforePrepare ? $custom : $original);
                $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                    return $route;
                };
                $allocated = $factories->getValue();
                $result = Orm::readPrepared($adapter, function () use (
                    $factories,
                    $allocated,
                    $customBeforePrepare,
                    $registry,
                    $custom,
                    &$route,
                    $other
                ): int {
                    $this->integer($factories->getValue() - $allocated)->isIdenticalTo($customBeforePrepare ? 1 : 0);
                    $registry->override(Types::STRING, $custom);
                    $route = $other;
                    return 37;
                }, function (EntityManager $manager, int $prepared) use ($selected): int {
                    $this->object($manager->getConnection())->isIdenticalTo($selected);
                    $manager->getEventManager()->addEventListener([Events::onClear], new class () {
                        public function onClear(): void
                        {
                            throw new LogicException('Custom prepared readers must not acquire a clear callback');
                        }
                    });
                    return $prepared;
                });
                $this->integer($result)->isIdenticalTo(37);
                $this->integer($factories->getValue() - $allocated)->isIdenticalTo(1);
            }
            $this->boolean($selected->isConnected())->isFalse();
            $this->boolean($other->isConnected())->isFalse();
        } finally {
            $registry->override(Types::STRING, $original);
            $selected->close();
            $other->close();
        }
    }

    public function testPostgresLexicalPreparationPreservesWideProjectionAndOpaqueRegions(): void
    {
        $columns = implode(', ', array_map(static fn (int $number): string =>
            'asset_alias.column_' . $number . ' AS scalar_' . $number, range(1, 160)));
        $plain = 'SELECT ' . $columns . ' FROM assets asset_alias WHERE asset_alias.id = ?';
        $this->string(PostgresParameters::prepare($plain))->isIdenticalTo($plain);
        $opaque = <<<'SQL'
SELECT "identifier""$1", '$2 -- /* ??', E'escaped\\backslash\'quote', café$embedded, alias$1, 100 / 2 - 1
-- $3 ? /* literal line comment
FROM assets WHERE id = ?
SQL;
        $this->string(PostgresParameters::prepare($opaque))->isIdenticalTo($opaque);
        $source = <<<'SQL'
SELECT $tag$dollar $1 ? /* body */ 'quoted'\path$tag$, $$untagged$$, /* outer /* inner */ tail */ ?
SQL;
        $expected = <<<'SQL'
SELECT E'dollar $1 ? /* body */ ''quoted''\\path', E'untagged', /* outer    inner    tail */ ?
SQL;
        $this->string(PostgresParameters::prepare($source))->isIdenticalTo($expected);
    }

    public function testPostgresNumberedParametersRetainOrderTypesAndJsonOperators(): void
    {
        $source = <<<'SQL'
SELECT long_identifier, "quoted$1", '$2', $tag$ignored $3 ?$tag$, café$1, alias$2 FROM assets
WHERE second = $2 AND first = $1 AND again = $2 AND doc ? 'key' AND doc ?| ARRAY['a'] AND doc ?& ARRAY['b']
/* $3 /* nested $4 */ ? */ -- $5 ?
SQL;
        $expected = <<<'SQL'
SELECT long_identifier, "quoted$1", '$2', $tag$ignored $3 ?$tag$, café$1, alias$2 FROM assets
WHERE second = ? AND first = ? AND again = ? AND doc ?? 'key' AND doc ??| ARRAY['a'] AND doc ??& ARRAY['b']
/* $3 /* nested $4 */ ? */ -- $5 ?
SQL;
        $this->array(PostgresParameters::bind($source, [false, null]))
            ->isIdenticalTo([$expected, [null, false, null]]);
        $this->array(PostgresParameters::bind('SELECT $1, $2, $1', ['literal ? $9', 42]))
            ->isIdenticalTo(['SELECT ?, ?, ?', ['literal ? $9', 42, 'literal ? $9']]);
    }

    public function testPostgresLexicalErrorsRemainDiagnosedAfterOrdinarySpans(): void
    {
        foreach (["'unterminated", '"unterminated', '$tag$unterminated', '/* outer /* inner */'] as $suffix) {
            $sql = 'SELECT ordinary_projection, other_projection FROM ordinary_table WHERE value = ' . $suffix;
            $this->exception(static fn () => PostgresParameters::prepare($sql))
                ->isInstanceOf(InvalidArgumentException::class);
            $this->exception(static fn () => PostgresParameters::bind($sql, [1]))
                ->isInstanceOf(InvalidArgumentException::class);
        }
        foreach ([['SELECT ordinary_name, $0', [1]], ['SELECT ordinary_name, $2', [1]],
            ['SELECT ordinary_name, $1', [1, 2]]] as [$sql, $values]) {
            $this->exception(static fn () => PostgresParameters::bind($sql, $values))
                ->isInstanceOf(InvalidArgumentException::class);
        }
    }

    public function testTableExist()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->boolean($this->testedInstance->tableExists('glpi_configs'))->isTrue()
              ->boolean($this->testedInstance->tableExists('fakeTable'))->isFalse();

        $hadTimezone = array_key_exists('glpi_tz', $_SESSION);
        $timezone = $_SESSION['glpi_tz'] ?? null;
        $probe = null;
        $created = false;
        try {
            unset($_SESSION['glpi_tz']);
            $probe = new class () extends LegacyDB {
                public array $tableScans = [];

                public function listTables($table = 'glpi\\_%', array $where = [])
                {
                    $this->tableScans[] = $table;
                    return parent::listTables($table, $where);
                }
            };
            $this->boolean($probe->connected)->isTrue();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi_configs']);
            $probe->tableScans = [];
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->array($probe->tableScans)->isEmpty('The targeted bootstrap result remains positively cached');

            $probe->clearSchemaCache();
            $this->boolean($probe->tableExists('glpi_configs', false))->isTrue();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi_configs']);
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi_configs']);
            $this->boolean($probe->tableExists('fakeTable', false))->isFalse();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi_configs', 'fakeTable']);

            $probe->clearSchemaCache();
            $probe->tableScans = [];
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi\\_%']);
            $probe->tableScans = [];
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->array($probe->tableScans)->isEmpty();

            $table = 'glpi_timezone_probe_' . bin2hex(random_bytes(4));
            $native = $probe->getDoctrineConnection();
            $quoted = $native->quoteIdentifier($table);
            $this->boolean($probe->tableExists($table, false))->isFalse();
            $native->executeStatement('CREATE TABLE ' . $quoted . ' (id INTEGER NOT NULL)');
            $created = true;
            $this->boolean($probe->tableExists($table, false))->isTrue();
            $native->executeStatement('DROP TABLE ' . $quoted);
            $created = false;
            $this->boolean($probe->tableExists($table))->isTrue('The positive cache remains intact');
            $this->boolean($probe->tableExists($table, false))->isFalse('Forced lookup sees the fresh catalog');
            $probe->tableScans = [];

            $_SESSION['glpi_tz'] = 'Pacific/Auckland';
            $this->string($probe->guessTimezone())->isIdenticalTo('Pacific/Auckland');
            $this->array($probe->tableScans)->isEmpty('A session timezone needs no catalog read');
            $probe->disableTableCaching();
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->boolean($probe->tableExists('glpi_configs'))->isTrue();
            $this->array($probe->tableScans)->isIdenticalTo(['glpi_configs', 'glpi_configs']);
        } finally {
            if ($created) {
                $native->executeStatement('DROP TABLE ' . $quoted);
            }
            $probe?->close();
            if ($hadTimezone) {
                $_SESSION['glpi_tz'] = $timezone;
            } else {
                unset($_SESSION['glpi_tz']);
            }
        }
    }

    public function testFieldExists()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->boolean($this->testedInstance->fieldExists('glpi_configs', 'id'))->isTrue()
              ->boolean($this->testedInstance->fieldExists('glpi_configs', 'ID'))->isFalse()
              ->boolean($this->testedInstance->fieldExists('glpi_configs', 'fakeField'))->isFalse()
              ->when(
                  function () {
                      $this->boolean($this->testedInstance->fieldExists('fakeTable', 'id'))->isFalse();
                  }
              )->error
                 ->withType(E_USER_WARNING)
                 ->exists()
              ->when(
                  function () {
                      $this->boolean($this->testedInstance->fieldExists('fakeTable', 'fakeField'))->isFalse();
                  }
              )->error
                 ->withType(E_USER_WARNING)
                 ->exists();
    }

    protected function dataName()
    {
        return [
           ['field', '`field`', '"field"'],
           ['`field`', '`field`', '"field"'],
           ['*', '*', '*'],
           ['table.field', '`table`.`field`', '"table"."field"'],
           ['table.*', '`table`.*', '"table".*'],
           ['field AS f', '`field` AS `f`', '"field" AS "f"'],
           ['field as f', '`field` AS `f`', '"field" AS "f"'],
           ['table.field as f', '`table`.`field` AS `f`', '"table"."field" AS "f"'],
        ];
    }

    /**
     * @dataProvider dataName
     */
    public function testQuoteName($raw, $mysqlExpected, $pgsqlExpected)
    {
        global $DB;
        $expected = $DB->getProvider() === 'pgsql' ? $pgsqlExpected : $mysqlExpected;
        $this->string(LegacyDB::quoteName($raw))->isIdenticalTo($expected);
    }

    protected function dataValue()
    {
        return [
           ['foo', "'foo'"],
           ['bar', "'bar'"],
           ['42', "'42'"],
           ['+33', "'+33'"],
           [null, 'NULL'],
           ['null', 'NULL'],
           ['NULL', 'NULL'],
           [new \QueryExpression('`field`'), '`field`'],
           ['`field', "'`field'"],
           [false, "'0'"],
           [true, "'1'"],
        ];
    }

    /**
     * @dataProvider dataValue
     */
    public function testQuoteValue($raw, $expected)
    {
        $this->string(\DB::quoteValue($raw))->isIdenticalTo($expected);
    }


    protected function dataInsert()
    {
        return [
           [
              'table', [
                 'field'  => 'value',
                 'other'  => 'doe'
              ],
              'INSERT INTO `table` (`field`, `other`) VALUES (\'value\', \'doe\')',
              'INSERT INTO "table" ("field", "other") VALUES (\'value\', \'doe\')'
           ], [
              '`table`', [
                 '`field`'  => 'value',
                 '`other`'  => 'doe'
              ],
              'INSERT INTO `table` (`field`, `other`) VALUES (\'value\', \'doe\')',
              'INSERT INTO "table" ("field", "other") VALUES (\'value\', \'doe\')'
           ], [
              'table', [
                 'field'  => new \QueryParam(),
                 'other'  => new \QueryParam()
              ],
              'INSERT INTO `table` (`field`, `other`) VALUES (?, ?)',
              'INSERT INTO "table" ("field", "other") VALUES (?, ?)'
           ], [
              'table', [
                 'field'  => new \QueryParam('field'),
                 'other'  => new \QueryParam('other')
              ],
              'INSERT INTO `table` (`field`, `other`) VALUES (:field, :other)',
              'INSERT INTO "table" ("field", "other") VALUES (:field, :other)'
           ]
        ];
    }

    /**
     * @dataProvider dataInsert
     */
    public function testBuildInsert($table, $values, $mysqlExpected, $pgsqlExpected)
    {
        global $DB;
        $expected = $DB->getProvider() === 'pgsql' ? $pgsqlExpected : $mysqlExpected;
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->buildInsert($table, $values))->isIdenticalTo($expected);
    }

    protected function dataUpdate()
    {
        return [
           [
              'table', [
                 'field'  => 'value',
                 'other'  => 'doe'
              ], [
                 'id'  => 1
              ],
              'UPDATE `table` SET `field` = \'value\', `other` = \'doe\' WHERE `id` = \'1\'',
              'UPDATE "table" SET "field" = \'value\', "other" = \'doe\' WHERE "id" = \'1\''
           ], [
              'table', [
                 'field'  => 'value'
              ], [
                 'id'  => [1, 2]
              ],
              'UPDATE `table` SET `field` = \'value\' WHERE `id` IN (\'1\', \'2\')',
              'UPDATE "table" SET "field" = \'value\' WHERE "id" IN (\'1\', \'2\')'
           ], [
              'table', [
                 'field'  => 'value'
              ], [
                 'NOT'  => ['id' => [1, 2]]
              ],
              'UPDATE `table` SET `field` = \'value\' WHERE  NOT (`id` IN (\'1\', \'2\'))',
              'UPDATE "table" SET "field" = \'value\' WHERE  NOT ("id" IN (\'1\', \'2\'))'
           ], [
              'table', [
                 'field'  => new \QueryParam()
              ], [
                 'NOT' => ['id' => [new \QueryParam(), new \QueryParam()]]
              ],
              'UPDATE `table` SET `field` = ? WHERE  NOT (`id` IN (?, ?))',
              'UPDATE "table" SET "field" = ? WHERE  NOT ("id" IN (?, ?))'
           ], [
              'table', [
                 'field'  => new \QueryParam('field')
              ], [
                 'NOT' => ['id' => [new \QueryParam('idone'), new \QueryParam('idtwo')]]
              ],
              'UPDATE `table` SET `field` = :field WHERE  NOT (`id` IN (:idone, :idtwo))',
              'UPDATE "table" SET "field" = :field WHERE  NOT ("id" IN (:idone, :idtwo))'
           ], [
              'table', [
                 'field'  => new \QueryExpression(\DB::quoteName('field') . ' + 1')
              ], [
                 'id'  => [1, 2]
              ],
              'UPDATE `table` SET `field` = `field` + 1 WHERE `id` IN (\'1\', \'2\')',
              'UPDATE "table" SET "field" = "field" + 1 WHERE "id" IN (\'1\', \'2\')'
           ]
        ];
    }

    /**
     * @dataProvider dataUpdate
     */
    public function testBuildUpdate($table, $values, $where, $mysqlExpected, $pgsqlExpected)
    {
        global $DB;
        $expected = $DB->getProvider() === 'pgsql' ? $pgsqlExpected : $mysqlExpected;
        $this
          ->if($this->newTestedInstance)
          ->then
             ->string($this->testedInstance->buildUpdate($table, $values, $where))->isIdenticalTo($expected);
    }

    public function testBuildUpdateWException()
    {
        $this->exception(
            function () {
                $this
                   ->if($this->newTestedInstance)
                   ->then
                      ->string($this->testedInstance->buildUpdate('table', ['a' => 'b'], []))->isIdenticalTo('');
            }
        )->hasMessage('Cannot run an UPDATE query without WHERE clause!');
    }

    protected function dataDelete()
    {
        return [
           [
              'table', [
                 'id'  => 1
              ],
              'DELETE `table` FROM `table` WHERE `id` = \'1\'',
              'DELETE FROM "table" WHERE "id" = \'1\''
           ], [
              'table', [
                 'id'  => [1, 2]
              ],
              'DELETE `table` FROM `table` WHERE `id` IN (\'1\', \'2\')',
              'DELETE FROM "table" WHERE "id" IN (\'1\', \'2\')'
           ], [
              'table', [
                 'NOT'  => ['id' => [1, 2]]
              ],
              'DELETE `table` FROM `table` WHERE  NOT (`id` IN (\'1\', \'2\'))',
              'DELETE FROM "table" WHERE  NOT ("id" IN (\'1\', \'2\'))'
           ], [
              'table', [
                 'NOT'  => ['id' => [new \QueryParam(), new \QueryParam()]]
              ],
              'DELETE `table` FROM `table` WHERE  NOT (`id` IN (?, ?))',
              'DELETE FROM "table" WHERE  NOT ("id" IN (?, ?))'
           ], [
              'table', [
                 'NOT'  => ['id' => [new \QueryParam('idone'), new \QueryParam('idtwo')]]
              ],
              'DELETE `table` FROM `table` WHERE  NOT (`id` IN (:idone, :idtwo))',
              'DELETE FROM "table" WHERE  NOT ("id" IN (:idone, :idtwo))'
           ]
        ];
    }

    /**
     * @dataProvider dataDelete
     */
    public function testBuildDelete($table, $where, $mysqlExpected, $pgsqlExpected)
    {
        global $DB;
        $expected = $DB->getProvider() === 'pgsql' ? $pgsqlExpected : $mysqlExpected;
        $this
          ->if($this->newTestedInstance)
          ->then
             ->string($this->testedInstance->buildDelete($table, $where))->isIdenticalTo($expected);
    }

    public function testBuildDeleteWException()
    {
        global $DB;
        $expected = $DB->getProvider() === 'pgsql'
            ? 'Cannot run a DELETE query without WHERE clause!'
            : 'Cannot run an DELETE query without WHERE clause!';
        $this->exception(
            function () {
                $this
                   ->if($this->newTestedInstance)
                   ->then
                      ->string($this->testedInstance->buildDelete('table', []))->isIdenticalTo('');
            }
        )->hasMessage($expected);
    }

    public function testListTables()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->given($tables = $this->testedInstance->listTables())
              ->object($tables)
                 ->isInstanceOf(\DBmysqlIterator::class)
              ->integer(count($tables))
                 ->isGreaterThan(100)
              ->given($tables = $this->testedInstance->listTables('glpi_configs'))
              ->object($tables)
                 ->isInstanceOf(\DBmysqlIterator::class)
                 ->hasSize(1);

    }

    public function testTablesHasItemtype()
    {
        $dbu = new \DbUtils();
        $this->newTestedInstance();
        $list = $this->testedInstance->listTables();
        $this->object($list)->isInstanceOf(\DBmysqlIterator::class);
        $this->integer(count($list))->isGreaterThan(200);

        //check if each table has a corresponding itemtype
        while ($line = $list->next()) {
            $this->array($line)
               ->hasSize(1);
            $table = $line['TABLE_NAME'];
            if (in_array($table, ['glpi_planningexternaleventguests', 'glpi_networkportaggregateorigins', 'itsmng_migrations'])) {
                // Internal ORM memberships and the migration ledger have no standalone legacy model.
                continue;
            }
            if (in_array($table, ['glpi_appliancerelations', 'glpi_oidc_config', 'glpi_oidc_users', 'glpi_oidc_mapping'])) {
                //FIXME temporary hack for unit tests
                continue;
            }
            $type = $dbu->getItemTypeForTable($table);

            $this->string($type)->isNotEqualTo('UNKNOWN', 'Cannot find type for table ' . $table);
            $this->object($item = $dbu->getItemForItemtype($type))->isInstanceOf('CommonDBTM', $table);
            $this->string(get_class($item))->isIdenticalTo($type);
            $this->string($dbu->getTableForItemType($type))->isIdenticalTo($table);
        }
    }

    public function testEscape()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->escape('nothing to do'))->isIdenticalTo('nothing to do')
              ->string($this->testedInstance->escape("shoul'be escaped"))->isIdenticalTo("shoul\\'be escaped")
              ->string($this->testedInstance->escape("First\nSecond"))->isIdenticalTo("First\\nSecond")
              ->string($this->testedInstance->escape("First\rSecond"))->isIdenticalTo("First\\rSecond")
              ->string($this->testedInstance->escape('Hi, "you"'))->isIdenticalTo('Hi, \\"you\\"');
    }

    protected function commentsProvider()
    {
        return [
           [
              'sql' => "SQL EXPRESSION;
/* Here begins a
   multiline comment */
OTHER EXPRESSION;
",
              'expected'  => "SQL EXPRESSION;
OTHER EXPRESSION;"
           ]
        ];
    }

    /**
     * @dataProvider commentsProvider
     */
    public function testRemoveSqlComments($sql, $expected)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->removeSqlComments($sql))->isIdenticalTo($expected);
    }

    /**
     * Sql expressions provider
     */
    protected function sqlProvider()
    {
        return array_merge([
           [
              'sql'       => "SQL;\n-- comment;\n\nSQL2;",
              'expected'  => "SQL;\n\nSQL2;"
           ]
        ], $this->commentsProvider());
    }

    /**
     * @dataProvider sqlProvider
     */
    public function testRemoveSqlRemarks($sql, $expected)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->removeSqlRemarks($sql))->isIdenticalTo($expected);
    }
}
