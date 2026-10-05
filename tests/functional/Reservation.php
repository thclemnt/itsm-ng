<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\ReservationRepository;

class Reservation extends \DbTestCase
{
    public function testUserTabReusesDisplayLookupsAndKeepsAssetLinksFresh(): void
    {
        global $DB;
        $this->login();
        $original = $DB;
        $session = $_SESSION;
        $level = $original->getDoctrineConnection()->getTransactionNestingLevel();
        $rootId = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $childId = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $this->boolean((bool)\Session::haveRight('reservation', READ))->isTrue();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $reads = [];
            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $sql = str_replace(['`', '"'], '', $context['sql']);
                    if (preg_match('/\bFROM\s+(glpi_users|glpi_entities|glpi_reservationitems|glpi_reservations)\b/i', $sql, $match)) {
                        $this->reads[$match[1]][] = $sql;
                    }
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $parameters = $original->getDoctrineConnection()->getParams();
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($parameters, $configuration)
            : \itsmng\Database\MySQLConnection::create($parameters, $configuration);
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $frame = null;
        $primary = null;
        try {
            $DB = $probe;
            $frame = OwnedMutationFrame::begin($connection);
            $em = Orm::create($probe);
            $root = $em->getReference(Record\Entity::class, $rootId);
            $user = new Record\User();
            $user->entities = $root;
            $user->name = 'reservation-display-' . bin2hex(random_bytes(6));
            $user->firstname = 'Reservation Ada';
            $user->realname = 'Reader';
            $user->authtype = \Auth::DB_GLPI;
            $em->persist($user);
            $items = $assets = [];
            foreach ([['Computer', 'computer', $rootId], ['Monitor', 'monitor', $childId], ['Computer', 'computer', 0]] as $index => [$kind, $association, $entityId]) {
                $class = 'itsmng\\Database\\Entity\\' . $kind;
                $asset = new $class();
                $asset->entities = $em->getReference(Record\Entity::class, $entityId);
                $asset->name = 'Reservation asset ' . $index;
                $em->persist($asset);
                $item = new Record\ReservationItem();
                $item->entities = $asset->entities;
                $item->itemtype = $kind;
                $item->$association = $asset;
                $em->persist($item);
                $assets[] = $asset;
                $items[] = $item;
            }
            $add = static function (Record\ReservationItem $item, ?Record\User $owner, ?string $end, string $comment) use ($em): void {
                $reservation = new Record\Reservation();
                $reservation->reservationitems = $item;
                $reservation->users = $owner;
                $reservation->begin = new \DateTime('2030-01-01 09:00:00');
                $reservation->end = $end === null ? null : new \DateTime($end);
                $reservation->comment = $comment;
                $em->persist($reservation);
            };
            for ($i = 0; $i < 25; $i++) {
                $add($items[$i % 2], $user, '2030-01-01 11:00:00', "Current reservation $i\nsecond line");
            }
            $add($items[0], $user, '2030-01-01 10:00:00', 'Past boundary reservation');
            $add($items[2], $user, '2030-01-01 11:00:00', 'Other entity reservation');
            $add($items[0], $user, null, 'Undated reservation');
            $add($items[0], null, '2030-01-01 11:00:00', 'Anonymous reservation');
            $em->flush();
            $em->clear();
            $_SESSION['glpiactiveentities'] = [$rootId, $childId];
            $_SESSION['glpishowallentities'] = 0;
            $_SESSION['glpi_currenttime'] = '2030-01-01 10:00:00';
            $_SESSION['glpinames_format'] = \User::FIRSTNAME_BEFORE;
            $_SESSION['glpiis_ids_visible'] = 0;
            $repository = new ReservationRepository($em);
            $rows = $repository->forUser($user->id, $_SESSION['glpi_currenttime'], false, [$rootId, $childId]);
            $this->array($rows)->hasSize(25);
            $this->string($rows[0]['itemtype'])->isIdenticalTo('Computer');
            $this->integer((int)$rows[0]['items_id'])->isIdenticalTo($assets[0]->id);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->array($repository->forUser($user->id, $_SESSION['glpi_currenttime'], false, null))->hasSize(26);
            $this->array($repository->forUser(0, $_SESSION['glpi_currenttime'], false, null))->isEmpty();
            $render = static function (int $id): string {
                ob_start();
                try {
                    \Reservation::showForUser($id);
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            foreach (['Reservation Ada', 'Reservation Grace'] as $firstName) {
                $connection->update('glpi_users', ['firstname' => $firstName], ['id' => $user->id]);
                $connection->update('glpi_computers', ['name' => $firstName . ' asset'], ['id' => $assets[0]->id]);
                $name = getUserName($user->id);
                $labels = [\Dropdown::getDropdownName('glpi_entities', $rootId), \Dropdown::getDropdownName('glpi_entities', $childId)];
                $asset = new \Computer();
                $this->boolean($asset->getFromDB($assets[0]->id))->isTrue();
                $this->boolean($asset->can($assets[0]->id, READ))->isTrue();
                $link = $asset->getLink();
                $logger->reads = [];
                $html = $render($user->id);
                $this->string($html)->contains($link)->contains($labels[0])->contains($labels[1])
                    ->contains('Past boundary reservation')->contains(nl2br("Current reservation 24\nsecond line"))
                    ->contains('reservationitems_id=' . $items[0]->id . '&amp;mois_courant=01&amp;annee_courante=2030')
                    ->notContains('Other entity reservation')->notContains('Undated reservation')->notContains('Anonymous reservation');
                $this->integer(substr_count($html, "<td class='center'>" . $name . '</td>'))->isIdenticalTo(26);
                $this->array($logger->reads['glpi_users'] ?? [])->hasSize(1);
                $this->array($logger->reads['glpi_entities'] ?? [])->hasSize(2);
                $this->array($logger->reads['glpi_reservations'] ?? [])->hasSize(2);
                $this->array($logger->reads['glpi_reservationitems'] ?? [])->isEmpty();
                $frame->assertActive();
                $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            }
            $_SESSION['glpiactiveentities'] = [];
            $_SESSION['glpishowallentities'] = 1; // Empty authoritative scope still wins.
            $logger->reads = [];
            $this->string($render($user->id))->notContains('Current reservation 0')->notContains('Past boundary reservation');
            $this->array($logger->reads['glpi_users'] ?? [])->isEmpty();
            $this->array($logger->reads['glpi_entities'] ?? [])->isEmpty();
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpishowallentities'] = 0;
            $this->string($render($user->id))->contains('Other entity reservation')->notContains('Current reservation 0');
            $_SESSION['glpiactiveprofile']['reservation'] = 0;
            $logger->reads = [];
            $this->string($render($user->id))->isIdenticalTo('');
            $this->array($logger->reads)->isEmpty();
            $frame->assertActive();
        } catch (\Throwable $error) {
            $primary = $error;
        } finally {
            $DB = $original;
            $_SESSION = $session;
            try {
                if ($frame !== null) {
                    $frame->rollBack();
                }
            } catch (\Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new \itsmng\Database\MutationRollbackFailure($primary, $cleanup);
            }
            try {
                $probe->close();
            } catch (\Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->integer($original->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($level);
    }
}
