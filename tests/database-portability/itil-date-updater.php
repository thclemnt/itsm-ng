<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-date-updater.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    // Native query text and parameters must not be printed by this contract.
    fwrite(STDERR, 'ITIL updater contract failed: ' . $error::class . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Dedicated idle caller required');
$adapter = $DB;
$savedSession = $_SESSION;
$savedSessionId = session_id();
$savedSessionStatus = session_status();
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$savedRequest = $_REQUEST;
$savedCookies = $_COOKIE;
$savedGet = $_GET;
$savedPost = $_POST;
$savedTranslation = $TRANSLATE;
$savedLocale = class_exists(Locale::class) ? Locale::getDefault() : null;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$tables = ['glpi_users', 'glpi_tickets', 'glpi_problems', 'glpi_changes', 'glpi_itilfollowups',
    'glpi_logs', 'glpi_events', 'glpi_queuednotifications', 'glpi_groups',
    'glpi_tickets_users', 'glpi_problems_users', 'glpi_changes_users', 'glpi_groups_problems', 'glpi_changes_groups'];
$allRows = static fn (string $table): array => $connection->fetchAllAssociative(
    'SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id'
);
$read = static fn (string $table, int $id): array => $connection->fetchAssociative(
    'SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]
);
$before = [];
foreach ($tables as $table) {
    $before[$table] = $allRows($table);
}
$frame = OwnedMutationFrame::begin($connection);
$primary = null;
$rollbackProven = false;
$cleanupFailures = 0;
try {
    $_SESSION['glpiextauth'] = 0;
    unset($_SESSION['glpicronuserrunning']);
    verify((new Auth())->login('itsm', 'itsm', true), 'Real authenticated administrator');
    $human = (int)Session::getLoginUserID();
    verify($human > 0, 'Authenticated user is a real positive actor');
    $authenticated = $_SESSION;
    $CFG_GLPI['use_notifications'] = false;
    $prefix = 'ITIL updater ' . bin2hex(random_bytes(6));
    $fixtures = new FixtureRecords($DB);
    $fallback = $fixtures->create('glpi_users', ['name' => $prefix . ' fallback']);
    $previous = $fixtures->create('glpi_users', ['name' => $prefix . ' previous']);
    $group = $fixtures->create('glpi_groups', ['name' => $prefix . ' actual assignment']);
    $missing = 100 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_users');
    verify(!$connection->fetchOne('SELECT id FROM glpi_users WHERE id = ?', [$missing]), 'Invalid positive actor genuinely absent');
    $clock = new DateTimeImmutable('2031-02-03 04:05:06', new DateTimeZone('UTC'));
    $advance = static function () use (&$clock): void {
        $clock = $clock->modify('+1 second');
        $_SESSION['glpi_currenttime'] = $clock->format('Y-m-d H:i:s');
    };
    $assertActorDate = static function (string $table, int $id, ?int $actor) use ($read): void {
        $row = $read($table, $id);
        verify(($row['users_id_lastupdater'] === null ? null : (int)$row['users_id_lastupdater']) === $actor, 'Actual owning actor matches selection');
        verify((new DateTimeImmutable($row['date_mod']))->getTimestamp() === (new DateTimeImmutable($_SESSION['glpi_currenttime']))->getTimestamp(), 'Actual modification clock still advances');
    };
    $selectActor = static function (string $mode) use ($authenticated): void {
        $_SESSION = $authenticated;
        unset($_SESSION['glpicronuserrunning']);
        if ($mode === 'absent') {
            unset($_SESSION['glpiID']);
        } elseif ($mode === 'zero') {
            $_SESSION['glpiID'] = 0;
        } elseif ($mode === 'cron') {
            $_SESSION['glpicronuserrunning'] = 'cron_updater_contract';
        }
    };
    foreach ([Ticket::class, Problem::class, Change::class] as $type) {
        $table = $type::getTable();
        foreach ([null, $previous] as $stored) {
            $values = ['name' => $prefix . ' ' . $type, 'users_id_lastupdater' => $stored,
                'date_mod' => new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'))];
            if ($type === Ticket::class) {
                $values['takeintoaccount_delay_stat'] = 1;
            }
            $id = $fixtures->create($table, $values);
            foreach ([['absent', 0, $stored], ['zero', 0, $stored], ['cron', 0, $stored], ['absent', $fallback, $fallback],
                ['authenticated', $fallback, $human], ['authenticated', $missing, $human],
                ['cron', $fallback, $fallback], ['cron', 0, $fallback]] as [$mode, $supplied, $expected]) {
                $selectActor($mode);
                $advance();
                $rowBefore = $read($table, $id);
                (new $type())->updateDateMod($id, true, $supplied);
                $assertActorDate($table, $id, $expected);
                verify(array_diff_key($read($table, $id), ['date_mod' => true, 'users_id_lastupdater' => true])
                    === array_diff_key($rowBefore, ['date_mod' => true, 'users_id_lastupdater' => true]), 'All unrelated native cells remain exact');
                $frame->assertActive();
            }
            $selectActor('absent');
            $advance();
            $rowBefore = $read($table, $id);
            $refusal = OwnedMutationFrame::begin($connection);
            $refusalPrimary = null;
            try {
                try {
                    (new $type())->updateDateMod($id, true, $missing);
                } catch (Throwable) {
                    // MySQL can deliver the native error through its installed SQL handler.
                }
                $code = (string)$DB->errno();
                $message = $DB->error();
                verify(in_array($code, ['23503', '1452'], true)
                    && str_contains($message, ForeignKeys::name($table, 'users_id_lastupdater')), 'Positive invalid actor retains selected native FK refusal');
            } catch (Throwable $error) {
                $refusalPrimary = $error;
            } finally {
                try {
                    $refusal->rollBack();
                } catch (Throwable $cleanup) {
                    throw $refusalPrimary === null ? $cleanup : new MutationRollbackFailure($refusalPrimary, $cleanup);
                }
            }
            if ($refusalPrimary !== null) {
                throw $refusalPrimary;
            }
            verify($read($table, $id) === $rowBefore, 'Rejected actor leaves entire native row unchanged');
        }
    }
    $events = [];
    $plugins->setValue(null, [...$savedPlugins, 'itil_updater_fixture']);
    foreach (['item_add', 'item_update', 'item_delete'] as $event) {
        $PLUGIN_HOOKS[$event]['itil_updater_fixture'][ITILFollowup::class] = static function (ITILFollowup $item) use (&$events, $event): void {
            $events[] = $event;
        };
    }
    foreach (['authenticated', 'absent', 'zero', 'cron'] as $mode) {
        $selectActor($mode);
        if ($mode === 'cron') {
            unset($_SESSION['glpiID']); // A genuine non-human cron, separate from priority controls above.
        }
        $ticket = $fixtures->create('glpi_tickets', ['name' => $prefix . ' public ' . $mode,
            'takeintoaccount_delay_stat' => 1, 'users_id_lastupdater' => $previous]);
        $advance();
        $logsBefore = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE itemtype = ? AND items_id = ?', ['Ticket', $ticket]);
        $followup = new ITILFollowup();
        $events = [];
        $id = $followup->add(['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'Public owner add',
            'users_id' => $fallback, '_do_not_compute_takeintoaccount' => true, '_disablenotif' => true]);
        verify((int)$id > 0, 'Real public followup add');
        $expected = $mode === 'authenticated' ? $human : $fallback;
        $assertActorDate('glpi_tickets', $ticket, $expected);
        verify((int)$read('glpi_itilfollowups', (int)$id)['users_id'] === $fallback, 'Supplied real followup author remains independent of session updater');
        verify($events === ['item_add'], 'Actual public add hook retained');
        $advance();
        verify($followup->update(['id' => $id, 'content' => 'Public owner edit', 'users_id_editor' => $fallback, '_disablenotif' => true]), 'Real public followup content edit');
        $assertActorDate('glpi_tickets', $ticket, $expected);
        verify($events === ['item_add', 'item_update'], 'Actual public edit hook retained');
        $advance();
        verify($followup->delete(['id' => $id, '_disablenotif' => true], true), 'Real public followup cleanup');
        $assertActorDate('glpi_tickets', $ticket, $expected);
        verify(!$connection->fetchOne('SELECT id FROM glpi_itilfollowups WHERE id = ?', [$id]), 'Cleanup removes real child');
        verify($events === ['item_add', 'item_update', 'item_delete'], 'Actual public delete hook retained');
        $actions = $connection->fetchFirstColumn('SELECT linked_action FROM glpi_logs WHERE itemtype = ? AND items_id = ? ORDER BY id', ['Ticket', $ticket]);
        foreach ([Log::HISTORY_ADD_SUBITEM, Log::HISTORY_UPDATE_SUBITEM, Log::HISTORY_DELETE_SUBITEM] as $action) {
            verify(in_array($action, array_map('intval', $actions), true), 'Real followup history action retained');
        }
        verify(count($actions) >= $logsBefore + 3, 'Lifecycle history is not suppressed');
        $frame->assertActive();
    }
    $selectActor('absent');
    $ticket = $fixtures->create('glpi_tickets', ['name' => $prefix . ' anonymous', 'takeintoaccount_delay_stat' => 1]);
    $advance();
    $child = new ITILFollowup();
    $id = $child->add(['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => 'Absent author', '_do_not_compute_takeintoaccount' => true, '_disablenotif' => true]);
    verify((int)$id > 0 && $read('glpi_itilfollowups', (int)$id)['users_id'] === null, 'Unlogged trusted child retains genuine nullable author');
    $assertActorDate('glpi_tickets', $ticket, null);
    $advance();
    verify($child->delete(['id' => $id, '_disablenotif' => true], true), 'Unlogged nullable child public cleanup');
    $assertActorDate('glpi_tickets', $ticket, null);
    $advance();
    $publicTicket = new Ticket();
    $ticket = $publicTicket->add(['name' => $prefix . ' public nested followup', 'content' => 'Public ticket',
        'entities_id' => 0, 'takeintoaccount_delay_stat' => 1,
        '_followup' => ['content' => 'Original public nested followup'], '_disablenotif' => true]);
    verify((int)$ticket > 0, 'Unlogged real Ticket add with nested public Followup');
    $assertActorDate('glpi_tickets', (int)$ticket, null);
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_itilfollowups WHERE tickets_id = ?', [$ticket]) === 1, 'Actual nested child persisted through the public Ticket caller');
    verify($publicTicket->delete(['id' => $ticket, '_disablenotif' => true], true), 'Original public Ticket cleanup with Followup child');
    verify(!$connection->fetchOne('SELECT id FROM glpi_itilfollowups WHERE tickets_id = ?', [$ticket]), 'Parent cleanup retires actual Followup child');
    foreach ([Ticket_User::class, Problem_User::class, Change_User::class, Group_Problem::class, Change_Group::class] as $relationType) {
        $parentType = $relationType::$itemtype_1;
        $parentTable = $parentType::getTable();
        $parentKey = $relationType::getItilObjectForeignKey();
        $actorKey = $relationType::$items_id_2;
        $actorId = $actorKey === 'users_id' ? $fallback : $group;
        foreach (['absent', 'authenticated'] as $mode) {
            foreach ([null, $previous] as $stored) {
                $selectActor($mode);
                $values = ['name' => $prefix . ' real actor ' . $relationType,
                    'status' => CommonITILObject::SOLVED, 'users_id_lastupdater' => $stored];
                if ($parentType === Ticket::class) {
                    $values['takeintoaccount_delay_stat'] = 1;
                }
                $parentId = $fixtures->create($parentTable, $values);
                $advance();
                $parentBefore = $read($parentTable, $parentId);
                $events = [];
                foreach (['item_add', 'item_delete'] as $event) {
                    $PLUGIN_HOOKS[$event]['itil_updater_fixture'][$relationType] = static function (CommonITILActor $item) use (&$events, $event): void {
                        $events[] = $event;
                    };
                }
                $relation = new $relationType();
                $actor = $relation->add([$parentKey => $parentId, $actorKey => $actorId,
                    'type' => $actorKey === 'groups_id' ? CommonITILActor::ASSIGN : CommonITILActor::OBSERVER,
                    '_disablenotif' => true]);
                verify((int)$actor > 0, 'Real public user/group actor add');
                $expected = $mode === 'authenticated' ? $human : $stored;
                $assertActorDate($parentTable, $parentId, $expected);
                verify((int)$read($relationType::getTable(), (int)$actor)[$actorKey] === $actorId, 'Actual valid user/group affinity retained');
                verify(array_diff_key($read($parentTable, $parentId), ['date_mod' => true, 'users_id_lastupdater' => true])
                    === array_diff_key($parentBefore, ['date_mod' => true, 'users_id_lastupdater' => true]), 'Public actor add preserves all other parent cells');
                verify($events === ['item_add'], 'Real actor add hook retained');
                $advance();
                verify($relation->delete(['id' => $actor, '_disablenotif' => true], true), 'Real actor public cleanup');
                $assertActorDate($parentTable, $parentId, $expected);
                verify(!$connection->fetchOne('SELECT id FROM ' . $connection->quoteIdentifier($relationType::getTable()) . ' WHERE id = ?', [$actor]), 'Actual actor row removed by public cleanup');
                verify($events === ['item_add', 'item_delete'], 'Real actor cleanup hook retained');
                $frame->assertActive();
            }
        }
    }
    foreach ([Problem::class, Change::class] as $parentType) {
        $selectActor('absent');
        $advance();
        $parent = new $parentType();
        $id = $parent->add(['name' => $prefix . ' public group assignment ' . $parentType,
            'content' => 'Original pre-login assignment caller', 'entities_id' => 0,
            '_groups_id_assign' => $group, '_disablenotif' => true]);
        verify((int)$id > 0, 'Original public Problem/Change group assignment before login');
        $assertActorDate($parentType::getTable(), (int)$id, null);
        $relationType = $parentType === Problem::class ? Group_Problem::class : Change_Group::class;
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($relationType::getTable())
            . ' WHERE ' . $connection->quoteIdentifier($relationType::getItilObjectForeignKey()) . ' = ? AND groups_id = ?', [$id, $group]) === 1, 'Real nested assigned-group actor retained');
        verify($parent->delete(['id' => $id, '_disablenotif' => true], true), 'Original assigned Problem/Change public cleanup');
    }
    verify($allRows('glpi_queuednotifications') === $before['glpi_queuednotifications'], 'Explicit notification-disable retains exact existing queue');
    verify($DB === $adapter && $DB->getDoctrineConnection() === $connection, 'Original configured owner retained');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        verify($DB === $adapter && $DB->getDoctrineConnection() === $connection, 'Cleanup owns original supplied connection');
        $frame->rollBack();
        $rollbackProven = true;
    } catch (Throwable $cleanup) {
        $primary = $primary === null ? $cleanup : new MutationRollbackFailure($primary, $cleanup);
    }
    $cleanup = static function (callable $operation) use (&$primary, &$cleanupFailures): void {
        try {
            $operation();
        } catch (Throwable $error) {
            if ($primary === null) {
                $primary = $error;
            } else {
                ++$cleanupFailures;
            }
        }
    };
    $cleanup(static function () use ($savedSessionId, $savedSessionStatus): void {
        if (session_id() !== $savedSessionId || session_status() !== $savedSessionStatus) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            session_id($savedSessionId);
            if ($savedSessionStatus === PHP_SESSION_ACTIVE) {
                Session::start();
            }
        }
    });
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $_REQUEST = $savedRequest;
    $_COOKIE = $savedCookies;
    $_GET = $savedGet;
    $_POST = $savedPost;
    $TRANSLATE = $savedTranslation;
    $cleanup(static fn () => $plugins->setValue(null, $savedPlugins));
    if ($savedLocale !== null) {
        $cleanup(static fn () => Locale::setDefault($savedLocale));
    }
    if ($rollbackProven) {
        foreach ($tables as $table) {
            $cleanup(static fn () => verify($allRows($table) === $before[$table], 'Exact pre-contract native rows restored'));
        }
    }
}
if ($cleanupFailures > 0) {
    fwrite(STDERR, 'Secondary cleanup failures: ' . $cleanupFailures . "\n");
}
if ($primary !== null) {
    throw $primary;
}
echo $DB->getProvider() . ': ITIL actor ownership and public followup lifecycle passed (' . $assertions . " assertions).\n";
