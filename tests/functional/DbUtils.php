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

/* Test for inc/dbutils.class.php */

class DbUtils extends DbTestCase
{
    protected $cached_methods = [
       'testGetAncestorsOfCached',
       'testGetSonsOfCached'
    ];

    public function setUp()
    {
        global $CFG_GLPI;

        // Clean the cache
        unset($CFG_GLPI['glpiitemtypetables']);
        unset($CFG_GLPI['glpitablesitemtype']);
    }

    public function testGetUserNamePreservesModesAndCurrentValues(): void
    {
        global $DB;
        $this->login();
        $_SESSION['glpinames_format'] = \User::FIRSTNAME_BEFORE;
        $_SESSION['glpiis_ids_visible'] = 0;
        $user = new \User();
        $login = 'display-' . bin2hex(random_bytes(6));
        $id = (int)$user->add(['name' => $login, 'firstname' => 'Ada', 'realname' => 'Lovelace']);
        $this->integer($id)->isGreaterThan(0);
        $utils = new \DbUtils();
        $this->string($utils->getUserName($id))->isEqualTo('Ada Lovelace');
        $this->string($utils->getUserName($id, 1))->contains('Ada Lovelace')
            ->contains(\User::getFormURLWithID($id));
        $details = $utils->getUserName($id, 2);
        $this->string($details['name'])->isEqualTo('Ada Lovelace');
        $this->string($details['link'])->isEqualTo(\User::getFormURLWithID($id));
        $this->string($details['comment'])->contains($login);

        // These writes are still inside the fixture transaction on the supplied connection.
        $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
        $this->boolean($DB->update('glpi_users', ['firstname' => 'Grace'], ['id' => $id]))->isTrue();
        $this->string($utils->getUserName($id))->isEqualTo('Grace Lovelace');
        $this->boolean($DB->update('glpi_users', ['realname' => null, 'firstname' => null], ['id' => $id]))->isTrue();
        $this->string($utils->getUserName($id))->isEqualTo($login);
        $this->boolean($DB->update('glpi_users', ['is_deleted' => 1, 'is_active' => 0], ['id' => $id]))->isTrue();
        $this->string($utils->getUserName($id))->isEqualTo($login);

        foreach ([0, -1] as $missing) {
            $this->string($utils->getUserName($missing))->isEmpty();
            $this->string($utils->getUserName($missing, 1))->isEmpty();
            $this->array($utils->getUserName($missing, 2))->isEqualTo(['name' => '', 'comment' => '', 'link' => '']);
        }
    }

    public function testGetUserNameKeepsTooltipRelationsAndLoginVisibility(): void
    {
        global $DB;
        $this->login();
        $_SESSION['glpinames_format'] = \User::FIRSTNAME_BEFORE;
        $_SESSION['glpiis_ids_visible'] = 0;
        $suffix = bin2hex(random_bytes(6));
        $location = (int)(new \Location())->add(['name' => 'Office-' . $suffix, 'entities_id' => 0]);
        $title = (int)(new \UserTitle())->add(['name' => 'Title-' . $suffix]);
        $category = (int)(new \UserCategory())->add(['name' => 'Category-' . $suffix]);
        foreach ([$location, $title, $category] as $reference) {
            $this->integer($reference)->isGreaterThan(0);
        }
        $login = 'tooltip-' . $suffix;
        $user = new \User();
        $id = (int)$user->add([
            'name' => $login, 'firstname' => 'Grace', 'realname' => 'Hopper',
            'phone' => '0123456', 'mobile' => '0789012',
            'locations_id' => $location, 'usertitles_id' => $title, 'usercategories_id' => $category,
            '_useremails' => ['display@example.test'],
        ]);
        $this->integer($id)->isGreaterThan(0);
        $this->boolean($DB->update('glpi_users', ['picture' => 'display.png'], ['id' => $id]))->isTrue();
        $utils = new \DbUtils();
        $details = $utils->getUserName($id, 2);
        foreach ([$login, '0123456', '0789012', 'Office-' . $suffix, 'Title-' . $suffix, 'Category-' . $suffix, 'display@example.test', \User::getThumbnailURLForPicture('display.png')] as $value) {
            $this->string($details['comment'])->contains($value);
        }
        $this->string($details['name'])->isEqualTo('Grace Hopper');
        $_SESSION['glpiactiveprofile']['user'] = 0;
        $restricted = $utils->getUserName($id, 2);
        $this->string($restricted['comment'])->notContains($login)->contains('display@example.test');
        $this->string($restricted['name'])->isEqualTo('Grace Hopper');
        $this->string($restricted['link'])->isEqualTo(\User::getFormURLWithID($id));
    }

    protected function dataTableKey()
    {

        return [
           ['foo', ''],
           ['glpi_computers', 'computers_id'],
           ['glpi_users', 'users_id'],
           ['glpi_plugin_foo_bars', 'plugin_foo_bars_id'],
           ['glpi_plugin_fooglpis', 'plugin_fooglpis_id']
        ];
    }

    protected function dataTableForeignKey()
    {

        return [
           ['glpi_computers', 'computers_id'],
           ['glpi_users', 'users_id'],
           ['glpi_plugin_foo_bars', 'plugin_foo_bars_id']
        ];
    }

