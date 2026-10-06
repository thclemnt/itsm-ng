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

class ITILCategory extends DbTestCase
{
    public function testKnowledgeLinksUseCurrentIdentifiersAndKeepArticleAdmission(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $publicFaq = $CFG_GLPI['use_public_faq'];
        $entity = (int)\Session::getActiveEntity();
        $connection = $DB->getDoctrineConnection();
        $em = \itsmng\Database\Orm::create($DB);
        $listener = new class () {
            public int $loaded = 0;
            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $prefix = 'Knowledge links ' . $this->getUniqueString();
            $author = (int)getItemByTypeName('User', 'itsm', true);
            $this->integer($author)->isGreaterThan(0)->isNotIdenticalTo((int)\Session::getLoginUserID());
            $category = $this->createItem(\KnowbaseItemCategory::class, ['name' => $prefix]);
            $other = $this->createItem(\KnowbaseItemCategory::class, ['name' => $prefix . ' other']);
            $dropdown = $this->createItem(\ITILCategory::class, [
                'name' => $prefix . ' dropdown', 'entities_id' => $entity, 'knowbaseitemcategories_id' => $category->getID(),
            ]);
            $empty = $this->createItem(\ITILCategory::class, ['name' => $prefix . ' unlinked', 'entities_id' => $entity]);
            $this->variable($empty->fields['knowbaseitemcategories_id'])->isNull();
            $this->string($empty->getLinks())->isEmpty();
            $this->string($empty->getLinks(true))->isIdenticalTo($prefix . ' unlinked&nbsp;&nbsp;');
            $articles = [];
            foreach (['shared', 'hidden', 'owned', 'public'] as $kind) {
                $articles[$kind] = $this->createItem(\KnowbaseItem::class, [
                    'name' => $prefix . ' ' . $kind, 'answer' => $prefix . ' answer ' . $kind,
                    'users_id' => $kind === 'owned' ? (int)\Session::getLoginUserID() : $author,
                    'knowbaseitemcategories_id' => in_array($kind, ['shared', 'hidden'], true) ? $category->getID() : $other->getID(),
                    'is_faq' => $kind === 'public' ? 1 : 0,
                ]);
                if ($kind !== 'owned') {
                    $this->createItem(\Entity_KnowbaseItem::class, [
                        'knowbaseitems_id' => $articles[$kind]->getID(), 'entities_id' => $kind === 'shared' ? $entity : 0,
                        'is_recursive' => $kind === 'public' ? 1 : 0,
                    ]);
                }
            }
            $_SESSION['glpiactiveprofile']['knowbase'] = READ;
            $admitted = \KnowbaseItem::getForCategory($category->getID());
            $this->array(array_values($admitted))->isIdenticalTo([(int)$articles['shared']->getID()]);
            $repository = new \itsmng\Database\Repository\KnowledgeBaseRepository($em);
            $this->array($repository->existingLinkIds([...$admitted, ...$admitted, -1, PHP_INT_MAX]))
                ->isIdenticalTo([(int)$articles['shared']->getID()]);
            $this->array($repository->existingLinkIds([]))->isEmpty();
            $this->array($repository->existingLinkIds([-1]))->isEmpty();
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            $html = $dropdown->getLinks(true);
            $this->string($html)->contains($prefix . ' dropdown')->contains($prefix . ' answer shared')
                ->notContains($prefix . ' answer hidden')->notContains('getKnowbaseItemAnswer');
            $this->boolean($articles['shared']->getFromDB($articles['shared']->getID()))->isTrue();
            $this->integer($articles['shared']->fields['view'])->isIdenticalTo(1);
            $managed = $em->find(\itsmng\Database\Entity\KnowbaseItem::class, (int)$articles['shared']->getID());
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->boolean($DB->update(\KnowbaseItem::getTable(), ['answer' => $prefix . ' current answer'], ['id' => $articles['shared']->getID()]))->isTrue();
            $this->string($dropdown->getLinks())->contains($prefix . ' current answer');
            $this->string($managed->answer)->isIdenticalTo($prefix . ' answer shared');
            $this->integer($listener->loaded)->isIdenticalTo(1);

            $this->boolean($articles['owned']->update(['id' => $articles['owned']->getID(),
                'knowbaseitemcategories_id' => $category->getID()]))->isTrue();
            $html = $dropdown->getLinks();
            $this->string($html)->contains('getKnowbaseItemAnswer')->notContains($prefix . ' answer hidden');
            $this->boolean(str_contains($html, $prefix . ' current answer') || str_contains($html, $prefix . ' answer owned'))->isTrue();
            $views = 0;
            foreach (['shared', 'owned'] as $kind) {
                $this->boolean($articles[$kind]->getFromDB($articles[$kind]->getID()))->isTrue();
                $views += $articles[$kind]->fields['view'];
            }
            $this->integer($views)->isIdenticalTo(3); // Exactly one rich preview per invocation.

            // Author admission without READ is intentionally different from the
            // list repository's visibility rule. showFull still denies rendering.
            $_SESSION['glpiactiveprofile']['knowbase'] = 0;
            $this->array(array_values(\KnowbaseItem::getForCategory($category->getID())))
                ->isIdenticalTo([(int)$articles['owned']->getID()]);
            $this->string($dropdown->getLinks())->contains('faqadd_block')->notContains($prefix . ' answer owned');
            $_SESSION = $session;
            $this->boolean($articles['public']->update(['id' => $articles['public']->getID(),
                'knowbaseitemcategories_id' => $category->getID()]))->isTrue();
            $CFG_GLPI['use_public_faq'] = true;
            unset($_SESSION['glpiID']);
            $_SESSION['glpiactiveprofile']['knowbase'] = 0;
            $this->array(array_values(\KnowbaseItem::getForCategory($category->getID())))
                ->isIdenticalTo([(int)$articles['public']->getID()]);
            $this->string($dropdown->getLinks())->contains($prefix . ' answer public')
                ->notContains($prefix . ' answer owned')->notContains($prefix . ' current answer');
            $CFG_GLPI['use_public_faq'] = false;
            $this->string($dropdown->getLinks())->isEmpty();

            $_SESSION = $session;
            $this->boolean($articles['shared']->delete(['id' => $articles['shared']->getID()], true))->isTrue();
            $this->array($repository->existingLinkIds($admitted))->isEmpty();
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
        } finally {
            $_SESSION = $session;
            $CFG_GLPI['use_public_faq'] = $publicFaq;
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
        }
    }

