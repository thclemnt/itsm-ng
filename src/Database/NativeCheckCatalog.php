<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use RuntimeException;

/** One operation's native CHECK ownership, clause and enforcement snapshot. */
final class NativeCheckCatalog
{
    public static function snapshot(Connection $connection, ?string $table = null): array
    {
        CheckConstraintSupport::assertSupported($connection);
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        // MariaDB deparses CHECK identifiers under this actual session mode.
        // Inspect it without changing the connection's interpretation.
        $ansiQuotes = $mysql && in_array('ANSI_QUOTES', explode(',', (string)$connection->fetchOne('SELECT @@SESSION.sql_mode')), true);
        return ['mysql' => $mysql, 'ansi_quotes' => $ansiQuotes, 'checks' => self::readChecks($connection, $table)];
    }

    private static function readChecks(Connection $connection, ?string $table = null): array
    {
        $checks = [];
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if ($connection->getDatabasePlatform() instanceof MariaDBPlatform) {
                // MariaDB owns each CHECK's table in this native catalogue.
                // Enforcement is session-wide and was asserted above.
                $query = "SELECT cc.TABLE_NAME AS table_name, cc.CONSTRAINT_NAME AS constraint_name, cc.CHECK_CLAUSE AS clause, 'YES' AS enforced "
                    . 'FROM information_schema.CHECK_CONSTRAINTS cc WHERE cc.CONSTRAINT_SCHEMA = DATABASE()';
                $parameters = [];
                if ($table !== null) {
                    $query .= ' AND cc.TABLE_NAME = ?';
                    $parameters[] = $table;
                }
                foreach ($connection->fetchAllAssociative($query, $parameters) as $check) {
                    $checks[$check['table_name']][$check['constraint_name']] = $check;
                }
            } else {
                $checks = self::readMySQLChecks($connection, $table);
            }
        }
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $query = 'SELECT t.relname AS table_name, c.conname AS constraint_name, '
                . 'pg_get_expr(c.conbin, c.conrelid) AS clause, c.convalidated AS validated, '
                . "COALESCE(to_jsonb(c)->>'conenforced', 'true') AS enforced, "
                . 'c.conbin::text AS native_nodes, '
                . "(SELECT jsonb_object_agg(a.attname, jsonb_build_object('attribute_number', a.attnum::text, 'type_oid', a.atttypid::text, "
                . "'type_modifier', a.atttypmod::text, 'collation_oid', a.attcollation::text, 'not_null', a.attnotnull, 'generated', a.attgenerated)) "
                . "FROM pg_catalog.pg_attribute a WHERE a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) AND NOT a.attisdropped)::text AS selection_column_identity, "
                . "(SELECT jsonb_object_agg(o.oid::text, jsonb_build_object('name', o.oprname, 'left_type', o.oprleft::text, 'right_type', o.oprright::text, "
                . "'function_oid', o.oprcode::oid::text)) FROM pg_catalog.pg_operator o JOIN pg_catalog.pg_namespace onsp ON onsp.oid=o.oprnamespace "
                . "JOIN pg_catalog.pg_proc p ON p.oid=o.oprcode JOIN pg_catalog.pg_namespace pn ON pn.oid=p.pronamespace "
                . "WHERE onsp.nspname='pg_catalog' AND pn.nspname='pg_catalog' AND o.oprresult='pg_catalog.bool'::regtype "
                . "AND p.prorettype='pg_catalog.bool'::regtype AND NOT p.proretset AND p.provariadic=0 AND p.provolatile='i' "
                . "AND p.proargtypes::text=(o.oprleft::text || ' ' || o.oprright::text) "
                . "AND ((o.oprname IN ('=', '<>', '>', '>=') AND o.oprleft IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype) "
                . "AND o.oprright IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype)) "
                . "OR (o.oprname='=' AND o.oprleft='pg_catalog.bool'::regtype AND o.oprright='pg_catalog.bool'::regtype)))::text AS selection_operator_bindings, "
                . "COALESCE((SELECT jsonb_agg(a.attname ORDER BY k.position) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, position) "
                . "JOIN pg_catalog.pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum), '[]'::jsonb)::text AS checked_columns, "
                . "(SELECT jsonb_agg(o.oid::text ORDER BY o.oid) FROM pg_catalog.pg_operator o "
                . "JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace WHERE n.nspname = 'pg_catalog' AND o.oprname = '>=' "
                . "AND o.oprleft IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype) "
                . "AND o.oprright IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype))::text AS integer_ge_oids, "
                . "(SELECT jsonb_agg(o.oid::text ORDER BY o.oid) FROM pg_catalog.pg_operator o "
                . "JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace WHERE n.nspname = 'pg_catalog' AND o.oprname IN ('=', '<>', '>', '>=') "
                . "AND ((o.oprleft = 'pg_catalog.text'::regtype AND o.oprright = 'pg_catalog.text'::regtype) "
                . "OR (o.oprleft IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype) "
                . "AND o.oprright IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype))))::text AS reference_operator_oids, "
                . "(SELECT jsonb_object_agg(o.oid::text, o.oprcode::oid::text) FROM pg_catalog.pg_operator o "
                . "JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace JOIN pg_catalog.pg_proc p ON p.oid = o.oprcode "
                . "JOIN pg_catalog.pg_namespace pn ON pn.oid = p.pronamespace WHERE n.nspname = 'pg_catalog' AND pn.nspname = 'pg_catalog' AND p.prorettype='pg_catalog.bool'::regtype AND NOT p.proretset "
                . "AND o.oprname IN ('=', '<>', '>', '>=') AND ((o.oprleft = 'pg_catalog.text'::regtype AND o.oprright = 'pg_catalog.text'::regtype) "
                . "OR (o.oprleft IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype) "
                . "AND o.oprright IN ('pg_catalog.int2'::regtype, 'pg_catalog.int4'::regtype, 'pg_catalog.int8'::regtype))))::text AS subject_operator_functions, "
                . "jsonb_build_object('boolean', 'pg_catalog.bool'::regtype::oid::text, 'varchar', 'pg_catalog.varchar'::regtype::oid::text, "
                . "'varchar_array', 'pg_catalog.varchar[]'::regtype::oid::text, 'text', 'pg_catalog.text'::regtype::oid::text, "
                . "'smallint', 'pg_catalog.int2'::regtype::oid::text, 'integer', 'pg_catalog.int4'::regtype::oid::text, 'bigint', 'pg_catalog.int8'::regtype::oid::text, "
                . "'integer_to_bigint', (SELECT pc.castfunc::text FROM pg_catalog.pg_cast pc JOIN pg_catalog.pg_proc p ON p.oid=pc.castfunc "
                . "JOIN pg_catalog.pg_namespace pn ON pn.oid=p.pronamespace WHERE pc.castsource='pg_catalog.int4'::regtype "
                . "AND pc.casttarget='pg_catalog.int8'::regtype AND pc.castmethod='f' AND pc.castcontext='i' AND pn.nspname='pg_catalog' "
                . "AND p.prorettype=pc.casttarget AND p.proargtypes::text=pc.castsource::text AND NOT p.proretset AND p.provolatile='i'), "
                . "'text_array', 'pg_catalog.text[]'::regtype::oid::text, 'binary', EXISTS (SELECT 1 FROM pg_catalog.pg_cast pc "
                . "WHERE pc.castsource = 'pg_catalog.varchar'::regtype AND pc.casttarget = 'pg_catalog.text'::regtype "
                . "AND pc.castmethod = 'b' AND pc.castfunc = 0))::text AS reference_text_coercion "
                . 'FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class t ON t.oid = c.conrelid '
                . "WHERE c.contype = 'c' AND pg_catalog.pg_table_is_visible(t.oid)";
            $parameters = [];
            if ($table !== null) {
                $query .= ' AND t.relname = ?';
                $parameters[] = $table;
            }
            foreach ($connection->fetchAllAssociative($query, $parameters) as $check) {
                $checks[$check['table_name']][$check['constraint_name']] = $check;
            }
        }
        ksort($checks);
        foreach ($checks as &$tableChecks) {
            ksort($tableChecks);
        }
        unset($tableChecks);
        return $checks;
    }

    private static function readMySQLChecks(Connection $connection, ?string $table): array
    {
        // MySQL CHECK names are unique within a schema. Inspect the native views
        // independently: joining their lateral owner view can omit whole tables.
        $query = 'SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name, ENFORCED AS enforced '
            . "FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'";
        $parameters = [];
        if ($table !== null) {
            $query .= ' AND TABLE_NAME = ?';
            $parameters[] = $table;
        }
        $owners = [];
        foreach ($connection->fetchAllAssociative($query, $parameters) as $owner) {
            $name = $owner['constraint_name'];
            if (isset($owners[$name])) {
                throw new RuntimeException('Ambiguous native CHECK ownership: ' . $name);
            }
            $owners[$name] = $owner;
        }
        if ($table !== null && $owners === []) {
            return [];
        }
        $query = 'SELECT CONSTRAINT_NAME AS constraint_name, CHECK_CLAUSE AS clause '
            . 'FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()';
        $parameters = $types = [];
        if ($table !== null) {
            $query .= ' AND CONSTRAINT_NAME IN (?)';
            $parameters = [array_keys($owners)];
            $types = [ArrayParameterType::STRING];
        }
        $clauses = [];
        foreach ($connection->fetchAllAssociative($query, $parameters, $types) as $check) {
            $name = $check['constraint_name'];
            if (array_key_exists($name, $clauses)) {
                throw new RuntimeException('Ambiguous native CHECK clause: ' . $name);
            }
            $clauses[$name] = $check['clause'];
        }
        $checks = [];
        foreach ($owners as $name => $owner) {
            if (!isset($clauses[$name])) {
                throw new RuntimeException('Native CHECK owner lacks a clause: ' . $owner['table_name'] . '.' . $name);
            }
            $checks[$owner['table_name']][$name] = [
                'table_name' => $owner['table_name'],
                'constraint_name' => $name,
                'clause' => $clauses[$name],
                'enforced' => $owner['enforced'],
            ];
            unset($clauses[$name]);
        }
        if ($clauses !== []) {
            throw new RuntimeException('Native CHECK clause lacks an owner: ' . array_key_first($clauses));
        }
        return $checks;
    }

}
