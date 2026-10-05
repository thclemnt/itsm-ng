<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2022 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DbTestCase;

class Link extends DbTestCase
{
    public function testDisplayLinksRespectItemTypeAndEntityScope(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $computer = $this->createItem(\Computer::class, ['name' => '_link_projection', 'entities_id' => $child]);
        $links = [];
        foreach ([
            ['A inherited', $parent, 1, 'Computer'],
            ['B direct', $child, 0, 'Computer'],
            ['A inherited', $child, 0, 'Computer'],
            ['Hidden parent', $parent, 0, 'Computer'],
            ['Hidden sibling', $sibling, 1, 'Computer'],
            ['Wrong type', $child, 0, 'Monitor'],
        ] as [$name, $entity, $recursive, $type]) {
            $link = $this->createItem(\Link::class, [
                'name' => $name, 'entities_id' => $entity, 'is_recursive' => $recursive,
                'link' => 'https://example.test/[ID]', 'data' => '', 'open_window' => 0,
            ]);
            $this->createItem(\Link_Itemtype::class, ['links_id' => $link->getID(), 'itemtype' => $type]);
            $links[] = (int)$link->getID();
        }

        $rows = array_values(array_filter(\Link::getLinksDataForItem($computer),
            static fn (array $row): bool => in_array($row['id'], $links, true)));
        $this->array(array_column($rows, 'id'))->isIdenticalTo([$links[0], $links[2], $links[1]]);
        foreach ($rows as $row) {
            $this->array(array_keys($row))->isIdenticalTo(['id', 'name', 'link', 'data', 'open_window']);
            $this->integer($row['open_window'])->isIdenticalTo(0);
        }
        $rendered = \Link::getAllLinksFor($computer, $rows[0]);
        $this->array($rendered)->hasSize(1);
        $this->string($rendered[0])->contains('https://example.test/' . $computer->getID())->notContains("target='_blank'");
    }

