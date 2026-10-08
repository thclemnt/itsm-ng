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

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use PlanningExternalEvent as LegacyPlanningExternalEvent;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\PlanningExternalEvent as PlanningExternalEventEntity;
use itsmng\Database\Entity\User;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PlanningGuestRepository;

include_once __DIR__ . '/../abstracts/AbstractPlanningEvent.php';

class PlanningExternalEvent extends \AbstractPlanningEvent
{
    public $myclass = "\PlanningExternalEvent";


    public function testGuestValidationUsesScalarReadsAndPreservesAtomicOrder(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $em = Orm::create($DB);
        try {
            $users = [];
            foreach (range(1, 3) as $index) {
                $user = new User();
                $user->entities = $em->getReference(Entity::class, (int)$_SESSION['glpiactive_entity']);
                $user->name = 'Planning scalar guest ' . $index . ' ' . $this->getUniqueString();
                $em->persist($user);
                $users[] = $user;
            }
            $em->flush();
            [$first, $second, $stale] = array_map(static fn ($user): int => (int)$user->id, $users);
            $em->clear();
            $event = new LegacyPlanningExternalEvent();
            $eventId = (int)$event->add($this->input + ['users_id_guests' => [$first, (string)$second, $first]]);
            $this->integer($eventId)->isGreaterThan(0);
            $this->boolean($event->getFromDB($eventId))->isTrue();
            $this->array($event->fields['users_id_guests'])->isIdenticalTo([$first, $second]);

            $loads = new class () {
                public int $users = 0;
                public int $events = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    $this->users += (int)($event->getObject() instanceof User);
                    $this->events += (int)($event->getObject() instanceof PlanningExternalEventEntity);
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            $repository = new PlanningGuestRepository($em);
            $repository->replaceGuests($eventId, [$second, $first, (string)$second]);
            $this->array($repository->userIds($eventId))->isIdenticalTo([$second, $first]);
            $this->integer($loads->users)->isIdenticalTo(0);
            $this->integer($loads->events)->isIdenticalTo(1);

            // A missing guest fails before replacing the current ordered set.
            // The first invalid selection wins even when IDs sort differently.
            $this->exception(fn () => $event->update([
                'id' => $eventId, 'name' => 'Must roll back',
                'users_id_guests' => [$second, PHP_INT_MAX, PHP_INT_MAX - 1, $first],
            ]))->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('Unknown planning guest: ' . PHP_INT_MAX);
            $this->boolean($event->getFromDB($eventId))->isTrue();
            $this->string($event->fields['name'])->isIdenticalTo($this->input['name']);
            $this->array($event->fields['users_id_guests'])->isIdenticalTo([$second, $first]);
            foreach ([null, '', 0] as $invalid) {
                $this->exception(fn () => $repository->replaceGuests($eventId, [$first, $invalid]))
                    ->isInstanceOf(InvalidArgumentException::class)
                    ->hasMessage('Planning guests require positive user IDs');
                $this->array($repository->userIds($eventId))->isIdenticalTo([$second, $first]);
            }

            // Existing managed state must not validate a user already removed
            // through a separate writer on the same transaction/connection.
            $managed = $em->find(User::class, $stale);
            $this->object($managed)->isInstanceOf(User::class);
            $this->boolean($DB->delete('glpi_users', ['id' => $stale]))->isTrue();
            $this->boolean($em->contains($managed))->isTrue();
            $this->exception(fn () => $repository->replaceGuests($eventId, [$first, $stale]))
                ->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('Unknown planning guest: ' . $stale);
            $this->array($repository->userIds($eventId))->isIdenticalTo([$second, $first]);
            $this->boolean($em->contains($managed))->isTrue();

            $this->boolean($event->update(['id' => $eventId, 'users_id_guests' => []]))->isTrue();
            $this->boolean($event->getFromDB($eventId))->isTrue();
            $this->array($event->fields['users_id_guests'])->isEmpty();
        } finally {
            $em->clear();
        }
    }


    public function testAddInstanceException()
    {
        $this->login();

        $event     = new $this->myclass();
        $id        = $event->add($this->input);
        $exception = date('Y-m-d', $this->now + DAY_TIMESTAMP);

        $this->boolean($event->addInstanceException($id, $exception))->isTrue();

        $rrule = json_decode((string) $event->fields['rrule'], true);
        $this->array($rrule['exceptions'])
           ->hasSize(3) // original event has 2 exceptions, we add one
           ->contains($exception);
    }


    public function testCreateInstanceClone()
    {
        $this->login();

        $event     = new $this->myclass();
        $serie_id  = $event->add($this->input);
        $start     = date('Y-m-d H:i:s', $this->now + DAY_TIMESTAMP);
        $start_day = date('Y-m-d', $this->now + DAY_TIMESTAMP);

        // the clone of serie should not have rrule
        $new_event = $event->createInstanceClone($serie_id, $start);
        $this->object($new_event)->isInstanceOf($this->myclass);
        $this->integer($new_event->fields['id'])->isNotEqualTo($serie_id);
        $this->variable($new_event->fields['rrule'])->isNull();

        // original event should have the instance exception
        $rrule = json_decode((string) $event->fields['rrule'], true);
        $this->array($rrule['exceptions'])
           ->hasSize(3) // original event has 2 exceptions, we add one
           ->contains($start_day);
    }
}
