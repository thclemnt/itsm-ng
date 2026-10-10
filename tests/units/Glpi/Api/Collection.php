<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\Glpi\Api;

use GLPITestCase;
use Glpi\Api\APIRest;
use RuntimeException;

class Collection extends GLPITestCase
{
    public function testOrdinaryCollectionsAdmitWarmAndFreshRoutes(): void
    {
        foreach ([\Manufacturer::class, \Netpoint::class] as $type) {
            $this->assertOrdinaryCollection($type);
        }
    }

    private function assertOrdinaryCollection(string $type): void
    {
        global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST;

        $configuration = $CFG_GLPI;
        $session = $_SESSION;
        $debugSql = $DEBUG_SQL ?? [];
        $requestCount = $SQL_TOTAL_REQUEST ?? 0;
        $tables = ConfiguredNetpoint::tableCache();
        $fixture = new $type();
        $id = null;
        $name = '_api_mapped_' . bin2hex(random_bytes(8));
        try {
            $_SESSION['glpiID'] = $_SESSION['glpiID'] ?? 2;
            $_SESSION['glpiactive_entity'] = 0;
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $_SESSION['glpilist_limit'] = 50;
            $_SESSION['glpiactiveprofile'][$type::$rightname] =
                ($_SESSION['glpiactiveprofile'][$type::$rightname] ?? 0) | READ;
            $fixture->getEmpty();
            $input = ['name' => $name, 'comment' => $name];
            if (array_key_exists('entities_id', $fixture->fields)) {
                $input['entities_id'] = 0;
            }
            $id = $fixture->add($input);
            $this->integer((int)$id)->isGreaterThan(0);
            $table = getTableForItemType($type);
            $CFG_GLPI['debug_sql'] = true;
            $_SESSION['glpi_use_mode'] = \Session::DEBUG_MODE;

            foreach (['warm', 'fresh'] as $routing) {
                if ($routing === 'fresh') {
                    // Exercise ordinary resolution again; production admission
                    // must be independent of this memoized key's presence.
                    unset($CFG_GLPI['glpitablesitemtype'][getSingular($type)]);
                    $type::forceTable('');
                } else {
                    $this->string($CFG_GLPI['glpitablesitemtype'][getSingular($type)])->isIdenticalTo($table);
                }
                $DEBUG_SQL['queries'] = [];
                $total = 0;
                $rows = (new CollectionEndpoint())->collection($type, [
                    'searchText' => ['name' => '^' . $name . '$'],
                    'only_id' => true, 'get_hateoas' => false, 'range' => '0-0',
                ], $total);
                $this->integer($total)->isIdenticalTo(1);
                $this->array(array_map(static fn (array $row): int => (int)$row['id'], $rows))->isIdenticalTo([(int)$id]);
                $this->string($CFG_GLPI['glpitablesitemtype'][getSingular($type)])->isIdenticalTo($table);
                // These successful exact results require actual retrieval. No raw
                // collection page/count means the mapped repository executed them.
                $raw = array_values(array_filter($DEBUG_SQL['queries'], static fn (string $sql): bool =>
                    str_contains($sql, $table) && preg_match('/^\s*SELECT (?:DISTINCT|COUNT\(\*\))/i', $sql)));
                $this->array($raw)->isEmpty();
            }
        } finally {
            if ($id !== null && $id !== false) {
                $fixture->delete(['id' => $id], true);
            }
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            $DEBUG_SQL = $debugSql;
            $SQL_TOTAL_REQUEST = $requestCount;
            ConfiguredNetpoint::restoreTableCache($tables);
        }
    }

