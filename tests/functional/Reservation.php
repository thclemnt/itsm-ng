<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

use DBAdapter;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Events;
use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\ReservationRepository;
use LogicException;
use mock\tests\units\ReservationDisplayAdapterBase as ReservationDisplayAdapter;
use ReflectionProperty;
use SplObjectStorage;

class Reservation extends \DbTestCase
{
    public function testPublicAddReturnsAfterLifecycleWithoutControllerNavigation(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $hadUri = array_key_exists('REQUEST_URI', $_SERVER);
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $connection = $DB->getDoctrineConnection();
        $depth = $connection->getTransactionNestingLevel();
        $this->integer($depth)->isGreaterThan(0);
        try {
            $entity = (int)\Session::getActiveEntity();
            $asset = $this->createItem(\Computer::class, [
                'name' => 'Reservation lifecycle ' . $this->getUniqueString(), 'entities_id' => $entity,
            ]);
            $item = $this->createItem(\ReservationItem::class, [
                'itemtype' => 'Computer', 'items_id' => $asset->getID(), 'entities_id' => $entity, 'is_active' => 1,
            ]);
            $events = [];
            $observed = [];
            $plugins->setValue(null, [...$activePlugins, 'reservation_lifecycle_fixture']);
            $PLUGIN_HOOKS['item_add']['reservation_lifecycle_fixture'][\Reservation::class]
                = static function (\Reservation $reservation) use (&$events, &$observed, $connection): void {
                    global $DB;
                    $id = (int)$reservation->getID();
                    $events[] = ['hook', $id];
                    $observed[] = [
                        'id' => $id,
                        'rows' => countElementsInTable(\Reservation::getTable(), ['id' => $id]),
                        'writer' => $DB->getDoctrineConnection() === $connection,
                        'depth' => $connection->getTransactionNestingLevel(),
                    ];
                };
            $reservation = new \Reservation();
            $ids = $expectedEvents = [];
            // Creation is a model operation in every context, including callers
            // with no HTTP request. The owning form alone performs navigation.
            foreach ([
                ['central', '/front/reservation.form.php'],
                ['helpdesk', '/plugins/formcreator/front/reservation.form.php'],
                ['central', '/apirest.php/Reservation'],
                ['central', null],
            ] as $index => [$interface, $requestUri]) {
                $_SESSION['glpiactiveprofile']['interface'] = $interface;
                if ($requestUri === null) {
                    unset($_SERVER['REQUEST_URI']);
                } else {
                    $_SERVER['REQUEST_URI'] = $requestUri;
                }
                $day = sprintf('2030-03-%02d', $index + 1);
                $input = [
                    'reservationitems_id' => $item->getID(), 'users_id' => (int)\Session::getLoginUserID(),
                    'begin' => $day . ' 09:00:00', 'end' => $day . ' 10:00:00',
                    'comment' => 'Completed public booking ' . $index,
                ];
                $this->boolean($reservation->can(-1, CREATE, $input))->isTrue();
                unset($reservation->fields['id']);
                ob_start();
                try {
                    $id = $reservation->add($input);
                    $output = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->integer((int)$id)->isGreaterThan(0);
                $id = (int)$id;
                $ids[] = $id;
                $events[] = ['return', $id];
                $expectedEvents[] = ['hook', $id];
                $expectedEvents[] = ['return', $id];
                $this->string($output)->isEmpty();
                $this->array($events)->isIdenticalTo($expectedEvents);
                $this->array($observed[$index])->isIdenticalTo(['id' => $id, 'rows' => 1, 'writer' => true, 'depth' => $depth]);
                $readback = new \Reservation();
                $this->boolean($readback->getFromDB($id))->isTrue();
                foreach (['begin', 'end', 'comment'] as $field) {
                    $this->string($readback->fields[$field])->isIdenticalTo($input[$field]);
                }
                $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            }
            $this->array(array_unique($ids))->hasSize(4);
            $this->integer(countElementsInTable(\Reservation::getTable(), ['reservationitems_id' => $item->getID()]))->isIdenticalTo(4);

            foreach ([
                ['2030-03-05 10:00:00', '2030-03-05 09:00:00', __('Error in entering dates. The starting date is later than the ending date')],
                ['2030-03-01 09:15:00', '2030-03-01 09:45:00', __('The required item is already reserved for this timeframe')],
            ] as [$begin, $end, $error]) {
                ob_start();
                try {
                    $failed = (new \Reservation())->add([
                        'reservationitems_id' => $item->getID(), 'users_id' => (int)\Session::getLoginUserID(),
                        'begin' => $begin, 'end' => $end,
                    ]);
                    $output = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->boolean($failed)->isFalse();
                $this->string($output)->contains($error);
                $this->array($events)->isIdenticalTo($expectedEvents);
            }
            $foreign = (int)getItemByTypeName('User', 'itsm', true);
            $this->integer($foreign)->isGreaterThan(0)->isNotIdenticalTo((int)\Session::getLoginUserID());
            $_SESSION['glpiactiveprofile']['reservation'] = \ReservationItem::RESERVEANITEM;
            $creation = ['reservationitems_id' => $item->getID()];
            // The opening form and legacy empty borrower selection remain valid.
            $this->boolean((new \Reservation())->can(-1, CREATE, $creation))->isTrue();
            foreach ([null, '', 0, '0', false, (int)\Session::getLoginUserID(), (string)\Session::getLoginUserID()] as $borrower) {
                $selection = $creation + ['users_id' => $borrower];
                $this->boolean((new \Reservation())->can(-1, CREATE, $selection))->isTrue();
            }
            $selection = $creation + ['users_id' => $foreign];
            $this->boolean((new \Reservation())->can(-1, CREATE, $selection))->isFalse();
            $_SESSION['glpiactiveprofile']['reservation'] |= UPDATE;
            $this->boolean((new \Reservation())->can(-1, CREATE, $selection))->isTrue();
            foreach ([$foreign . 'junk', -1, true, 1.5, []] as $borrower) {
                $selection = $creation + ['users_id' => $borrower];
                $this->boolean((new \Reservation())->can(-1, CREATE, $selection))->isFalse();
            }
            $_SESSION['glpiactiveprofile']['reservation'] = 0;
            $this->boolean((new \Reservation())->can(-1, CREATE, $input))->isFalse();
            $this->integer(countElementsInTable(\Reservation::getTable(), ['reservationitems_id' => $item->getID()]))->isIdenticalTo(4);
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $activePlugins);
            if ($hadUri) {
                $_SERVER['REQUEST_URI'] = $uri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
        }
    }

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
        $logger = new class () extends \Psr\Log\AbstractLogger {
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
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
            try {
                $itemRows = $repository->forItem($items[0]->id, $_SESSION['glpi_currenttime'], false);
                $this->array($itemRows)->hasSize(14);
                $this->integer($itemRows[0]['reservationitems_id'])->isIdenticalTo($items[0]->id);
                $this->integer($itemRows[0]['users_id'])->isIdenticalTo($user->id);
                $this->integer($itemRows[0]['group'])->isIdenticalTo(0);
                $this->string($itemRows[0]['begin'])->isIdenticalTo('2030-01-01 09:00:00');
                $this->string($itemRows[0]['end'])->isIdenticalTo('2030-01-01 11:00:00');
                $this->string($itemRows[0]['comment'])->isIdenticalTo("Current reservation 0\nsecond line");
                $this->string($itemRows[0]['_user_name'])->isIdenticalTo($user->name);
                $this->string($itemRows[0]['_user_realname'])->isIdenticalTo('Reader');
                $this->string($itemRows[0]['_user_firstname'])->isIdenticalTo('Reservation Ada');
                $anonymous = $itemRows[array_key_last($itemRows)];
                $this->variable($anonymous['users_id'])->isNull();
                foreach (['_user_name', '_user_realname', '_user_firstname'] as $field) {
                    $this->string($anonymous[$field])->isIdenticalTo('');
                }
                $pastRows = $repository->forItem($items[0]->id, $_SESSION['glpi_currenttime'], true);
                $this->array($pastRows)->hasSize(1);
                $this->string($pastRows[0]['comment'])->isIdenticalTo('Past boundary reservation');
                $this->array($repository->during($items[0]->id, '2030-01-01 10:00:00', '2030-01-01 11:00:00'))->hasSize(14);
                $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
                $this->integer($loads->count)->isIdenticalTo(0);
                // A managed user must not hide a later legacy write on the supplied connection.
                $em->find(Record\User::class, $user->id);
                $this->integer($loads->count)->isGreaterThan(0);
                $connection->update('glpi_users', ['firstname' => 'Reservation Fresh'], ['id' => $user->id]);
                $fresh = $repository->forItem($items[0]->id, $_SESSION['glpi_currenttime'], false);
                $this->string($fresh[0]['_user_firstname'])->isIdenticalTo('Reservation Fresh');
                $connection->update('glpi_users', ['firstname' => 'Reservation Ada'], ['id' => $user->id]);
            } finally {
                $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $loads);
                $em->clear();
            }
            $rows = $repository->forUser($user->id, $_SESSION['glpi_currenttime'], false, [$rootId, $childId]);
            $this->array($rows)->hasSize(25);
            $this->string($rows[0]['itemtype'])->isIdenticalTo('Computer');
            $this->integer((int)$rows[0]['items_id'])->isIdenticalTo($assets[0]->id);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->array($repository->forUser($user->id, $_SESSION['glpi_currenttime'], false, null))->hasSize(26);
            $this->array($repository->forUser(0, $_SESSION['glpi_currenttime'], false, null))->isEmpty();
            // The direct DBAL display path must retain the ORM projection contract,
            // including root scope, absent grants, time boundaries, and scalar types.
            foreach ([false, true] as $past) {
                foreach ([[$rootId, $childId], [0], [], null] as $scope) {
                    $this->array($repository->nativeForUser($user->id, $_SESSION['glpi_currenttime'], $past, $scope))
                        ->isIdenticalTo($repository->forUser($user->id, $_SESSION['glpi_currenttime'], $past, $scope));
                }
            }
            $this->array($repository->nativeForUser(0, $_SESSION['glpi_currenttime'], false, null))->isEmpty();
            $originalText = \Doctrine\DBAL\Types\Type::getType(\Doctrine\DBAL\Types\Types::TEXT);
            try {
                \Doctrine\DBAL\Types\Type::overrideType(\Doctrine\DBAL\Types\Types::TEXT, new ReservationDisplayTextType());
                $converted = $repository->nativeForUser($user->id, $_SESSION['glpi_currenttime'], true, [$rootId, $childId]);
                $this->array($converted)->isIdenticalTo($repository->forUser($user->id, $_SESSION['glpi_currenttime'], true, [$rootId, $childId]));
                $this->string($converted[0]['comment'])->isIdenticalTo('PAST BOUNDARY RESERVATION|php');
            } finally {
                \Doctrine\DBAL\Types\Type::overrideType(\Doctrine\DBAL\Types\Types::TEXT, $originalText);
            }

            $connection->update('glpi_reservations', ['comment' => null], ['reservationitems_id' => $items[2]->id]);
            $rootRows = $repository->nativeForUser($user->id, $_SESSION['glpi_currenttime'], false, [0]);
            $this->array($rootRows)->hasSize(1);
            $this->variable($rootRows[0]['comment'])->isNull();
            $connection->update('glpi_reservations', ['comment' => 'Other entity reservation'], ['reservationitems_id' => $items[2]->id]);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            $render = static function (int $id): string {
                ob_start();
                try {
                    \Reservation::showForUser($id);
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            // Empty public partitions require neither entity metadata nor a manager.
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeFactories = $factories->getValue();
            $this->string($render(0))->notContains('reservationitems_id=');
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
            $originalText = Type::getType(Types::TEXT);
            try {
                Type::overrideType(Types::TEXT, new ReservationDisplayTextType());
                $convertedHtml = $render($user->id);
                $this->string($convertedHtml)->contains('PAST BOUNDARY RESERVATION|php')
                    ->contains(nl2br("CURRENT RESERVATION 24\nSECOND LINE|php"));
                // The first partition keeps its selected route/scope; the second
                // resolves again after display/Type callbacks have changed them.
                ReservationDisplayTextType::$callback = static function () use ($original): void {
                    $GLOBALS['DB'] = $original;
                    $_SESSION['glpiactiveentities'] = [0];
                };
                $logger->reads = [];
                $routed = $render($user->id);
                $this->string($routed)->contains('CURRENT RESERVATION 0')
                    ->notContains('PAST BOUNDARY RESERVATION')->notContains('OTHER ENTITY RESERVATION');
                $this->object($DB)->isIdenticalTo($original);
                $this->array($logger->reads['glpi_reservations'] ?? [])->hasSize(1);
            } finally {
                ReservationDisplayTextType::$callback = null;
                Type::overrideType(Types::TEXT, $originalText);
                $DB = $probe;
                $_SESSION['glpiactiveentities'] = [$rootId, $childId];
            }
            $events = new EventManager();
            $listener = new class () {
                public int $loads = 0;
                public int $clears = 0;
                public int $otherClears = 0;
                public SplObjectStorage $owners;
                public function __construct()
                {
                    $this->owners = new SplObjectStorage();
                }
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    if ($event->getClassMetadata()->name === Record\Reservation::class) {
                        $this->owners->offsetSet($event->getObjectManager());
                    }
                    if (in_array($event->getClassMetadata()->name, [Record\Reservation::class, Record\ReservationItem::class], true)) {
                        ++$this->loads;
                    }
                }
                public function onClear(OnClearEventArgs $event): void
                {
                    if ($this->owners->offsetExists($event->getObjectManager())) {
                        ++$this->clears;
                    } else {
                        ++$this->otherClears;
                    }
                }
            };
            $events->addEventListener([Events::loadClassMetadata, Events::onClear], $listener);
            $extendedConnection = new ReservationDisplayConnectionProbe($connection, $events);
            // The generic connection extension requires the base adapter return
            // type; a cloned DBpgsql retains its narrower PostgresConnection contract.
            $this->mockGenerator->orphanize('__construct');
            $extendedAdapter = new ReservationDisplayAdapter();
            $this->calling($extendedAdapter)->getDoctrineConnection = $extendedConnection;
            $this->calling($extendedAdapter)->getProvider = $probe->getProvider();
            foreach (get_object_vars($probe) as $property => $value) {
                if (property_exists($extendedAdapter, $property)) {
                    $extendedAdapter->$property = $value;
                }
            }
            try {
                $DB = $extendedAdapter;
                $beforeFactories = $factories->getValue();
                $this->string($render(0))->notContains('reservationitems_id=');
                $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(2);
                $this->integer($listener->loads)->isIdenticalTo(4);
                $this->integer(count($listener->owners))->isIdenticalTo(2);
                $this->integer($listener->clears)->isIdenticalTo(0);
                // A separate display owner still dispatches onClear through the
                // shared EventManager; only reservation owners must remain uncleared.
                $otherClears = $listener->otherClears;
                $control = Orm::forConnection($extendedConnection);
                $control->clear();
                unset($control);
                $this->integer($listener->otherClears - $otherClears)->isIdenticalTo(1);
                // A second platform callback belongs after first-partition scope
                // evaluation, as in the existing bare-manager display reader.
                $extendedConnection->platformCalls = 0;
                $extendedConnection->secondPlatform = static function (): void {
                    $_SESSION['glpiactiveentities'] = [0];
                };
                $callbackHtml = $render($user->id);
                $this->string($callbackHtml)->contains('Current reservation 0')
                    ->notContains('Past boundary reservation')->notContains('Other entity reservation');
                $this->integer(count($listener->owners))->isIdenticalTo(4);
                $this->integer($listener->clears)->isIdenticalTo(0);
            } finally {
                $extendedConnection->secondPlatform = null;
                $events->removeEventListener([Events::loadClassMetadata, Events::onClear], $listener);
                $DB = $probe;
                $_SESSION['glpiactiveentities'] = [$rootId, $childId];
            }
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

    public function testAvailablePeripheralLabelsUseProjectedClassification(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $session = $_SESSION;
        $post = $_POST;
        $reservationTypes = $CFG_GLPI['reservation_types'];
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = Orm::create($DB);
        $listener = new class () {
            public int $loaded = 0;
            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $this->boolean((bool)\Session::haveRight('reservation', \ReservationItem::RESERVEANITEM))->isTrue();
            $prefix = 'Reservation classification ' . bin2hex(random_bytes(6));
            $first = $this->createItem(\PeripheralType::class, ['name' => $prefix . ' first']);
            $second = $this->createItem(\PeripheralType::class, ['name' => $prefix . ' second']);
            $this->createItem(\DropdownTranslation::class, [
                'itemtype' => 'PeripheralType', 'items_id' => $second->getID(),
                'language' => 'en_GB', 'field' => 'name', 'value' => $prefix . ' translated',
            ]);
            $assets = $items = [];
            foreach (['typed', 'untyped', 'inactive', 'outside'] as $kind) {
                $input = ['name' => $prefix . ' ' . $kind, 'entities_id' => $kind === 'outside' ? 0 : $entity];
                if ($kind !== 'untyped') {
                    $input['peripheraltypes_id'] = $first->getID();
                }
                $assets[$kind] = $this->createItem(\Peripheral::class, $input);
                $items[$kind] = $this->createItem(\ReservationItem::class, [
                    'itemtype' => 'Peripheral', 'items_id' => $assets[$kind]->getID(),
                    'entities_id' => $input['entities_id'], 'is_active' => $kind === 'inactive' ? 0 : 1,
                ]);
            }
            $scope = ['AND' => [
                getEntitiesRestrictCriteria(\Peripheral::getTable(), '', [$entity], false),
                ['id' => array_map(static fn ($asset) => (int)$asset->getID(), $assets)],
            ]];
            $repository = new \itsmng\Database\Repository\ReservationItemRepository($em);
            $rows = array_column($repository->available('Peripheral', 'name', $scope, null, null), null, 'items_id');
            $this->array($rows)->hasSize(2);
            $this->integer((int)$rows[$assets['typed']->getID()]['peripheraltypes_id'])->isIdenticalTo((int)$first->getID());
            $this->variable($rows[$assets['untyped']->getID()]['peripheraltypes_id'])->isNull();
            $this->array($repository->available('Peripheral', 'name', $scope, null, null, (int)$first->getID()))->hasSize(1);
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            // A real managed entity is the positive hydration control. The scalar read must
            // see this connection's current write without replacing its managed association.
            $managed = $em->find(Record\Peripheral::class, (int)$assets['typed']->getID());
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_peripherals', ['peripheraltypes_id' => $second->getID()], ['id' => $assets['typed']->getID()]);
            $rows = array_column($repository->available('Peripheral', 'name', $scope, null, null), null, 'items_id');
            $this->integer((int)$rows[$assets['typed']->getID()]['peripheraltypes_id'])->isIdenticalTo((int)$second->getID());
            $this->integer((int)$em->getUnitOfWork()->getEntityIdentifier($managed->peripheraltypes)['id'])->isIdenticalTo((int)$first->getID());
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->array($repository->available('Peripheral', 'name', $scope, null, null, (int)$first->getID()))->isEmpty();

            $_SESSION['glpiactiveentities'] = [$entity];
            $_SESSION['glpilanguage'] = 'en_GB';
            $_SESSION['glpi_dropdowntranslations']['PeripheralType']['name'] = 'name';
            unset($_SESSION['glpi_saved']['ReservationItem']);
            $CFG_GLPI['reservation_types'] = ['Peripheral'];
            $_POST = [];
            $render = static function (): string {
                ob_start();
                try {
                    \ReservationItem::showListSimple();
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            $html = $render();
            $this->string($html)->contains($prefix . ' typed')->contains($prefix . ' untyped')
                ->contains("<small class='text-muted'>" . $prefix . ' translated</small>')
                ->contains("<small class='text-muted'>" . htmlspecialchars(\Peripheral::getTypeName()) . '</small>')
                ->contains('reservationitems_id=' . $items['typed']->getID())
                ->notContains($prefix . ' inactive')->notContains($prefix . ' outside');
            $_SESSION['glpiactiveprofile']['reservation'] = 0;
            $this->string($render())->isIdenticalTo('');
        } finally {
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
            $_SESSION = $session;
            $_POST = $post;
            $CFG_GLPI['reservation_types'] = $reservationTypes;
        }
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

}

final class ReservationDisplayTextType extends \Doctrine\DBAL\Types\TextType
{
    public static $callback = null;

    public function convertToPHPValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
    {
        if (self::$callback !== null) {
            $callback = self::$callback;
            self::$callback = null;
            $callback();
        }
        return 'UPPER(' . $sqlExpr . ')';
    }

    public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string)$value . '|php';
    }
}

/** Atoum can implement the instance API; the display must use DBAL quoting. */
abstract class ReservationDisplayAdapterBase extends DBAdapter
{
    public static function getQuoteNameChar(): string
    {
        throw new LogicException('Reservation display probe requires DBAL identifier quoting.');
    }
}

/** A connection extension shares the owned fixture's real transaction. */
class ReservationDisplayConnectionProbe extends Connection
{
    public int $platformCalls = 0;
    public $secondPlatform = null;

    public function __construct(private Connection $selected, private EventManager $events)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getEventManager(): EventManager
    {
        return $this->events;
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        if (++$this->platformCalls === 2 && $this->secondPlatform !== null) {
            ($this->secondPlatform)();
        }
        return $this->selected->getDatabasePlatform();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
