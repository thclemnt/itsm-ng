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

use DbTestCase;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LineOperator as LegacyLineOperator;
use itsmng\Database\Entity\LineOperator as LineOperatorRecord;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordWriter;
use mock\DBAdapter as LineOperatorAdapterProbe;
use RuntimeException;
use Stringable;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class LineOperator extends DbTestCase
{
    private $method;

    public function beforeTestMethod($method)
    {
        parent::beforeTestMethod($method);
        //to handle GLPI barbarian replacements.
        $this->method = str_replace(
            ['\\', 'beforeTestMethod'],
            ['', $method],
            __METHOD__
        );
    }

    public function testAdd()
    {
        $this->login();
        $obj = new \LineOperator();

        // Add
        $in = [
              'name'                     => $this->method,
              'comment'                  => $this->getUniqueString(),
              'entities_id'              => getItemByTypeName('Entity', '_test_root_entity', true)
        ];
        $id = $obj->add($in);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        $this->variable($obj->getField('id'))->isEqualTo($id);
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isEqualTo($v);
        }
    }

    public function testUpdate()
    {
        $this->login();
        $obj = new \LineOperator();

        // Add
        $id = $obj->add([
              'name'                     => $this->getUniqueString(),
              'comment'                  => $this->getUniqueString(),
              'entities_id'              => getItemByTypeName('Entity', '_test_root_entity', true)
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Update
        $id = $obj->getID();
        $in = [
              'id'                       => $id,
              'name'                     => $this->method,
              'comment'                  => $this->getUniqueString(),
        ];
        $this->boolean($obj->update($in))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isEqualTo($v);
        }
    }

    public function testDelete()
    {
        $this->login();
        $obj = new \LineOperator();

        // Add
        $id = $obj->add([
              'name'                     => $this->method,
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Delete
        $in = [
              'id'                       => $obj->getID(),
        ];
        $this->boolean($obj->delete($in))->isTrue();
    }

    public function testCanonicalCodePairAndNullableUpdates()
    {
        $this->login();
        $code = 100000 + hexdec(substr(md5($this->getUniqueString()), 0, 6));
        $model = new LegacyLineOperator();
        $country = new class ($code) implements Stringable {
            public int $calls = 0;

            public function __construct(private int $code)
            {
            }

            public function __toString(): string
            {
                ++$this->calls;
                return '00' . $this->code;
            }
        };
        $id = $model->add(['name' => $this->getUniqueString(), 'mcc' => $country, 'mnc' => '20.0']);
        $this->integer($country->calls)->isIdenticalTo(1);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($model->getFromDB($id))->isTrue();
        $this->integer((int)$model->fields['mcc'])->isIdenticalTo($code);
        $this->integer((int)$model->fields['mnc'])->isIdenticalTo(20);
        $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null;
        try {
            $duplicate = new LegacyLineOperator();
            $this->variable($duplicate->add([
                'name' => $this->getUniqueString(),
                'entities_id' => getItemByTypeName('Entity', '_test_child_1', true),
                'mcc' => $code, 'mnc' => 20,
            ]))->isIdenticalTo(false);
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR])
                ->contains(__('Mobile country code and network code combination must be unique!'));
            $this->variable($model->prepareInputForAdd(['mcc' => ['>', 0], 'mnc' => 1]))->isIdenticalTo(false);
            $this->boolean($model->update(['id' => $id, 'mcc' => '1.000000000000000001']))->isFalse();
            $this->array($model->prepareInputForAdd(['mcc' => null, 'mnc' => $code]))
                ->isIdenticalTo(['mcc' => 0, 'mnc' => $code]);
            $nullable = new LegacyLineOperator();
            $nullableId = $nullable->add(['mcc' => 'nUlL', 'mnc' => $code]);
            $this->integer((int)$nullableId)->isGreaterThan(0);
            $this->boolean($nullable->getFromDB($nullableId))->isTrue();
            $this->variable($nullable->fields['mcc'])->isNull();
        } finally {
            if ($messages === null) {
                unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
            } else {
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
            }
        }
        $this->boolean($model->update(['id' => $id, 'name' => 'Partial code update']))->isTrue();
        $this->boolean($model->getFromDB($id))->isTrue();
        $this->integer((int)$model->fields['mcc'])->isIdenticalTo($code);
        $this->boolean($model->update(['id' => $id, 'mnc' => 'NuLl']))->isTrue();
        $this->boolean($model->getFromDB($id))->isTrue();
        $this->variable($model->fields['mnc'])->isNull();
        $this->exception(function () use ($id): void {
            (new RecordWriter(Orm::create($GLOBALS['DB'])))->update(
                'glpi_lineoperators',
                (int)$id,
                ['mcc' => '1.000000000000000001']
            );
        })->isInstanceOf(InvalidArgumentException::class);
        $this->boolean($model->getFromDB($id))->isTrue();
        $this->integer((int)$model->fields['mcc'])->isIdenticalTo($code);
        $this->array((new LineOperatorRecord())->normalizeInput(['name' => 'Only name']))
            ->isIdenticalTo(['name' => 'Only name']);
        $this->integer(LineOperatorRecord::normalizeCode(false))->isIdenticalTo(0);
        $this->integer(LineOperatorRecord::normalizeCode('-2147483648'))->isIdenticalTo(-2147483648);
        $this->variable(LineOperatorRecord::normalizeCode('2147483648'))->isIdenticalTo(false);
    }

    public function testFreshDuplicateReadRetainsLiveOwnersAndCapturedRoute()
    {
        global $DB;
        $this->login();
        $original = $DB;
        $connection = $original->getDoctrineConnection();
        $code = 100000 + hexdec(substr(md5($this->getUniqueString()), 0, 6));
        $model = new LegacyLineOperator();
        $id = (int)$model->add(['name' => $this->getUniqueString(), 'mcc' => $code, 'mnc' => 40]);
        $this->integer($id)->isGreaterThan(0);
        $writer = Orm::create($original);
        $live = $writer->find(LineOperatorRecord::class, $id);
        $live->mnc = 41;
        $connection->update('glpi_lineoperators', ['mnc' => 42], ['id' => $id], ['mnc' => Types::INTEGER]);
        $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null;
        try {
            $connection->withApplicationEntityManager(function ($outer) use ($id, $model, $code, $writer, $live): void {
                $retained = $outer->find(LineOperatorRecord::class, $id);
                $retained->mnc = 43;
                $this->variable($model->prepareInputForAdd(['mcc' => $code, 'mnc' => 42]))->isIdenticalTo(false);
                $this->array($model->prepareInputForAdd(['mcc' => $code, 'mnc' => 43]))->hasKey('mnc');
                $this->boolean($outer->contains($retained))->isTrue();
                $this->integer($retained->mnc)->isIdenticalTo(43);
                $this->boolean($writer->contains($live))->isTrue();
                $this->integer($live->mnc)->isIdenticalTo(41);
            });
            $probe = new ScalarReadProbe($connection);
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new LineOperatorAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $value = new class ($original, $code + 1) implements Stringable {
                public int $calls = 0;

                public function __construct(private object $database, private int $code)
                {
                }

                public function __toString(): string
                {
                    ++$this->calls;
                    $GLOBALS['DB'] = $this->database;
                    return (string)$this->code;
                }
            };
            $DB = $adapter;
            $prepared = $model->prepareInputForAdd(['mcc' => $value]);
            $this->integer($prepared['mcc'])->isIdenticalTo($code + 1);
            $this->integer($prepared['mnc'])->isIdenticalTo(0);
            $this->integer($value->calls)->isIdenticalTo(1);
            $this->array($probe->queries)->hasSize(1);
            $this->object($DB)->isIdenticalTo($original);
            $this->integer((int)$connection->fetchOne('SELECT mnc FROM glpi_lineoperators WHERE id=?', [$id]))->isIdenticalTo(42);
            $failure = new RuntimeException('Code callback failure');
            $throwing = new class ($failure) implements Stringable {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function __toString(): string
                {
                    throw $this->failure;
                }
            };
            $this->exception(function () use ($model, $throwing): void {
                $model->prepareInputForAdd(['mcc' => $throwing]);
            })->isIdenticalTo($failure);
        } finally {
            $DB = $original;
            if ($messages === null) {
                unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
            } else {
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
            }
        }
    }
}