    public function testConfiguredAliasUsesActualSelectedMappedColumns(): void
    {
        global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST;

        $configuration = $CFG_GLPI;
        $session = $_SESSION;
        $debugSql = $DEBUG_SQL ?? [];
        $requestCount = $SQL_TOTAL_REQUEST ?? 0;
        $tables = ConfiguredNetpoint::tableCache();
        $fixture = new \Manufacturer();
        $id = null;
        $name = '_api_alias_columns_' . bin2hex(random_bytes(8));
        try {
            $_SESSION['glpiID'] = $_SESSION['glpiID'] ?? 2;
            $_SESSION['glpiactive_entity'] = 0;
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $_SESSION['glpilist_limit'] = 50;
            // The public class inherits Netpoint, but the configured physical route
            // has Manufacturer columns. Metadata must follow that actual route.
            $CFG_GLPI['glpitablesitemtype'][ConfiguredNetpoint::class] = 'glpi_manufacturers';
            ConfiguredNetpoint::forceTable('');
            $id = $fixture->add(['name' => $name, 'comment' => 'The "selected" physical route']);
            $this->integer((int)$id)->isGreaterThan(0);
            $CFG_GLPI['debug_sql'] = true;
            $_SESSION['glpi_use_mode'] = \Session::DEBUG_MODE;
            $DEBUG_SQL['queries'] = [];
            CollectionTrace::$events = [];
            $total = 0;
            $rows = (new CollectionEndpoint())->collection(ConfiguredNetpoint::class, [
                'searchText' => ['name' => '^' . $name . '$'],
                'get_hateoas' => false, 'range' => '0-0',
            ], $total);
            $this->integer($total)->isIdenticalTo(1);
            $this->array($rows)->hasSize(1);
            $this->integer((int)$rows[0]['id'])->isIdenticalTo((int)$id);
            $this->string($rows[0]['name'])->isIdenticalTo($name);
            $this->string($rows[0]['comment'])->isIdenticalTo('The "selected" physical route');
            $this->array($rows[0])->notHasKey('entities_id')->notHasKey('locations_id');
            $this->array(CollectionTrace::$events)->isIdenticalTo(['deleted', 'assigned']);
            $raw = array_values(array_filter($DEBUG_SQL['queries'], static fn (string $sql): bool =>
                str_contains($sql, 'glpi_manufacturers') && preg_match('/^\s*SELECT (?:DISTINCT|COUNT\(\*\))/i', $sql)));
            $this->array($raw)->isEmpty();
        } finally {
            if ($id !== null && $id !== false) {
                $fixture->delete(['id' => $id], true);
            }
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            $DEBUG_SQL = $debugSql;
            $SQL_TOTAL_REQUEST = $requestCount;
            ConfiguredNetpoint::restoreTableCache($tables);
        }
    }

    public function testConfiguredCoreTableRoutesKeepOneScopeDecisionAfterText(): void
    {
        foreach ([ConfiguredNetpoint::class, ConfiguredNetpoints::class, \GlpiPlugin\Collectionprobe\Netpoint::class] as $type) {
            $this->assertConfiguredCollection($type);
        }
    }

    public function testChildScopeConstructsItsParentOnceAfterText(): void
    {
        global $CFG_GLPI;

        $configuration = $CFG_GLPI;
        $session = $_SESSION;
        $tables = ConfiguredNetpoint::tableCache();
        try {
            $_SESSION['glpiID'] = $_SESSION['glpiID'] ?? 2;
            $_SESSION['glpilist_limit'] = 50;
            $CFG_GLPI['glpitablesitemtype'][ConfiguredUserEmail::class] = 'glpi_useremails';
            ConfiguredUserEmail::forceTable('');
            CollectionTrace::$events = [];
            $total = 0;
            $rows = (new CollectionEndpoint())->collection(ConfiguredUserEmail::class, [
                'searchText' => ['email' => new CollectionPattern('_api_absent_' . bin2hex(random_bytes(8)))],
                'only_id' => true, 'get_hateoas' => false, 'range' => '0-0',
            ], $total);
            $this->array($rows)->isEmpty();
            $this->integer($total)->isIdenticalTo(0);
            $this->array(CollectionTrace::$events)->isIdenticalTo(['deleted', 'text', 'assigned', 'parent']);
        } finally {
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            ConfiguredNetpoint::restoreTableCache($tables);
        }
    }

