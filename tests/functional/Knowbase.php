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

namespace test\units;

use DbTestCase;

/* Test for inc/knowbase.class.php */

class Knowbase extends DbTestCase
{
    public function testCategoryTreeProjectionPreservesVisibilityTranslationsAndCurrentReads(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $config = $CFG_GLPI;
        try {
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $author = (int)getItemByTypeName('User', 'itsm', true);
            $this->integer($entity)->isGreaterThan(0);
            $this->integer($author)->isGreaterThan(0);
            $this->boolean($author !== (int)\Session::getLoginUserID())->isTrue();
            $this->boolean(in_array(0, $_SESSION['glpiactiveentities'], false))->isFalse();
            $parent = $this->createItem(\KnowbaseItemCategory::class, ['name' => 'Projected parent']);
            $children = [];
            foreach (['Alpha child', 'Beta fallback', 'Hidden child'] as $name) {
                $children[] = $this->createItem(\KnowbaseItemCategory::class, [
                    'name' => $name, 'knowbaseitemcategories_id' => $parent->getID(),
                ]);
            }
            foreach ($children as $index => $child) {
                $article = $this->createItem(\KnowbaseItem::class, [
                    'name' => 'Tree article ' . $index, 'users_id' => $author,
                    'knowbaseitemcategories_id' => $child->getID(), 'is_faq' => 0,
                ]);
                $this->createItem(\Entity_KnowbaseItem::class, [
                    'knowbaseitems_id' => $article->getID(), 'entities_id' => $index === 2 ? 0 : $entity,
                    'is_recursive' => 0,
                ]);
            }
            $connection = $DB->getDoctrineConnection();
            foreach ([[$parent->getID(), 'fr_FR', 'Translated parent'],
                [$children[0]->getID(), 'fr_FR', 'Zulu translated child'],
                [$children[1]->getID(), 'de_DE', 'Wrong language']] as [$id, $language, $value]) {
                $connection->insert('glpi_dropdowntranslations', [
                    'items_id' => $id, 'itemtype' => 'KnowbaseItemCategory',
                    'field' => 'name', 'language' => $language, 'value' => $value,
                ]);
            }
            $_SESSION['glpiactiveprofile']['knowbase'] = READ;
            $_SESSION['glpilanguage'] = 'fr_FR';
            $_SESSION['glpi_dropdowntranslations']['KnowbaseItemCategory']['name'] = 'name';
            $CFG_GLPI['translate_dropdowns'] = 1;
            $owned = array_map(static fn ($item): string => (string)$item->getID(), [$parent, ...$children]);
            $nodes = static function () use ($owned): array {
                return array_values(array_filter(
                    \Knowbase::getJstreeCategoryList(),
                    static fn (array $node): bool => in_array($node['id'], $owned, true)
                ));
            };
            $tree = $nodes();
            $this->array(array_column($tree, 'id'))->isIdenticalTo([
                (string)$children[0]->getID(), (string)$children[1]->getID(), (string)$parent->getID(),
            ]);
            $this->string($tree[0]['text'])->contains('Zulu translated child')->contains('(1)');
            $this->string($tree[1]['text'])->contains('Beta fallback')->notContains('Wrong language');
            $this->string($tree[2]['text'])->isIdenticalTo('Translated parent');
            $this->string($tree[0]['parent'])->isIdenticalTo((string)$parent->getID());
            $this->string($tree[2]['parent'])->isIdenticalTo('0');
            $root = array_values(array_filter(
                \Knowbase::getJstreeCategoryList(),
                static fn (array $node): bool => $node['id'] === '0'
            ));
            $this->array($root)->hasSize(1);
            $this->string($root[0]['parent'])->isIdenticalTo('#');

            // A translated label does not change ordering by the original category name.
            // Each existing translation gate independently retains untranslated labels.
            $CFG_GLPI['translate_dropdowns'] = 0;
            $this->string($nodes()[0]['text'])->contains('Alpha child')->notContains('Zulu');
            $CFG_GLPI['translate_dropdowns'] = 1;
            unset($_SESSION['glpi_dropdowntranslations']['KnowbaseItemCategory']['name']);
            $this->string($nodes()[0]['text'])->contains('Alpha child')->notContains('Zulu');
            $_SESSION['glpi_dropdowntranslations']['KnowbaseItemCategory']['name'] = 'name';

            $manager = \itsmng\Database\Orm::create($DB);
            $repository = new \itsmng\Database\Repository\KnowledgeBaseRepository($manager);
            $loads = new class () {
                public int $count = 0;
                public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof \itsmng\Database\Entity\KnowbaseItemCategory
                        || $event->getObject() instanceof \itsmng\Database\Entity\DropdownTranslation) {
                        ++$this->count;
                    }
                }
            };
            $manager->getEventManager()->addEventListener(['postLoad'], $loads);
            $access = \itsmng\Database\KnowledgeBaseAccess::current();
            $rows = array_column($repository->categoryTree($access, 'fr_FR')['categories'], null, 'id');
            $this->integer($rows[$children[0]->getID()]['knowbaseitemcategories_id'])->isIdenticalTo((int)$parent->getID());
            $this->variable($rows[$parent->getID()]['knowbaseitemcategories_id'])->isNull();
            $this->integer($rows[$children[0]->getID()]['items_count'])->isIdenticalTo(1);
            $this->integer($rows[$children[2]->getID()]['items_count'])->isIdenticalTo(0);
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $managed = $manager->find(\itsmng\Database\Entity\KnowbaseItemCategory::class, (int)$children[0]->getID());
            $this->integer($loads->count)->isIdenticalTo(1, 'The observer detects a real category load');
            $this->boolean($DB->update(
                'glpi_knowbaseitemcategories',
                ['name' => 'Current alpha'],
                ['id' => $children[0]->getID()]
            ))->isTrue();
            foreach ([null, '', '0'] as $emptyTranslation) {
                $connection->update('glpi_dropdowntranslations', ['value' => $emptyTranslation], [
                    'items_id' => $children[0]->getID(), 'itemtype' => 'KnowbaseItemCategory',
                    'field' => 'name', 'language' => 'fr_FR',
                ]);
                $current = array_column($repository->categoryTree($access, 'fr_FR')['categories'], null, 'id');
                $this->string($current[$children[0]->getID()]['name'])->isIdenticalTo('Current alpha');
            }
            $this->string($managed->name)->isIdenticalTo('Alpha child');
            $this->integer($loads->count)->isIdenticalTo(1);
            $this->boolean($manager->contains($managed))->isTrue();
            $manager->clear();
        } finally {
            $_SESSION = $session;
            $CFG_GLPI = $config;
        }
    }

    public function testGetJstreeCategoryList()
    {

        // Create empty categories
        $kbcat = new \KnowbaseItemCategory();

        $cat_1_id = $kbcat->add(
            [
              'name' => 'cat 1',
              'knowbaseitemcategories_id' => 0,
              'level' => 1,
         ]
        );
        $this->integer($cat_1_id)->isGreaterThan(0);

        $cat_1_1_id = $kbcat->add(
            [
              'name' => 'cat 1.1',
              'knowbaseitemcategories_id' => $cat_1_id,
              'level' => 2,
         ]
        );
        $this->integer($cat_1_1_id)->isGreaterThan(0);

        $cat_1_1_1_id = $kbcat->add(
            [
              'name' => 'cat 1.1.1',
              'knowbaseitemcategories_id' => $cat_1_1_id,
              'level' => 3,
         ]
        );
        $this->integer($cat_1_1_1_id)->isGreaterThan(0);

        $cat_1_1_2_id = $kbcat->add(
            [
              'name' => 'cat 1.1.2',
              'knowbaseitemcategories_id' => $cat_1_1_id,
              'level' => 3,
         ]
        );
        $this->integer($cat_1_1_2_id)->isGreaterThan(0);

        $cat_1_2_id = $kbcat->add(
            [
              'name' => 'cat 1.2',
              'knowbaseitemcategories_id' => $cat_1_id,
              'level' => 2,
         ]
        );
        $this->integer($cat_1_2_id)->isGreaterThan(0);

        $cat_1_2_1_id = $kbcat->add(
            [
              'name' => 'cat 1.2.1',
              'knowbaseitemcategories_id' => $cat_1_2_id,
              'level' => 3,
         ]
        );
        $this->integer($cat_1_2_1_id)->isGreaterThan(0);

        $cat_1_3_id = $kbcat->add(
            [
              'name' => 'cat 1.3',
              'knowbaseitemcategories_id' => $cat_1_id,
              'level' => 2,
         ]
        );
        $this->integer($cat_1_3_id)->isGreaterThan(0);

        // Returned tree should only containes root, as categories does not contains any elements
        $this->login('normal', 'normal');

        // Expected root category item for normal user
        $expected_root_cat = [
           'id' => '0',
           'parent' => '#',
           'text' => 'Root category <strong title="This category contains articles">(2)</strong>',
           'a_attr' => ['data-id' => '0']
        ];

        $tree = \Knowbase::getJstreeCategoryList();
        $this->array($tree)->isEqualTo([$expected_root_cat]);

        // Add a private item (not FAQ)
        $kbitem = new \KnowbaseItem();
        $kbitem_id = $kbitem->add(
            [
              'knowbaseitemcategories_id' => $cat_1_1_2_id,
              'users_id' => \Session::getLoginUserID(),
         ]
        );
        $this->integer($kbitem_id)->isGreaterThan(0);

        $kbitem_target = new \Entity_KnowbaseItem();
        $kbitem_target_id = $kbitem_target->add(
            [
              'knowbaseitems_id' => $kbitem_id,
              'entities_id' => 0,
              'is_recursive' => 1,
         ]
        );
        $this->integer($kbitem_target_id)->isGreaterThan(0);

        // Check that tree contains root + category branch containing kb item of user
        $tree = \Knowbase::getJstreeCategoryList();
        $this->array($tree)->isEqualTo(
            [
              [
                 'id' => "$cat_1_1_2_id",
                 'parent' => "$cat_1_1_id",
                 'text' => 'cat 1.1.2 <strong title="This category contains articles">(1)</strong>',
                 'a_attr' => ['data-id' => "$cat_1_1_2_id"]
              ],
              [
                 'id' => "$cat_1_1_id",
                 'parent' => "$cat_1_id",
                 'text' => 'cat 1.1',
                 'a_attr' => ['data-id' => "$cat_1_1_id"]
              ],
              [
                 'id' => "$cat_1_id",
                 'parent' => '0',
                 'text' => 'cat 1',
                 'a_attr' => ['data-id' => "$cat_1_id"]
              ],
              $expected_root_cat
         ]
        );

        // Add a FAQ item
        $kbitem = new \KnowbaseItem();
        $kbitem_id = $kbitem->add(
            [
              'knowbaseitemcategories_id' => $cat_1_2_1_id,
              'is_faq' => 1,
         ]
        );
        $this->integer($kbitem_id)->isGreaterThan(0);

        $kbitem_target = new \Entity_KnowbaseItem();
        $kbitem_target_id = $kbitem_target->add(
            [
              'knowbaseitems_id' => $kbitem_id,
              'entities_id' => 0,
              'is_recursive' => 1,
         ]
        );
        $this->integer($kbitem_target_id)->isGreaterThan(0);

        // Expected root category item for anonymous user
        $expected_root_cat = [
           'id' => '0',
           'parent' => '#',
           'text' => 'Root category',
           'a_attr' => ['data-id' => '0']
        ];

        // Check that tree contains root only (FAQ is not public) for anonymous user
        // Force session reset
        $session_bck = $_SESSION;
        $this->resetSession();
        $tree_with_no_public_faq = \Knowbase::getJstreeCategoryList();

        // Check that tree contains root + category branch containing FAQ item (FAQ is public) for anonymous user
        global $CFG_GLPI;
        $use_public_faq_bck = $CFG_GLPI['use_public_faq'];
        $CFG_GLPI['use_public_faq'] = 1;
        $tree_with_public_faq = \Knowbase::getJstreeCategoryList();

        // Put back globals
        $_SESSION = $session_bck;
        $CFG_GLPI['use_public_faq'] = $use_public_faq_bck;

        $this->array($tree_with_no_public_faq)->isEqualTo([$expected_root_cat]);
        $this->array($tree_with_public_faq)->isEqualTo(
            [
              [
                 'id' => "$cat_1_2_1_id",
                 'parent' => "$cat_1_2_id",
                 'text' => 'cat 1.2.1 <strong title="This category contains articles">(1)</strong>',
                 'a_attr' => ['data-id' => "$cat_1_2_1_id"]
              ],
              [
                 'id' => "$cat_1_2_id",
                 'parent' => "$cat_1_id",
                 'text' => 'cat 1.2',
                 'a_attr' => ['data-id' => "$cat_1_2_id"]
              ],
              [
                 'id' => "$cat_1_id",
                 'parent' => '0',
                 'text' => 'cat 1',
                 'a_attr' => ['data-id' => "$cat_1_id"]
              ],
              $expected_root_cat
         ]
        );
    }
}