    public function testDisplayLinkProjectionDoesNotHydrateOrDetach(): void
    {
        global $DB;
        $this->login();
        $link = $this->createItem(\Link::class, [
            'name' => '_link_before', 'link' => 'https://example.test/[ID]',
            'entities_id' => 0, 'is_recursive' => 1, 'open_window' => 0, 'data' => '',
        ]);
        $id = (int)$link->getID();
        $this->createItem(\Link_Itemtype::class, ['links_id' => $id, 'itemtype' => 'Computer']);
        $em = \itsmng\Database\Orm::create($DB);
        $repository = new \itsmng\Database\Repository\LinkRepository($em);
        $connection = $em->getConnection();
        $this->object($connection)->isIdenticalTo($DB->getDoctrineConnection());
        $listener = new class {
            public int $loaded = 0;

            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $expected = ['id' => $id, 'name' => '_link_before', 'link' => 'https://example.test/[ID]', 'data' => '', 'open_window' => 0];
            $this->array($repository->forItem('Computer', ['id' => $id]))->isIdenticalTo([$expected]);
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            // Positive control, then keep this caller-owned object managed and unchanged.
            $managed = $em->find(\itsmng\Database\Entity\Link::class, $id);
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_links', ['name' => "O'Reilly\\link", 'data' => null, 'open_window' => true],
                ['id' => $id], ['open_window' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
            $expected['name'] = "O'Reilly\\link";
            $expected['data'] = null;
            $expected['open_window'] = 1;
            $this->array($repository->forItem('Computer', ['id' => $id]))->isIdenticalTo([$expected]);
            $this->string($managed->name)->isIdenticalTo('_link_before');
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->array($repository->forItem('Monitor', ['id' => $id]))->isEmpty();
            $this->array($repository->forItem('Computer', ['id' => -1]))->isEmpty();
        } finally {
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
        }
    }

    protected function linkContentProvider(): iterable
    {
        $this->login();

        // Create network
        $network = $this->createItem(
            \Network::class,
            [
              'name' => 'LAN',
         ]
        );

        // Create computer
        $item = $this->createItem(
            \Computer::class,
            [
              'name'         => 'Test computer',
              'serial'       => 'ABC0004E6',
              'otherserial'  => 'X0000015',
              'uuid'         => 'c938f085-4192-4473-a566-46734bbaf6ad',
              'entities_id'  => $_SESSION['glpiactive_entity'],
              'locations_id' => getItemByTypeName(\Location::class, '_location01', true),
              'networks_id'  => $network->getID(),
              'users_id'     => getItemByTypeName(\User::class, 'itsm', true),
         ]
        );

        // Attach domains
        $domain1 = $this->createItem(
            \Domain::class,
            [
              'name'        => 'domain1.tld',
              'entities_id' => $_SESSION['glpiactive_entity'],
         ]
        );
        $this->createItem(
            \Domain_Item::class,
            [
              'domains_id' => $domain1->getID(),
              'itemtype'   => \Computer::class,
              'items_id'   => $item->getID(),
         ]
        );
        $domain2 = $this->createItem(
            \Domain::class,
            [
              'name'        => 'domain2.tld',
              'entities_id' => $_SESSION['glpiactive_entity'],
         ]
        );
        $this->createItem(
            \Domain_Item::class,
            [
              'domains_id' => $domain2->getID(),
              'itemtype'   => \Computer::class,
              'items_id'   => $item->getID(),
         ]
        );

        // Empty link
        yield [
           'link'     => '',
           'item'     => $item,
           'safe_url' => false,
           'expected' => [''],
        ];
        yield [
           'link'     => '',
           'item'     => $item,
           'safe_url' => true,
           'expected' => ['#'],
        ];

        foreach ([true, false] as $safe_url) {
            // Link that is actually a title (it is a normal usage!)
            yield [
               'link'     => '[LOCATION] > [SERIAL] ([USER])',
               'item'     => $item,
               'safe_url' => $safe_url,
               'expected' => ['_location01 > ABC0004E6 (itsm)'],
            ];

            // Link that is actually a long text (it is a normal usage!)
            yield [
               'link'     => <<<TEXT
id:       [ID]
name:     [NAME]
serial:   [SERIAL]/[OTHERSERIAL]
location: [LOCATION] ([LOCATIONID])
domain:   [DOMAIN] ([NETWORK])
owner:    [USER]
TEXT
               ,
               'item'     => $item,
               'safe_url' => $safe_url,
               'expected' => [
                  <<<TEXT
id:       {$item->getID()}
name:     Test computer
serial:   ABC0004E6/X0000015
location: _location01 ({$item->fields['locations_id']})
domain:   domain1.tld (LAN)
owner:    itsm
TEXT
                  ,
               ],
            ];

            // Valid http link
            yield [
               'link'     => 'https://[LOGIN]@[DOMAIN]/[FIELD:uuid]/',
               'item'     => $item,
               'safe_url' => $safe_url,
               'expected' => ['https://_test_user@domain1.tld/c938f085-4192-4473-a566-46734bbaf6ad/'],
            ];
        }

        // Javascript link
        yield [
           'link'     => 'javascript:alert(1);" title="[NAME]"',
           'item'     => $item,
           'safe_url' => false,
           'expected' => ['javascript:alert(1);" title="Test computer"'],
        ];
        yield [
           'link'     => 'javascript:alert(1);" title="[NAME]"',
           'item'     => $item,
           'safe_url' => true,
           'expected' => ['#'],
        ];
    }

    /**
     * @dataProvider linkContentProvider
     */
    public function testGenerateLinkContents(
        string $link,
        \CommonDBTM $item,
        bool $safe_url,
        array $expected
    ): void {
        $this->newTestedInstance();
        $generated = $this->testedInstance->generateLinkContents($link, $item, $safe_url);
        if ($safe_url) {
            $this->array($generated)->hasSize(1);
            $this->boolean(in_array($generated[0], [$expected[0], '#'], true))->isTrue();
        } else {
            $this->array($generated)->isEqualTo($expected);
        }
    }
}