    public function testPrepareInputForAdd()
    {
        $this->login();

        $category = new \ITILCategory();

        $input = [
           'name'    => '_test_itilcategory_1',
           'comment' => '_test_itilcategory_1',
        ];
        $expected = [
           'name'              => '_test_itilcategory_1',
           'comment'           => '_test_itilcategory_1',
           'itilcategories_id' => 0,
           'level'             => 1,
           'completename'      => '_test_itilcategory_1',
           'code'              => '',
        ];
        $this->array($category->prepareInputForAdd($input))->isIdenticalTo($expected);

        $input = [
           'name'    => '_test_itilcategory_2',
           'comment' => '_test_itilcategory_2',
           'code'    => 'code2',
        ];
        $expected = [
           'name'              => '_test_itilcategory_2',
           'comment'           => '_test_itilcategory_2',
           'code'              => 'code2',
           'itilcategories_id' => 0,
           'level'             => 1,
           'completename'      => '_test_itilcategory_2',
        ];
        $this->array($category->prepareInputForAdd($input))->isIdenticalTo($expected);

        $input = [
           'name'    => '_test_itilcategory_3',
           'comment' => '_test_itilcategory_3',
           'code'    => ' code 3 ',
        ];
        $expected = [
           'name'              => '_test_itilcategory_3',
           'comment'           => '_test_itilcategory_3',
           'code'              => 'code 3',
           'itilcategories_id' => 0,
           'level'             => 1,
           'completename'      => '_test_itilcategory_3',
        ];
        $this->array($category->prepareInputForAdd($input))->isIdenticalTo($expected);
    }

