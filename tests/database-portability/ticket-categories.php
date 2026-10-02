<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketCategoryRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/ticket-categories.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedPost = $_POST;
$savedCache = $GLPI_CACHE;
// Suite fixtures roll back SQL but leave filesystem caches. The full-order
// failure returned ancestors [0] for a reused child ID whose stored parent was
// 1. Keep warm-cache behavior within this fixture's own transactional graph.
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $parent = (int)(new Entity())->add(['name' => 'Category parent', 'entities_id' => 0]);
    $child = (int)(new Entity())->add(['name' => 'Category child', 'entities_id' => $parent]);
    $foreign = (int)(new Entity())->add(['name' => 'Category foreign', 'entities_id' => 0]);
    verify($parent > 0 && $child > 0 && $foreign > 0, 'Entity hierarchy');
    verify(in_array($parent, getAncestorsOf('glpi_entities', $child), true), 'Warm ancestor cache reflects the actual fixture parent');
    $ids = [];
    foreach (['incident', 'request', 'both', 'hidden', 'parent_recursive', 'parent_local', 'foreign'] as $name) {
        $ids[$name] = $fixtures->create('glpi_itilcategories', ['name' => $name, 'completename' => 'Category ' . $name,
            'entities_id' => str_starts_with($name, 'parent') ? $parent : ($name === 'foreign' ? $foreign : $child),
            'is_recursive' => $name === 'parent_recursive', 'is_helpdeskvisible' => $name !== 'hidden',
            'is_incident' => $name !== 'request', 'is_request' => $name !== 'incident']);
    }
    $em = Orm::create($DB);
    $repository = new TicketCategoryRepository($em);
    $ownIds = static fn (array $choices): array => array_values(array_intersect(array_keys($choices), array_values($ids)));
    $incident = $ownIds($repository->choices(Ticket::INCIDENT_TYPE, [$child], false));
    verify($incident === [$ids['both'], $ids['hidden'], $ids['incident'], $ids['parent_recursive']], 'Incident choices scope child plus recursive ancestor and preserve label ordering: ' . json_encode(['actual' => $incident, 'expected' => $ids, 'parent' => $parent, 'child' => $child, 'cached_ancestors' => getAncestorsOf('glpi_entities', $child), 'stored_parent' => $DB->getDoctrineConnection()->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = ?', [$child])]));
    verify($ownIds($repository->choices(Ticket::DEMAND_TYPE, [$child], true)) === [$ids['both'], $ids['parent_recursive'], $ids['request']], 'Helpdesk request choices apply type and visibility flags');
    verify($repository->choices(Ticket::INCIDENT_TYPE, [], false) === [], 'Empty scope never lists all categories');
    verify(!isset($repository->choices(Ticket::INCIDENT_TYPE, [$child], false)[$ids['foreign']]), 'Sibling entity category cannot leak');
    $em->clear();
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $endpoint = static function (array $post): array {
        $_POST = $post;
        ob_start();
        try {
            include GLPI_ROOT . '/ajax/dropdownTicketCategories.php';
            return json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    };
    $choices = $endpoint(['entity_restrict' => $child, 'type' => Ticket::INCIDENT_TYPE]);
    verify($ownIds($choices) === [$ids['both'], $ids['hidden'], $ids['incident'], $ids['parent_recursive']] && $choices[0] === Dropdown::EMPTY_VALUE, 'Actual endpoint returns scoped choices and legacy empty option');
    verify($endpoint(['entity_restrict' => $foreign, 'type' => Ticket::INCIDENT_TYPE]) === [0 => Dropdown::EMPTY_VALUE], 'Forged foreign scope is intersected with authorized active entities');
    verify($endpoint(['entity_restrict' => [], 'type' => Ticket::INCIDENT_TYPE]) === [0 => Dropdown::EMPTY_VALUE], 'Explicit empty endpoint scope does not fall back to root');
    verify($endpoint(['entity_restrict' => 'not-an-entity', 'type' => Ticket::INCIDENT_TYPE]) === [0 => Dropdown::EMPTY_VALUE], 'Malformed scope cannot become root by integer coercion');
    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $choices = $endpoint(['entity_restrict' => $child, 'type' => Ticket::DEMAND_TYPE]);
    verify($ownIds($choices) === [$ids['both'], $ids['parent_recursive'], $ids['request']], 'Actual helpdesk endpoint hides non-visible categories');
    verify($SQL_TOTAL_REQUEST === 0, 'Category endpoint bypasses native/adapter queries');
} finally {
    $_SESSION = $savedSession;
    $_POST = $savedPost;
    $DB->rollBack();
    $GLPI_CACHE = $savedCache;
}
echo $DB->getProvider() . ": ticket category type/helpdesk flags, recursive owning entity scope, scoped JSON endpoint and malformed/foreign/empty input isolation passed.\n";
