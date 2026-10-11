<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;

/**
 * Frozen installation input: 355 historical tables before the 2.2.0 ORM transition.
 * Captured from glpi-empty.sql at 897c5c9bd636f65e5a2a596d350ef087635303f9.
 * PostgreSQL flag types, defaults and expression indexes are frozen here too.
 * Never consult current entities or amend this history for a new schema change.
 */
final class Baseline
{
    public const PHASE = '20261001_baseline_legacy_2_2';

    public function build(AbstractPlatform $platform): Schema
    {
        $postgres = $platform instanceof PostgreSQLPlatform;
        $schema = new Schema();

        $table = $schema->createTable('`glpi_alerts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '0', 'comment' => 'see define.php ALERT_* constant']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['default' => 'CURRENT_TIMESTAMP'] : ['default' => 'CURRENT_TIMESTAMP', 'columnDefinition' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`type`'], 'glpi_alerts_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`type`'], 'glpi_alerts_type', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_alerts_date', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`type`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`type`'], 'type', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notificationchatconfigs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`hookurl`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`chat`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_authldapreplicates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`authldaps_id`', 'integer', ['default' => '0']);
        $table->addColumn('`host`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`port`', 'integer', ['default' => '389']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`authldaps_id`'], 'glpi_authldapreplicates_authldaps_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`authldaps_id`'], 'authldaps_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_authldaps`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`host`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`basedn`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`rootdn`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`port`', 'integer', ['default' => '389']);
        $table->addColumn('`condition`', 'text', ['notnull' => false]);
        $table->addColumn('`login_field`', 'string', ['default' => 'uid', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`sync_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`use_tls`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`group_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`group_condition`', 'text', ['notnull' => false]);
        $table->addColumn('`group_search_type`', 'integer', ['default' => '0']);
        $table->addColumn('`group_member_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email1_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`realname_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`firstname_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phone_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phone2_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mobile_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`use_dn`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`time_offset`', 'integer', ['default' => '0', 'comment' => 'in seconds']);
        $table->addColumn('`deref_option`', 'integer', ['default' => '0']);
        $table->addColumn('`title_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`category_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`language_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entity_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entity_condition`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`rootdn_passwd`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`registration_number_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email2_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email3_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email4_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`location_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`responsible_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`pagesize`', 'integer', ['default' => '0']);
        $table->addColumn('`ldap_maxlimit`', 'integer', ['default' => '0']);
        $table->addColumn('`can_support_pagesize`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`inventory_domain`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_authldaps_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'glpi_authldaps_is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_authldaps_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_authldaps_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`sync_field`'], 'glpi_authldaps_sync_field', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`sync_field`'], 'sync_field', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_authmails`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`connect_string`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`host`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_authmails_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_authmails_is_active', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_apiclients`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', 'smallint', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_active`', 'smallint', ['default' => '0']);
        $table->addColumn('`ipv4_range_start`', 'bigint', ['notnull' => false]);
        $table->addColumn('`ipv4_range_end`', 'bigint', ['notnull' => false]);
        $table->addColumn('`ipv6`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`app_token`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`app_token_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`dolog_method`', 'smallint', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_apiclients_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_apiclients_is_active', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_autoupdatesystems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_autoupdatesystems_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_blacklistedmailcontents`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_blacklistedmailcontents_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_blacklistedmailcontents_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_blacklists`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`type`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`'], 'glpi_blacklists_type', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_blacklists_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_blacklists_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_blacklists_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`'], 'type', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_savedsearches`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`type`', 'integer', ['default' => '0', 'comment' => 'see SavedSearch:: constants']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`path`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`query`', 'text', ['notnull' => false]);
        $table->addColumn('`last_execution_time`', 'integer', ['notnull' => false]);
        $table->addColumn('`do_count`', 'smallint', ['default' => '2', 'comment' => 'Do or do not count results on list display see SavedSearch::COUNT_* constants']);
        $table->addColumn('`last_execution_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`counter`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`'], 'glpi_savedsearches_type', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_savedsearches_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_savedsearches_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_savedsearches_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_savedsearches_is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_savedsearches_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`last_execution_time`'], 'glpi_savedsearches_last_execution_time', [], ['lengths' => [null]]);
            $table->addIndex(['`last_execution_date`'], 'glpi_savedsearches_last_execution_date', [], ['lengths' => [null]]);
            $table->addIndex(['`do_count`'], 'glpi_savedsearches_do_count', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`'], 'type', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`last_execution_time`'], 'last_execution_time', [], ['lengths' => [null]]);
            $table->addIndex(['`last_execution_date`'], 'last_execution_date', [], ['lengths' => [null]]);
            $table->addIndex(['`do_count`'], 'do_count', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_savedsearches_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`savedsearches_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`itemtype`'], 'glpi_savedsearches_users_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`savedsearches_id`'], 'glpi_savedsearches_users_savedsearches_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`itemtype`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`savedsearches_id`'], 'savedsearches_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_savedsearches_alerts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`savedsearches_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`operator`', 'smallint', $postgres ? ['default' => 0] : []);
        $table->addColumn('`value`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_savedsearches_alerts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_savedsearches_alerts_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_savedsearches_alerts_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_savedsearches_alerts_date_creation', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`savedsearches_id`', '`operator`', '`value`'], 'glpi_savedsearches_alerts_unicity', ['lengths' => [null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`savedsearches_id`', '`operator`', '`value`'], 'unicity', ['lengths' => [null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_budgets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`value`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`budgettypes_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_budgets_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_budgets_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_budgets_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_budgets_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_budgets_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_budgets_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_budgets_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_budgets_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_budgets_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_budgets_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgettypes_id`'], 'glpi_budgets_budgettypes_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgettypes_id`'], 'budgettypes_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_budgettypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_budgettypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_budgettypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_budgettypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_businesscriticities`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`businesscriticities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_businesscriticities_name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`businesscriticities_id`', '`name`'], 'glpi_businesscriticities_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_businesscriticities_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_businesscriticities_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`businesscriticities_id`', '`name`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_calendars`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`cache_duration`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_calendars_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_calendars_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_calendars_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_calendars_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_calendars_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_calendars_holidays`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`holidays_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`calendars_id`', '`holidays_id`'], 'glpi_calendars_holidays_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`holidays_id`'], 'glpi_calendars_holidays_holidays_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`calendars_id`', '`holidays_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`holidays_id`'], 'holidays_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_calendarsegments`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`day`', 'smallint', ['default' => '1', 'comment' => 'numer of the day based on date(w)']);
        $table->addColumn('`begin`', 'time', ['notnull' => false]);
        $table->addColumn('`end`', 'time', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`calendars_id`'], 'glpi_calendarsegments_calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`day`'], 'glpi_calendarsegments_day', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`calendars_id`'], 'calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`day`'], 'day', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_cartridgeitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ref`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`cartridgeitemtypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`alarm_threshold`', 'integer', ['default' => '10']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_cartridgeitems_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_cartridgeitems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_cartridgeitems_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_cartridgeitems_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_cartridgeitems_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`cartridgeitemtypes_id`'], 'glpi_cartridgeitems_cartridgeitemtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_cartridgeitems_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`alarm_threshold`'], 'glpi_cartridgeitems_alarm_threshold', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_cartridgeitems_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_cartridgeitems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_cartridgeitems_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`cartridgeitemtypes_id`'], 'cartridgeitemtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`alarm_threshold`'], 'alarm_threshold', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_cartridgeitems_printermodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`cartridgeitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`printermodels_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`printermodels_id`', '`cartridgeitems_id`'], 'glpi_cartridgeitems_printermodels_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`cartridgeitems_id`'], 'glpi_cartridgeitems_printermodels_cartridgeitems_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`printermodels_id`', '`cartridgeitems_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`cartridgeitems_id`'], 'cartridgeitems_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_cartridgeitemtypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_cartridgeitemtypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_cartridgeitemtypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_cartridgeitemtypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_cartridges`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`cartridgeitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`printers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_in`', 'date', ['notnull' => false]);
        $table->addColumn('`date_use`', 'date', ['notnull' => false]);
        $table->addColumn('`date_out`', 'date', ['notnull' => false]);
        $table->addColumn('`pages`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`cartridgeitems_id`'], 'glpi_cartridges_cartridgeitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`printers_id`'], 'glpi_cartridges_printers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_cartridges_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_cartridges_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_cartridges_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`cartridgeitems_id`'], 'cartridgeitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`printers_id`'], 'printers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_certificates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`certificatetypes_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to glpi_certificatetypes (id)']);
        $table->addColumn('`dns_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`dns_suffix`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0', 'comment' => 'RELATION to glpi_users (id)']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0', 'comment' => 'RELATION to glpi_groups (id)']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to glpi_locations (id)']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to glpi_manufacturers (id)']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_autosign`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_expiration`', 'date', ['notnull' => false]);
        $table->addColumn('`states_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to states (id)']);
        $table->addColumn('`command`', 'text', ['notnull' => false]);
        $table->addColumn('`certificate_request`', 'text', ['notnull' => false]);
        $table->addColumn('`certificate_item`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_certificates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_certificates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_certificates_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_certificates_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`certificatetypes_id`'], 'glpi_certificates_certificatetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_certificates_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_certificates_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_certificates_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_certificates_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_certificates_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_certificates_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_certificates_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_certificates_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_certificates_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`certificatetypes_id`'], 'certificatetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_certificates_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`certificates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to various tables, according to itemtype (id)']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100, 'comment' => 'see .class.php file'] : ['length' => 100, 'comment' => 'see .class.php file']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`certificates_id`', '`itemtype`', '`items_id`'], 'glpi_certificates_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'glpi_certificates_items_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_certificates_items_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_creation`'], 'glpi_certificates_items_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_certificates_items_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`certificates_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_certificatetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_certificatetypes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_certificatetypes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_certificatetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_certificatetypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_certificatetypes_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changecosts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`cost_time`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_fixed`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_material`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_changecosts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'glpi_changecosts_changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_changecosts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_changecosts_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_changecosts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_changecosts_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_changecosts_budgets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`status`', 'integer', ['default' => '1']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`solvedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`closedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`time_to_resolve`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id_recipient`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_lastupdater`', 'integer', ['default' => '0']);
        $table->addColumn('`urgency`', 'integer', ['default' => '1']);
        $table->addColumn('`impact`', 'integer', ['default' => '1']);
        $table->addColumn('`priority`', 'integer', ['default' => '1']);
        $table->addColumn('`itilcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`impactcontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`controlistcontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`rolloutplancontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`backoutplancontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`checklistcontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`global_validation`', 'integer', ['default' => '1']);
        $table->addColumn('`validation_percent`', 'integer', ['default' => '0']);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`begin_waiting_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`waiting_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`close_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`solve_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_changes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_changes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_changes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_changes_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_changes_date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'glpi_changes_closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_changes_status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'glpi_changes_priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_changes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'glpi_changes_itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'glpi_changes_users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'glpi_changes_solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'glpi_changes_urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'glpi_changes_impact', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'glpi_changes_time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`global_validation`'], 'glpi_changes_global_validation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'glpi_changes_users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_changes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'impact', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`global_validation`'], 'global_validation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_groups`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`groups_id`'], 'glpi_changes_groups_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'glpi_changes_groups_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`groups_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`itemtype`', '`items_id`'], 'glpi_changes_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_changes_items_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_problems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`problems_id`'], 'glpi_changes_problems_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problems_id`'], 'glpi_changes_problems_problems_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`problems_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problems_id`'], 'problems_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_suppliers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`suppliers_id`'], 'glpi_changes_suppliers_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'glpi_changes_suppliers_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`suppliers_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`tickets_id`'], 'glpi_changes_tickets_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_changes_tickets_tickets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`tickets_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changes_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`users_id`', '`alternative_email`'], 'glpi_changes_users_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'glpi_changes_users_user', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changes_id`', '`type`', '`users_id`', '`alternative_email`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'user', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changetasks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`taskcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_editor`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`tasktemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_changetasks_uuid', ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'glpi_changetasks_changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_changetasks_state', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_changetasks_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'glpi_changetasks_users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_changetasks_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_changetasks_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_changetasks_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_changetasks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_changetasks_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'glpi_changetasks_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_changetasks_end', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'glpi_changetasks_taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'glpi_changetasks_tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_changetasks_is_private', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changevalidations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`changes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_validate`', 'integer', ['default' => '0']);
        $table->addColumn('`comment_submission`', 'text', ['notnull' => false]);
        $table->addColumn('`comment_validation`', 'text', ['notnull' => false]);
        $table->addColumn('`status`', 'integer', ['default' => '2']);
        $table->addColumn('`submission_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`validation_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_changevalidations_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_changevalidations_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_changevalidations_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_validate`'], 'glpi_changevalidations_users_id_validate', [], ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'glpi_changevalidations_changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`submission_date`'], 'glpi_changevalidations_submission_date', [], ['lengths' => [null]]);
            $table->addIndex(['`validation_date`'], 'glpi_changevalidations_validation_date', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_changevalidations_status', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_validate`'], 'users_id_validate', [], ['lengths' => [null]]);
            $table->addIndex(['`changes_id`'], 'changes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`submission_date`'], 'submission_date', [], ['lengths' => [null]]);
            $table->addIndex(['`validation_date`'], 'validation_date', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computerantiviruses`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`computers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`antivirus_version`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`signature_version`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_uptodate`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_expiration`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_computerantiviruses_name', [], ['lengths' => [null]]);
            $table->addIndex(['`antivirus_version`'], 'glpi_computerantiviruses_antivirus_version', [], ['lengths' => [null]]);
            $table->addIndex(['`signature_version`'], 'glpi_computerantiviruses_signature_version', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_computerantiviruses_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_uptodate`'], 'glpi_computerantiviruses_is_uptodate', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_computerantiviruses_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_computerantiviruses_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`computers_id`'], 'glpi_computerantiviruses_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_expiration`'], 'glpi_computerantiviruses_date_expiration', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_computerantiviruses_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_computerantiviruses_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`antivirus_version`'], 'antivirus_version', [], ['lengths' => [null]]);
            $table->addIndex(['`signature_version`'], 'signature_version', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_uptodate`'], 'is_uptodate', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`computers_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_expiration`'], 'date_expiration', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_disks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`device`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mountpoint`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`filesystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`totalsize`', 'integer', ['default' => '0']);
        $table->addColumn('`freesize`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`encryption_status`', 'integer', ['default' => '0']);
        $table->addColumn('`encryption_tool`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`encryption_algorithm`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`encryption_type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_items_disks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`device`'], 'glpi_items_disks_device', [], ['lengths' => [null]]);
            $table->addIndex(['`mountpoint`'], 'glpi_items_disks_mountpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`totalsize`'], 'glpi_items_disks_totalsize', [], ['lengths' => [null]]);
            $table->addIndex(['`freesize`'], 'glpi_items_disks_freesize', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_items_disks_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'glpi_items_disks_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_disks_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`filesystems_id`'], 'glpi_items_disks_filesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_disks_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_disks_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_disks_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_items_disks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_items_disks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`device`'], 'device', [], ['lengths' => [null]]);
            $table->addIndex(['`mountpoint`'], 'mountpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`totalsize`'], 'totalsize', [], ['lengths' => [null]]);
            $table->addIndex(['`freesize`'], 'freesize', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`filesystems_id`'], 'filesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computermodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_computermodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_computermodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_computermodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_computermodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`autoupdatesystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`computermodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`computertypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_computers_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_computers_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_computers_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`autoupdatesystems_id`'], 'glpi_computers_autoupdatesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_computers_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_computers_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_computers_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_computers_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_computers_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`computermodels_id`'], 'glpi_computers_computermodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'glpi_computers_networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_computers_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_computers_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`computertypes_id`'], 'glpi_computers_computertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_computers_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_computers_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_computers_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_computers_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_computers_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`uuid`'], 'glpi_computers_uuid', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_computers_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_computers_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`autoupdatesystems_id`'], 'autoupdatesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`computermodels_id`'], 'computermodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`computertypes_id`'], 'computertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`uuid`'], 'uuid', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computers_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to various table, according to itemtype (ID)']);
        $table->addColumn('`computers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`computers_id`'], 'glpi_computers_items_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_computers_items_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_computers_items_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_computers_items_is_dynamic', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`computers_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_softwarelicenses`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`softwarelicenses_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_softwarelicenses_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_items_softwarelicenses_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_softwarelicenses_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`softwarelicenses_id`'], 'glpi_items_softwarelicenses_softwarelicenses_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_softwarelicenses_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_softwarelicenses_is_dynamic', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`softwarelicenses_id`'], 'softwarelicenses_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_softwareversions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`softwareversions_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted_item`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template_item`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_install`', 'date', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`softwareversions_id`'], 'glpi_items_softwareversions_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`'], 'glpi_items_softwareversions_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_items_softwareversions_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_softwareversions_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`softwareversions_id`'], 'glpi_items_softwareversions_softwareversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`', '`is_template_item`', '`is_deleted_item`'], 'glpi_items_softwareversions_computers_info', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_template_item`'], 'glpi_items_softwareversions_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted_item`'], 'glpi_items_softwareversions_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_softwareversions_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_install`'], 'glpi_items_softwareversions_date_install', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`softwareversions_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`'], 'items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`softwareversions_id`'], 'softwareversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`', '`is_template_item`', '`is_deleted_item`'], 'computers_info', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_template_item`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted_item`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_install`'], 'date_install', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computertypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_computertypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_computertypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_computertypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_computervirtualmachines`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`computers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`virtualmachinestates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`virtualmachinesystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`virtualmachinetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`uuid`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`vcpu`', 'integer', ['default' => '0']);
        $table->addColumn('`ram`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`computers_id`'], 'glpi_computervirtualmachines_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_computervirtualmachines_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_computervirtualmachines_name', [], ['lengths' => [null]]);
            $table->addIndex(['`virtualmachinestates_id`'], 'glpi_computervirtualmachines_virtualmachinestates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`virtualmachinesystems_id`'], 'glpi_computervirtualmachines_virtualmachinesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`vcpu`'], 'glpi_computervirtualmachines_vcpu', [], ['lengths' => [null]]);
            $table->addIndex(['`ram`'], 'glpi_computervirtualmachines_ram', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_computervirtualmachines_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_computervirtualmachines_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`uuid`'], 'glpi_computervirtualmachines_uuid', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_computervirtualmachines_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_computervirtualmachines_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`computers_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`virtualmachinestates_id`'], 'virtualmachinestates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`virtualmachinesystems_id`'], 'virtualmachinesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`vcpu`'], 'vcpu', [], ['lengths' => [null]]);
            $table->addIndex(['`ram`'], 'ram', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`uuid`'], 'uuid', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_operatingsystems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`operatingsystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`operatingsystemversions_id`', 'integer', ['default' => '0']);
        $table->addColumn('`operatingsystemservicepacks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`operatingsystemarchitectures_id`', 'integer', ['default' => '0']);
        $table->addColumn('`operatingsystemkernelversions_id`', 'integer', ['default' => '0']);
        $table->addColumn('`license_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`licenseid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`operatingsystemeditions_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_operatingsystems_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_operatingsystems_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`operatingsystems_id`'], 'glpi_items_operatingsystems_operatingsystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemservicepacks_id`'], 'glpi_items_operatingsystems_operatingsystemservicepacks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemversions_id`'], 'glpi_items_operatingsystems_operatingsystemversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemarchitectures_id`'], 'glpi_items_operatingsystems_operatingsystemarchitectures_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemkernelversions_id`'], 'glpi_items_operatingsystems_operatingsystemkernelversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemeditions_id`'], 'glpi_items_operatingsystems_operatingsystemeditions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_operatingsystems_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_operatingsystems_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_operatingsystems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_operatingsystems_is_recursive', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`items_id`', '`itemtype`', '`operatingsystems_id`', '`operatingsystemarchitectures_id`'], 'glpi_items_operatingsystems_unicity', ['lengths' => [null, null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`operatingsystems_id`'], 'operatingsystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemservicepacks_id`'], 'operatingsystemservicepacks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemversions_id`'], 'operatingsystemversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemarchitectures_id`'], 'operatingsystemarchitectures_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemkernelversions_id`'], 'operatingsystemkernelversions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemeditions_id`'], 'operatingsystemeditions_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`items_id`', '`itemtype`', '`operatingsystems_id`', '`operatingsystemarchitectures_id`'], 'unicity', ['lengths' => [null, null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemkernels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemkernels_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemkernelversions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`operatingsystemkernels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemkernelversions_name', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemkernels_id`'], 'glpi_operatingsystemkernelversions_operatingsystemkernels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystemkernels_id`'], 'operatingsystemkernels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemeditions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemeditions_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_configs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`context`', 'string', ['notnull' => false, 'length' => 150]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 150]);
        $table->addColumn('`value`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`context`', '`name`'], 'glpi_configs_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`context`', '`name`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_impactrelations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype_source`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`items_id_source`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype_impacted`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`items_id_impacted`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype_source`', '`items_id_source`', '`itemtype_impacted`', '`items_id_impacted`'], 'glpi_impactrelations_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype_source`', '`items_id_source`'], 'glpi_impactrelations_source_asset', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype_impacted`', '`items_id_impacted`'], 'glpi_impactrelations_impacted_asset', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype_source`', '`items_id_source`', '`itemtype_impacted`', '`items_id_impacted`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype_source`', '`items_id_source`'], 'source_asset', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype_impacted`', '`items_id_impacted`'], 'impacted_asset', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_impactcompounds`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`color`', 'string', ['default' => '', 'length' => 255]);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_impactitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`parent_id`', 'integer', ['default' => '0']);
        $table->addColumn('`impactcontexts_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_slave`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'glpi_impactitems_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_impactitems_source', [], ['lengths' => [null, null]]);
            $table->addIndex(['`parent_id`'], 'glpi_impactitems_parent_id', [], ['lengths' => [null]]);
            $table->addIndex(['`impactcontexts_id`'], 'glpi_impactitems_impactcontexts_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'source', [], ['lengths' => [null, null]]);
            $table->addIndex(['`parent_id`'], 'parent_id', [], ['lengths' => [null]]);
            $table->addIndex(['`impactcontexts_id`'], 'impactcontexts_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_impactcontexts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`positions`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`zoom`', 'float', ['default' => '0']);
        $table->addColumn('`pan_x`', 'float', ['default' => '0']);
        $table->addColumn('`pan_y`', 'float', ['default' => '0']);
        $table->addColumn('`impact_color`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`depends_color`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`impact_and_depends_color`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`show_depends`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`show_impact`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`max_depth`', 'integer', ['default' => '5']);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_consumableitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ref`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`consumableitemtypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`alarm_threshold`', 'integer', ['default' => '10']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_consumableitems_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_consumableitems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_consumableitems_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_consumableitems_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_consumableitems_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`consumableitemtypes_id`'], 'glpi_consumableitems_consumableitemtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_consumableitems_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`alarm_threshold`'], 'glpi_consumableitems_alarm_threshold', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_consumableitems_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_consumableitems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_consumableitems_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_consumableitems_otherserial', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`consumableitemtypes_id`'], 'consumableitemtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`alarm_threshold`'], 'alarm_threshold', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_consumableitemtypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_consumableitemtypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_consumableitemtypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_consumableitemtypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_consumables`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`consumableitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_in`', 'date', ['notnull' => false]);
        $table->addColumn('`date_out`', 'date', ['notnull' => false]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_in`'], 'glpi_consumables_date_in', [], ['lengths' => [null]]);
            $table->addIndex(['`date_out`'], 'glpi_consumables_date_out', [], ['lengths' => [null]]);
            $table->addIndex(['`consumableitems_id`'], 'glpi_consumables_consumableitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_consumables_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_consumables_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_consumables_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_consumables_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_in`'], 'date_in', [], ['lengths' => [null]]);
            $table->addIndex(['`date_out`'], 'date_out', [], ['lengths' => [null]]);
            $table->addIndex(['`consumableitems_id`'], 'consumableitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contacts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`firstname`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phone`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phone2`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mobile`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`fax`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contacttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`usertitles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`address`', 'text', ['notnull' => false]);
        $table->addColumn('`postcode`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`town`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`state`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`country`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_contacts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_contacts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`contacttypes_id`'], 'glpi_contacts_contacttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_contacts_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`usertitles_id`'], 'glpi_contacts_usertitles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_contacts_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_contacts_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`contacttypes_id`'], 'contacttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`usertitles_id`'], 'usertitles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contacts_suppliers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`contacts_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`suppliers_id`', '`contacts_id`'], 'glpi_contacts_suppliers_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`contacts_id`'], 'glpi_contacts_suppliers_contacts_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`suppliers_id`', '`contacts_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`contacts_id`'], 'contacts_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contacttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_contacttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_contacttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_contacttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contractcosts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`contracts_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`cost`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_contractcosts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`contracts_id`'], 'glpi_contractcosts_contracts_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_contractcosts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_contractcosts_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_contractcosts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_contractcosts_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_contractcosts_budgets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`contracts_id`'], 'contracts_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contracts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contracttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`duration`', 'integer', ['default' => '0']);
        $table->addColumn('`notice`', 'integer', ['default' => '0']);
        $table->addColumn('`periodicity`', 'integer', ['default' => '0']);
        $table->addColumn('`billing`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`accounting_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`week_begin_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`week_end_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`saturday_begin_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`saturday_end_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`use_saturday`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`monday_begin_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`monday_end_hour`', 'time', ['default' => '00:00:00']);
        $table->addColumn('`use_monday`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`max_links_allowed`', 'integer', ['default' => '0']);
        $table->addColumn('`alert`', 'integer', ['default' => '0']);
        $table->addColumn('`renewal`', 'integer', ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`begin_date`'], 'glpi_contracts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_contracts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`contracttypes_id`'], 'glpi_contracts_contracttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_contracts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_contracts_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`use_monday`'], 'glpi_contracts_use_monday', [], ['lengths' => [null]]);
            $table->addIndex(['`use_saturday`'], 'glpi_contracts_use_saturday', [], ['lengths' => [null]]);
            $table->addIndex(['`alert`'], 'glpi_contracts_alert', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_contracts_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_contracts_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_contracts_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`contracttypes_id`'], 'contracttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`use_monday`'], 'use_monday', [], ['lengths' => [null]]);
            $table->addIndex(['`use_saturday`'], 'use_saturday', [], ['lengths' => [null]]);
            $table->addIndex(['`alert`'], 'alert', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contracts_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`contracts_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`contracts_id`', '`itemtype`', '`items_id`'], 'glpi_contracts_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'glpi_contracts_items_FK_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_contracts_items_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`contracts_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'FK_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contracts_suppliers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`contracts_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`suppliers_id`', '`contracts_id`'], 'glpi_contracts_suppliers_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`contracts_id`'], 'glpi_contracts_suppliers_contracts_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`suppliers_id`', '`contracts_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`contracts_id`'], 'contracts_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_contracttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_contracttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_contracttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_contracttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_crontasklogs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`crontasks_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`crontasklogs_id`', 'integer', $postgres ? ['default' => 0, 'comment' => 'id of \'start\' event'] : ['comment' => 'id of \'start\' event']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['default' => 'CURRENT_TIMESTAMP'] : ['default' => 'CURRENT_TIMESTAMP', 'columnDefinition' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP']);
        $table->addColumn('`state`', 'integer', $postgres ? ['default' => 0, 'comment' => '0:start, 1:run, 2:stop'] : ['comment' => '0:start, 1:run, 2:stop']);
        $table->addColumn('`elapsed`', 'float', $postgres ? ['default' => 0, 'comment' => 'time elapsed since start'] : ['comment' => 'time elapsed since start']);
        $table->addColumn('`volume`', 'integer', $postgres ? ['default' => 0, 'comment' => 'for statistics'] : ['comment' => 'for statistics']);
        $table->addColumn('`content`', 'string', ['notnull' => false, 'length' => 255, 'comment' => 'message']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'glpi_crontasklogs_date', [], ['lengths' => [null]]);
            $table->addIndex(['`crontasks_id`'], 'glpi_crontasklogs_crontasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`crontasklogs_id`', '`state`'], 'glpi_crontasklogs_crontasklogs_id_state', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`crontasks_id`'], 'crontasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`crontasklogs_id`', '`state`'], 'crontasklogs_id_state', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_crontasks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`name`', 'string', $postgres ? ['default' => '', 'length' => 150, 'comment' => 'task name'] : ['length' => 150, 'comment' => 'task name']);
        $table->addColumn('`frequency`', 'integer', $postgres ? ['default' => 0, 'comment' => 'second between launch'] : ['comment' => 'second between launch']);
        $table->addColumn('`param`', 'integer', ['notnull' => false, 'comment' => 'task specify parameter']);
        $table->addColumn('`state`', 'integer', ['default' => '1', 'comment' => '0:disabled, 1:waiting, 2:running']);
        $table->addColumn('`mode`', 'integer', ['default' => '1', 'comment' => '1:internal, 2:external']);
        $table->addColumn('`allowmode`', 'integer', ['default' => '3', 'comment' => '1:internal, 2:external, 3:both']);
        $table->addColumn('`hourmin`', 'integer', ['default' => '0']);
        $table->addColumn('`hourmax`', 'integer', ['default' => '24']);
        $table->addColumn('`logs_lifetime`', 'integer', ['default' => '30', 'comment' => 'number of days']);
        $table->addColumn('`lastrun`', 'datetimetz', $postgres ? ['notnull' => false, 'comment' => 'last run date'] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL COMMENT \'last run date\'', 'comment' => 'last run date']);
        $table->addColumn('`lastcode`', 'integer', ['notnull' => false, 'comment' => 'last run return code']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`name`'], 'glpi_crontasks_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`mode`'], 'glpi_crontasks_mode', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_crontasks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_crontasks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`name`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`mode`'], 'mode', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_dashboards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100, 'platformOptions' => ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4']]);
        $table->addColumn('`content`', 'text', $postgres ? ['default' => ''] : ['length' => 4294967295, 'platformOptions' => ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4']]);
        $table->addColumn('`profileId`', 'integer', ['default' => '0']);
        $table->addColumn('`userId`', 'integer', ['default' => '0']);
        $table->setPrimaryKey(['`profileId`', '`userId`']);
        // The SQL dump declares id UNIQUE inline; the former reader discarded it.
        // This key is required for MySQL AUTO_INCREMENT before dashboard adoption.
        $table->addUniqueIndex(['id'], 'dashboard_legacy_id');
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicecasemodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicecasemodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicecasemodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicecases`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicecasetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicecasemodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicecases_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicecases_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecasetypes_id`'], 'glpi_devicecases_devicecasetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicecases_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicecases_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicecases_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicecases_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecasemodels_id`'], 'glpi_devicecases_devicecasemodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecasetypes_id`'], 'devicecasetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecasemodels_id`'], 'devicecasemodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicecasetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicecasetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicecasetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicecasetypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicecontrolmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicecontrolmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicecontrolmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicecontrols`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_raid`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`interfacetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicecontrolmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicecontrols_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicecontrols_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'glpi_devicecontrols_interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicecontrols_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicecontrols_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicecontrols_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicecontrols_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecontrolmodels_id`'], 'glpi_devicecontrols_devicecontrolmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecontrolmodels_id`'], 'devicecontrolmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicedrivemodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicedrivemodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicedrivemodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicedrives`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_writer`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`speed`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`interfacetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicedrivemodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicedrives_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicedrives_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'glpi_devicedrives_interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicedrives_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicedrives_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicedrives_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicedrives_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicedrivemodels_id`'], 'glpi_devicedrives_devicedrivemodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicedrivemodels_id`'], 'devicedrivemodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicegenericmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicegenericmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicegenericmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicegenerics`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicegenerictypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`devicegenericmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicegenerics_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicegenerics_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenerictypes_id`'], 'glpi_devicegenerics_devicegenerictypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicegenerics_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicegenerics_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_devicegenerics_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_devicegenerics_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicegenerics_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicegenerics_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenericmodels_id`'], 'glpi_devicegenerics_devicegenericmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenerictypes_id`'], 'devicegenerictypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenericmodels_id`'], 'devicegenericmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicegenerictypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicegenerictypes_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicegraphiccardmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicegraphiccardmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicegraphiccardmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicegraphiccards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`interfacetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`memory_default`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicegraphiccardmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`chipset`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicegraphiccards_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicegraphiccards_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'glpi_devicegraphiccards_interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicegraphiccards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicegraphiccards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`chipset`'], 'glpi_devicegraphiccards_chipset', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicegraphiccards_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicegraphiccards_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegraphiccardmodels_id`'], 'glpi_devicegraphiccards_devicegraphiccardmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`chipset`'], 'chipset', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegraphiccardmodels_id`'], 'devicegraphiccardmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_deviceharddrivemodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_deviceharddrivemodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_deviceharddrivemodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_deviceharddrives`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`rpm`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`interfacetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`cache`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`capacity_default`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`deviceharddrivemodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_deviceharddrives_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_deviceharddrives_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'glpi_deviceharddrives_interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_deviceharddrives_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_deviceharddrives_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_deviceharddrives_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_deviceharddrives_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceharddrivemodels_id`'], 'glpi_deviceharddrives_deviceharddrivemodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`interfacetypes_id`'], 'interfacetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceharddrivemodels_id`'], 'deviceharddrivemodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicememorymodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicememorymodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicememorymodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicememories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`frequence`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`size_default`', 'integer', ['default' => '0']);
        $table->addColumn('`devicememorytypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicememorymodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicememories_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicememories_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememorytypes_id`'], 'glpi_devicememories_devicememorytypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicememories_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicememories_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicememories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicememories_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememorymodels_id`'], 'glpi_devicememories_devicememorymodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememorytypes_id`'], 'devicememorytypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememorymodels_id`'], 'devicememorymodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicememorytypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicememorytypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicememorytypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicememorytypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicemotherboardmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicemotherboardmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicemotherboardmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicemotherboards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`chipset`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicemotherboardmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicemotherboards_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicemotherboards_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicemotherboards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicemotherboards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicemotherboards_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicemotherboards_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicemotherboardmodels_id`'], 'glpi_devicemotherboards_devicemotherboardmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicemotherboardmodels_id`'], 'devicemotherboardmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicenetworkcardmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicenetworkcardmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicenetworkcardmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicenetworkcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`bandwidth`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mac_default`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicenetworkcardmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicenetworkcards_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicenetworkcards_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicenetworkcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicenetworkcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicenetworkcards_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicenetworkcards_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcardmodels_id`'], 'glpi_devicenetworkcards_devicenetworkcardmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcardmodels_id`'], 'devicenetworkcardmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicepcimodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicepcimodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicepcimodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicepcis`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`devicenetworkcardmodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicepcimodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicepcis_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicepcis_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcardmodels_id`'], 'glpi_devicepcis_devicenetworkcardmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicepcis_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicepcis_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicepcis_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicepcis_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepcimodels_id`'], 'glpi_devicepcis_devicepcimodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcardmodels_id`'], 'devicenetworkcardmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepcimodels_id`'], 'devicepcimodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicepowersupplymodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicepowersupplymodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicepowersupplymodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicepowersupplies`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`power`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_atx`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicepowersupplymodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicepowersupplies_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicepowersupplies_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicepowersupplies_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicepowersupplies_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicepowersupplies_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicepowersupplies_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepowersupplymodels_id`'], 'glpi_devicepowersupplies_devicepowersupplymodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepowersupplymodels_id`'], 'devicepowersupplymodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_deviceprocessormodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_deviceprocessormodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_deviceprocessormodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_deviceprocessors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`frequence`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`frequency_default`', 'integer', ['default' => '0']);
        $table->addColumn('`nbcores_default`', 'integer', ['notnull' => false]);
        $table->addColumn('`nbthreads_default`', 'integer', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`deviceprocessormodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_deviceprocessors_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_deviceprocessors_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_deviceprocessors_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_deviceprocessors_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_deviceprocessors_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_deviceprocessors_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceprocessormodels_id`'], 'glpi_deviceprocessors_deviceprocessormodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceprocessormodels_id`'], 'deviceprocessormodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesensors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicesensortypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`devicesensormodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicesensors_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicesensors_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesensortypes_id`'], 'glpi_devicesensors_devicesensortypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicesensors_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicesensors_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_devicesensors_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_devicesensors_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicesensors_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicesensors_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesensortypes_id`'], 'devicesensortypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesensormodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicesensormodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicesensormodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesensortypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicesensortypes_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesimcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`voltage`', 'integer', ['notnull' => false]);
        $table->addColumn('`devicesimcardtypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`allow_voip`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicesimcards_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicesimcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicesimcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesimcardtypes_id`'], 'glpi_devicesimcards_devicesimcardtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicesimcards_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicesimcards_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicesimcards_manufacturers_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesimcardtypes_id`'], 'devicesimcardtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicesimcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to various table, according to itemtype (id)']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`devicesimcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`lines_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`pin`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`pin2`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`puk`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`puk2`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`msin`', 'string', ['default' => '', 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicesimcards_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`devicesimcards_id`'], 'glpi_items_devicesimcards_devicesimcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicesimcards_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicesimcards_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicesimcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicesimcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicesimcards_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicesimcards_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicesimcards_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicesimcards_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`lines_id`'], 'glpi_items_devicesimcards_lines_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_items_devicesimcards_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_items_devicesimcards_groups_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`devicesimcards_id`'], 'devicesimcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`lines_id`'], 'lines_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesimcardtypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicesimcardtypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicesimcardtypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicesimcardtypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesoundcardmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicesoundcardmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicesoundcardmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicesoundcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicesoundcardmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicesoundcards_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicesoundcards_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicesoundcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicesoundcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicesoundcards_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicesoundcards_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesoundcardmodels_id`'], 'glpi_devicesoundcards_devicesoundcardmodels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesoundcardmodels_id`'], 'devicesoundcardmodels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_displaypreferences`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        $table->addColumn('`rank`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`itemtype`', '`num`'], 'glpi_displaypreferences_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`rank`'], 'glpi_displaypreferences_rank', [], ['lengths' => [null]]);
            $table->addIndex(['`num`'], 'glpi_displaypreferences_num', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_displaypreferences_itemtype', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`itemtype`', '`num`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`rank`'], 'rank', [], ['lengths' => [null]]);
            $table->addIndex(['`num`'], 'num', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_documentcategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`documentcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_documentcategories_name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`documentcategories_id`', '`name`'], 'glpi_documentcategories_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_documentcategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_documentcategories_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`documentcategories_id`', '`name`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_documents`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`filename`', 'string', ['notnull' => false, 'length' => 255, 'comment' => 'for display and transfert']);
        $table->addColumn('`filepath`', 'string', ['notnull' => false, 'length' => 255, 'comment' => 'file storage path']);
        $table->addColumn('`documentcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mime`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`link`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`sha1sum`', 'string', ['notnull' => false, 'length' => 40, 'fixed' => true]);
        $table->addColumn('`is_blacklisted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`tag`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_documents_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_documents_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_documents_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_documents_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_documents_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`documentcategories_id`'], 'glpi_documents_documentcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_documents_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`sha1sum`'], 'glpi_documents_sha1sum', [], ['lengths' => [null]]);
            $table->addIndex(['`tag`'], 'glpi_documents_tag', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_documents_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`documentcategories_id`'], 'documentcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`sha1sum`'], 'sha1sum', [], ['lengths' => [null]]);
            $table->addIndex(['`tag`'], 'tag', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_documents_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`documents_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0', 'notnull' => false]);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`documents_id`', '`itemtype`', '`items_id`', '`timeline_position`'], 'glpi_documents_items_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`entities_id`', '`is_recursive`'], 'glpi_documents_items_item', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`'], 'glpi_documents_items_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_documents_items_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_documents_items_date', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`documents_id`', '`itemtype`', '`items_id`', '`timeline_position`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`entities_id`', '`is_recursive`'], 'item', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_documenttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ext`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`icon`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mime`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_uploadable`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ext`'], 'glpi_documenttypes_unicity', ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_documenttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_uploadable`'], 'glpi_documenttypes_is_uploadable', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_documenttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_documenttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ext`'], 'unicity', ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_uploadable`'], 'is_uploadable', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domains`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`domaintypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_expiration`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP  NULL DEFAULT NULL']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`others`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_domains_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_domains_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domaintypes_id`'], 'glpi_domains_domaintypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_domains_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_domains_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_domains_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_domains_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_expiration`'], 'glpi_domains_date_expiration', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_domains_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domaintypes_id`'], 'domaintypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_expiration`'], 'date_expiration', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_dropdowntranslations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 10]);
        $table->addColumn('`field`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`value`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`language`', '`field`'], 'glpi_dropdowntranslations_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_dropdowntranslations_typeid', [], ['lengths' => [null, null]]);
            $table->addIndex(['`language`'], 'glpi_dropdowntranslations_language', [], ['lengths' => [null]]);
            $table->addIndex(['`field`'], 'glpi_dropdowntranslations_field', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`language`', '`field`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'typeid', [], ['lengths' => [null, null]]);
            $table->addIndex(['`language`'], 'language', [], ['lengths' => [null]]);
            $table->addIndex(['`field`'], 'field', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_entities`');
        $table->addColumn('`id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`address`', 'text', ['notnull' => false]);
        $table->addColumn('`postcode`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`town`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`state`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`country`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`website`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phonenumber`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`fax`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`admin_email`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`admin_email_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`admin_reply`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`admin_reply_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`notification_subject_tag`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ldap_dn`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`tag`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`authldaps_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mail_domain`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entity_ldapfilter`', 'text', ['notnull' => false]);
        $table->addColumn('`mailing_signature`', 'text', ['notnull' => false]);
        $table->addColumn('`cartridges_alert_repeat`', 'integer', ['default' => '-2']);
        $table->addColumn('`consumables_alert_repeat`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_licenses_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_licenses_alert_before_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_certificates_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_certificates_alert_before_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_contracts_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_contracts_alert_before_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_infocoms_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_infocoms_alert_before_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_reservations_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`use_domains_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_domains_alert_close_expiries_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`send_domains_alert_expired_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`autoclose_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`autopurge_delay`', 'integer', ['default' => '-10']);
        $table->addColumn('`notclosed_delay`', 'integer', ['default' => '-2']);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '-2']);
        $table->addColumn('`auto_assign_mode`', 'integer', ['default' => '-2']);
        $table->addColumn('`tickettype`', 'integer', ['default' => '-2']);
        $table->addColumn('`max_closedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`inquest_config`', 'integer', ['default' => '-2']);
        $table->addColumn('`inquest_rate`', 'integer', ['default' => '0']);
        $table->addColumn('`inquest_delay`', 'integer', ['default' => '-10']);
        $table->addColumn('`inquest_URL`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`autofill_warranty_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`autofill_use_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`autofill_buy_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`autofill_delivery_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`autofill_order_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '-2']);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '-2']);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '-2']);
        $table->addColumn('`entities_id_software`', 'integer', ['default' => '-2']);
        $table->addColumn('`default_contract_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`default_infocom_alert`', 'integer', ['default' => '-2']);
        $table->addColumn('`default_cartridges_alarm_threshold`', 'integer', ['default' => '-2']);
        $table->addColumn('`default_consumables_alarm_threshold`', 'integer', ['default' => '-2']);
        $table->addColumn('`delay_send_emails`', 'integer', ['default' => '-2']);
        $table->addColumn('`is_notif_enable_default`', 'integer', ['default' => '-2']);
        $table->addColumn('`inquest_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`autofill_decommission_date`', 'string', ['default' => '-2', 'length' => 255]);
        $table->addColumn('`suppliers_as_private`', 'integer', ['default' => '-2']);
        $table->addColumn('`anonymize_support_agents`', 'integer', ['default' => '-2']);
        $table->addColumn('`enable_custom_css`', 'integer', ['default' => '-2']);
        $table->addColumn('`custom_css_code`', 'text', ['notnull' => false]);
        $table->addColumn('`latitude`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`longitude`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`altitude`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`name`'], 'glpi_entities_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'glpi_entities_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_entities_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_entities_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_entities_tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'glpi_entities_changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_entities_problemtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`name`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_entities_knowbaseitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'glpi_entities_knowbaseitems_knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_entities_knowbaseitems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_entities_knowbaseitems_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_entities_reminders`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reminders_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'glpi_entities_reminders_reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_entities_reminders_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_entities_reminders_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_entities_rssfeeds`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rssfeeds_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'glpi_entities_rssfeeds_rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_entities_rssfeeds_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_entities_rssfeeds_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_events`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`service`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`message`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'glpi_events_date', [], ['lengths' => [null]]);
            $table->addIndex(['`level`'], 'glpi_events_level', [], ['lengths' => [null]]);
            $table->addIndex(['`type`', '`items_id`'], 'glpi_events_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`level`'], 'level', [], ['lengths' => [null]]);
            $table->addIndex(['`type`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_fieldblacklists`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`field`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`value`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_fieldblacklists_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_fieldblacklists_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_fieldblacklists_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_fieldunicities`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`fields`', 'text', ['notnull' => false]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`action_refuse`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`action_notify`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_fieldunicities_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_fieldunicities_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_filesystems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_filesystems_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_filesystems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_filesystems_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_fqdns`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`fqdn`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_fqdns_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_fqdns_name', [], ['lengths' => [null]]);
            $table->addIndex(['`fqdn`'], 'glpi_fqdns_fqdn', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_fqdns_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_fqdns_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_fqdns_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`fqdn`'], 'fqdn', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`ldap_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ldap_value`', 'text', ['notnull' => false]);
        $table->addColumn('`ldap_group_dn`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_requester`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_watcher`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_assign`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_task`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_notify`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_itemgroup`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_usergroup`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_manager`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_groups_name', [], ['lengths' => [null]]);
            $table->addIndex(['`ldap_field`'], 'glpi_groups_ldap_field', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_groups_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_groups_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_groups_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_requester`'], 'glpi_groups_is_requester', [], ['lengths' => [null]]);
            $table->addIndex(['`is_watcher`'], 'glpi_groups_is_watcher', [], ['lengths' => [null]]);
            $table->addIndex(['`is_assign`'], 'glpi_groups_is_assign', [], ['lengths' => [null]]);
            $table->addIndex(['`is_notify`'], 'glpi_groups_is_notify', [], ['lengths' => [null]]);
            $table->addIndex(['`is_itemgroup`'], 'glpi_groups_is_itemgroup', [], ['lengths' => [null]]);
            $table->addIndex(['`is_usergroup`'], 'glpi_groups_is_usergroup', [], ['lengths' => [null]]);
            $table->addIndex(['`is_manager`'], 'glpi_groups_is_manager', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_groups_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`ldap_field`'], 'ldap_field', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`ldap_value`'], 'ldap_value', [], ['lengths' => [200]]);
            $table->addIndex(['`ldap_group_dn`'], 'ldap_group_dn', [], ['lengths' => [200]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_requester`'], 'is_requester', [], ['lengths' => [null]]);
            $table->addIndex(['`is_watcher`'], 'is_watcher', [], ['lengths' => [null]]);
            $table->addIndex(['`is_assign`'], 'is_assign', [], ['lengths' => [null]]);
            $table->addIndex(['`is_notify`'], 'is_notify', [], ['lengths' => [null]]);
            $table->addIndex(['`is_itemgroup`'], 'is_itemgroup', [], ['lengths' => [null]]);
            $table->addIndex(['`is_usergroup`'], 'is_usergroup', [], ['lengths' => [null]]);
            $table->addIndex(['`is_manager`'], 'is_manager', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_knowbaseitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'glpi_groups_knowbaseitems_knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_groups_knowbaseitems_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_groups_knowbaseitems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_groups_knowbaseitems_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_problems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`groups_id`'], 'glpi_groups_problems_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'glpi_groups_problems_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`groups_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_reminders`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reminders_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'glpi_groups_reminders_reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_groups_reminders_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_groups_reminders_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_groups_reminders_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_rssfeeds`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rssfeeds_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'glpi_groups_rssfeeds_rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_groups_rssfeeds_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_groups_rssfeeds_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_groups_rssfeeds_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`groups_id`'], 'glpi_groups_tickets_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'glpi_groups_tickets_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`groups_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`groups_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_groups_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_manager`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_userdelegate`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`groups_id`'], 'glpi_groups_users_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`groups_id`'], 'glpi_groups_users_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_manager`'], 'glpi_groups_users_is_manager', [], ['lengths' => [null]]);
            $table->addIndex(['`is_userdelegate`'], 'glpi_groups_users_is_userdelegate', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`groups_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_manager`'], 'is_manager', [], ['lengths' => [null]]);
            $table->addIndex(['`is_userdelegate`'], 'is_userdelegate', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_holidays`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`is_perpetual`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_holidays_name', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_holidays_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_holidays_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`is_perpetual`'], 'glpi_holidays_is_perpetual', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_holidays_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_holidays_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`is_perpetual`'], 'is_perpetual', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_infocoms`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`buy_date`', 'date', ['notnull' => false]);
        $table->addColumn('`use_date`', 'date', ['notnull' => false]);
        $table->addColumn('`warranty_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`warranty_info`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`order_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`delivery_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`immo_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`warranty_value`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`sink_time`', 'integer', ['default' => '0']);
        $table->addColumn('`sink_type`', 'integer', ['default' => '0']);
        $table->addColumn('`sink_coeff`', 'float', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`bill`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`alert`', 'integer', ['default' => '0']);
        $table->addColumn('`order_date`', 'date', ['notnull' => false]);
        $table->addColumn('`delivery_date`', 'date', ['notnull' => false]);
        $table->addColumn('`inventory_date`', 'date', ['notnull' => false]);
        $table->addColumn('`warranty_date`', 'date', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`decommission_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`businesscriticities_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'glpi_infocoms_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`buy_date`'], 'glpi_infocoms_buy_date', [], ['lengths' => [null]]);
            $table->addIndex(['`alert`'], 'glpi_infocoms_alert', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_infocoms_budgets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`suppliers_id`'], 'glpi_infocoms_suppliers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_infocoms_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_infocoms_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_infocoms_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_infocoms_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`businesscriticities_id`'], 'glpi_infocoms_businesscriticities_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`buy_date`'], 'buy_date', [], ['lengths' => [null]]);
            $table->addIndex(['`alert`'], 'alert', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`suppliers_id`'], 'suppliers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`businesscriticities_id`'], 'businesscriticities_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_interfacetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_interfacetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_interfacetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_interfacetypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ipaddresses`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`version`', 'smallint', $postgres ? ['default' => '0', 'notnull' => false] : ['default' => '0', 'notnull' => false, 'unsigned' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`binary_0`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`binary_1`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`binary_2`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`binary_3`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`mainitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mainitemtype`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_ipaddresses_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_ipaddresses_textual', [], ['lengths' => [null]]);
            $table->addIndex(['`binary_0`', '`binary_1`', '`binary_2`', '`binary_3`'], 'glpi_ipaddresses_binary', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_ipaddresses_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_ipaddresses_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`is_deleted`'], 'glpi_ipaddresses_item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`mainitemtype`', '`mainitems_id`', '`is_deleted`'], 'glpi_ipaddresses_mainitem', [], ['lengths' => [null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'textual', [], ['lengths' => [null]]);
            $table->addIndex(['`binary_0`', '`binary_1`', '`binary_2`', '`binary_3`'], 'binary', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`is_deleted`'], 'item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`mainitemtype`', '`mainitems_id`', '`is_deleted`'], 'mainitem', [], ['lengths' => [null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ipaddresses_ipnetworks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`ipaddresses_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ipnetworks_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ipaddresses_id`', '`ipnetworks_id`'], 'glpi_ipaddresses_ipnetworks_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`ipnetworks_id`'], 'glpi_ipaddresses_ipnetworks_ipnetworks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`ipaddresses_id`'], 'glpi_ipaddresses_ipnetworks_ipaddresses_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ipaddresses_id`', '`ipnetworks_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`ipnetworks_id`'], 'ipnetworks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`ipaddresses_id`'], 'ipaddresses_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ipnetworks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`ipnetworks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`addressable`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`version`', 'smallint', $postgres ? ['default' => '0', 'notnull' => false] : ['default' => '0', 'notnull' => false, 'unsigned' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`address`', 'string', ['notnull' => false, 'length' => 40]);
        $table->addColumn('`address_0`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`address_1`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`address_2`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`address_3`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`netmask`', 'string', ['notnull' => false, 'length' => 40]);
        $table->addColumn('`netmask_0`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`netmask_1`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`netmask_2`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`netmask_3`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`gateway`', 'string', ['notnull' => false, 'length' => 40]);
        $table->addColumn('`gateway_0`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`gateway_1`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`gateway_2`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`gateway_3`', $postgres ? 'bigint' : 'integer', $postgres ? ['default' => '0'] : ['default' => '0', 'unsigned' => true]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`', '`address`', '`netmask`'], 'glpi_ipnetworks_network_definition', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`address_0`', '`address_1`', '`address_2`', '`address_3`'], 'glpi_ipnetworks_address', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`netmask_0`', '`netmask_1`', '`netmask_2`', '`netmask_3`'], 'glpi_ipnetworks_netmask', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`gateway_0`', '`gateway_1`', '`gateway_2`', '`gateway_3`'], 'glpi_ipnetworks_gateway', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`name`'], 'glpi_ipnetworks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_ipnetworks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_ipnetworks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`', '`address`', '`netmask`'], 'network_definition', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`address_0`', '`address_1`', '`address_2`', '`address_3`'], 'address', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`netmask_0`', '`netmask_1`', '`netmask_2`', '`netmask_3`'], 'netmask', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`gateway_0`', '`gateway_1`', '`gateway_2`', '`gateway_3`'], 'gateway', [], ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ipnetworks_vlans`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`ipnetworks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`vlans_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ipnetworks_id`', '`vlans_id`'], 'glpi_ipnetworks_vlans_link', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`ipnetworks_id`', '`vlans_id`'], 'link', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicecases`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicecases_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicecases_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecases_id`'], 'glpi_items_devicecases_devicecases_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicecases_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicecases_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicecases_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicecases_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicecases_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicecases_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicecases_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicecases_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicecases_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecases_id`'], 'devicecases_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicecontrols`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicecontrols_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicecontrols_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecontrols_id`'], 'glpi_items_devicecontrols_devicecontrols_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicecontrols_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicecontrols_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicecontrols_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicecontrols_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicecontrols_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicecontrols_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicecontrols_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicecontrols_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicecontrols_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicecontrols_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicecontrols_id`'], 'devicecontrols_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicedrives`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicedrives_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicedrives_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicedrives_id`'], 'glpi_items_devicedrives_devicedrives_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicedrives_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicedrives_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicedrives_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicedrives_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicedrives_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicedrives_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicedrives_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicedrives_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicedrives_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicedrives_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicedrives_id`'], 'devicedrives_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicegenerics`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicegenerics_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicegenerics_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenerics_id`'], 'glpi_items_devicegenerics_devicegenerics_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicegenerics_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicegenerics_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicegenerics_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicegenerics_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicegenerics_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicegenerics_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicegenerics_otherserial', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegenerics_id`'], 'devicegenerics_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicegraphiccards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicegraphiccards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`memory`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicegraphiccards_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegraphiccards_id`'], 'glpi_items_devicegraphiccards_devicegraphiccards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`memory`'], 'glpi_items_devicegraphiccards_specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicegraphiccards_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicegraphiccards_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicegraphiccards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicegraphiccards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicegraphiccards_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicegraphiccards_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicegraphiccards_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicegraphiccards_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicegraphiccards_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicegraphiccards_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicegraphiccards_id`'], 'devicegraphiccards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`memory`'], 'specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_deviceharddrives`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`deviceharddrives_id`', 'integer', ['default' => '0']);
        $table->addColumn('`capacity`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_deviceharddrives_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceharddrives_id`'], 'glpi_items_deviceharddrives_deviceharddrives_id', [], ['lengths' => [null]]);
            $table->addIndex(['`capacity`'], 'glpi_items_deviceharddrives_specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_deviceharddrives_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_deviceharddrives_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_deviceharddrives_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_deviceharddrives_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_deviceharddrives_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_deviceharddrives_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_deviceharddrives_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_deviceharddrives_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_deviceharddrives_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_deviceharddrives_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceharddrives_id`'], 'deviceharddrives_id', [], ['lengths' => [null]]);
            $table->addIndex(['`capacity`'], 'specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicememories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicememories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`size`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicememories_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememories_id`'], 'glpi_items_devicememories_devicememories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`size`'], 'glpi_items_devicememories_specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicememories_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicememories_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicememories_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicememories_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicememories_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicememories_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicememories_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicememories_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicememories_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicememories_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicememories_id`'], 'devicememories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`size`'], 'specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicemotherboards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicemotherboards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicemotherboards_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicemotherboards_id`'], 'glpi_items_devicemotherboards_devicemotherboards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicemotherboards_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicemotherboards_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicemotherboards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicemotherboards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicemotherboards_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicemotherboards_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicemotherboards_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicemotherboards_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicemotherboards_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicemotherboards_id`'], 'devicemotherboards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicenetworkcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicenetworkcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mac`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicenetworkcards_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcards_id`'], 'glpi_items_devicenetworkcards_devicenetworkcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`mac`'], 'glpi_items_devicenetworkcards_specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicenetworkcards_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicenetworkcards_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicenetworkcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicenetworkcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicenetworkcards_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicenetworkcards_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicenetworkcards_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicenetworkcards_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicenetworkcards_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicenetworkcards_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicenetworkcards_id`'], 'devicenetworkcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`mac`'], 'specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicepcis`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicepcis_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicepcis_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepcis_id`'], 'glpi_items_devicepcis_devicepcis_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicepcis_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicepcis_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicepcis_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicepcis_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicepcis_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicepcis_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicepcis_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicepcis_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicepcis_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicepcis_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepcis_id`'], 'devicepcis_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicepowersupplies`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicepowersupplies_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicepowersupplies_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepowersupplies_id`'], 'glpi_items_devicepowersupplies_devicepowersupplies_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicepowersupplies_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicepowersupplies_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicepowersupplies_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicepowersupplies_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicepowersupplies_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicepowersupplies_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicepowersupplies_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicepowersupplies_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicepowersupplies_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicepowersupplies_id`'], 'devicepowersupplies_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_deviceprocessors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`deviceprocessors_id`', 'integer', ['default' => '0']);
        $table->addColumn('`frequency`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`nbcores`', 'integer', ['notnull' => false]);
        $table->addColumn('`nbthreads`', 'integer', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_deviceprocessors_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceprocessors_id`'], 'glpi_items_deviceprocessors_deviceprocessors_id', [], ['lengths' => [null]]);
            $table->addIndex(['`frequency`'], 'glpi_items_deviceprocessors_specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_deviceprocessors_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_deviceprocessors_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_deviceprocessors_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`nbcores`'], 'glpi_items_deviceprocessors_nbcores', [], ['lengths' => [null]]);
            $table->addIndex(['`nbthreads`'], 'glpi_items_deviceprocessors_nbthreads', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_deviceprocessors_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_deviceprocessors_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_deviceprocessors_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_deviceprocessors_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_deviceprocessors_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_deviceprocessors_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_deviceprocessors_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`deviceprocessors_id`'], 'deviceprocessors_id', [], ['lengths' => [null]]);
            $table->addIndex(['`frequency`'], 'specificity', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`nbcores`'], 'nbcores', [], ['lengths' => [null]]);
            $table->addIndex(['`nbthreads`'], 'nbthreads', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicesensors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicesensors_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicesensors_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesensors_id`'], 'glpi_items_devicesensors_devicesensors_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicesensors_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicesensors_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicesensors_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicesensors_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicesensors_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicesensors_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicesensors_otherserial', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesensors_id`'], 'devicesensors_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicesoundcards`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicesoundcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`busID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicesoundcards_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesoundcards_id`'], 'glpi_items_devicesoundcards_devicesoundcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicesoundcards_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicesoundcards_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicesoundcards_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicesoundcards_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicesoundcards_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'glpi_items_devicesoundcards_busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicesoundcards_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicesoundcards_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_items_devicesoundcards_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_items_devicesoundcards_states_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicesoundcards_id`'], 'devicesoundcards_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`busID`'], 'busID', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_problems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`itemtype`', '`items_id`'], 'glpi_items_problems_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_problems_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_projects`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projects_id`', '`itemtype`', '`items_id`'], 'glpi_items_projects_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_projects_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projects_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`tickets_id`'], 'glpi_items_tickets_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_items_tickets_tickets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`tickets_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_itilcategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`itilcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`knowbaseitemcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`code`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_helpdeskvisible`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`tickettemplates_id_incident`', 'integer', ['default' => '0']);
        $table->addColumn('`tickettemplates_id_demand`', 'integer', ['default' => '0']);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_incident`', 'integer', ['default' => '1']);
        $table->addColumn('`is_request`', 'integer', ['default' => '1']);
        $table->addColumn('`is_problem`', 'integer', ['default' => '1']);
        $table->addColumn('`is_change`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_itilcategories_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_itilcategories_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_itilcategories_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'glpi_itilcategories_knowbaseitemcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_itilcategories_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_itilcategories_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdeskvisible`'], 'glpi_itilcategories_is_helpdeskvisible', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'glpi_itilcategories_itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id_incident`'], 'glpi_itilcategories_tickettemplates_id_incident', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id_demand`'], 'glpi_itilcategories_tickettemplates_id_demand', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'glpi_itilcategories_changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_itilcategories_problemtemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_incident`'], 'glpi_itilcategories_is_incident', [], ['lengths' => [null]]);
            $table->addIndex(['`is_request`'], 'glpi_itilcategories_is_request', [], ['lengths' => [null]]);
            $table->addIndex(['`is_problem`'], 'glpi_itilcategories_is_problem', [], ['lengths' => [null]]);
            $table->addIndex(['`is_change`'], 'glpi_itilcategories_is_change', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_itilcategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_itilcategories_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'knowbaseitemcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdeskvisible`'], 'is_helpdeskvisible', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id_incident`'], 'tickettemplates_id_incident', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id_demand`'], 'tickettemplates_id_demand', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_incident`'], 'is_incident', [], ['lengths' => [null]]);
            $table->addIndex(['`is_request`'], 'is_request', [], ['lengths' => [null]]);
            $table->addIndex(['`is_problem`'], 'is_problem', [], ['lengths' => [null]]);
            $table->addIndex(['`is_change`'], 'is_change', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_itils_projects`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`projects_id`'], 'glpi_itils_projects_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`projects_id`'], 'glpi_itils_projects_projects_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`projects_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`projects_id`'], 'projects_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitemcategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`knowbaseitemcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`knowbaseitemcategories_id`', '`name`'], 'glpi_knowbaseitemcategories_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`name`'], 'glpi_knowbaseitemcategories_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_knowbaseitemcategories_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_knowbaseitemcategories_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_knowbaseitemcategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_knowbaseitemcategories_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`knowbaseitemcategories_id`', '`name`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitemcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'text', ['notnull' => false]);
        $table->addColumn('`answer`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_faq`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`view`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`begin_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id`'], 'glpi_knowbaseitems_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'glpi_knowbaseitems_knowbaseitemcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_faq`'], 'glpi_knowbaseitems_is_faq', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_knowbaseitems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_knowbaseitems_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_knowbaseitems_end_date', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'knowbaseitemcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_faq`'], 'is_faq', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`name`', '`answer`'], 'fulltext', ['fulltext'], ['lengths' => [null, null]]);
            $table->addIndex(['`name`'], 'name', ['fulltext'], ['lengths' => [null]]);
            $table->addIndex(['`answer`'], 'answer', ['fulltext'], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems_profiles`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'glpi_knowbaseitems_profiles_knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'glpi_knowbaseitems_profiles_profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_knowbaseitems_profiles_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_knowbaseitems_profiles_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'glpi_knowbaseitems_users_knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_knowbaseitems_users_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`'], 'knowbaseitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitemtranslations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 10]);
        $table->addColumn('`name`', 'text', ['notnull' => false]);
        $table->addColumn('`answer`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`', '`language`'], 'glpi_knowbaseitemtranslations_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`users_id`'], 'glpi_knowbaseitemtranslations_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`knowbaseitems_id`', '`language`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`', '`answer`'], 'fulltext', ['fulltext'], ['lengths' => [null, null]]);
            $table->addIndex(['`name`'], 'name', ['fulltext'], ['lengths' => [null]]);
            $table->addIndex(['`answer`'], 'answer', ['fulltext'], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_lines`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', 'smallint', ['default' => '0']);
        $table->addColumn('`is_deleted`', 'smallint', ['default' => '0']);
        $table->addColumn('`caller_num`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`caller_name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`lineoperators_id`', 'integer', ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`linetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_lines_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_lines_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_lines_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`lineoperators_id`'], 'glpi_lines_lineoperators_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`lineoperators_id`'], 'lineoperators_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_lineoperators`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`mcc`', 'integer', ['notnull' => false]);
        $table->addColumn('`mnc`', 'integer', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', 'smallint', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_lineoperators_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_lineoperators_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_lineoperators_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_lineoperators_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_lineoperators_date_creation', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`mcc`', '`mnc`'], 'glpi_lineoperators_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`mcc`', '`mnc`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_linetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_linetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_linetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_linetypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_links`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`link`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`data`', 'text', ['notnull' => false]);
        $table->addColumn('`open_window`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_links_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_links_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_links_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_links_itemtypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`links_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`links_id`'], 'glpi_links_itemtypes_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`links_id`'], 'glpi_links_itemtypes_links_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`links_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`links_id`'], 'links_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_locations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`address`', 'text', ['notnull' => false]);
        $table->addColumn('`postcode`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`town`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`state`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`country`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`building`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`room`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`latitude`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`longitude`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`altitude`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`locations_id`', '`name`'], 'glpi_locations_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`'], 'glpi_locations_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_locations_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_locations_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_locations_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_locations_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`entities_id`', '`locations_id`', '`name`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_logs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype_link`', 'string', ['default' => '', 'length' => 100]);
        $table->addColumn('`linked_action`', 'integer', ['default' => '0', 'comment' => 'see define.php HISTORY_* constant']);
        $table->addColumn('`user_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`id_search_option`', 'integer', ['default' => '0', 'comment' => 'see search.constant.php for value']);
        $table->addColumn('`old_value`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`new_value`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_logs_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype_link`'], 'glpi_logs_itemtype_link', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_logs_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`id_search_option`'], 'glpi_logs_id_search_option', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype_link`'], 'itemtype_link', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`id_search_option`'], 'id_search_option', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_mailcollectors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`host`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`login`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`filesize_max`', 'integer', ['default' => '2097152']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`passwd`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`accepted`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`refused`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`errors`', 'integer', ['default' => '0']);
        $table->addColumn('`use_mail_date`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`requester_field`', 'integer', ['default' => '0']);
        $table->addColumn('`add_cc_to_observer`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`collect_only_unread`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`is_active`'], 'glpi_mailcollectors_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_mailcollectors_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_mailcollectors_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_manufacturers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_manufacturers_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_manufacturers_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_manufacturers_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_monitormodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_monitormodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_monitormodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_monitormodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_monitormodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_monitors`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`size`', 'decimal', ['default' => '0.00', 'precision' => 5, 'scale' => 2]);
        $table->addColumn('`have_micro`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_speaker`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_subd`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_bnc`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_dvi`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_pivot`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_hdmi`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_displayport`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`monitortypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`monitormodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_global`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_monitors_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_monitors_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'glpi_monitors_is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_monitors_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_monitors_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_monitors_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_monitors_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_monitors_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`monitormodels_id`'], 'glpi_monitors_monitormodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_monitors_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_monitors_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`monitortypes_id`'], 'glpi_monitors_monitortypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_monitors_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_monitors_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_monitors_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_monitors_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_monitors_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_monitors_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_monitors_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`monitormodels_id`'], 'monitormodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`monitortypes_id`'], 'monitortypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_monitortypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_monitortypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_monitortypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_monitortypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_netpoints`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_netpoints_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`', '`locations_id`', '`name`'], 'glpi_netpoints_complete', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`', '`name`'], 'glpi_netpoints_location_name', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_netpoints_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_netpoints_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`', '`locations_id`', '`name`'], 'complete', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`', '`name`'], 'location_name', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkaliases`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networknames_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`fqdns_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_networkaliases_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_networkaliases_name', [], ['lengths' => [null]]);
            $table->addIndex(['`networknames_id`'], 'glpi_networkaliases_networknames_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`networknames_id`'], 'networknames_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkequipmentmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_networkequipmentmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkequipmentmodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkequipmentmodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_networkequipmentmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkequipments`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ram`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networkequipmenttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networkequipmentmodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_networkequipments_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_networkequipments_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_networkequipments_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_networkequipments_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_networkequipments_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_networkequipments_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_networkequipments_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networkequipmentmodels_id`'], 'glpi_networkequipments_networkequipmentmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'glpi_networkequipments_networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_networkequipments_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_networkequipments_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`networkequipmenttypes_id`'], 'glpi_networkequipments_networkequipmenttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_networkequipments_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkequipments_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_networkequipments_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_networkequipments_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_networkequipments_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_networkequipments_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkequipments_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networkequipmentmodels_id`'], 'networkequipmentmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`networkequipmenttypes_id`'], 'networkequipmenttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkequipmenttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_networkequipmenttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkequipmenttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkequipmenttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkinterfaces`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_networkinterfaces_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networknames`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`fqdns_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_networknames_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`', '`fqdns_id`'], 'glpi_networknames_FQDN', [], ['lengths' => [null, null]]);
            $table->addIndex(['`name`'], 'glpi_networknames_name', [], ['lengths' => [null]]);
            $table->addIndex(['`fqdns_id`'], 'glpi_networknames_fqdns_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_networknames_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_networknames_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`is_deleted`'], 'glpi_networknames_item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networknames_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networknames_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`', '`fqdns_id`'], 'FQDN', [], ['lengths' => [null, null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`fqdns_id`'], 'fqdns_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`', '`is_deleted`'], 'item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportaggregates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networkports_id_list`', 'text', ['notnull' => false, 'comment' => 'array of associated networkports_id']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportaggregates_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportaggregates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportaggregates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportaliases`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networkports_id_alias`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportaliases_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`networkports_id_alias`'], 'glpi_networkportaliases_networkports_id_alias', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportaliases_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportaliases_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`networkports_id_alias`'], 'networkports_id_alias', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportdialups`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportdialups_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportdialups_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportdialups_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportethernets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_devicenetworkcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`netpoints_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'string', ['default' => '', 'notnull' => false, 'length' => 10, 'comment' => 'T, LX, SX']);
        $table->addColumn('`speed`', 'integer', ['default' => '10', 'comment' => 'Mbit/s: 10, 100, 1000, 10000']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportethernets_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'glpi_networkportethernets_card', [], ['lengths' => [null]]);
            $table->addIndex(['`netpoints_id`'], 'glpi_networkportethernets_netpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`type`'], 'glpi_networkportethernets_type', [], ['lengths' => [null]]);
            $table->addIndex(['`speed`'], 'glpi_networkportethernets_speed', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportethernets_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportethernets_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'card', [], ['lengths' => [null]]);
            $table->addIndex(['`netpoints_id`'], 'netpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`type`'], 'type', [], ['lengths' => [null]]);
            $table->addIndex(['`speed`'], 'speed', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportfiberchannels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_devicenetworkcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`netpoints_id`', 'integer', ['default' => '0']);
        $table->addColumn('`wwn`', 'string', ['default' => '', 'notnull' => false, 'length' => 16]);
        $table->addColumn('`speed`', 'integer', ['default' => '10', 'comment' => 'Mbit/s: 10, 100, 1000, 10000']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportfiberchannels_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'glpi_networkportfiberchannels_card', [], ['lengths' => [null]]);
            $table->addIndex(['`netpoints_id`'], 'glpi_networkportfiberchannels_netpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`wwn`'], 'glpi_networkportfiberchannels_wwn', [], ['lengths' => [null]]);
            $table->addIndex(['`speed`'], 'glpi_networkportfiberchannels_speed', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportfiberchannels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportfiberchannels_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'card', [], ['lengths' => [null]]);
            $table->addIndex(['`netpoints_id`'], 'netpoint', [], ['lengths' => [null]]);
            $table->addIndex(['`wwn`'], 'wwn', [], ['lengths' => [null]]);
            $table->addIndex(['`speed`'], 'speed', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportlocals`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportlocals_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportlocals_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportlocals_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkports`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`logical_number`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`instantiation_type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mac`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`', '`itemtype`'], 'glpi_networkports_on_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_networkports_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'glpi_networkports_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_networkports_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`mac`'], 'glpi_networkports_mac', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_networkports_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_networkports_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkports_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkports_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`', '`itemtype`'], 'on_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`mac`'], 'mac', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkports_networkports`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id_1`', 'integer', ['default' => '0']);
        $table->addColumn('`networkports_id_2`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id_1`', '`networkports_id_2`'], 'glpi_networkports_networkports_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`networkports_id_2`'], 'glpi_networkports_networkports_networkports_id_2', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id_1`', '`networkports_id_2`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`networkports_id_2`'], 'networkports_id_2', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkports_vlans`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`vlans_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tagged`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`', '`vlans_id`'], 'glpi_networkports_vlans_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`vlans_id`'], 'glpi_networkports_vlans_vlans_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`', '`vlans_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`vlans_id`'], 'vlans_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networkportwifis`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`networkports_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_devicenetworkcards_id`', 'integer', ['default' => '0']);
        $table->addColumn('`wifinetworks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networkportwifis_id`', 'integer', ['default' => '0', 'comment' => 'only useful in case of Managed node']);
        $table->addColumn('`version`', 'string', ['notnull' => false, 'length' => 20, 'comment' => 'a, a/b, a/b/g, a/b/g/n, a/b/g/n/y']);
        $table->addColumn('`mode`', 'string', ['notnull' => false, 'length' => 20, 'comment' => 'ad-hoc, managed, master, repeater, secondary, monitor, auto']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'glpi_networkportwifis_networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'glpi_networkportwifis_card', [], ['lengths' => [null]]);
            $table->addIndex(['`wifinetworks_id`'], 'glpi_networkportwifis_essid', [], ['lengths' => [null]]);
            $table->addIndex(['`version`'], 'glpi_networkportwifis_version', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'glpi_networkportwifis_mode', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networkportwifis_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networkportwifis_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`networkports_id`'], 'networkports_id', ['lengths' => [null]]);
            $table->addIndex(['`items_devicenetworkcards_id`'], 'card', [], ['lengths' => [null]]);
            $table->addIndex(['`wifinetworks_id`'], 'essid', [], ['lengths' => [null]]);
            $table->addIndex(['`version`'], 'version', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'mode', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_networks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_networks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_networks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_networks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notepads`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_lastupdater`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_notepads_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_notepads_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_notepads_date', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'glpi_notepads_users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_notepads_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notifications`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`event`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`allow_response`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_notifications_name', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_notifications_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_notifications_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_notifications_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_notifications_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_notifications_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_notifications_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notifications_notificationtemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`notifications_id`', 'integer', ['default' => '0']);
        $table->addColumn('`mode`', 'string', $postgres ? ['default' => '', 'length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants'] : ['length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants']);
        $table->addColumn('`notificationtemplates_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`notifications_id`', '`mode`', '`notificationtemplates_id`'], 'glpi_notifications_notificationtemplates_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`notifications_id`'], 'glpi_notifications_notificationtemplates_notifications_id', [], ['lengths' => [null]]);
            $table->addIndex(['`notificationtemplates_id`'], 'glpi_notifications_notificationtemplates_notif_e3e13564478f2c87', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'glpi_notifications_notificationtemplates_mode', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`notifications_id`', '`mode`', '`notificationtemplates_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`notifications_id`'], 'notifications_id', [], ['lengths' => [null]]);
            $table->addIndex(['`notificationtemplates_id`'], 'notificationtemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'mode', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notificationtargets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '0']);
        $table->addColumn('`notifications_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`', '`items_id`'], 'glpi_notificationtargets_items', [], ['lengths' => [null, null]]);
            $table->addIndex(['`notifications_id`'], 'glpi_notificationtargets_notifications_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`type`', '`items_id`'], 'items', [], ['lengths' => [null, null]]);
            $table->addIndex(['`notifications_id`'], 'notifications_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notificationtemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`css`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'glpi_notificationtemplates_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_notificationtemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_notificationtemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_notificationtemplates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notificationtemplatetranslations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`notificationtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`language`', 'string', ['default' => '', 'length' => 10]);
        $table->addColumn('`subject`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`content_text`', 'text', ['notnull' => false]);
        $table->addColumn('`content_html`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`notificationtemplates_id`'], 'glpi_notificationtemplatetranslations_notificationtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`notificationtemplates_id`'], 'notificationtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_notimportedemails`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`from`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`to`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`mailcollectors_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['default' => 'CURRENT_TIMESTAMP'] : ['default' => 'CURRENT_TIMESTAMP', 'columnDefinition' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP']);
        $table->addColumn('`subject`', 'text', ['notnull' => false]);
        $table->addColumn('`messageid`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`reason`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id`'], 'glpi_notimportedemails_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`mailcollectors_id`'], 'glpi_notimportedemails_mailcollectors_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`mailcollectors_id`'], 'mailcollectors_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_objectlocks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100, 'comment' => 'Type of locked object'] : ['length' => 100, 'comment' => 'Type of locked object']);
        $table->addColumn('`items_id`', 'integer', $postgres ? ['default' => 0, 'comment' => 'RELATION to various tables, according to itemtype (ID)'] : ['comment' => 'RELATION to various tables, according to itemtype (ID)']);
        $table->addColumn('`users_id`', 'integer', $postgres ? ['default' => 0, 'comment' => 'id of the locker'] : ['comment' => 'id of the locker']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['default' => 'CURRENT_TIMESTAMP', 'comment' => 'Timestamp of the lock'] : ['default' => 'CURRENT_TIMESTAMP', 'columnDefinition' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'Timestamp of the lock\'', 'comment' => 'Timestamp of the lock']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'glpi_objectlocks_item', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'item', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemarchitectures`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemarchitectures_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_operatingsystemarchitectures_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_operatingsystemarchitectures_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystems_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_operatingsystems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_operatingsystems_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemservicepacks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemservicepacks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_operatingsystemservicepacks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_operatingsystemservicepacks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_operatingsystemversions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_operatingsystemversions_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_operatingsystemversions_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_operatingsystemversions_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_passivedcequipments`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`passivedcequipmentmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`passivedcequipmenttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to states (id)']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_passivedcequipments_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_passivedcequipments_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_passivedcequipments_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`passivedcequipmentmodels_id`'], 'glpi_passivedcequipments_passivedcequipmentmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`passivedcequipmenttypes_id`'], 'glpi_passivedcequipments_passivedcequipmenttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_passivedcequipments_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_passivedcequipments_group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_passivedcequipments_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_passivedcequipments_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_passivedcequipments_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_passivedcequipments_manufacturers_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`passivedcequipmentmodels_id`'], 'passivedcequipmentmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`passivedcequipmenttypes_id`'], 'passivedcequipmenttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_passivedcequipmentmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_passivedcequipmentmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_passivedcequipmentmodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_passivedcequipmentmodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_passivedcequipmentmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_passivedcequipmenttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_passivedcequipmenttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_passivedcequipmenttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_passivedcequipmenttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_peripheralmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_peripheralmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_peripheralmodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_peripheralmodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_peripheralmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_peripherals`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`peripheraltypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`peripheralmodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`brand`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_global`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_peripherals_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_peripherals_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'glpi_peripherals_is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_peripherals_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_peripherals_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_peripherals_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_peripherals_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_peripherals_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`peripheralmodels_id`'], 'glpi_peripherals_peripheralmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_peripherals_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_peripherals_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`peripheraltypes_id`'], 'glpi_peripherals_peripheraltypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_peripherals_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_peripherals_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_peripherals_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_peripherals_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_peripherals_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_peripherals_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_peripherals_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_peripherals_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`peripheralmodels_id`'], 'peripheralmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`peripheraltypes_id`'], 'peripheraltypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_peripheraltypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_peripheraltypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_peripheraltypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_peripheraltypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_phonemodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_phonemodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_phonemodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_phonemodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_phonemodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_phonepowersupplies`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_phonepowersupplies_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_phonepowersupplies_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_phonepowersupplies_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_phones`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`phonetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`phonemodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`brand`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phonepowersupplies_id`', 'integer', ['default' => '0']);
        $table->addColumn('`number_line`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`have_headset`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_hp`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_global`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_phones_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_phones_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'glpi_phones_is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_phones_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_phones_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_phones_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_phones_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_phones_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`phonemodels_id`'], 'glpi_phones_phonemodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`phonepowersupplies_id`'], 'glpi_phones_phonepowersupplies_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_phones_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_phones_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`phonetypes_id`'], 'glpi_phones_phonetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_phones_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_phones_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_phones_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_phones_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_phones_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_phones_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_phones_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_phones_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`phonemodels_id`'], 'phonemodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`phonepowersupplies_id`'], 'phonepowersupplies_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`phonetypes_id`'], 'phonetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_phonetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_phonetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_phonetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_phonetypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_planningrecalls`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`before_time`', 'integer', ['default' => '-10']);
        $table->addColumn('`when`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`users_id`'], 'glpi_planningrecalls_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`users_id`'], 'glpi_planningrecalls_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`before_time`'], 'glpi_planningrecalls_before_time', [], ['lengths' => [null]]);
            $table->addIndex(['`when`'], 'glpi_planningrecalls_when', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`users_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`before_time`'], 'before_time', [], ['lengths' => [null]]);
            $table->addIndex(['`when`'], 'when', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_plugins`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`directory`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`name`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`version`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`state`', 'integer', ['default' => '0', 'comment' => 'see define.php PLUGIN_* constant']);
        $table->addColumn('`author`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`homepage`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`license`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`directory`'], 'glpi_plugins_unicity', ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_plugins_state', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`directory`'], 'unicity', ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_printermodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_printermodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_printermodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_printermodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_printermodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_printers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`have_serial`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_parallel`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_usb`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_wifi`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`have_ethernet`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`memory_size`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`networks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`printertypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`printermodels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_global`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`init_pages_counter`', 'integer', ['default' => '0']);
        $table->addColumn('`last_pages_counter`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_printers_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_printers_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'glpi_printers_is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_printers_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_printers_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_printers_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_printers_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_printers_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`printermodels_id`'], 'glpi_printers_printermodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'glpi_printers_networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_printers_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_printers_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`printertypes_id`'], 'glpi_printers_printertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_printers_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_printers_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_printers_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`last_pages_counter`'], 'glpi_printers_last_pages_counter', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_printers_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_printers_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_printers_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_printers_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_global`'], 'is_global', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`printermodels_id`'], 'printermodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`networks_id`'], 'networks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`printertypes_id`'], 'printertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`last_pages_counter`'], 'last_pages_counter', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_printertypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_printertypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_printertypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_printertypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemcosts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`cost_time`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_fixed`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_material`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_problemcosts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`problems_id`'], 'glpi_problemcosts_problems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_problemcosts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_problemcosts_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_problemcosts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_problemcosts_budgets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`problems_id`'], 'problems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`status`', 'integer', ['default' => '1']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`solvedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`closedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`time_to_resolve`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id_recipient`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_lastupdater`', 'integer', ['default' => '0']);
        $table->addColumn('`urgency`', 'integer', ['default' => '1']);
        $table->addColumn('`impact`', 'integer', ['default' => '1']);
        $table->addColumn('`priority`', 'integer', ['default' => '1']);
        $table->addColumn('`itilcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`impactcontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`causecontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`symptomcontent`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`begin_waiting_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`waiting_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`close_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`solve_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_problems_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_problems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_problems_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_problems_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_problems_date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'glpi_problems_closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_problems_status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'glpi_problems_priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_problems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'glpi_problems_itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'glpi_problems_users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'glpi_problems_solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'glpi_problems_urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'glpi_problems_impact', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'glpi_problems_time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'glpi_problems_users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_problems_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'impact', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problems_suppliers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`suppliers_id`'], 'glpi_problems_suppliers_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'glpi_problems_suppliers_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`suppliers_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problems_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`tickets_id`'], 'glpi_problems_tickets_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_problems_tickets_tickets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`tickets_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problems_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`users_id`', '`alternative_email`'], 'glpi_problems_users_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'glpi_problems_users_user', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problems_id`', '`type`', '`users_id`', '`alternative_email`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'user', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemtasks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`problems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`taskcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_editor`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`tasktemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_problemtasks_uuid', ['lengths' => [null]]);
            $table->addIndex(['`problems_id`'], 'glpi_problemtasks_problems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_problemtasks_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'glpi_problemtasks_users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_problemtasks_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_problemtasks_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_problemtasks_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_problemtasks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_problemtasks_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'glpi_problemtasks_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_problemtasks_end', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_problemtasks_state', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'glpi_problemtasks_taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'glpi_problemtasks_tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_problemtasks_is_private', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`problems_id`'], 'problems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_profilerights`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`rights`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`profiles_id`', '`name`'], 'glpi_profilerights_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`profiles_id`', '`name`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_profiles`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`interface`', 'string', ['default' => 'helpdesk', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`is_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`helpdesk_hardware`', 'integer', ['default' => '0']);
        $table->addColumn('`helpdesk_item_type`', 'text', ['notnull' => false]);
        $table->addColumn('`ticket_status`', 'text', ['notnull' => false, 'comment' => 'json encoded array of from/dest allowed status change']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`problem_status`', 'text', ['notnull' => false, 'comment' => 'json encoded array of from/dest allowed status change']);
        $table->addColumn('`create_ticket_on_login`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`change_status`', 'text', ['notnull' => false, 'comment' => 'json encoded array of from/dest allowed status change']);
        $table->addColumn('`managed_domainrecordtypes`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`interface`'], 'glpi_profiles_interface', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'glpi_profiles_is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_profiles_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_profiles_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_profiles_tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'glpi_profiles_changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_profiles_problemtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`interface`'], 'interface', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_profiles_reminders`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reminders_id`', 'integer', ['default' => '0']);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'glpi_profiles_reminders_reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'glpi_profiles_reminders_profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_profiles_reminders_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_profiles_reminders_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_profiles_rssfeeds`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rssfeeds_id`', 'integer', ['default' => '0']);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '-1']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'glpi_profiles_rssfeeds_rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'glpi_profiles_rssfeeds_profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_profiles_rssfeeds_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_profiles_rssfeeds_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_profiles_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_default_profile`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_profiles_users_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'glpi_profiles_users_profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_profiles_users_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_profiles_users_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_profiles_users_is_dynamic', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projectcosts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`cost`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projectcosts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'glpi_projectcosts_projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_projectcosts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_projectcosts_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_projectcosts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_projectcosts_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_projectcosts_budgets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projects`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`code`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`priority`', 'integer', ['default' => '1']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projectstates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`plan_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`plan_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`percent_done`', 'integer', ['default' => '0']);
        $table->addColumn('`auto_percent_done`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`show_on_global_gantt`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`comment`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`projecttemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projects_name', [], ['lengths' => [null]]);
            $table->addIndex(['`code`'], 'glpi_projects_code', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_projects_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_projects_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'glpi_projects_projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'glpi_projects_projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttypes_id`'], 'glpi_projects_projecttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'glpi_projects_priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_projects_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projects_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_projects_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_projects_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'glpi_projects_plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'glpi_projects_plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'glpi_projects_real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'glpi_projects_real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'glpi_projects_percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`show_on_global_gantt`'], 'glpi_projects_show_on_global_gantt', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_projects_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttemplates_id`'], 'glpi_projects_projecttemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_projects_is_template', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`code`'], 'code', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttypes_id`'], 'projecttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'priority', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`show_on_global_gantt`'], 'show_on_global_gantt', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttemplates_id`'], 'projecttemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projectstates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_finished`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projectstates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_finished`'], 'glpi_projectstates_is_finished', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projectstates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_projectstates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_finished`'], 'is_finished', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttasks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`comment`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttasks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`plan_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`plan_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`planned_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`effective_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`projectstates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttasktypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`percent_done`', 'integer', ['default' => '0']);
        $table->addColumn('`auto_percent_done`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_milestone`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`projecttasktemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_projecttasks_uuid', ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_projecttasks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_projecttasks_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_projecttasks_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'glpi_projecttasks_projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasks_id`'], 'glpi_projecttasks_projecttasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_projecttasks_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projecttasks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_projecttasks_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'glpi_projecttasks_plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'glpi_projecttasks_plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'glpi_projecttasks_real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'glpi_projecttasks_real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'glpi_projecttasks_percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'glpi_projecttasks_projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktypes_id`'], 'glpi_projecttasks_projecttasktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktemplates_id`'], 'glpi_projecttasks_projecttasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_projecttasks_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_milestone`'], 'glpi_projecttasks_is_milestone', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasks_id`'], 'projecttasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktypes_id`'], 'projecttasktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktemplates_id`'], 'projecttasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_milestone`'], 'is_milestone', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttasktemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`description`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`comment`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttasks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`plan_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`plan_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_start_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`real_end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`planned_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`effective_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`projectstates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttasktypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`percent_done`', 'integer', ['default' => '0']);
        $table->addColumn('`is_milestone`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comments`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projecttasktemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_projecttasktemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_projecttasktemplates_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'glpi_projecttasktemplates_projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasks_id`'], 'glpi_projecttasktemplates_projecttasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_projecttasktemplates_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projecttasktemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_projecttasktemplates_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'glpi_projecttasktemplates_plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'glpi_projecttasktemplates_plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'glpi_projecttasktemplates_real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'glpi_projecttasktemplates_real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'glpi_projecttasktemplates_percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'glpi_projecttasktemplates_projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktypes_id`'], 'glpi_projecttasktemplates_projecttasktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_milestone`'], 'glpi_projecttasktemplates_is_milestone', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`projects_id`'], 'projects_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasks_id`'], 'projecttasks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_start_date`'], 'plan_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`plan_end_date`'], 'plan_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_start_date`'], 'real_start_date', [], ['lengths' => [null]]);
            $table->addIndex(['`real_end_date`'], 'real_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`percent_done`'], 'percent_done', [], ['lengths' => [null]]);
            $table->addIndex(['`projectstates_id`'], 'projectstates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`projecttasktypes_id`'], 'projecttasktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_milestone`'], 'is_milestone', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttasks_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`projecttasks_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`projecttasks_id`'], 'glpi_projecttasks_tickets_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`projecttasks_id`'], 'glpi_projecttasks_tickets_projects_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`projecttasks_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`projecttasks_id`'], 'projects_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttaskteams`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`projecttasks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projecttasks_id`', '`itemtype`', '`items_id`'], 'glpi_projecttaskteams_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_projecttaskteams_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projecttasks_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttasktypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projecttasktypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projecttasktypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_projecttasktypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projectteams`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`projects_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projects_id`', '`itemtype`', '`items_id`'], 'glpi_projectteams_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_projectteams_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`projects_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_projecttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_projecttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_projecttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_projecttypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_queuednotifications`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`notificationtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`sent_try`', 'integer', ['default' => '0']);
        $table->addColumn('`create_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`send_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`sent_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`name`', 'text', ['notnull' => false]);
        $table->addColumn('`sender`', 'text', ['notnull' => false]);
        $table->addColumn('`sendername`', 'text', ['notnull' => false]);
        $table->addColumn('`recipient`', 'text', ['notnull' => false]);
        $table->addColumn('`recipientname`', 'text', ['notnull' => false]);
        $table->addColumn('`replyto`', 'text', ['notnull' => false]);
        $table->addColumn('`replytoname`', 'text', ['notnull' => false]);
        $table->addColumn('`headers`', 'text', ['notnull' => false]);
        $table->addColumn('`body_html`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`body_text`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`messageid`', 'text', ['notnull' => false]);
        $table->addColumn('`documents`', 'text', ['notnull' => false]);
        $table->addColumn('`mode`', 'string', $postgres ? ['default' => '', 'length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants'] : ['length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`', '`notificationtemplates_id`'], 'glpi_queuednotifications_item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_queuednotifications_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_queuednotifications_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_try`'], 'glpi_queuednotifications_sent_try', [], ['lengths' => [null]]);
            $table->addIndex(['`create_time`'], 'glpi_queuednotifications_create_time', [], ['lengths' => [null]]);
            $table->addIndex(['`send_time`'], 'glpi_queuednotifications_send_time', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_time`'], 'glpi_queuednotifications_sent_time', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'glpi_queuednotifications_mode', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`', '`notificationtemplates_id`'], 'item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_try`'], 'sent_try', [], ['lengths' => [null]]);
            $table->addIndex(['`create_time`'], 'create_time', [], ['lengths' => [null]]);
            $table->addIndex(['`send_time`'], 'send_time', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_time`'], 'sent_time', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'mode', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_queuedchats`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`notificationtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itilcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`sent_try`', 'integer', ['default' => '0']);
        $table->addColumn('`create_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`send_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`sent_time`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`entName`', 'text', ['notnull' => false]);
        $table->addColumn('`ticketTitle`', 'text', ['notnull' => false]);
        $table->addColumn('`completName`', 'text', ['notnull' => false]);
        $table->addColumn('`serverName`', 'text', ['notnull' => false]);
        $table->addColumn('`hookurl`', 'string', ['notnull' => false, 'length' => 250]);
        $table->addColumn('`mode`', 'string', $postgres ? ['default' => '', 'length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants'] : ['length' => 20, 'comment' => 'See Notification_NotificationTemplate::MODE_* constants']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`', '`notificationtemplates_id`'], 'glpi_queuedchats_item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_queuedchats_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_queuedchats_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_try`'], 'glpi_queuedchats_sent_try', [], ['lengths' => [null]]);
            $table->addIndex(['`create_time`'], 'glpi_queuedchats_create_time', [], ['lengths' => [null]]);
            $table->addIndex(['`send_time`'], 'glpi_queuedchats_send_time', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_time`'], 'glpi_queuedchats_sent_time', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'glpi_queuedchats_mode', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`', '`items_id`', '`notificationtemplates_id`'], 'item', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_try`'], 'sent_try', [], ['lengths' => [null]]);
            $table->addIndex(['`create_time`'], 'create_time', [], ['lengths' => [null]]);
            $table->addIndex(['`send_time`'], 'send_time', [], ['lengths' => [null]]);
            $table->addIndex(['`sent_time`'], 'sent_time', [], ['lengths' => [null]]);
            $table->addIndex(['`mode`'], 'mode', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_registeredids`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`device_type`', 'string', $postgres ? ['default' => '', 'length' => 100, 'comment' => 'USB, PCI ...'] : ['length' => 100, 'comment' => 'USB, PCI ...']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_registeredids_name', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'glpi_registeredids_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`device_type`'], 'glpi_registeredids_device_type', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`device_type`'], 'device_type', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_reminders`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`text`', 'text', ['notnull' => false]);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_planned`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`begin_view_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end_view_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_reminders_uuid', ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_reminders_date', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'glpi_reminders_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_reminders_end', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_reminders_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_planned`'], 'glpi_reminders_is_planned', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_reminders_state', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_reminders_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_reminders_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_planned`'], 'is_planned', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_remindertranslations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reminders_id`', 'integer', ['default' => '0']);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 5]);
        $table->addColumn('`name`', 'text', ['notnull' => false]);
        $table->addColumn('`text`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`', '`language`'], 'glpi_remindertranslations_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`users_id`'], 'glpi_remindertranslations_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`', '`language`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_reminders_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reminders_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'glpi_reminders_users_reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_reminders_users_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`reminders_id`'], 'reminders_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_requesttypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_helpdesk_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_followup_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_mail_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_mailfollowup_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_active`', 'smallint', ['default' => '1']);
        $table->addColumn('`is_ticketheader`', 'smallint', ['default' => '1']);
        $table->addColumn('`is_itilfollowup`', 'smallint', ['default' => '1']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_requesttypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_default`'], 'glpi_requesttypes_is_helpdesk_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_followup_default`'], 'glpi_requesttypes_is_followup_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_mail_default`'], 'glpi_requesttypes_is_mail_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_mailfollowup_default`'], 'glpi_requesttypes_is_mailfollowup_default', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_requesttypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_requesttypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_requesttypes_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_ticketheader`'], 'glpi_requesttypes_is_ticketheader', [], ['lengths' => [null]]);
            $table->addIndex(['`is_itilfollowup`'], 'glpi_requesttypes_is_itilfollowup', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_default`'], 'is_helpdesk_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_followup_default`'], 'is_followup_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_mail_default`'], 'is_mail_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_mailfollowup_default`'], 'is_mailfollowup_default', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_ticketheader`'], 'is_ticketheader', [], ['lengths' => [null]]);
            $table->addIndex(['`is_itilfollowup`'], 'is_itilfollowup', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_reservationitems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`is_active`'], 'glpi_reservationitems_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_reservationitems_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'glpi_reservationitems_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_reservationitems_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_reservationitems_is_deleted', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_reservations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`reservationitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`group`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`begin`'], 'glpi_reservations_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_reservations_end', [], ['lengths' => [null]]);
            $table->addIndex(['`reservationitems_id`'], 'glpi_reservations_reservationitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_reservations_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`reservationitems_id`', '`group`'], 'glpi_reservations_resagroup', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`reservationitems_id`'], 'reservationitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`reservationitems_id`', '`group`'], 'resagroup', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rssfeeds`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`url`', 'text', ['notnull' => false]);
        $table->addColumn('`refresh_rate`', 'integer', ['default' => '86400']);
        $table->addColumn('`max_items`', 'integer', ['default' => '20']);
        $table->addColumn('`have_error`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_rssfeeds_name', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_rssfeeds_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_rssfeeds_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`have_error`'], 'glpi_rssfeeds_have_error', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_rssfeeds_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_rssfeeds_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`have_error`'], 'have_error', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rssfeeds_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rssfeeds_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'glpi_rssfeeds_users_rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_rssfeeds_users_users_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rssfeeds_id`'], 'rssfeeds_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ruleactions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rules_id`', 'integer', ['default' => '0']);
        $table->addColumn('`action_type`', 'string', ['notnull' => false, 'length' => 255, 'comment' => 'VALUE IN (assign, regex_result, append_regex_result, affectbyip, affectbyfqdn, affectbymac)']);
        $table->addColumn('`field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rules_id`'], 'glpi_ruleactions_rules_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rules_id`'], 'rules_id', [], ['lengths' => [null]]);
            $table->addIndex(['`field`', '`value`'], 'field_value', [], ['lengths' => [50, 50]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rulecriterias`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`rules_id`', 'integer', ['default' => '0']);
        $table->addColumn('`criteria`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`condition`', 'integer', ['default' => '0', 'comment' => 'see define.php PATTERN_* and REGEX_* constant']);
        $table->addColumn('`pattern`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rules_id`'], 'glpi_rulecriterias_rules_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'glpi_rulecriterias_condition', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`rules_id`'], 'rules_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'condition', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rulerightparameters`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_rulerightparameters_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_rulerightparameters_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rules`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`sub_type`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`ranking`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`description`', 'text', ['notnull' => false]);
        $table->addColumn('`match`', 'string', ['notnull' => false, 'length' => 10, 'fixed' => true, 'comment' => 'see define.php *_MATCHING constant']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`condition`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_rules_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_rules_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`sub_type`'], 'glpi_rules_sub_type', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_rules_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_rules_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'glpi_rules_condition', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_rules_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`sub_type`'], 'sub_type', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'condition', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slalevelactions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`slalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`action_type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`slalevels_id`'], 'glpi_slalevelactions_slalevels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`slalevels_id`'], 'slalevels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slalevelcriterias`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`slalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`criteria`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`condition`', 'integer', ['default' => '0', 'comment' => 'see define.php PATTERN_* and REGEX_* constant']);
        $table->addColumn('`pattern`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`slalevels_id`'], 'glpi_slalevelcriterias_slalevels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'glpi_slalevelcriterias_condition', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`slalevels_id`'], 'slalevels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'condition', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slalevels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`slas_id`', 'integer', ['default' => '0']);
        $table->addColumn('`execution_time`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`match`', 'string', ['notnull' => false, 'length' => 10, 'fixed' => true, 'comment' => 'see define.php *_MATCHING constant']);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_slalevels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_slalevels_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id`'], 'glpi_slalevels_slas_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id`'], 'slas_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slalevels_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`slalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickets_id`'], 'glpi_slalevels_tickets_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slalevels_id`'], 'glpi_slalevels_tickets_slalevels_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`tickets_id`', '`slalevels_id`'], 'glpi_slalevels_tickets_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slalevels_id`'], 'slalevels_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`tickets_id`', '`slalevels_id`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_olalevelactions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`olalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`action_type`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`value`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`olalevels_id`'], 'glpi_olalevelactions_olalevels_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`olalevels_id`'], 'olalevels_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_olalevelcriterias`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`olalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`criteria`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`condition`', 'integer', ['default' => '0', 'comment' => 'see define.php PATTERN_* and REGEX_* constant']);
        $table->addColumn('`pattern`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`olalevels_id`'], 'glpi_olalevelcriterias_olalevels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'glpi_olalevelcriterias_condition', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`olalevels_id`'], 'olalevels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`condition`'], 'condition', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_olalevels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`olas_id`', 'integer', ['default' => '0']);
        $table->addColumn('`execution_time`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`match`', 'string', ['notnull' => false, 'length' => 10, 'fixed' => true, 'comment' => 'see define.php *_MATCHING constant']);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_olalevels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_olalevels_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id`'], 'glpi_olalevels_olas_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id`'], 'olas_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_olalevels_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`olalevels_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickets_id`'], 'glpi_olalevels_tickets_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`olalevels_id`'], 'glpi_olalevels_tickets_olalevels_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`tickets_id`', '`olalevels_id`'], 'glpi_olalevels_tickets_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`olalevels_id`'], 'olalevels_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`tickets_id`', '`olalevels_id`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slms`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_slms_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_slms_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_slms_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'glpi_slms_calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_slms_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_slms_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_slas`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`number_time`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`definition_time`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`end_of_working_day`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`slms_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_slas_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_slas_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_slas_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'glpi_slas_calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slms_id`'], 'glpi_slas_slms_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slms_id`'], 'slms_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_olas`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`number_time`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`definition_time`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`end_of_working_day`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`slms_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_olas_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_olas_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_olas_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'glpi_olas_calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slms_id`'], 'glpi_olas_slms_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`calendars_id`'], 'calendars_id', [], ['lengths' => [null]]);
            $table->addIndex(['`slms_id`'], 'slms_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_softwarecategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`softwarecategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`softwarecategories_id`'], 'glpi_softwarecategories_softwarecategories_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`softwarecategories_id`'], 'softwarecategories_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_softwarelicenses`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`softwares_id`', 'integer', ['default' => '0']);
        $table->addColumn('`softwarelicenses_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`number`', 'integer', ['default' => '0']);
        $table->addColumn('`softwarelicensetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`softwareversions_id_buy`', 'integer', ['default' => '0']);
        $table->addColumn('`softwareversions_id_use`', 'integer', ['default' => '0']);
        $table->addColumn('`expire`', 'date', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_valid`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_helpdesk_visible`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`contact`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`contact_num`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`allow_overquota`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_softwarelicenses_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_softwarelicenses_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_softwarelicenses_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_softwarelicenses_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`expire`'], 'glpi_softwarelicenses_expire', [], ['lengths' => [null]]);
            $table->addIndex(['`softwareversions_id_buy`'], 'glpi_softwarelicenses_softwareversions_id_buy', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_softwarelicenses_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarelicensetypes_id`'], 'glpi_softwarelicenses_softwarelicensetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`softwareversions_id_use`'], 'glpi_softwarelicenses_softwareversions_id_use', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_softwarelicenses_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`', '`expire`', '`number`'], 'glpi_softwarelicenses_softwares_id_expire_number', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`'], 'glpi_softwarelicenses_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_softwarelicenses_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_softwarelicenses_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_softwarelicenses_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_softwarelicenses_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'glpi_softwarelicenses_is_helpdesk_visible', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_softwarelicenses_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_softwarelicenses_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_softwarelicenses_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_softwarelicenses_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`allow_overquota`'], 'glpi_softwarelicenses_allow_overquota', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`expire`'], 'expire', [], ['lengths' => [null]]);
            $table->addIndex(['`softwareversions_id_buy`'], 'softwareversions_id_buy', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarelicensetypes_id`'], 'softwarelicensetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`softwareversions_id_use`'], 'softwareversions_id_use', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`', '`expire`', '`number`'], 'softwares_id_expire_number', [], ['lengths' => [null, null, null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'is_helpdesk_visible', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`allow_overquota`'], 'allow_overquota', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_softwarelicensetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`softwarelicensetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_softwarelicensetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_softwarelicensetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_softwarelicensetypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarelicensetypes_id`'], 'glpi_softwarelicensetypes_softwarelicensetypes_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarelicensetypes_id`'], 'softwarelicensetypes_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_softwares`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_update`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`softwares_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ticket_tco`', 'decimal', ['default' => '0.0000', 'notnull' => false, 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`is_helpdesk_visible`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`softwarecategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_valid`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_softwares_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_softwares_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_softwares_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_update`'], 'glpi_softwares_is_update', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarecategories_id`'], 'glpi_softwares_softwarecategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_softwares_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_softwares_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_softwares_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_softwares_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_softwares_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_softwares_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`'], 'glpi_softwares_softwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_softwares_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'glpi_softwares_is_helpdesk_visible', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_softwares_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_softwares_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_update`'], 'is_update', [], ['lengths' => [null]]);
            $table->addIndex(['`softwarecategories_id`'], 'softwarecategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`'], 'softwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'is_helpdesk_visible', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_softwareversions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`softwares_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`operatingsystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_softwareversions_name', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`'], 'glpi_softwareversions_softwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_softwareversions_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_softwareversions_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_softwareversions_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystems_id`'], 'glpi_softwareversions_operatingsystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_softwareversions_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_softwareversions_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`softwares_id`'], 'softwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`operatingsystems_id`'], 'operatingsystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_solutiontemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', ['notnull' => false]);
        $table->addColumn('`solutiontypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_solutiontemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_solutiontemplates_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`solutiontypes_id`'], 'glpi_solutiontemplates_solutiontypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_solutiontemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_solutiontemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_solutiontemplates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`solutiontypes_id`'], 'solutiontypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_solutiontypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_solutiontypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_solutiontypes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_solutiontypes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_solutiontypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_solutiontypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_itilsolutions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`solutiontypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`solutiontype_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_approval`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`user_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_editor`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_approval`', 'integer', ['default' => '0']);
        $table->addColumn('`user_name_approval`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`status`', 'integer', ['default' => '1']);
        $table->addColumn('`itilfollowups_id`', 'integer', ['notnull' => false, 'comment' => 'Followup reference on reject or approve a solution']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'glpi_itilsolutions_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'glpi_itilsolutions_item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_itilsolutions_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`solutiontypes_id`'], 'glpi_itilsolutions_solutiontypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_itilsolutions_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'glpi_itilsolutions_users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_approval`'], 'glpi_itilsolutions_users_id_approval', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_itilsolutions_status', [], ['lengths' => [null]]);
            $table->addIndex(['`itilfollowups_id`'], 'glpi_itilsolutions_itilfollowups_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`solutiontypes_id`'], 'solutiontypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_approval`'], 'users_id_approval', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
            $table->addIndex(['`itilfollowups_id`'], 'itilfollowups_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ssovariables`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_ssovariables_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_ssovariables_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_states`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_visible_computer`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_monitor`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_networkequipment`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_peripheral`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_phone`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_printer`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_softwareversion`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_softwarelicense`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_line`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_certificate`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_passivedcequipment`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_enclosure`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_pdu`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_cluster`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_contract`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_visible_appliance`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_states_name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`states_id`', '`name`'], 'glpi_states_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`is_visible_computer`'], 'glpi_states_is_visible_computer', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_monitor`'], 'glpi_states_is_visible_monitor', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_networkequipment`'], 'glpi_states_is_visible_networkequipment', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_peripheral`'], 'glpi_states_is_visible_peripheral', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_phone`'], 'glpi_states_is_visible_phone', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_printer`'], 'glpi_states_is_visible_printer', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_softwareversion`'], 'glpi_states_is_visible_softwareversion', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_softwarelicense`'], 'glpi_states_is_visible_softwarelicense', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_line`'], 'glpi_states_is_visible_line', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_certificate`'], 'glpi_states_is_visible_certificate', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_rack`'], 'glpi_states_is_visible_rack', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_passivedcequipment`'], 'glpi_states_is_visible_passivedcequipment', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_enclosure`'], 'glpi_states_is_visible_enclosure', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_pdu`'], 'glpi_states_is_visible_pdu', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_cluster`'], 'glpi_states_is_visible_cluster', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_contract`'], 'glpi_states_is_visible_contract', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_appliance`'], 'glpi_states_is_visible_appliance', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_states_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_states_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`states_id`', '`name`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`is_visible_computer`'], 'is_visible_computer', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_monitor`'], 'is_visible_monitor', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_networkequipment`'], 'is_visible_networkequipment', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_peripheral`'], 'is_visible_peripheral', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_phone`'], 'is_visible_phone', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_printer`'], 'is_visible_printer', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_softwareversion`'], 'is_visible_softwareversion', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_softwarelicense`'], 'is_visible_softwarelicense', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_line`'], 'is_visible_line', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_certificate`'], 'is_visible_certificate', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_rack`'], 'is_visible_rack', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_passivedcequipment`'], 'is_visible_passivedcequipment', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_enclosure`'], 'is_visible_enclosure', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_pdu`'], 'is_visible_pdu', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_cluster`'], 'is_visible_cluster', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_contract`'], 'is_visible_contract', [], ['lengths' => [null]]);
            $table->addIndex(['`is_visible_appliance`'], 'is_visible_appliance', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_suppliers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`suppliertypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`address`', 'text', ['notnull' => false]);
        $table->addColumn('`postcode`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`town`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`state`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`country`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`website`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phonenumber`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`fax`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`email`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_suppliers_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_suppliers_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`suppliertypes_id`'], 'glpi_suppliers_suppliertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_suppliers_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_suppliers_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_suppliers_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_suppliers_is_active', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`suppliertypes_id`'], 'suppliertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_suppliers_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`suppliers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`suppliers_id`'], 'glpi_suppliers_tickets_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'glpi_suppliers_tickets_group', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`suppliers_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`suppliers_id`', '`type`'], 'group', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_suppliertypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_suppliertypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_suppliertypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_suppliertypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_taskcategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`taskcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`completename`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`level`', 'integer', ['default' => '0']);
        $table->addColumn('`ancestors_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`sons_cache`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`is_helpdeskvisible`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`knowbaseitemcategories_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_taskcategories_name', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'glpi_taskcategories_taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_taskcategories_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_taskcategories_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_taskcategories_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdeskvisible`'], 'glpi_taskcategories_is_helpdeskvisible', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_taskcategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_taskcategories_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'glpi_taskcategories_knowbaseitemcategories_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdeskvisible`'], 'is_helpdeskvisible', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`knowbaseitemcategories_id`'], 'knowbaseitemcategories_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tasktemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', ['notnull' => false]);
        $table->addColumn('`taskcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_tasktemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_tasktemplates_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'glpi_tasktemplates_taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_tasktemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_tasktemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_tasktemplates_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_tasktemplates_is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_tasktemplates_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_tasktemplates_groups_id_tech', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ticketcosts`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`begin_date`', 'date', ['notnull' => false]);
        $table->addColumn('`end_date`', 'date', ['notnull' => false]);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`cost_time`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_fixed`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`cost_material`', 'decimal', ['default' => '0.0000', 'precision' => 20, 'scale' => 4]);
        $table->addColumn('`budgets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_ticketcosts_name', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_ticketcosts_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_ticketcosts_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_ticketcosts_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_ticketcosts_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'glpi_ticketcosts_budgets_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`budgets_id`'], 'budgets_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ticketrecurrents`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`begin_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`periodicity`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`create_before`', 'integer', ['default' => '0']);
        $table->addColumn('`next_creation_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`calendars_id`', 'integer', ['default' => '0']);
        $table->addColumn('`end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_ticketrecurrents_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_ticketrecurrents_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_ticketrecurrents_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_ticketrecurrents_tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`next_creation_date`'], 'glpi_ticketrecurrents_next_creation_date', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`next_creation_date`'], 'next_creation_date', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`closedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`solvedate`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id_lastupdater`', 'integer', ['default' => '0']);
        $table->addColumn('`status`', 'integer', ['default' => '1']);
        $table->addColumn('`users_id_recipient`', 'integer', ['default' => '0']);
        $table->addColumn('`requesttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`urgency`', 'integer', ['default' => '1']);
        $table->addColumn('`impact`', 'integer', ['default' => '1']);
        $table->addColumn('`priority`', 'integer', ['default' => '1']);
        $table->addColumn('`itilcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`global_validation`', 'integer', ['default' => '1']);
        $table->addColumn('`slas_id_ttr`', 'integer', ['default' => '0']);
        $table->addColumn('`slas_id_tto`', 'integer', ['default' => '0']);
        $table->addColumn('`slalevels_id_ttr`', 'integer', ['default' => '0']);
        $table->addColumn('`time_to_resolve`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`time_to_own`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`begin_waiting_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`sla_waiting_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`ola_waiting_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`olas_id_tto`', 'integer', ['default' => '0']);
        $table->addColumn('`olas_id_ttr`', 'integer', ['default' => '0']);
        $table->addColumn('`olalevels_id_ttr`', 'integer', ['default' => '0']);
        $table->addColumn('`ola_ttr_begin_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`internal_time_to_resolve`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`internal_time_to_own`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`waiting_duration`', 'integer', ['default' => '0']);
        $table->addColumn('`close_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`solve_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`takeintoaccount_delay_stat`', 'integer', ['default' => '0']);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`validation_percent`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'glpi_tickets_date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'glpi_tickets_closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_tickets_status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'glpi_tickets_priority', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'glpi_tickets_request_type', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_tickets_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_tickets_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'glpi_tickets_users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'glpi_tickets_solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'glpi_tickets_urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'glpi_tickets_impact', [], ['lengths' => [null]]);
            $table->addIndex(['`global_validation`'], 'glpi_tickets_global_validation', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id_tto`'], 'glpi_tickets_slas_id_tto', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id_ttr`'], 'glpi_tickets_slas_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'glpi_tickets_time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_own`'], 'glpi_tickets_time_to_own', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id_tto`'], 'glpi_tickets_olas_id_tto', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id_ttr`'], 'glpi_tickets_olas_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`slalevels_id_ttr`'], 'glpi_tickets_slalevels_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`internal_time_to_resolve`'], 'glpi_tickets_internal_time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`internal_time_to_own`'], 'glpi_tickets_internal_time_to_own', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'glpi_tickets_users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`type`'], 'glpi_tickets_type', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'glpi_tickets_itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_tickets_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_tickets_name', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_tickets_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_tickets_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`ola_waiting_duration`'], 'glpi_tickets_ola_waiting_duration', [], ['lengths' => [null]]);
            $table->addIndex(['`olalevels_id_ttr`'], 'glpi_tickets_olalevels_id_ttr', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`closedate`'], 'closedate', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
            $table->addIndex(['`priority`'], 'priority', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'request_type', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_recipient`'], 'users_id_recipient', [], ['lengths' => [null]]);
            $table->addIndex(['`solvedate`'], 'solvedate', [], ['lengths' => [null]]);
            $table->addIndex(['`urgency`'], 'urgency', [], ['lengths' => [null]]);
            $table->addIndex(['`impact`'], 'impact', [], ['lengths' => [null]]);
            $table->addIndex(['`global_validation`'], 'global_validation', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id_tto`'], 'slas_id_tto', [], ['lengths' => [null]]);
            $table->addIndex(['`slas_id_ttr`'], 'slas_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_resolve`'], 'time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`time_to_own`'], 'time_to_own', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id_tto`'], 'olas_id_tto', [], ['lengths' => [null]]);
            $table->addIndex(['`olas_id_ttr`'], 'olas_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`slalevels_id_ttr`'], 'slalevels_id_ttr', [], ['lengths' => [null]]);
            $table->addIndex(['`internal_time_to_resolve`'], 'internal_time_to_resolve', [], ['lengths' => [null]]);
            $table->addIndex(['`internal_time_to_own`'], 'internal_time_to_own', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_lastupdater`'], 'users_id_lastupdater', [], ['lengths' => [null]]);
            $table->addIndex(['`type`'], 'type', [], ['lengths' => [null]]);
            $table->addIndex(['`itilcategories_id`'], 'itilcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`ola_waiting_duration`'], 'ola_waiting_duration', [], ['lengths' => [null]]);
            $table->addIndex(['`olalevels_id_ttr`'], 'olalevels_id_ttr', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickets_tickets`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id_1`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id_2`', 'integer', ['default' => '0']);
        $table->addColumn('`link`', 'integer', ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id_1`', '`tickets_id_2`'], 'glpi_tickets_tickets_unicity', ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id_1`', '`tickets_id_2`'], 'unicity', ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickets_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`use_notification`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`alternative_email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`users_id`', '`alternative_email`'], 'glpi_tickets_users_unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'glpi_tickets_users_user', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`', '`type`', '`users_id`', '`alternative_email`'], 'unicity', ['lengths' => [null, null, null, null]]);
            $table->addIndex(['`users_id`', '`type`'], 'user', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ticketsatisfactions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`type`', 'integer', ['default' => '1']);
        $table->addColumn('`date_begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_answered`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`satisfaction`', 'integer', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`'], 'glpi_ticketsatisfactions_tickets_id', ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickets_id`'], 'tickets_id', ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickettasks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`taskcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_editor`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`actiontime`', 'integer', ['default' => '0']);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`state`', 'integer', ['default' => '1']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`tasktemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        $table->addColumn('`sourceitems_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_tickettasks_uuid', ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_tickettasks_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_tickettasks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_tickettasks_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_tickettasks_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'glpi_tickettasks_users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_tickettasks_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_tickettasks_is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'glpi_tickettasks_taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_tickettasks_state', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_tickettasks_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_tickettasks_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'glpi_tickettasks_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_tickettasks_end', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'glpi_tickettasks_tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceitems_id`'], 'glpi_tickettasks_sourceitems_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`taskcategories_id`'], 'taskcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`tasktemplates_id`'], 'tasktemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceitems_id`'], 'sourceitems_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickettemplatehiddenfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickettemplates_id`', '`num`'], 'glpi_tickettemplatehiddenfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_tickettemplatehiddenfields_tickettemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickettemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changetemplatehiddenfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changetemplates_id`', '`num`'], 'glpi_changetemplatehiddenfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`changetemplates_id`'], 'glpi_changetemplatehiddenfields_changetemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changetemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemtemplatehiddenfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problemtemplates_id`', '`num`'], 'glpi_problemtemplatehiddenfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_problemtemplatehiddenfields_problemtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problemtemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickettemplatemandatoryfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickettemplates_id`', '`num`'], 'glpi_tickettemplatemandatoryfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_tickettemplatemandatoryfields_tickettemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`tickettemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changetemplatemandatoryfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changetemplates_id`', '`num`'], 'glpi_changetemplatemandatoryfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`changetemplates_id`'], 'glpi_changetemplatemandatoryfields_changetemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`changetemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemtemplatemandatoryfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problemtemplates_id`', '`num`'], 'glpi_problemtemplatemandatoryfields_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_problemtemplatemandatoryfields_problemtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`problemtemplates_id`', '`num`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickettemplatepredefinedfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`tickettemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        $table->addColumn('`value`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickettemplates_id`'], 'glpi_tickettemplatepredefinedfields_tickettemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`tickettemplates_id`'], 'tickettemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changetemplatepredefinedfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`changetemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        $table->addColumn('`value`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`changetemplates_id`'], 'glpi_changetemplatepredefinedfields_changetemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`changetemplates_id`'], 'changetemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemtemplatepredefinedfields`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`problemtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`num`', 'integer', ['default' => '0']);
        $table->addColumn('`value`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`problemtemplates_id`'], 'glpi_problemtemplatepredefinedfields_problemtemplates_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`problemtemplates_id`'], 'problemtemplates_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_tickettemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_tickettemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_tickettemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_tickettemplates_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_changetemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_changetemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_changetemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_changetemplates_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_problemtemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_problemtemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_problemtemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_problemtemplates_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_ticketvalidations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`tickets_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_validate`', 'integer', ['default' => '0']);
        $table->addColumn('`comment_submission`', 'text', ['notnull' => false]);
        $table->addColumn('`comment_validation`', 'text', ['notnull' => false]);
        $table->addColumn('`status`', 'integer', ['default' => '2']);
        $table->addColumn('`submission_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`validation_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_ticketvalidations_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_ticketvalidations_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_validate`'], 'glpi_ticketvalidations_users_id_validate', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'glpi_ticketvalidations_tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`submission_date`'], 'glpi_ticketvalidations_submission_date', [], ['lengths' => [null]]);
            $table->addIndex(['`validation_date`'], 'glpi_ticketvalidations_validation_date', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'glpi_ticketvalidations_status', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_validate`'], 'users_id_validate', [], ['lengths' => [null]]);
            $table->addIndex(['`tickets_id`'], 'tickets_id', [], ['lengths' => [null]]);
            $table->addIndex(['`submission_date`'], 'submission_date', [], ['lengths' => [null]]);
            $table->addIndex(['`validation_date`'], 'validation_date', [], ['lengths' => [null]]);
            $table->addIndex(['`status`'], 'status', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_transfers`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`keep_ticket`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_networklink`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_reservation`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_history`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_device`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_infocom`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_dc_monitor`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_dc_monitor`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_dc_phone`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_dc_phone`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_dc_peripheral`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_dc_peripheral`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_dc_printer`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_dc_printer`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_supplier`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_supplier`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_contact`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_contact`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_contract`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_contract`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_software`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_software`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_document`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_document`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_cartridgeitem`', 'integer', ['default' => '0']);
        $table->addColumn('`clean_cartridgeitem`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_cartridge`', 'integer', ['default' => '0']);
        $table->addColumn('`keep_consumable`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`keep_disk`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_transfers_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_usercategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_usercategories_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_usercategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_usercategories_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_useremails`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_default`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`email`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`email`'], 'glpi_useremails_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`email`'], 'glpi_useremails_email', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'glpi_useremails_is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_useremails_is_dynamic', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`users_id`', '`email`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`email`'], 'email', [], ['lengths' => [null]]);
            $table->addIndex(['`is_default`'], 'is_default', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`password`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`password_last_update`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`phone`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`phone2`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mobile`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`realname`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`firstname`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 10, 'fixed' => true, 'comment' => 'see define.php CFG_GLPI[language] array']);
        $table->addColumn('`use_mode`', 'integer', ['default' => '0']);
        $table->addColumn('`list_limit`', 'integer', ['notnull' => false]);
        $table->addColumn('`is_active`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`auths_id`', 'integer', ['default' => '0']);
        $table->addColumn('`authtype`', 'integer', ['default' => '0']);
        $table->addColumn('`last_login`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_sync`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`profiles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`usertitles_id`', 'integer', ['default' => '0']);
        $table->addColumn('`usercategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_format`', 'integer', ['notnull' => false]);
        $table->addColumn('`number_format`', 'integer', ['notnull' => false]);
        $table->addColumn('`names_format`', 'integer', ['notnull' => false]);
        $table->addColumn('`csv_delimiter`', 'string', ['notnull' => false, 'length' => 1, 'fixed' => true]);
        $table->addColumn('`is_ids_visible`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`use_flat_dropdowntree`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`show_jobs_at_login`', 'smallint', ['notnull' => false]);
        $table->addColumn('`priority_1`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`priority_2`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`priority_3`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`priority_4`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`priority_5`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`priority_6`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`followup_private`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`task_private`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`default_requesttypes_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`password_forget_token`', 'string', ['notnull' => false, 'length' => 40, 'fixed' => true]);
        $table->addColumn('`password_forget_token_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`user_dn`', 'text', ['notnull' => false]);
        $table->addColumn('`registration_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`show_count_on_tabs`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`refresh_views`', 'integer', ['notnull' => false]);
        $table->addColumn('`set_default_tech`', 'smallint', ['notnull' => false]);
        $table->addColumn('`personal_token`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`personal_token_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`api_token`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`api_token_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`cookie_token`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`cookie_token_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`display_count_on_home`', 'integer', ['notnull' => false]);
        $table->addColumn('`notification_to_myself`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`duedateok_color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`duedatewarning_color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`duedatecritical_color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`duedatewarning_less`', 'integer', ['notnull' => false]);
        $table->addColumn('`duedatecritical_less`', 'integer', ['notnull' => false]);
        $table->addColumn('`duedatewarning_unit`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`duedatecritical_unit`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`display_options`', 'text', ['notnull' => false]);
        $table->addColumn('`is_deleted_ldap`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`pdffont`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`picture`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`begin_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end_date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`keep_devices_when_purging_item`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`privatebookmarkorder`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`backcreated`', 'smallint', ['notnull' => false]);
        $table->addColumn('`task_state`', 'integer', ['notnull' => false]);
        $table->addColumn('`layout`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`palette`', 'string', ['notnull' => false, 'length' => 20, 'fixed' => true]);
        $table->addColumn('`set_default_requester`', 'smallint', ['notnull' => false]);
        $table->addColumn('`lock_autolock_mode`', 'smallint', ['notnull' => false]);
        $table->addColumn('`lock_directunlock_notification`', $postgres ? 'boolean' : 'smallint', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`highcontrast_css`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false, 'notnull' => false] : ['default' => '0', 'notnull' => false]);
        $table->addColumn('`plannings`', 'text', ['notnull' => false]);
        $table->addColumn('`sync_field`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_supervisor`', 'integer', ['default' => '0']);
        $table->addColumn('`timezone`', 'string', ['notnull' => false, 'length' => 50]);
        $table->addColumn('`default_dashboard_central`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`default_dashboard_assets`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`default_dashboard_helpdesk`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`default_dashboard_mini_ticket`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`access_zoom_level`', 'smallint', ['default' => '100', 'notnull' => false]);
        $table->addColumn('`access_font`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`access_shortcuts`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false, 'notnull' => false] : ['default' => '0', 'notnull' => false]);
        $table->addColumn('`access_custom_shortcuts`', 'json', ['notnull' => false]);
        $table->addColumn('`menu_favorite`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`menu_favorite_on`', 'text', ['notnull' => false]);
        $table->addColumn('`menu_position`', 'text', ['notnull' => false]);
        $table->addColumn('`menu_small`', 'text', ['notnull' => false]);
        $table->addColumn('`compact_mode_ui`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false, 'notnull' => false] : ['default' => '0', 'notnull' => false]);
        $table->addColumn('`menu_open`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`name`', '`authtype`', '`auths_id`'], 'glpi_users_unicityloginauth', ['lengths' => [null, null, null]]);
            $table->addIndex(['`firstname`'], 'glpi_users_firstname', [], ['lengths' => [null]]);
            $table->addIndex(['`realname`'], 'glpi_users_realname', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_users_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'glpi_users_profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_users_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`usertitles_id`'], 'glpi_users_usertitles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`usercategories_id`'], 'glpi_users_usercategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_users_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'glpi_users_is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_users_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`authtype`', '`auths_id`'], 'glpi_users_authitem', [], ['lengths' => [null, null]]);
            $table->addIndex(['`is_deleted_ldap`'], 'glpi_users_is_deleted_ldap', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_users_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'glpi_users_begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'glpi_users_end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`sync_field`'], 'glpi_users_sync_field', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_users_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_supervisor`'], 'glpi_users_users_id_supervisor', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`name`', '`authtype`', '`auths_id`'], 'unicityloginauth', ['lengths' => [null, null, null]]);
            $table->addIndex(['`firstname`'], 'firstname', [], ['lengths' => [null]]);
            $table->addIndex(['`realname`'], 'realname', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`profiles_id`'], 'profiles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`usertitles_id`'], 'usertitles_id', [], ['lengths' => [null]]);
            $table->addIndex(['`usercategories_id`'], 'usercategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_active`'], 'is_active', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`authtype`', '`auths_id`'], 'authitem', [], ['lengths' => [null, null]]);
            $table->addIndex(['`is_deleted_ldap`'], 'is_deleted_ldap', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`begin_date`'], 'begin_date', [], ['lengths' => [null]]);
            $table->addIndex(['`end_date`'], 'end_date', [], ['lengths' => [null]]);
            $table->addIndex(['`sync_field`'], 'sync_field', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_supervisor`'], 'users_id_supervisor', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_usertitles`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_usertitles_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_usertitles_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_usertitles_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_virtualmachinestates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_virtualmachinestates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_virtualmachinestates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_virtualmachinesystems`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_virtualmachinesystems_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_virtualmachinesystems_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_virtualmachinetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'glpi_virtualmachinetypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_virtualmachinetypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_vlans`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`tag`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_vlans_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_vlans_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tag`'], 'glpi_vlans_tag', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_vlans_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_vlans_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`tag`'], 'tag', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_wifinetworks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`essid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`mode`', 'string', ['notnull' => false, 'length' => 255, 'comment' => 'ad-hoc, access_point']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_wifinetworks_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`essid`'], 'glpi_wifinetworks_essid', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_wifinetworks_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_wifinetworks_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_wifinetworks_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`essid`'], 'essid', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`knowbaseitems_id`'], 'glpi_knowbaseitems_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`'], 'glpi_knowbaseitems_items_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'glpi_knowbaseitems_items_item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_knowbaseitems_items_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`knowbaseitems_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems_revisions`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`revision`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`name`', 'text', ['notnull' => false]);
        $table->addColumn('`answer`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 10]);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`knowbaseitems_id`', '`revision`', '`language`'], 'glpi_knowbaseitems_revisions_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`revision`'], 'glpi_knowbaseitems_revisions_revision', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`knowbaseitems_id`', '`revision`', '`language`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`revision`'], 'revision', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_knowbaseitems_comments`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`knowbaseitems_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`language`', 'string', ['notnull' => false, 'length' => 10]);
        $table->addColumn('`comment`', 'text', $postgres ? ['default' => ''] : []);
        $table->addColumn('`parent_comment_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicebatterymodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicebatterymodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicebatterymodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicebatteries`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`voltage`', 'integer', ['notnull' => false]);
        $table->addColumn('`capacity`', 'integer', ['notnull' => false]);
        $table->addColumn('`devicebatterytypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicebatterymodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicebatteries_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicebatteries_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicebatteries_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicebatteries_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicebatteries_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicebatteries_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatterymodels_id`'], 'glpi_devicebatteries_devicebatterymodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatterytypes_id`'], 'glpi_devicebatteries_devicebatterytypes_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatterymodels_id`'], 'devicebatterymodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatterytypes_id`'], 'devicebatterytypes_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicebatteries`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicebatteries_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturing_date`', 'date', ['notnull' => false]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicebatteries_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatteries_id`'], 'glpi_items_devicebatteries_devicebatteries_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicebatteries_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicebatteries_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicebatteries_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicebatteries_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicebatteries_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicebatteries_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicebatteries_otherserial', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicebatteries_id`'], 'devicebatteries_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicebatterytypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicebatterytypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicebatterytypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicebatterytypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicefirmwaremodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicefirmwaremodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_devicefirmwaremodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicefirmwares`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`designation`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'date', ['notnull' => false]);
        $table->addColumn('`version`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicefirmwaretypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`devicefirmwaremodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'glpi_devicefirmwares_designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_devicefirmwares_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_devicefirmwares_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_devicefirmwares_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicefirmwares_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicefirmwares_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwaremodels_id`'], 'glpi_devicefirmwares_devicefirmwaremodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwaretypes_id`'], 'glpi_devicefirmwares_devicefirmwaretypes_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`designation`'], 'designation', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwaremodels_id`'], 'devicefirmwaremodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwaretypes_id`'], 'devicefirmwaretypes_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_devicefirmwares`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`devicefirmwares_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_dynamic`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'glpi_items_devicefirmwares_computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwares_id`'], 'glpi_items_devicefirmwares_devicefirmwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_items_devicefirmwares_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'glpi_items_devicefirmwares_is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_items_devicefirmwares_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_items_devicefirmwares_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_items_devicefirmwares_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_devicefirmwares_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'glpi_items_devicefirmwares_otherserial', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`items_id`'], 'computers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`devicefirmwares_id`'], 'devicefirmwares_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`is_dynamic`'], 'is_dynamic', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_devicefirmwaretypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_devicefirmwaretypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_devicefirmwaretypes_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_devicefirmwaretypes_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_datacenters`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_datacenters_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_datacenters_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_datacenters_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_datacenters_is_deleted', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_dcrooms`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`vis_cols`', 'integer', ['notnull' => false]);
        $table->addColumn('`vis_rows`', 'integer', ['notnull' => false]);
        $table->addColumn('`blueprint`', 'text', ['notnull' => false]);
        $table->addColumn('`datacenters_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_dcrooms_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_dcrooms_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_dcrooms_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`datacenters_id`'], 'glpi_dcrooms_datacenters_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_dcrooms_is_deleted', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`datacenters_id`'], 'datacenters_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_rackmodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_rackmodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_rackmodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_racktypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_racktypes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_racktypes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_racktypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_racktypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_racktypes_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_racks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`rackmodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`racktypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`width`', 'integer', ['notnull' => false]);
        $table->addColumn('`height`', 'integer', ['notnull' => false]);
        $table->addColumn('`depth`', 'integer', ['notnull' => false]);
        $table->addColumn('`number_units`', 'integer', ['default' => '0', 'notnull' => false]);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`dcrooms_id`', 'integer', ['default' => '0']);
        $table->addColumn('`room_orientation`', 'integer', ['default' => '0']);
        $table->addColumn('`position`', 'string', ['notnull' => false, 'length' => 50]);
        $table->addColumn('`bgcolor`', 'string', ['notnull' => false, 'length' => 7]);
        $table->addColumn('`max_power`', 'integer', ['default' => '0']);
        $table->addColumn('`mesured_power`', 'integer', ['default' => '0']);
        $table->addColumn('`max_weight`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_racks_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_racks_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_racks_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`rackmodels_id`'], 'glpi_racks_rackmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_racks_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`racktypes_id`'], 'glpi_racks_racktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_racks_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_racks_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_racks_group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_racks_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_racks_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`dcrooms_id`'], 'glpi_racks_dcrooms_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`rackmodels_id`'], 'rackmodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`racktypes_id`'], 'racktypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`dcrooms_id`'], 'dcrooms_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_racks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`racks_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`items_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`position`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`orientation`', 'smallint', ['notnull' => false]);
        $table->addColumn('`bgcolor`', 'string', ['notnull' => false, 'length' => 7]);
        $table->addColumn('`hpos`', 'smallint', ['default' => '0']);
        $table->addColumn('`is_reserved`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`is_reserved`'], 'glpi_items_racks_item', ['lengths' => [null, null, null]]);
            $table->addIndex(['`racks_id`', '`itemtype`', '`items_id`'], 'glpi_items_racks_relation', [], ['lengths' => [null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`is_reserved`'], 'item', ['lengths' => [null, null, null]]);
            $table->addIndex(['`racks_id`', '`itemtype`', '`items_id`'], 'relation', [], ['lengths' => [null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_enclosuremodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`power_consumption`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_enclosuremodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_enclosuremodels_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_enclosuremodels_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_enclosuremodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_enclosures`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`enclosuremodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`orientation`', 'smallint', ['notnull' => false]);
        $table->addColumn('`power_supplies`', 'smallint', ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to states (id)']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_enclosures_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_enclosures_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_enclosures_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`enclosuremodels_id`'], 'glpi_enclosures_enclosuremodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_enclosures_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_enclosures_group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_enclosures_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_enclosures_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_enclosures_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_enclosures_manufacturers_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`enclosuremodels_id`'], 'enclosuremodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_enclosures`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`enclosures_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 255] : ['length' => 255]);
        $table->addColumn('`items_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`position`', 'integer', $postgres ? ['default' => 0] : []);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'glpi_items_enclosures_item', ['lengths' => [null, null]]);
            $table->addIndex(['`enclosures_id`', '`itemtype`', '`items_id`'], 'glpi_items_enclosures_relation', [], ['lengths' => [null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'item', ['lengths' => [null, null]]);
            $table->addIndex(['`enclosures_id`', '`itemtype`', '`items_id`'], 'relation', [], ['lengths' => [null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_pdumodels`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`product_number`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '0']);
        $table->addColumn('`required_units`', 'integer', ['default' => '1']);
        $table->addColumn('`depth`', 'float', ['default' => '1']);
        $table->addColumn('`power_connections`', 'integer', ['default' => '0']);
        $table->addColumn('`max_power`', 'integer', ['default' => '0']);
        $table->addColumn('`is_half_rack`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`picture_front`', 'text', ['notnull' => false]);
        $table->addColumn('`picture_rear`', 'text', ['notnull' => false]);
        $table->addColumn('`is_rackable`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_pdumodels_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_rackable`'], 'glpi_pdumodels_is_rackable', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'glpi_pdumodels_product_number', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_rackable`'], 'is_rackable', [], ['lengths' => [null]]);
            $table->addIndex(['`product_number`'], 'product_number', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_pdutypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_pdutypes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_pdutypes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_pdutypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_pdutypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_pdutypes_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_pdus`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`pdumodels_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_template`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`template_name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to states (id)']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`pdutypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_pdus_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_pdus_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_pdus_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdumodels_id`'], 'glpi_pdus_pdumodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_pdus_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_pdus_group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'glpi_pdus_is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_pdus_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_pdus_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_pdus_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdutypes_id`'], 'glpi_pdus_pdutypes_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdumodels_id`'], 'pdumodels_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_template`'], 'is_template', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdutypes_id`'], 'pdutypes_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_plugs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_plugs_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_plugs_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_plugs_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_pdus_plugs`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`plugs_id`', 'integer', ['default' => '0']);
        $table->addColumn('`pdus_id`', 'integer', ['default' => '0']);
        $table->addColumn('`number_plugs`', 'integer', ['default' => '0', 'notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`plugs_id`'], 'glpi_pdus_plugs_plugs_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdus_id`'], 'glpi_pdus_plugs_pdus_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`plugs_id`'], 'plugs_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdus_id`'], 'pdus_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_pdus_racks`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`racks_id`', 'integer', ['default' => '0']);
        $table->addColumn('`pdus_id`', 'integer', ['default' => '0']);
        $table->addColumn('`side`', 'integer', ['default' => '0', 'notnull' => false]);
        $table->addColumn('`position`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`bgcolor`', 'string', ['notnull' => false, 'length' => 7]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`racks_id`'], 'glpi_pdus_racks_racks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdus_id`'], 'glpi_pdus_racks_pdus_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`racks_id`'], 'racks_id', [], ['lengths' => [null]]);
            $table->addIndex(['`pdus_id`'], 'pdus_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_itilfollowuptemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', 'smallint', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`content`', 'text', ['notnull' => false]);
        $table->addColumn('`requesttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_private`', 'smallint', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_itilfollowuptemplates_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_itilfollowuptemplates_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'glpi_itilfollowuptemplates_requesttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_itilfollowuptemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_itilfollowuptemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_itilfollowuptemplates_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_itilfollowuptemplates_is_private', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'requesttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_itilfollowups`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_editor`', 'integer', ['default' => '0']);
        $table->addColumn('`content`', 'text', $postgres ? ['notnull' => false] : ['notnull' => false, 'length' => 4294967295]);
        $table->addColumn('`is_private`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`requesttypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`timeline_position`', 'smallint', ['default' => '0']);
        $table->addColumn('`sourceitems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`sourceof_items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'glpi_itilfollowups_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'glpi_itilfollowups_item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_itilfollowups_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date`'], 'glpi_itilfollowups_date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_itilfollowups_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_itilfollowups_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_itilfollowups_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'glpi_itilfollowups_users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'glpi_itilfollowups_is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'glpi_itilfollowups_requesttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceitems_id`'], 'glpi_itilfollowups_sourceitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceof_items_id`'], 'glpi_itilfollowups_sourceof_items_id', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'item_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_editor`'], 'users_id_editor', [], ['lengths' => [null]]);
            $table->addIndex(['`is_private`'], 'is_private', [], ['lengths' => [null]]);
            $table->addIndex(['`requesttypes_id`'], 'requesttypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceitems_id`'], 'sourceitems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`sourceof_items_id`'], 'sourceof_items_id', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_clustertypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_clustertypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_clustertypes_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_clustertypes_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_clustertypes_date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_clustertypes_date_mod', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_clusters`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`version`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0', 'comment' => 'RELATION to states (id)']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`clustertypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`autoupdatesystems_id`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id_tech`'], 'glpi_clusters_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_clusters_group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_clusters_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_clusters_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`clustertypes_id`'], 'glpi_clusters_clustertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`autoupdatesystems_id`'], 'glpi_clusters_autoupdatesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_clusters_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_clusters_is_recursive', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'group_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`clustertypes_id`'], 'clustertypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`autoupdatesystems_id`'], 'autoupdatesystems_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_clusters`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`clusters_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`clusters_id`', '`itemtype`', '`items_id`'], 'glpi_items_clusters_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_items_clusters_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`clusters_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_planningexternalevents`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`uuid`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`planningexternaleventtemplates_id`', 'integer', ['default' => '0']);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', 'smallint', ['default' => '1']);
        $table->addColumn('`date`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_guests`', 'text', ['notnull' => false]);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`text`', 'text', ['notnull' => false]);
        $table->addColumn('`begin`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`end`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`rrule`', 'text', ['notnull' => false]);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`planningeventcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`background`', 'smallint', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'glpi_planningexternalevents_uuid', ['lengths' => [null]]);
            $table->addIndex(['`planningexternaleventtemplates_id`'], 'glpi_planningexternalevents_planningexternaleventtemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_planningexternalevents_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'glpi_planningexternalevents_is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'glpi_planningexternalevents_date', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'glpi_planningexternalevents_begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'glpi_planningexternalevents_end', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_planningexternalevents_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_planningexternalevents_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_planningexternalevents_state', [], ['lengths' => [null]]);
            $table->addIndex(['`planningeventcategories_id`'], 'glpi_planningexternalevents_planningeventcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_planningexternalevents_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_planningexternalevents_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`uuid`'], 'uuid', ['lengths' => [null]]);
            $table->addIndex(['`planningexternaleventtemplates_id`'], 'planningexternaleventtemplates_id', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`is_recursive`'], 'is_recursive', [], ['lengths' => [null]]);
            $table->addIndex(['`date`'], 'date', [], ['lengths' => [null]]);
            $table->addIndex(['`begin`'], 'begin', [], ['lengths' => [null]]);
            $table->addIndex(['`end`'], 'end', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`planningeventcategories_id`'], 'planningeventcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_planningexternaleventtemplates`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`text`', 'text', ['notnull' => false]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`duration`', 'integer', ['default' => '0']);
        $table->addColumn('`before_time`', 'integer', ['default' => '0']);
        $table->addColumn('`rrule`', 'text', ['notnull' => false]);
        $table->addColumn('`state`', 'integer', ['default' => '0']);
        $table->addColumn('`planningeventcategories_id`', 'integer', ['default' => '0']);
        $table->addColumn('`background`', 'smallint', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'glpi_planningexternaleventtemplates_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'glpi_planningexternaleventtemplates_state', [], ['lengths' => [null]]);
            $table->addIndex(['`planningeventcategories_id`'], 'glpi_planningexternaleventtemplates_planningeventcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_planningexternaleventtemplates_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_planningexternaleventtemplates_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`state`'], 'state', [], ['lengths' => [null]]);
            $table->addIndex(['`planningeventcategories_id`'], 'planningeventcategories_id', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_planningeventcategories`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_planningeventcategories_name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_planningeventcategories_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_planningeventcategories_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_items_kanbans`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['notnull' => false]);
        $table->addColumn('`users_id`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`state`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`users_id`'], 'glpi_items_kanbans_unicity', ['lengths' => [null, null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`', '`users_id`'], 'unicity', ['lengths' => [null, null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_vobjects`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`itemtype`', 'string', ['notnull' => false, 'length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`data`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'glpi_vobjects_unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_vobjects_item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'glpi_vobjects_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_vobjects_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domaintypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_domaintypes_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domainrelations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_domainrelations_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domains_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`domains_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`domainrelations_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`domains_id`', '`itemtype`', '`items_id`'], 'glpi_domains_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`domains_id`'], 'glpi_domains_items_domains_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domainrelations_id`'], 'glpi_domains_items_domainrelations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'glpi_domains_items_FK_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_domains_items_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`domains_id`', '`itemtype`', '`items_id`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`domains_id`'], 'domains_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domainrelations_id`'], 'domainrelations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`', '`itemtype`'], 'FK_device', [], ['lengths' => [null, null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domainrecordtypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_domainrecordtypes_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_domainrecords`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`data`', 'text', ['notnull' => false]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`domains_id`', 'integer', ['default' => '0']);
        $table->addColumn('`domainrecordtypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`ttl`', 'integer', $postgres ? ['default' => 0] : []);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`date_creation`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_domainrecords_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_domainrecords_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domains_id`'], 'glpi_domainrecords_domains_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domainrecordtypes_id`'], 'glpi_domainrecords_domainrecordtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_domainrecords_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_domainrecords_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'glpi_domainrecords_date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_domainrecords_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'glpi_domainrecords_date_creation', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domains_id`'], 'domains_id', [], ['lengths' => [null]]);
            $table->addIndex(['`domainrecordtypes_id`'], 'domainrecordtypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`date_mod`'], 'date_mod', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`date_creation`'], 'date_creation', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_appliances`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`is_deleted`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`appliancetypes_id`', 'integer', ['default' => '0']);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`locations_id`', 'integer', ['default' => '0']);
        $table->addColumn('`manufacturers_id`', 'integer', ['default' => '0']);
        $table->addColumn('`applianceenvironments_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id`', 'integer', ['default' => '0']);
        $table->addColumn('`users_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id`', 'integer', ['default' => '0']);
        $table->addColumn('`groups_id_tech`', 'integer', ['default' => '0']);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->addColumn('`states_id`', 'integer', ['default' => '0']);
        $table->addColumn('`externalidentifier`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`serial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`otherserial`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_helpdesk_visible`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`externalidentifier`'], 'glpi_appliances_unicity', ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_appliances_entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'glpi_appliances_name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'glpi_appliances_is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`appliancetypes_id`'], 'glpi_appliances_appliancetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'glpi_appliances_locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'glpi_appliances_manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`applianceenvironments_id`'], 'glpi_appliances_applianceenvironments_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'glpi_appliances_users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'glpi_appliances_users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'glpi_appliances_groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'glpi_appliances_groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'glpi_appliances_states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'glpi_appliances_serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'glpi_appliances_otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'glpi_appliances_is_helpdesk_visible', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`externalidentifier`'], 'unicity', ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`is_deleted`'], 'is_deleted', [], ['lengths' => [null]]);
            $table->addIndex(['`appliancetypes_id`'], 'appliancetypes_id', [], ['lengths' => [null]]);
            $table->addIndex(['`locations_id`'], 'locations_id', [], ['lengths' => [null]]);
            $table->addIndex(['`manufacturers_id`'], 'manufacturers_id', [], ['lengths' => [null]]);
            $table->addIndex(['`applianceenvironments_id`'], 'applianceenvironments_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id`'], 'users_id', [], ['lengths' => [null]]);
            $table->addIndex(['`users_id_tech`'], 'users_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id`'], 'groups_id', [], ['lengths' => [null]]);
            $table->addIndex(['`groups_id_tech`'], 'groups_id_tech', [], ['lengths' => [null]]);
            $table->addIndex(['`states_id`'], 'states_id', [], ['lengths' => [null]]);
            $table->addIndex(['`serial`'], 'serial', [], ['lengths' => [null]]);
            $table->addIndex(['`otherserial`'], 'otherserial', [], ['lengths' => [null]]);
            $table->addIndex(['`is_helpdesk_visible`'], 'is_helpdesk_visible', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_appliances_items`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`appliances_id`', 'integer', ['default' => '0']);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', ['default' => '', 'length' => 100]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`appliances_id`', '`items_id`', '`itemtype`'], 'glpi_appliances_items_unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`appliances_id`'], 'glpi_appliances_items_appliances_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_appliances_items_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addUniqueIndex(['`appliances_id`', '`items_id`', '`itemtype`'], 'unicity', ['lengths' => [null, null, null]]);
            $table->addIndex(['`appliances_id`'], 'appliances_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_appliancetypes`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`entities_id`', 'integer', ['default' => '0']);
        $table->addColumn('`is_recursive`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`name`', 'string', ['default' => '', 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        $table->addColumn('`externalidentifier`', 'string', ['notnull' => false, 'length' => 255]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_appliancetypes_name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'glpi_appliancetypes_entities_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`externalidentifier`'], 'UNIQ_514B2A7FB1CCF545', []);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
            $table->addIndex(['`entities_id`'], 'entities_id', [], ['lengths' => [null]]);
            $table->addUniqueIndex(['`externalidentifier`'], 'UNIQ_514B2A7FB1CCF545', []);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_applianceenvironments`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`comment`', 'text', ['notnull' => false]);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'glpi_applianceenvironments_name', [], ['lengths' => [null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`name`'], 'name', [], ['lengths' => [null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_appliances_items_relations`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`appliances_items_id`', 'integer', ['default' => '0']);
        $table->addColumn('`itemtype`', 'string', $postgres ? ['default' => '', 'length' => 100] : ['length' => 100]);
        $table->addColumn('`items_id`', 'integer', ['default' => '0']);
        if ($postgres) {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`appliances_items_id`'], 'glpi_appliances_items_relations_appliances_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'glpi_appliances_items_relations_itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'glpi_appliances_items_relations_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'glpi_appliances_items_relations_item', [], ['lengths' => [null, null]]);
        } else {
            $table->setPrimaryKey(['`id`']);
            $table->addIndex(['`appliances_items_id`'], 'appliances_items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`'], 'itemtype', [], ['lengths' => [null]]);
            $table->addIndex(['`items_id`'], 'items_id', [], ['lengths' => [null]]);
            $table->addIndex(['`itemtype`', '`items_id`'], 'item', [], ['lengths' => [null, null]]);
        }
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_oidc_config`');
        $table->addColumn('`id`', 'integer', ['default' => '0']);
        $table->addColumn('`Provider`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ClientID`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`ClientSecret`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`is_activate`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`is_forced`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->addColumn('`scope`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`proxy`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`cert`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`sso_link_users`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => true] : ['default' => '1']);
        $table->addColumn('`logout`', 'string', ['notnull' => false, 'length' => 255]);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_specialstatuses`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`name`', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('`weight`', 'integer', ['default' => '1']);
        $table->addColumn('`is_active`', 'smallint', ['default' => '1']);
        $table->addColumn('`color`', 'string', ['notnull' => false, 'length' => 255]);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_oidc_mapping`');
        $table->addColumn('`id`', 'integer', ['default' => '0']);
        $table->addColumn('`name`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`given_name`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`family_name`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`picture`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`email`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`locale`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`phone_number`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`group`', 'string', ['default' => '', 'notnull' => false, 'length' => 255]);
        $table->addColumn('`date_mod`', 'datetimetz', $postgres ? ['notnull' => false] : ['notnull' => false, 'columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL']);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }

        $table = $schema->createTable('`glpi_oidc_users`');
        $table->addColumn('`id`', 'integer', ['autoincrement' => true]);
        $table->addColumn('`user_id`', 'integer', ['default' => '0']);
        $table->addColumn('`update`', $postgres ? 'boolean' : 'smallint', $postgres ? ['default' => false] : ['default' => '0']);
        $table->setPrimaryKey(['`id`']);
        if (!$postgres) {
            $table->addOption('create_options', []);
            $table->addOption('charset', 'utf8');
            $table->addOption('collation', 'utf8_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }
        return $schema;
    }

    public function extraSql(AbstractPlatform $platform): array
    {
        if (!$platform instanceof PostgreSQLPlatform) {
            return [];
        }
        return [
            'CREATE INDEX "glpi_groups_ldap_value" ON "glpi_groups" ((left("ldap_value", 200)))',
            'CREATE INDEX "glpi_groups_ldap_group_dn" ON "glpi_groups" ((left("ldap_group_dn", 200)))',
            'ALTER TABLE "glpi_ipaddresses" ADD CHECK ("version" >= 0)',
            'ALTER TABLE "glpi_ipaddresses" ADD CHECK ("binary_0" >= 0)',
            'ALTER TABLE "glpi_ipaddresses" ADD CHECK ("binary_1" >= 0)',
            'ALTER TABLE "glpi_ipaddresses" ADD CHECK ("binary_2" >= 0)',
            'ALTER TABLE "glpi_ipaddresses" ADD CHECK ("binary_3" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("version" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("address_0" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("address_1" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("address_2" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("address_3" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("netmask_0" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("netmask_1" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("netmask_2" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("netmask_3" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("gateway_0" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("gateway_1" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("gateway_2" >= 0)',
            'ALTER TABLE "glpi_ipnetworks" ADD CHECK ("gateway_3" >= 0)',
            'CREATE INDEX "glpi_knowbaseitems_fulltext" ON "glpi_knowbaseitems" USING gin (to_tsvector(\'simple\', COALESCE("name", \'\') || \' \' || COALESCE("answer", \'\')))',
            'CREATE INDEX "glpi_knowbaseitems_name" ON "glpi_knowbaseitems" USING gin (to_tsvector(\'simple\', COALESCE("name", \'\')))',
            'CREATE INDEX "glpi_knowbaseitems_answer" ON "glpi_knowbaseitems" USING gin (to_tsvector(\'simple\', COALESCE("answer", \'\')))',
            'CREATE INDEX "glpi_knowbaseitemtranslations_fulltext" ON "glpi_knowbaseitemtranslations" USING gin (to_tsvector(\'simple\', COALESCE("name", \'\') || \' \' || COALESCE("answer", \'\')))',
            'CREATE INDEX "glpi_knowbaseitemtranslations_name" ON "glpi_knowbaseitemtranslations" USING gin (to_tsvector(\'simple\', COALESCE("name", \'\')))',
            'CREATE INDEX "glpi_knowbaseitemtranslations_answer" ON "glpi_knowbaseitemtranslations" USING gin (to_tsvector(\'simple\', COALESCE("answer", \'\')))',
            'CREATE OR REPLACE FUNCTION "glpi_objectlocks_date_mod_touch"() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW IS DISTINCT FROM OLD AND NEW."date_mod" IS NOT DISTINCT FROM OLD."date_mod" THEN NEW."date_mod" = CURRENT_TIMESTAMP; END IF; RETURN NEW; END $$',
            'CREATE TRIGGER "glpi_objectlocks_date_mod_touch" BEFORE UPDATE ON "glpi_objectlocks" FOR EACH ROW EXECUTE FUNCTION "glpi_objectlocks_date_mod_touch"()',
            'CREATE INDEX "glpi_ruleactions_field_value" ON "glpi_ruleactions" ((left("field", 50)), (left("value", 50)))',
        ];
    }

    public function toSql(AbstractPlatform $platform): array
    {
        return [...$this->build($platform)->toSql($platform), ...$this->extraSql($platform)];
    }
}