    public function testPrepareInputForUpdate()
    {
        $this->login();

        $category = new \ITILCategory();
        $category_id = (int)$category->add([
           'name'    => '_test_itilcategory_1',
           'comment' => '_test_itilcategory_1',
        ]);
        $this->integer($category_id)->isGreaterThan(0);

        $this->boolean($category->update([
           'id'   => $category_id,
           'code' => ' code 1 ',
        ]))->isTrue();
        $this->boolean($category->getFromDB($category_id))->isTrue();
        $this->string($category->fields['name'])->isIdenticalTo('_test_itilcategory_1');
        $this->string($category->fields['comment'])->isIdenticalTo('_test_itilcategory_1');
        $this->string($category->fields['code'])->isIdenticalTo('code 1');

        $this->boolean($category->update([
           'id'      => $category_id,
           'comment' => 'new comment',
        ]))->isTrue();
        $this->boolean($category->getFromDB($category_id))->isTrue();
        $this->string($category->fields['name'])->isIdenticalTo('_test_itilcategory_1');
        $this->string($category->fields['comment'])->isIdenticalTo('new comment');
        $this->string($category->fields['code'])->isIdenticalTo('code 1');

        $this->boolean($category->update([
           'id'   => $category_id,
           'code' => '',
        ]))->isTrue();
        $this->boolean($category->getFromDB($category_id))->isTrue();
        $this->string($category->fields['name'])->isIdenticalTo('_test_itilcategory_1');
        $this->string($category->fields['comment'])->isIdenticalTo('new comment');
        $this->string($category->fields['code'])->isIdenticalTo('');
    }

    public function testRecursiveITILCategoryRights()
    {
        $this->login();

        $root_entity_id = getItemByTypeName('Entity', '_test_root_entity', true);
        $child_entity_id = getItemByTypeName('Entity', '_test_child_1', true);

        $category = new \ITILCategory();

        $category_id = (int)$category->add([
           'name'         => 'Recursive Category Test',
           'entities_id'  => $root_entity_id,
           'is_recursive' => 1,
        ]);
        $this->integer($category_id)->isGreaterThan(0);

        $non_recursive_category_id = (int)$category->add([
           'name'         => 'Non-Recursive Category Test',
           'entities_id'  => $root_entity_id,
           'is_recursive' => 0,
        ]);
        $this->integer($non_recursive_category_id)->isGreaterThan(0);

        $child_category_id = (int)$category->add([
           'name'         => 'Child Category Test',
           'entities_id'  => $child_entity_id,
           'is_recursive' => 0,
        ]);
        $this->integer($child_category_id)->isGreaterThan(0);

        $this->boolean(\Session::changeActiveEntities($child_entity_id))->isTrue();

        $this->boolean($category->can($category_id, READ))->isTrue();
        $this->boolean($category->can($non_recursive_category_id, READ))->isFalse();

        $this->boolean($category->getFromDB($category_id))->isTrue();
        $this->boolean($category->canUpdateItem())->isFalse();
        $this->boolean($category->canDeleteItem())->isFalse();
        $this->boolean($category->canPurgeItem())->isFalse();

        $this->boolean($category->getFromDB($child_category_id))->isTrue();
        $this->boolean($category->canUpdateItem())->isTrue();
        $this->boolean($category->canDeleteItem())->isTrue();
        $this->boolean($category->canPurgeItem())->isTrue();

        $this->boolean(\Session::changeActiveEntities($root_entity_id))->isTrue();

        $this->boolean($category->getFromDB($category_id))->isTrue();
        $this->boolean($category->canUpdateItem())->isTrue();
        $this->boolean($category->canDeleteItem())->isTrue();
        $this->boolean($category->canPurgeItem())->isTrue();
    }
}
