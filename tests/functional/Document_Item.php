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
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Document;
use Document_Item as LegacyDocument_Item;
use Dropdown;
use Plugin;
use ReflectionProperty;
use Software;
use SoftwareLicense;
use Ticket as LegacyTicket;
use TypeError;
use itsmng\Database\Entity\Document as DocumentEntity;
use itsmng\Database\Entity\Software as SoftwareEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\SoftwareRepository;

/* Test for inc/document_item.class.php */

class Document_Item extends DbTestCase
{
    public function testLicenseLabelsUseScopedRowsAndCurrentSoftwareNames(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $reader = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $root = (int)$_SESSION['glpiactive_entity'];
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $document = $this->createItem(Document::class, ['name' => $this->getUniqueString(), 'entities_id' => $root, 'is_recursive' => true]);
            $software = [];
            foreach ([$root, $root, $child] as $entity) {
                $software[] = $this->createItem(Software::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            }
            $licenses = [];
            foreach ([0, 0, 1, 0, 2] as $index => $owner) {
                $license = $this->createItem(SoftwareLicense::class, ['name' => 'License ' . $index,
                    'softwares_id' => $software[$owner]->getID(), 'entities_id' => $software[$owner]->fields['entities_id'],
                    'serial' => 'Serial ' . $index, 'otherserial' => 'Inventory ' . $index]);
                $this->createItem(LegacyDocument_Item::class, ['documents_id' => $document->getID(),
                    'itemtype' => 'SoftwareLicense', 'items_id' => $license->getID()]);
                $licenses[] = $license;
            }
            $this->createItem(LegacyDocument_Item::class, ['documents_id' => $document->getID(),
                'itemtype' => 'SoftwareLicense', 'items_id' => $licenses[0]->getID(), 'timeline_position' => 1]);
            $this->boolean($DB->update('glpi_softwarelicenses', ['is_template' => true], ['id' => $licenses[3]->getID()]))->isTrue();
            // The owner label retains raw NULL names and does not add software visibility filters.
            $this->boolean($DB->update('glpi_softwares', ['name' => null, 'is_deleted' => true, 'is_template' => true], ['id' => $software[1]->getID()]))->isTrue();
            $this->setEntity($root, false);
            $selected = iterator_to_array(LegacyDocument_Item::getTypeItems($document->getID(), 'SoftwareLicense'), false);
            $this->array(array_map('intval', array_column($selected, 'id')))->isIdenticalTo(array_map(
                static fn (SoftwareLicense $license): int => (int)$license->getID(),
                [$licenses[0], $licenses[0], $licenses[1], $licenses[2]]
            ));
            $reader = new class ($DB->getDoctrineConnection(), Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform())) extends EntityManager {
                public int $queries = 0;
                public function createQuery(string $dql = ''): Query
                {
                    ++$this->queries;
                    return parent::createQuery($dql);
                }
            };
            $repository = new SoftwareRepository($reader);
            $this->array($repository->names([]))->isEmpty();
            $this->integer($reader->queries)->isIdenticalTo(0);
            $ids = array_map('intval', array_column($selected, 'softwares_id'));
            $names = $repository->names($ids);
            $this->integer($reader->queries)->isIdenticalTo(1);
            $this->array($names)->hasSize(2);
            $this->string($names[$ids[0]])->isIdenticalTo($software[0]->fields['name']);
            $this->variable($names[$ids[3]])->isNull();
            $this->array($reader->getUnitOfWork()->getIdentityMap())->isEmpty();
            $before = $reader->queries;
            $this->array($repository->names(array_merge($ids, range(-251, -1))))->isEqualTo($names);
            $this->integer($reader->queries - $before)->isIdenticalTo(2);
            $managed = $reader->find(SoftwareEntity::class, $ids[0]);
            $this->boolean($DB->update('glpi_softwares', ['name' => 'Current software'], ['id' => $ids[0]]))->isTrue();
            $this->string($repository->names([$ids[0]])[$ids[0]])->isIdenticalTo('Current software');
            $this->string($managed->name)->isIdenticalTo($software[0]->fields['name']);

            $_SESSION['glpiactiveprofile']['document'] = READ;
            $_SESSION['glpiactiveprofile']['license'] = READ;
            $_SESSION['glpiis_ids_visible'] = true;
            $render = function () use ($document): array {
                ob_start();
                try {
                    LegacyDocument_Item::showForDocument($document);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if ($html === '') {
                    return [];
                }
                $this->integer(preg_match('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $match))->isIdenticalTo(1);
                return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['dataSource']['rows'];
            };
            $rows = $render();
            $this->array($rows)->hasSize(4);
            foreach ($selected as $index => $row) {
                $name = sprintf(__('%1$s - %2$s'), $row['name'], $index < 3 ? 'Current software' : null);
                $label = sprintf(__('%1$s (%2$s)'), $name, $row['id']);
                $url = SoftwareLicense::getFormURLWithID($row['id']);
                $this->array($rows[$index])->isIdenticalTo([
                    SoftwareLicense::getTypeName(1), "<a href='$url'>$label </a>",
                    Dropdown::getDropdownName('glpi_entities', $root), $row['serial'], $row['otherserial'],
                ]);
            }
            $plugins->setValue(null, [...$active, 'document_name_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['document_name_fixture' => [Document::class =>
                static function () use ($DB, $ids): void {
                    $DB->update('glpi_softwares', ['name' => 'Permission callback name'], ['id' => $ids[0]]);
                }]];
            $this->string($render()[0][1])->contains('Permission callback name');
            $_SESSION['glpiactiveprofile']['license'] = 0;
            $this->array($render())->isEmpty();
            $_SESSION['glpiactiveprofile']['document'] = 0;
            $this->array($render())->isEmpty();
            $this->boolean($reader->contains($managed))->isTrue();
        } finally {
            $reader?->clear();
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testLinkedDocumentOptionsUseCurrentRowsAndReuseReadManager(): void
    {
        global $DB;
        require_once GLPI_ROOT . '/src/twig/twig.utils.php';
        $session = $_SESSION;
        $writer = null;
        $path = null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $ticket = $this->createItem(LegacyTicket::class, ['name' => $this->getUniqueString(),
                'content' => 'Linked document options', 'entities_id' => 0, '_disablenotif' => true]);
            $other = $this->createItem(LegacyTicket::class, ['name' => $this->getUniqueString(),
                'content' => 'Other document owner', 'entities_id' => 0, '_disablenotif' => true]);
            $path = tempnam(GLPI_DOC_DIR . '/_tmp', 'linked-options-');
            $this->string($path);
            $this->integer(file_put_contents($path, 'option bytes'))->isIdenticalTo(12);
            $relative = '_tmp/' . basename($path);
            $documents = [];
            foreach (['Before.txt', 'Second.txt'] as $filename) {
                $document = $this->createItem(Document::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
                $this->boolean($DB->update(
                    'glpi_documents',
                    ['filename' => $filename, 'filepath' => $relative],
                    ['id' => $document->getID()]
                ))->isTrue();
                $documents[] = $document;
            }
            $bindings = [];
            foreach ([0, 1, 0] as $index => $document) {
                $bindings[] = $this->createItem(
                    LegacyDocument_Item::class,
                    ['documents_id' => $documents[$document]->getID(), 'itemtype' => 'Ticket',
                        'items_id' => $ticket->getID(), 'timeline_position' => $index]
                );
            }
            $this->createItem(LegacyDocument_Item::class, ['documents_id' => $documents[0]->getID(),
                'itemtype' => 'Ticket', 'items_id' => $other->getID()]);
            $expected = static function (string $first) use ($bindings, $documents): array {
                $options = [];
                foreach ([0, 1, 0] as $index => $document) {
                    $filename = $document === 0 ? $first : 'Second.txt';
                    $options[(int)$bindings[$index]->getID()] = '<a href='
                        . Document::getFormURLWithID($documents[$document]->getID()) . '>'
                        . $filename . ' (12B)</a>';
                }
                return $options;
            };
            // Warm both the scalar binding read and subsequent model reads.
            $this->array(getLinkedDocumentsForItem('Ticket', $ticket->getID()))->isIdenticalTo($expected('Before.txt'));
            $this->array(getLinkedDocumentsForItem('UnsupportedFixtureKind', $ticket->getID()))->isEmpty();
            $this->array(getLinkedDocumentsForItem('Ticket', -1))->isEmpty();
            $writer = Orm::create($DB);
            $managed = $writer->find(DocumentEntity::class, (int)$documents[0]->getID());
            $this->string($managed->filename)->isIdenticalTo('Before.txt');
            $this->boolean($DB->update(
                'glpi_documents',
                ['filename' => 'Current.txt'],
                ['id' => $documents[0]->getID()]
            ))->isTrue();
            $creations = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $creations->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $this->array(getLinkedDocumentsForItem('Ticket', $ticket->getID()))->isIdenticalTo($expected('Current.txt'));
            }
            $canonical = null;
            $connection = $DB->getDoctrineConnection();
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$canonical): void {
                $canonical = $manager;
            });
            $kind = new class (function () use ($connection, $canonical): string {
                // String conversion completes before the helper activates its read scope.
                Orm::withConnection($connection, function (EntityManager $manager) use ($canonical): void {
                    $this->object($manager)->isIdenticalTo($canonical);
                });
                return 'Ticket';
            }) {
                public function __construct(private mixed $convert)
                {
                }
                public function __toString(): string
                {
                    return ($this->convert)();
                }
            };
            $this->array(getLinkedDocumentsForItem($kind, $ticket->getID()))->isIdenticalTo($expected('Current.txt'));
            $this->exception(static fn () => getLinkedDocumentsForItem([], $ticket->getID()))->isInstanceOf(TypeError::class);
            $this->integer($creations->getValue() - $before)->isIdenticalTo(
                0,
                'Repeated attachment options share the selected canonical read manager'
            );
            $this->boolean($writer->contains($managed))->isTrue();
            $this->string($managed->filename)->isIdenticalTo('Before.txt');
        } finally {
            $writer?->clear();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
            $_SESSION = $session;
        }
    }

    public function testGetForbiddenStandardMassiveAction()
    {
        $this->newTestedInstance();
        $this->array(
            $this->testedInstance->getForbiddenStandardMassiveAction()
        )->isIdenticalTo(['clone', 'update']);
    }

    public function testPrepareInputForAdd()
    {
        $input = [];
        $ditem = $this->newTestedInstance;
        $bindings = countElementsInTable(LegacyDocument_Item::getTable());

        $this->exception(
            function () use ($input) {
                $this->boolean($this->testedInstance->add($input))->isFalse();
            }
        )->message->contains('Item type is mandatory');

        $input['itemtype'] = '';
        $this->boolean($this->testedInstance->add($input))->isFalse();
        $this->exception(
            function () use ($input) {
                $this->testedInstance->prepareInputForAdd($input);
            }
        )->message->contains('Item type is mandatory');

        $input['itemtype'] = 'NotAClass';
        $this->boolean($this->testedInstance->add($input))->isFalse();
        $this->exception(
            function () use ($input) {
                $this->testedInstance->prepareInputForAdd($input);
            }
        )->message->contains('No class found for type NotAClass');

        $input['itemtype'] = 'Computer';
        $this->boolean($this->testedInstance->add($input))->isFalse();
        $this->exception(
            function () use ($input) {
                $this->testedInstance->prepareInputForAdd($input);
            }
        )->message->contains('Item ID is mandatory');

        $input['items_id'] = 0;
        $this->boolean($this->testedInstance->add($input))->isFalse();
        $this->exception(
            function () use ($input) {
                $this->testedInstance->prepareInputForAdd($input);
            }
        )->message->contains('Item ID is mandatory');
        $this->integer(countElementsInTable(LegacyDocument_Item::getTable()))->isIdenticalTo($bindings);

        $cid = getItemByTypeName('Computer', '_test_pc01', true);
        $input['items_id'] = $cid;

        $this->exception(
            function () use ($input) {
                $this->boolean($this->testedInstance->add($input))->isFalse();
            }
        )->message->contains('Document ID is mandatory');

        $input['documents_id'] = 0;
        $this->exception(
            function () use ($input) {
                $this->boolean($this->testedInstance->add($input))->isFalse();
            }
        )->message->contains('Document ID is mandatory');

        $document = new \Document();
        $this->integer(
            (int)$document->add([
              'name'   => 'Test document to link'
         ])
        )->isGreaterThan(0);
        $input['documents_id'] = $document->getID();

        $expected = [
           'itemtype'     => 'Computer',
           'items_id'     => $cid,
           'documents_id' => $document->getID(),
           'users_id'     => false,
           'entities_id'  => 0,
           'is_recursive' => 0
        ];

        $this->array(
            $this->testedInstance->prepareInputForAdd($input)
        )->isIdenticalTo($expected);
    }


    public function testGetDistinctTypesParams()
    {
        $expected = [
           'SELECT'          => 'itemtype',
           'DISTINCT'        => true,
           'FROM'            => 'glpi_documents_items',
           'WHERE'           => [
              'OR'  => [
                 'glpi_documents_items.documents_id'  => 1,
                 [
                    'glpi_documents_items.itemtype'  => 'Document',
                    'glpi_documents_items.items_id'  => 1
                 ]
              ]
           ],
           'ORDER'           => 'itemtype'
        ];
        $this->array(\Document_Item::getDistinctTypesParams(1))->isIdenticalTo($expected);

        $extra_where = ['date_mod' => ['>', '2000-01-01']];
        $expected = [
           'SELECT'          => 'itemtype',
           'DISTINCT'        => true,
           'FROM'            => 'glpi_documents_items',
           'WHERE'           => [
              'OR'  => [
                 'glpi_documents_items.documents_id'  => 1,
                 [
                    'glpi_documents_items.itemtype'  => 'Document',
                    'glpi_documents_items.items_id'  => 1
                 ]
              ],
              [
                 'date_mod'  => [
                    '>',
                    '2000-01-01'
                 ]
              ]
           ],
           'ORDER'           => 'itemtype'
        ];
        $this->array(\Document_Item::getDistinctTypesParams(1, $extra_where))->isIdenticalTo($expected);
    }


    public function testPostAddItem()
    {
        $uid = getItemByTypeName('User', TU_USER, true);

        $ticket = new \Ticket();
        $tickets_id = $ticket->add([
           'name' => '',
           'content' => 'Test modification date not updated from Document_Item',
           'date_mod' => '2020-01-01'
        ]);

        $this->integer($tickets_id)->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $initial_date_mod = $ticket->fields['date_mod'];

        // Document and Document_Item
        $doc = new \Document();
        $this->integer(
            (int)$doc->add([
              'users_id'     => $uid,
              'tickets_id'   => $tickets_id,
              'name'         => 'A simple document object'
         ])
        )->isGreaterThan(0);

        //do not change ticket modification date
        $doc_item = new \Document_Item();
        $this->integer(
            (int)$doc_item->add([
              'users_id'      => $uid,
              'items_id'      => $tickets_id,
              'itemtype'      => 'Ticket',
              'documents_id'  => $doc->getID(),
              '_do_update_ticket' => false
         ])
        )->isGreaterThan(0);

        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->string($ticket->fields['date_mod'])->isIdenticalTo($initial_date_mod);

        //do change ticket modification date
        $_SESSION["glpi_currenttime"] = '2021-01-01 00:00:01';
        $doc = new \Document();
        $this->integer(
            (int)$doc->add([
              'users_id'     => $uid,
              'tickets_id'   => $tickets_id,
              'name'         => 'A simple document object'
         ])
        )->isGreaterThan(0);

        $doc_item = new \Document_Item();
        $this->integer(
            (int)$doc_item->add([
              'users_id'      => $uid,
              'items_id'      => $tickets_id,
              'itemtype'      => 'Ticket',
              'documents_id'  => $doc->getID(),
         ])
        )->isGreaterThan(0);

        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->string($ticket->fields['date_mod'])->isNotEqualTo('2021-01-01 00:00:01');

    }
}