    /**
     * @dataProvider dataTableKey
    **/
    public function testGetForeignKeyFieldForTable($table, $key)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->getForeignKeyFieldForTable($table))
              ->isIdenticalTo($key);

        //keep testing old method from db.function
        $this->string(getForeignKeyFieldForTable($table))->isIdenticalTo($key);
    }

    /**
     * @dataProvider dataTableForeignKey
    **/
    public function testIsForeignKeyFieldBase($table, $key)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->boolean($this->testedInstance->isForeignKeyField($key))->isTrue();

        //keep testing old method from db.function
        $this->boolean(isForeignKeyField($key))->isTrue();
    }

    public function testIsForeignKeyFieldMore()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->boolean($this->testedInstance->isForeignKeyField('FakeId'))->isFalse()
              ->boolean($this->testedInstance->isForeignKeyField('id_Another_Fake_Id'))->isFalse()
              ->boolean($this->testedInstance->isForeignKeyField('users_id_tech'))->isTrue()
              ->boolean($this->testedInstance->isForeignKeyField('_id'))->isFalse();

        //keep testing old method from db.function
        $this->boolean(isForeignKeyField('FakeId'))->isFalse();
        $this->boolean(isForeignKeyField('id_Another_Fake_Id'))->isFalse();
        $this->boolean(isForeignKeyField('users_id_tech'))->isTrue();
        $this->boolean(isForeignKeyField('_id'))->isFalse();
    }


    /**
     * @dataProvider dataTableForeignKey
    **/
    public function testGetTableNameForForeignKeyField($table, $key)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
           ->string($this->testedInstance->getTableNameForForeignKeyField($key))->isIdenticalTo($table);

        //keep testing old method from db.function
        $this->string(getTableNameForForeignKeyField($key))->isIdenticalTo($table);
    }

    protected function dataTableType()
    {
        // Pseudo plugin class for test
        require_once __DIR__ . '/../fixtures/pluginbarabstractstuff.php';
        require_once __DIR__ . '/../fixtures/pluginbarfoo.php';
        require_once __DIR__ . '/../fixtures/pluginfoobar.php';
        require_once __DIR__ . '/../fixtures/pluginfooservice.php';

        return [
           ['glpi_dbmysqls', 'DBmysql', false], // not a CommonGLPI, should not be valid
           ['glpi_computers', 'Computer', true],
           ['glpi_events', 'Glpi\Event', true],
           ['glpi_users', 'User', true],
           ['glpi_users', 'User', true],
           ['glpi_plugin_bar_foos', 'GlpiPlugin\Bar\Foo', true],
           ['glpi_plugin_baz_foos', 'GlpiPlugin\Baz\Foo', false], // class not exists
           ['glpi_plugin_foo_bars', 'PluginFooBar', true],
           ['glpi_plugin_foo_bazs', 'PluginFooBaz', false], // class not exists
           ['glpi_plugin_foo_services', 'PluginFooService', false], // not a CommonGLPI should not be valid
        ];
    }

    /**
     * @dataProvider dataTableType
    **/
    public function testGetTableForItemType($table, $type, $is_valid_type)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
           ->string($this->testedInstance->getTableForItemType($type))->isIdenticalTo($table);

        //keep testing old method from db.function
        $this->string(getTableForItemType($type))->isIdenticalTo($table);
    }

    /**
     * @dataProvider dataTableType
    **/
    public function testGetItemTypeForTable($table, $type, $is_valid_type)
    {
        if ($is_valid_type) {
            $this
               ->if($this->newTestedInstance)
               ->then
                  ->string($this->testedInstance->getItemTypeForTable($table))->isIdenticalTo($type);
        } else {
            $this
               ->if($this->newTestedInstance)
               ->then
                  ->string($this->testedInstance->getItemTypeForTable($table))->isIdenticalTo('UNKNOWN');
        }

        //keep testing old method from db.function
        if ($is_valid_type) {
            $this->string(getItemTypeForTable($table))->isIdenticalTo($type);
        } else {
            $this->string(getItemTypeForTable($table))->isIdenticalTo('UNKNOWN');
        }
    }

    /**
     * @dataProvider dataTableType
    **/
    public function testGetItemForItemtype($table, $itemtype, $is_valid_type)
    {
        if ($is_valid_type) {
            $this
               ->if($this->newTestedInstance)
               ->then
                  ->object($this->testedInstance->getItemForItemtype($itemtype))->isInstanceOf($itemtype);
        } else {
            $this
               ->if($this->newTestedInstance)
               ->then
                  ->boolean($this->testedInstance->getItemForItemtype($itemtype))->isFalse();
        }

        //keep testing old method from db.function
        if ($is_valid_type) {
            $this->object(getItemForItemtype($itemtype))
               ->isInstanceOf($itemtype);
        } else {
            $this->boolean(getItemForItemtype($itemtype))->isFalse();
        }
    }

    public function testGetItemForItemtypeSanitized()
    {
        require_once __DIR__ . '/../fixtures/pluginbarfoo.php';

        $this
           ->if($this->newTestedInstance)
           ->then
              ->object($this->testedInstance->getItemForItemtype(addslashes('Glpi\Event')))->isInstanceOf('Glpi\Event')
              ->object($this->testedInstance->getItemForItemtype(addslashes('GlpiPlugin\Bar\Foo')))->isInstanceOf('GlpiPlugin\Bar\Foo');
    }

    public function testGetItemForItemtypeAbstract()
    {
        require_once __DIR__ . '/../fixtures/pluginbarabstractstuff.php';

        $this
           ->if($this->newTestedInstance)
           ->when(
               function () {
                   $this->boolean($this->testedInstance->getItemForItemtype('CommonDevice'))->isFalse();
               }
           )->error
              ->withType(E_USER_WARNING)
              ->withMessage('Cannot instanciate "CommonDevice" as it is an abstract class.')
              ->exists()
           ->when(
               function () {
                   $this->boolean($this->testedInstance->getItemForItemtype('GlpiPlugin\Bar\AbstractStuff'))->isFalse();
               }
           )->error
              ->withType(E_USER_WARNING)
              ->withMessage('Cannot instanciate "GlpiPlugin\Bar\AbstractStuff" as it is an abstract class.')
              ->exists();
    }

    public function testGetItemForItemtypeHavingConstructorWithMandatoryParameters()
    {
        require_once __DIR__ . '/../fixtures/pluginbarsomething.php';

        $this
           ->if($this->newTestedInstance)
           ->when(
               function () {
                   $this->boolean($this->testedInstance->getItemForItemtype('GlpiPlugin\Bar\Something'))->isFalse();
               }
           )->error
              ->withType(E_USER_WARNING)
              ->withMessage('Cannot instanciate "GlpiPlugin\Bar\Something" as its constructor has non optionnal parameters.')
              ->exists();
    }

    public function dataPlural()
    {

        return [
           ['model', 'models'],
           ['address', 'addresses'],
           ['computer', 'computers'],
           ['thing', 'things'],
           ['criteria', 'criterias'],
           ['version', 'versions'],
           ['config', 'configs'],
           ['machine', 'machines'],
           ['memory', 'memories'],
           ['licence', 'licences'],
           ['pdu', 'pdus']
        ];
    }

    /**
     * @dataProvider dataPlural
     */
    public function testGetPlural($singular, $plural)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->getPlural($singular))->isIdenticalTo($plural)
              ->string(
                  $this->testedInstance->getPlural(
                      $this->testedInstance->getPlural(
                          $singular
                      )
                  )
              )->isIdenticalTo($plural);

        //keep testing old method from db.function
        $this->string(getPlural($singular))->isIdenticalTo($plural);
        $this->string(getPlural(getPlural($singular)))->isIdenticalTo($plural);
    }

    /**
     * @dataProvider dataPlural
    **/
    public function testGetSingular($singular, $plural)
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->getSingular($plural))->isIdenticalTo($singular)
              ->string(
                  $this->testedInstance->getSingular(
                      $this->testedInstance->getSingular(
                          $plural
                      )
                  )
              )->isIdenticalTo($singular);

        //keep testing old method from db.function
        $this->string(getSingular($plural))->isIdenticalTo($singular);
        $this->string(getSingular(getSingular($plural)))->isIdenticalTo($singular);
    }


    public function testCountElementsInTable()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->integer($this->testedInstance->countElementsInTable('glpi_configs'))->isGreaterThan(100)
              ->integer($this->testedInstance->countElementsInTable(['glpi_configs', 'glpi_users']))->isGreaterThan(100)
              ->integer($this->testedInstance->countElementsInTable('glpi_configs', ['context' => 'core']))->isGreaterThan(100)
              ->integer($this->testedInstance->countElementsInTable('glpi_configs', ['context' => 'core', 'name' => 'version']))->isIdenticalTo(1)
              ->integer($this->testedInstance->countElementsInTable('glpi_configs', ['context' => 'fakecontext']))->isIdenticalTo(0);

        //keep testing old method from db.function
        //the case of using an element that is not a table is not handle in the function :
        $this->integer(countElementsInTable('glpi_configs'))->isGreaterThan(100);
        $this->integer(countElementsInTable(['glpi_configs', 'glpi_users']))->isGreaterThan(100);
        $this->integer(countElementsInTable('glpi_configs', ['context' => 'core', 'name' => 'version']))->isIdenticalTo(1);
        $this->integer(countElementsInTable('glpi_configs', ['context' => 'core']))->isGreaterThan(100);
        $this->integer(countElementsInTable('glpi_configs', ['context' => 'fakecontext']))->isIdenticalTo(0);
    }


    public function testCountDistinctElementsInTable()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_configs', 'id'))->isGreaterThan(0)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_configs', 'context'))->isGreaterThan(0)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_tickets', 'entities_id'))->isIdenticalTo(2)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_crontasks', 'itemtype', ['frequency' => '86400']))->isIdenticalTo(16)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_crontasks', 'id', ['frequency' => '86400']))->isIdenticalTo(19)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_configs', 'context', ['name' => 'version']))->isIdenticalTo(1)
              ->integer($this->testedInstance->countDistinctElementsInTable('glpi_configs', 'id', ['context' => 'fakecontext']))->isIdenticalTo(0);

        //keep testing old method from db.function
        //the case of using an element that is not a table is not handle in the function :
        //testCountElementsInTable($table, $condition="")
        $this->integer(countDistinctElementsInTable('glpi_configs', 'id'))->isGreaterThan(0);
        $this->integer(countDistinctElementsInTable('glpi_configs', 'context'))->isGreaterThan(0);
        $this->integer(
            countDistinctElementsInTable(
                'glpi_configs',
                'context',
                ['name' => 'version']
            )
        )->isIdenticalTo(1);
        $this->integer(
            countDistinctElementsInTable(
                'glpi_configs',
                'id',
                ['context' => 'fakecontext']
            )
        )->isIdenticalTo(0);
    }

    protected function dataCountMyEntities()
    {
        return [
           ['_test_root_entity', true, 'glpi_computers', [], 8],
           ['_test_root_entity', true, 'glpi_computers', ['name' => '_test_pc11'], 1],
           ['_test_root_entity', true, 'glpi_computers', ['name' => '_test_pc01'], 1],

           ['_test_root_entity', false, 'glpi_computers', [], 3],
           ['_test_root_entity', false, 'glpi_computers', ['name' => '_test_pc11'], 0],
           ['_test_root_entity', false, 'glpi_computers', ['name' => '_test_pc01'], 1],

           ['_test_child_1', false, 'glpi_computers', [], 3],
           ['_test_child_1', false, 'glpi_computers', ['name' => '_test_pc11'], 1],
           ['_test_child_1', false, 'glpi_computers', ['name' => '_test_pc01'], 0],
        ];
    }

    /**
     * @dataProvider dataCountMyEntities
     */
    public function testCountElementsInTableForMyEntities(
        $entity,
        $recursive,
        $table,
        $condition,
        $count
    ) {
        $this->login();
        $this->setEntity($entity, $recursive);

        $this
           ->if($this->newTestedInstance)
           ->then
              ->integer($this->testedInstance->countElementsInTableForMyEntities($table, $condition))->isIdenticalTo($count);

        //keep testing old method from db.function
        $this->integer(countElementsInTableForMyEntities($table, $condition))->isIdenticalTo($count);
    }

    protected function dataCountEntities()
    {
        return [
           ['_test_root_entity', 'glpi_computers', [], 3],
           ['_test_root_entity', 'glpi_computers', ['name' => '_test_pc11'], 0],
           ['_test_root_entity', 'glpi_computers', ['name' => '_test_pc01'], 1],

           ['_test_child_1', 'glpi_computers', [], 3],
           ['_test_child_1', 'glpi_computers', ['name' => '_test_pc11'], 1],
           ['_test_child_1', 'glpi_computers', ['name' => '_test_pc01'], 0],
        ];
    }


    /**
     * @dataProvider dataCountEntities
     */
    public function testCountElementsInTableForEntity(
        $entity,
        $table,
        $condition,
        $count
    ) {
        $eid = getItemByTypeName('Entity', $entity, true);

        $this
           ->if($this->newTestedInstance)
           ->then
              ->integer($this->testedInstance->countElementsInTableForEntity($table, $eid, $condition))->isIdenticalTo($count);

        //keep testing old method from db.function
        $this->integer(countElementsInTableForEntity($table, $eid, $condition))->isIdenticalTo($count);
    }

    public function testGetAllDatasFromTable()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->array($data = $this->testedInstance->getAllDataFromTable('glpi_configs'))
                 ->size->isGreaterThan(100);

        foreach ($data as $key => $array) {
            $this->array($array)
               ->variable['id']->isEqualTo($key);
        }

        $this
           ->if($this->newTestedInstance)
           ->then
              ->array($this->testedInstance->getAllDataFromTable('glpi_configs', ['context' => 'core', 'name' => 'version']))->hasSize(1)
              ->array($data = $this->testedInstance->getAllDataFromTable('glpi_configs', ['ORDER' => 'name']))->isNotEmpty();
        $previousArrayName = "";
        foreach ($data as $key => $array) {
            $this->boolean($previousArrayName <= $previousArrayName = $array['name'])->isTrue();
        }

        //TODO: test with cache === true

        //keep testing old method from db.function
        $data = getAllDataFromTable('glpi_configs');
        $this->array($data)
           ->size->isGreaterThan(100);
        foreach ($data as $key => $array) {
            $this->array($array)
               ->variable['id']->isEqualTo($key);
        }

        $data = getAllDataFromTable('glpi_configs', ['context' => 'core', 'name' => 'version']);
        $this->array($data)->hasSize(1);

        $data = getAllDataFromTable('glpi_configs', ['ORDER' => 'name']);
        $this->array($data)->isNotEmpty();
        $previousArrayName = "";
        foreach ($data as $key => $array) {
            $this->boolean($previousArrayName <= $previousArrayName = $array['name'])->isTrue();
        }
    }

    public function testIsIndex()
    {
        global $DB;
        // PostgreSQL index names occupy a schema-wide namespace.
        $locationIndex = $DB->getProvider() === 'pgsql' ? 'glpi_users_locations_id' : 'locations_id';
        $loginIndex = $DB->getProvider() === 'pgsql' ? 'glpi_users_unicityloginauth' : 'unicityloginauth';
        $this
           ->if($this->newTestedInstance)
           ->then
              ->boolean($this->testedInstance->isIndex('glpi_configs', 'fakeField'))->isFalse()
              ->boolean($this->testedInstance->isIndex('glpi_configs', 'name'))->isFalse()
              ->boolean($this->testedInstance->isIndex('glpi_configs', 'value'))->isFalse()
              ->boolean($this->testedInstance->isIndex('glpi_users', $locationIndex))->isTrue()
              ->boolean($this->testedInstance->isIndex('glpi_users', $loginIndex))->isTrue()
           ->when(
               function () {
                   $this->boolean($this->testedInstance->isIndex('fakeTable', 'id'))->isFalse();
               }
           )->error
              ->withType(E_USER_WARNING)
              ->exists();

        $this->boolean($this->testedInstance->isIndex('glpi_users', strtoupper($loginIndex)))->isFalse();

        //keep testing old method from db.function
        $this->boolean(isIndex('glpi_configs', 'fakeField'))->isFalse();
        $this->boolean(isIndex('glpi_configs', 'name'))->isFalse();
        $this->boolean(isIndex('glpi_users', $locationIndex))->isTrue();
        $this->boolean(isIndex('glpi_users', $loginIndex))->isTrue();

        $this->when(
            function () {
                $this->boolean(isIndex('fakeTable', 'id'))->isFalse();
            }
        )->error
           ->withType(E_USER_WARNING)
           ->exists();

    }

    public function testGetEntityRestrict()
    {
        global $DB;
        // Independent expected SQL differs only in the provider identifier delimiter.
        $expectedSql = static fn (string $sql): string => $DB->getProvider() === 'pgsql'
            ? str_replace('`', '"', $sql) : $sql;
        $this->login();
        $this->newTestedInstance();

        // See all, really all
        $_SESSION['glpishowallentities'] = 1; // will be restored by setEntity call

        $this->string($this->testedInstance->getEntitiesRestrictRequest('AND', 'glpi_computers'))->isEmpty();

        $it = new \DBmysqlIterator(null);

        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers`'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('AND', 'glpi_computers'))->isEmpty();
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers`'));

        // See all
        $this->setEntity('_test_root_entity', true);

        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('1', '2', '3')  ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE `glpi_computers`.`entities_id` IN (\'1\', \'2\', \'3\')'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('1', '2', '3')  ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`glpi_computers`.`entities_id` IN (\'1\', \'2\', \'3\'))'));

        // Root entity
        $this->setEntity('_test_root_entity', false);

        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('1')  ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE `glpi_computers`.`entities_id` IN (\'1\')'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('1')  ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`glpi_computers`.`entities_id` IN (\'1\'))'));

        // Child
        $this->setEntity('_test_child_1', false);

        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('2')  ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE `glpi_computers`.`entities_id` IN (\'2\')'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE', 'glpi_computers'))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('2')  ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`glpi_computers`.`entities_id` IN (\'2\'))'));

        // Child without table
        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE'))
           ->isIdenticalTo($expectedSql("WHERE ( `entities_id` IN ('2')  ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria());
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE `entities_id` IN (\'2\')'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE'))
           ->isIdenticalTo($expectedSql("WHERE ( `entities_id` IN ('2')  ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria());
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`entities_id` IN (\'2\'))'));

        // Child + parent
        $this->setEntity('_test_child_2', false);

        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE', 'glpi_computers', '', '', true))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('3')  OR (`glpi_computers`.`is_recursive`='1' AND `glpi_computers`.`entities_id` IN (0, 1)) ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`glpi_computers`.`entities_id` IN (\'3\') OR (`glpi_computers`.`is_recursive` = \'1\' AND `glpi_computers`.`entities_id` IN (\'0\', \'1\')))'));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE', 'glpi_computers', '', '', true))
           ->isIdenticalTo($expectedSql("WHERE ( `glpi_computers`.`entities_id` IN ('3')  OR (`glpi_computers`.`is_recursive`='1' AND `glpi_computers`.`entities_id` IN (0, 1)) ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE ((`glpi_computers`.`entities_id` IN (\'3\') OR (`glpi_computers`.`is_recursive` = \'1\' AND `glpi_computers`.`entities_id` IN (\'0\', \'1\'))))'));

        //Child + parent on glpi_entities
        $it->execute('glpi_entities', $this->testedInstance->getEntitiesRestrictCriteria('glpi_entities', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE (`glpi_entities`.`id` IN (\'3\', \'0\', \'1\'))'));

        //keep testing old method from db.function
        $it->execute('glpi_entities', getEntitiesRestrictCriteria('glpi_entities', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE ((`glpi_entities`.`id` IN (\'3\', \'0\', \'1\')))'));

        //Child + parent -- automatic recusrivity detection
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('glpi_computers', '', '', 'auto'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`glpi_computers`.`entities_id` IN (\'3\') OR (`glpi_computers`.`is_recursive` = \'1\' AND `glpi_computers`.`entities_id` IN (\'0\', \'1\')))'));

        //keep testing old method from db.function
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('glpi_computers', '', '', 'auto'));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE ((`glpi_computers`.`entities_id` IN (\'3\') OR (`glpi_computers`.`is_recursive` = \'1\' AND `glpi_computers`.`entities_id` IN (\'0\', \'1\'))))'));

        // Child + parent without table
        $this->string($this->testedInstance->getEntitiesRestrictRequest('WHERE', '', '', '', true))
           ->isIdenticalTo($expectedSql("WHERE ( `entities_id` IN ('3')  OR (`is_recursive`='1' AND `entities_id` IN (0, 1)) ) "));
        $it->execute('glpi_computers', $this->testedInstance->getEntitiesRestrictCriteria('', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE (`entities_id` IN (\'3\') OR (`is_recursive` = \'1\' AND `entities_id` IN (\'0\', \'1\')))'));

        $it->execute('glpi_entities', $this->testedInstance->getEntitiesRestrictCriteria('glpi_entities', '', 3, true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE (`glpi_entities`.`id` IN (\'3\', \'0\', \'1\'))'));

        $it->execute('glpi_entities', $this->testedInstance->getEntitiesRestrictCriteria('glpi_entities', '', 7, true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE `glpi_entities`.`id` = \'7\''));

        //keep testing old method from db.function
        $this->string(getEntitiesRestrictRequest('WHERE', '', '', '', true))
           ->isIdenticalTo($expectedSql("WHERE ( `entities_id` IN ('3')  OR (`is_recursive`='1' AND `entities_id` IN (0, 1)) ) "));
        $it->execute('glpi_computers', getEntitiesRestrictCriteria('', '', '', true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_computers` WHERE ((`entities_id` IN (\'3\') OR (`is_recursive` = \'1\' AND `entities_id` IN (\'0\', \'1\'))))'));

        $it->execute('glpi_entities', getEntitiesRestrictCriteria('glpi_entities', '', 3, true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE ((`glpi_entities`.`id` IN (\'3\', \'0\', \'1\')))'));

        $it->execute('glpi_entities', getEntitiesRestrictCriteria('glpi_entities', '', 7, true));
        $this->string($it->getSql())
           ->isIdenticalTo($expectedSql('SELECT * FROM `glpi_entities` WHERE (`glpi_entities`.`id` = \'7\')'));
    }

    /**
     * Run getAncestorsOf tests
     *
     * @param boolean $cache Is cache enabled?
     *
     * @return void
     */
    private function runGetAncestorsOf($cache = false)
    {
        global $DB, $GLPI_CACHE;

        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        // The caller owns an uncommitted tree; cache-enabled reads must not publish it.

        $ckey_ent0 = 'ancestors_cache_glpi_entities_' . $ent0;
        $ckey_ent1 = 'ancestors_cache_glpi_entities_' . $ent1;
        $ckey_ent2 = 'ancestors_cache_glpi_entities_' . $ent2;

        $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();

        //test on ent0
        $expected = [0 => 0];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent0, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent0))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent0))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', $ent0);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent0))->isFalse();
        }

        //test on ent1
        $expected = [0 => 0, 1 => $ent0];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent1, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent1))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', $ent1);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        //test on ent2
        $expected = [0 => 0, 1 => $ent0];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent2, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent2))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent2))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', $ent2);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent2))->isFalse();
        }

        //test with new sub entity
        //Cache tests:
        // Public creation in this caller frame must not publish tentative edges.
        $new_id = getItemByTypeName('Entity', 'Sub child entity', true);
        if (!$new_id) {
            $entity = new \Entity();
            $new_id = (int)$entity->add([
               'name'         => 'Sub child entity',
               'entities_id'  => $ent1
            ]);
            $this->integer($new_id)->isGreaterThan(0);
        }
        $ckey_new_id = 'ancestors_cache_glpi_entities_' . $new_id;

        $expected = [0 => 0, $ent0 => $ent0, $ent1 => $ent1];
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_new_id))->isFalse();
        }

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_new_id, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_new_id))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_new_id))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', $new_id);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_new_id))->isFalse();
        }

        //test with another new sub entity
        $new_id2 = getItemByTypeName('Entity', 'Sub child entity 2', true);
        if (!$new_id2) {
            $entity = new \Entity();
            $new_id2 = (int)$entity->add([
               'name'         => 'Sub child entity 2',
               'entities_id'  => $ent2
            ]);
            $this->integer($new_id2)->isGreaterThan(0);
        }
        $ckey_new_id2 = 'ancestors_cache_glpi_entities_' . $new_id2;

        $expected = [0 => 0, $ent0 => $ent0, $ent2 => $ent2];
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_new_id2))->isFalse();
        }

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_new_id2, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_new_id2))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_new_id2))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', $new_id2);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_new_id2))->isFalse();
        }

        //test on multiple entities
        $expected = [0 => 0, $ent0 => $ent0, $ent1 => $ent1, $ent2 => $ent2];
        $ckey_new_all = 'ancestors_cache_glpi_entities_' . md5($new_id . '|' . $new_id2);

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_new_all, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_new_all))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_new_all))->isIdenticalTo([$ent2 => $ent2]);
        }
        $ancestors = getAncestorsOf('glpi_entities', [$new_id, $new_id2]);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_new_all))->isFalse();
        }
    }

    public function testGetAncestorsOf()
    {
        global $DB;
        $this->login();
        //ensure db cache is unset
        $DB->update('glpi_entities', ['ancestors_cache' => null], [true]);
        $this->runGetAncestorsOf();

        $this->integer(
            countElementsInTable(
                'glpi_entities',
                [
                 'NOT' => ['ancestors_cache' => null]]
            )
        )->isIdenticalTo(0);
        // Repeated private reads still must not publish a durable cache.
        $this->runGetAncestorsOf();
    }

    /**
     * @extensions apcu
     */
    public function testGetAncestorsOfCached()
    {
        $this->login();

        global $GLPI_CACHE;
        $GLPI_CACHE->clear(); // Keep the controlled external cache fixture initially empty.

        //run with cache
        // Cold and repeated reads both remain private to the caller frame.
        $this->runGetAncestorsOf(true);
        // Repeated private reads still must not publish shared cache.
        $this->runGetAncestorsOf(true);
    }


    /**
     * Run getSonsOf tests
     *
     * @param boolean $cache Is cache enabled?
     *
     * @return void
     */
    private function runGetSonsOf($cache = false)
    {
        global $DB, $GLPI_CACHE;

        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);
        $this->newTestedInstance();

        // The caller owns an uncommitted tree; cache-enabled reads must not publish it.

        $ckey_ent0 = 'sons_cache_glpi_entities_' . $ent0;
        $ckey_ent1 = 'sons_cache_glpi_entities_' . $ent1;
        $ckey_ent2 = 'sons_cache_glpi_entities_' . $ent2;

        $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();

        //test on ent0
        $expected = [$ent0 => $ent0, $ent1 => $ent1, $ent2 => $ent2];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent0, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent0))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent0))->isIdenticalTo([$ent2 => $ent2]);
        }
        $sons = $this->testedInstance->getSonsOf('glpi_entities', $ent0);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent0))->isFalse();
        }

        //test on ent1
        $expected = [$ent1 => $ent1];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent1, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent1))->isIdenticalTo([$ent2 => $ent2]);
        }
        $sons = $this->testedInstance->getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        //test on ent2
        $expected = [$ent2 => $ent2];

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent2, [$ent0 => $ent0]);
            $this->boolean($GLPI_CACHE->has($ckey_ent2))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent2))->isIdenticalTo([$ent0 => $ent0]);
        }
        $sons = $this->testedInstance->getSonsOf('glpi_entities', $ent2);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent2))->isFalse();
        }

        //test with new sub entity
        //Cache tests:
        // Public creation in this caller frame must not publish tentative edges.
        $new_id = getItemByTypeName('Entity', 'Sub child entity', true);
        if (!$new_id) {
            $entity = new \Entity();
            $new_id = (int)$entity->add([
               'name'         => 'Sub child entity',
               'entities_id'  => $ent1
            ]);
            $this->integer($new_id)->isGreaterThan(0);
        }

        $expected = [$ent1 => $ent1, $new_id => $new_id];
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent1, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent1))->isIdenticalTo([$ent2 => $ent2]);
        }
        $sons = $this->testedInstance->getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        //test with another new sub entity
        $new_id2 = getItemByTypeName('Entity', 'Sub child entity 2', true);
        if (!$new_id2) {
            $entity = new \Entity();
            $new_id2 = (int)$entity->add([
               'name'         => 'Sub child entity 2',
               'entities_id'  => $ent1
            ]);
            $this->integer($new_id2)->isGreaterThan(0);
        }

        $expected = [$ent1 => $ent1, $new_id => $new_id, $new_id2 => $new_id2];
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        if ($cache === true) {
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($ckey_ent1, [$ent2 => $ent2]);
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isTrue();
            $this->array($GLPI_CACHE->get($ckey_ent1))->isIdenticalTo([$ent2 => $ent2]);
        }
        $sons = $this->testedInstance->getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        //drop sub entity
        $expected = [$ent1 => $ent1, $new_id2 => $new_id2];
        $this->boolean($entity->delete(['id' => $new_id], true))->isTrue();
        $this->array($this->testedInstance->getSonsOf('glpi_entities', $ent1))->isIdenticalTo($expected);
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }

        $expected = [$ent1 => $ent1];
        $this->boolean($entity->delete(['id' => $new_id2], true))->isTrue();
        $this->array($this->testedInstance->getSonsOf('glpi_entities', $ent1))->isIdenticalTo($expected);
        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ckey_ent1))->isFalse();
        }
    }

    public function testGetSonsOf()
    {
        global $DB;
        $this->login();
        //ensure db cache is unset
        $DB->update('glpi_entities', ['sons_cache' => null], [true]);
        $this->runGetSonsOf();

        $this->integer(
            $this->testedInstance->countElementsInTable(
                'glpi_entities',
                [
                 'NOT' => ['sons_cache' => null]
            ]
            )
        )->isIdenticalTo(0);
        // Repeated private reads still must not publish a durable cache.
        $this->runGetSonsOf();
    }

    /**
     * @extensions apcu
     */
    public function testGetSonsOfCached()
    {
        $this->login();

        global $GLPI_CACHE;
        $GLPI_CACHE->clear(); // Keep the controlled external cache fixture initially empty.

        //run with cache
        // Cold and repeated reads both remain private to the caller frame.
        $this->runGetSonsOf(true);
        // Repeated private reads still must not publish shared cache.
        $this->runGetSonsOf(true);
    }

    /**
     * Validates that relation mapping is based on existing tables and fields.
     */
    public function testRelationsValidity()
    {

        global $DB;

        $this
           ->if($this->newTestedInstance)
           ->then
           ->array($mapping = $this->testedInstance->getDbRelations())
           ->hasKey('_virtual_device');

        $virtual_mapping = $mapping['_virtual_device'];
        unset($mapping['_virtual_device']);

        foreach ($mapping as $tablename => $relations) {
            $this->boolean($DB->tableExists($tablename))
               ->isTrue(sprintf('Invalid table "%s" in relation mapping.', $tablename));

            foreach ($relations as $relation_tablename => $fields) {
                if (strpos((string) $relation_tablename, '_') === 0) {
                    $relation_tablename = substr((string) $relation_tablename, 1);
                }

                $this->boolean($DB->tableExists($relation_tablename))
                   ->isTrue(sprintf('Invalid table "%s" in relation mapping.', $relation_tablename));

                if (!is_array($fields)) {
                    $fields = [$fields];
                }

                foreach ($fields as $field) {
                    $this->boolean($DB->fieldExists($relation_tablename, $field))
                       ->isTrue(sprintf('Invalid table field "%s.%s" in relation mapping.', $relation_tablename, $field));
                }
            }
        }

        foreach ($virtual_mapping as $tablename => $fields) {
            $this->boolean($DB->tableExists($tablename))
               ->isTrue(sprintf('Invalid table "%s" in _virtual_device mapping.', $tablename));

            foreach ($fields as $field) {
                $this->boolean($DB->fieldExists($tablename, $field))
                   ->isTrue(sprintf('Invalid table field "%s.%s" in _virtual_device mapping.', $tablename, $field));
            }
        }
    }

    /**
     * Test getDateCriteria
     *
     * @return void
     */
    public function testGetDateCriteria()
    {
        global $DB;
        $expectedEnd = $DB->getProvider() === 'pgsql'
            ? "(CAST('2018-11-09' AS timestamp with time zone) + (1 || ' DAY')::interval)"
            : "DATE_ADD('2018-11-09', INTERVAL 1 DAY)";
        $this->newTestedInstance();

        $this->array(
            $this->testedInstance->getDateCriteria('date', null, null)
        )->isIdenticalTo([]);

        $this->array(
            $this->testedInstance->getDateCriteria('date', '2018-11-09', null)
        )->isIdenticalTo([
           ['date' => ['>=', '2018-11-09']]
        ]);

        $result = $this->testedInstance->getDateCriteria('date', null, '2018-11-09');
        $this->array($result)->hasSize(1);

        $this->array($result[0]['date'])
           ->hasSize(2)
           ->string[0]->isIdenticalTo('<=')
           ->object[1]->isInstanceOf('\QueryExpression');

        $this->string(
            $result[0]['date'][1]->getValue()
        )->isIdenticalTo($expectedEnd);

        $nativeEnd = $DB->getDoctrineConnection()->fetchOne('SELECT ' . $result[0]['date'][1]->getValue());
        $this->string(substr((string)$nativeEnd, 0, 10))->isIdenticalTo('2018-11-10');

        $result = $this->testedInstance->getDateCriteria('date', '2018-11-08', '2018-11-09');
        $this->array($result)->hasSize(2);

        $this->array($result[0])->isIdenticalTo(['date' => ['>=', '2018-11-08']]);
        $this->array($result[1]['date'])
           ->hasSize(2)
           ->string[0]->isIdenticalTo('<=')
           ->object[1]->isInstanceOf('\QueryExpression');

        $this->string(
            $result[1]['date'][1]->getValue()
        )->isIdenticalTo($expectedEnd);
    }

    protected function autoNameProvider()
    {
        return [
           //will return name without changes
           [
              //not a template
              'name'         => 'Computer 1',
              'field'        => 'name',
              'is_template'  => false,
              'itemtype'     => 'Computer',
              'entities_id'  => -1, //default
              'expected'     => 'Computer 1'
           ], [
              //not a template
              'name'         => '&lt;abc&gt;',
              'field'        => 'name',
              'is_template'  => false,
              'itemtype'     => 'Computer',
              'entities_id'  => -1, // default
              'expected'     => '&lt;abc&gt;'
           ], [
              //does not match pattern
              'name'         => '&lt;abc&gt;',
              'field'        => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => -1, // default
              'expected'     => '&lt;abc&gt;'
           ], [
              //first added
              'name'         => '&lt;####&gt;',
              'field'       => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => -1, // default
              'expected'     => '0001'
           ], [
              //existing
              'name'         => '&lt;_test_pc##&gt;',
              'field'       => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => -1, // default
              'expected'     => '_test_pc23'
           ], [
              //not existing on entity
              'name'         => '&lt;_test_pc##&gt;',
              'field'       => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => 0,
              'expected'     => '_test_pc01'
           ], [
              //existing on entity
              'name'         => '&lt;_test_pc##&gt;',
              'field'       => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => 1,
              'expected'     => '_test_pc04'
           ], [
              //existing on entity
              'name'         => '&lt;_test_pc##&gt;',
              'field'       => 'name',
              'is_template'  => true,
              'itemtype'     => 'Computer',
              'entities_id'  => 2,
              'expected'     => '_test_pc14'
           ]
        ];
    }

    /**
     * @dataProvider autoNameProvider
     */
    public function testAutoName($name, $field, $is_template, $itemtype, $entities_id, $expected)
    {
        $this
           ->if($this->newTestedInstance)
              ->then
              ->string(
                  $autoname = $this->testedInstance->autoName(
                      $name,
                      $field,
                      $is_template,
                      $itemtype,
                      $entities_id
                  )
              )->isIdenticalTo($expected);
    }

    protected function autoNameNumericPrefixProvider(): array
    {
        // Independent native MariaDB unsigned-conversion values, including both
        // overflow limits. Public masks stop at ten characters, but the DQL
        // expression must not silently implement a different wider conversion.
        return [
            ['', '0'], ['0000', '0'], ['12x3', '12'], ['x123', '0'],
            [' 123', '123'], ['+123', '123'], ['-123', '18446744073709551493'],
            ['1.5', '1'], ['1e2', '1'], ['12345678901234567890', '12345678901234567890'],
            ['18446744073709551615', '18446744073709551615'],
            ['18446744073709551616', '18446744073709551615'],
            ['-18446744073709551616', '9223372036854775808'],
            ['٠١٢٣', '0'], ['１２３４', '0'], ['12_3', '12'], ["\t123", '123'],
        ];
    }

    /** @dataProvider autoNameNumericPrefixProvider */
    public function testAutoNameNumericPrefix(string $value, string $expected): void
    {
        global $DB;
        $em = \itsmng\Database\Orm::create($DB);
        $record = new \itsmng\Database\Entity\Computer();
        $record->entities = $em->getReference(\itsmng\Database\Entity\Entity::class, 0);
        $record->name = 'Number conversion ' . $this->getUniqueString();
        $record->serial = $value;
        $em->persist($record);
        $em->flush();
        $query = $em->createQuery('SELECT AUTO_NAME_NUMBER(c.serial) FROM ' . $record::class . ' c WHERE c.id = :id')
            ->setParameter('id', $record->id, \Doctrine\DBAL\Types\Types::BIGINT);
        $this->string((string)$query->getSingleScalarResult())->isIdenticalTo($expected);

        // Exercise public generation, not only the custom numeric function.
        // Fresh unique prefixes isolate every provider row in the shared frame.
        $prefix = 'Number-' . $this->getUniqueString() . '-';
        $record->name = $prefix . $value;
        $em->flush();
        // A long prefix and a different extraction width expose reversed
        // placeholder mappings and PostgreSQL's overloaded SUBSTRING resolution.
        $substring = $em->createQuery('SELECT AUTO_NAME_NUMBER(c.name, :position, :width) FROM '
            . $record::class . ' c WHERE c.id = :id')
            ->setParameter('position', \Toolbox::strlen($prefix) + 1, \Doctrine\DBAL\Types\Types::INTEGER)
            ->setParameter('width', 24, \Doctrine\DBAL\Types\Types::INTEGER)
            ->setParameter('id', $record->id, \Doctrine\DBAL\Types\Types::BIGINT);
        $this->string((string)$substring->getSingleScalarResult())->isIdenticalTo($expected);
        if (\Toolbox::strlen($value) === 4) {
            $next = str_pad((string)($expected + 1), 4, '0', STR_PAD_LEFT);
            $this->string((new \DbUtils())->autoName('&lt;' . $prefix . '####&gt;', 'name', true, 'Computer'))
                ->isIdenticalTo($prefix . $next);
        }
        $record->serial = null;
        $em->flush();
        $this->variable($query->getSingleScalarResult())->isNull();
    }

    public function testAutoNameScopesPatternsAndFinancialNumbers(): void
    {
        global $DB, $CFG_GLPI;
        $savedConfiguration = $CFG_GLPI['use_autoname_by_entity'];
        $em = \itsmng\Database\Orm::create($DB);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $otherEntity = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $utils = new \DbUtils();
        // Quotes, multibyte characters, wildcard literals and the explicit LIKE
        // escape must all retain their character positions and literal meaning.
        $prefix = "É_case_\\'%!" . $this->getUniqueString() . '-';
        $asset = static function (string $class, ?string $name, int $scope, bool $deleted = false, bool $template = false) use ($em): object {
            $record = new $class();
            $record->entities = $em->getReference(\itsmng\Database\Entity\Entity::class, $scope);
            $record->name = $name;
            $record->is_deleted = $deleted;
            $record->is_template = $template;
            $em->persist($record);
            return $record;
        };
        try {
            $CFG_GLPI['use_autoname_by_entity'] = 1;
            $computer = $asset(\itsmng\Database\Entity\Computer::class, $prefix . '0007', $entity);
            $otherComputer = $asset(\itsmng\Database\Entity\Computer::class, $prefix . '0099', $otherEntity);
            $asset(\itsmng\Database\Entity\Computer::class, $prefix . '0999', $entity, true);
            $asset(\itsmng\Database\Entity\Computer::class, $prefix . '9999', $entity, false, true);
            $asset(\itsmng\Database\Entity\Computer::class, null, $entity);
            $asset(\itsmng\Database\Entity\Computer::class, str_replace('_', 'x', $prefix) . '0888', $entity);
            $asset(\itsmng\Database\Entity\Computer::class, str_replace('%', 'x', $prefix) . '0777', $entity);
            // An ASCII case change retains native case-insensitive matching.
            $asset(\itsmng\Database\Entity\Monitor::class, str_replace('a', 'A', $prefix) . '0011', $entity);
            $asset(\itsmng\Database\Entity\Printer::class, $prefix . '0012', $entity);
            $em->flush();
            $mask = '&lt;' . $prefix . '####&gt;';
            $this->string($utils->autoName($mask, 'name', true, 'Computer', $entity))->isIdenticalTo($prefix . '0008');
            $this->string($utils->autoName($mask, 'name', true, 'Computer', $otherEntity))->isIdenticalTo($prefix . '0100');
            $this->string($utils->autoName($mask, 'name', true, 'Computer', 0))->isIdenticalTo($prefix . '0001');
            $this->string($utils->autoName($mask, 'name', true, 'Computer', -1))->isIdenticalTo($prefix . '0100');
            $this->string($utils->autoName('&lt;\\g' . $prefix . '####&gt;', 'name', true, 'Computer', $entity))
                ->isIdenticalTo($prefix . '0013');
            $this->string(\autoName($mask, 'name', true, 'Computer', $entity))->isIdenticalTo($prefix . '0008');
            $CFG_GLPI['use_autoname_by_entity'] = 0;
            $this->string($utils->autoName($mask, 'name', true, 'Computer', $entity))->isIdenticalTo($prefix . '0100');
            $CFG_GLPI['use_autoname_by_entity'] = 1;

            // Run the explicitly unmapped plugin DBAL reader against this real
            // physical asset fixture; no plugin DDL belongs in DbTestCase.
            $numbers = new \itsmng\Database\Repository\AutoNameRepository($em);
            $pattern = strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '____';
            $this->string($numbers->pluginAssetMaximum('glpi_computers', 'name', $pattern, \Toolbox::strlen($prefix) + 1, 4, $entity))
                ->isIdenticalTo('7');
            $this->string($numbers->pluginAssetMaximum('glpi_computers', 'name', $pattern, \Toolbox::strlen($prefix) + 1, 4, null))
                ->isIdenticalTo('99');

            $financial = new \itsmng\Database\Entity\Infocom();
            $financial->entities = $em->getReference(\itsmng\Database\Entity\Entity::class, $otherEntity);
            $financial->itemtype = 'Computer';
            $financial->items_id = $otherComputer->id;
            $financial->immo_number = $prefix . '0042';
            $em->persist($financial);
            $em->flush();
            $this->string($utils->autoName($mask, 'immo_number', true, 'Infocom', $entity))->isIdenticalTo($prefix . '0043');
            $this->string($utils->autoName('&lt;\\g' . $prefix . '####&gt;', 'immo_number', true, 'Infocom', $entity))
                ->isIdenticalTo($prefix . '0043');
            // Previewing a number never reserves it or mutates any owned record.
            $this->string($utils->autoName($mask, 'name', true, 'Computer', $entity))->isIdenticalTo($prefix . '0008');
            $this->string($computer->name)->isIdenticalTo($prefix . '0007');
            $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
        } finally {
            $CFG_GLPI['use_autoname_by_entity'] = $savedConfiguration;
        }
    }
}