    private function assertConfiguredCollection(string $type): void
    {
        global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST;

        $configuration = $CFG_GLPI;
        $session = $_SESSION;
        $debugSql = $DEBUG_SQL ?? [];
        $requestCount = $SQL_TOTAL_REQUEST ?? 0;
        $tables = ConfiguredNetpoint::tableCache();
        $name = '_api_route_' . bin2hex(random_bytes(8));
        $netpoint = new \Netpoint();
        $id = null;
        try {
            $_SESSION['glpiID'] = $_SESSION['glpiID'] ?? 2;
            $_SESSION['glpiactive_entity'] = 0;
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $_SESSION['glpilist_limit'] = 50;
            // This is the existing public custom/plugin-to-core-table route.
            $CFG_GLPI['glpitablesitemtype'][getSingular($type)] = 'glpi_netpoints';
            $type::forceTable('');
            $id = $netpoint->add(['name' => $name, 'entities_id' => 0, 'locations_id' => 0]);
            $this->integer((int)$id)->isGreaterThan(0);

            CollectionTrace::$events = [];
            CollectionTrace::$tables = 0;
            CollectionTrace::$tablesAtScope = null;
            $api = new CollectionEndpoint();
            $total = 0;
            $rows = $api->collection($type, [
                'searchText' => ['name' => new CollectionPattern('^' . $name . '$')],
                'only_id' => true, 'get_hateoas' => false, 'range' => '0-0',
            ], $total);
            $this->integer($total)->isIdenticalTo(1);
            $this->array(array_map(static fn (array $row): int => (int)$row['id'], $rows))->isIdenticalTo([(int)$id]);
            $this->array(CollectionTrace::$events)->isIdenticalTo(['deleted', 'text', 'assigned', 'recursive']);
            // Original table reads occur during getEmpty and scope preparation.
            // A configured route must not probe getTable again for ORM admission.
            $this->integer(CollectionTrace::$tables)->isIdenticalTo(CollectionTrace::$tablesAtScope);

            // Compatible configured aliases admit scalar filters. Plugin routes
            // retain raw SQL; Stringable inputs above deliberately declined mapping.
            $CFG_GLPI['debug_sql'] = true;
            $_SESSION['glpi_use_mode'] = \Session::DEBUG_MODE;
            $DEBUG_SQL['queries'] = [];
            $scalar = $api->collection($type, [
                'searchText' => ['name' => '^' . $name . '$'],
                'only_id' => true, 'get_hateoas' => false, 'range' => '0-0',
            ], $total);
            $this->integer($total)->isIdenticalTo(1);
            $this->array(array_map(static fn (array $row): int => (int)$row['id'], $scalar))->isIdenticalTo([(int)$id]);
            $collectionSql = array_values(array_filter($DEBUG_SQL['queries'], static fn (string $sql): bool =>
                str_contains($sql, 'glpi_netpoints') && preg_match('/^\s*SELECT (?:DISTINCT|COUNT\(\*\))/i', $sql)));
            if (isPluginItemType($type)) {
                $this->array($collectionSql)->hasSize(2);
                $this->string($collectionSql[0])->matches('/^\s*SELECT DISTINCT/i');
                $this->string($collectionSql[1])->matches('/^\s*SELECT COUNT\(\*\)/i');
            } else {
                $this->array($collectionSql)->isEmpty();
            }
        } finally {
            if ($id !== null && $id !== false) {
                $netpoint->delete(['id' => $id], true);
            }
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            $DEBUG_SQL = $debugSql;
            $SQL_TOTAL_REQUEST = $requestCount;
            ConfiguredNetpoint::restoreTableCache($tables);
        }
    }
}

/** Expose the existing protected collection endpoint; auth checks still execute. */
class CollectionEndpoint extends APIRest
{
    public function __construct()
    {
        $this->session_write = true;
        $this->app_tokens = [1 => '_collection_unit_app'];
        $this->parameters = [
            'app_token' => '_collection_unit_app',
            'session_token' => session_id() ?: '_collection_unit_session',
        ];
    }

    public function collection(string $type, array $params, int &$total): array
    {
        return $this->getItems($type, $params, $total);
    }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new RuntimeException('Unexpected API response ' . $httpcode . ': ' . json_encode($response));
    }
}

class CollectionTrace
{
    public static array $events = [];
    public static int $tables = 0;
    public static ?int $tablesAtScope = null;
}

class CollectionPattern
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        CollectionTrace::$events[] = 'text';
        return $this->value;
    }
}

class ConfiguredNetpoint extends \Netpoint
{
    public static function tableCache(): array
    {
        return self::$tables_of;
    }

    public static function restoreTableCache(array $tables): void
    {
        self::$tables_of = $tables;
    }

    public static function canView()
    {
        return true;
    }

    public static function getTable($classname = null)
    {
        ++CollectionTrace::$tables;
        return parent::getTable($classname);
    }

    public function maybeDeleted()
    {
        CollectionTrace::$events[] = 'deleted';
        return parent::maybeDeleted();
    }

    public function isEntityAssign()
    {
        CollectionTrace::$events[] = 'assigned';
        return parent::isEntityAssign();
    }

    public function maybeRecursive()
    {
        CollectionTrace::$events[] = 'recursive';
        $recursive = parent::maybeRecursive();
        CollectionTrace::$tablesAtScope = CollectionTrace::$tables;
        return $recursive;
    }
}

/** Existing DbUtils routing normalizes plural public keys before config lookup. */
class ConfiguredNetpoints extends ConfiguredNetpoint
{
}

/** Exercise CommonDBChild's actual parent-construction path, not a fake scope result. */
class ConfiguredUserEmail extends \UserEmail
{
    public static function canView()
    {
        return true;
    }

    public function maybeDeleted()
    {
        CollectionTrace::$events[] = 'deleted';
        return parent::maybeDeleted();
    }

    public function isEntityAssign()
    {
        CollectionTrace::$events[] = 'assigned';
        return parent::isEntityAssign();
    }

    public function getItem($getFromDB = true, $getEmpty = true)
    {
        CollectionTrace::$events[] = 'parent';
        return parent::getItem($getFromDB, $getEmpty);
    }
}

namespace GlpiPlugin\Collectionprobe;

/** The ordinary registered plugin itemtype namespace, routed by public config. */
class Netpoint extends \tests\units\Glpi\Api\ConfiguredNetpoint
{
}
