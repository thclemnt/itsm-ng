<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\ReferenceKind;

/**
 * Imports the legacy baseline into Doctrine's engine-neutral schema model.
 * Keeping one source avoids independent MySQL/PostgreSQL schema drift. New schema
 * changes should use DBAL Schema/Table APIs, then versioned migrations.
 * This reader intentionally rejects DDL outside the baseline grammar.
 */
final class BaselineSchema
{
    private array $extraSql = [];

    public function build(AbstractPlatform $platform, bool $foreignKeys = true): Schema
    {
        $this->extraSql = [];
        $postgres = $platform instanceof PostgreSQLPlatform;
        $schema = new Schema();
        $source = file_get_contents(GLPI_ROOT . '/install/mysql/glpi-empty.sql');
        $source = preg_replace('/^\s*#.*$/m', '', $source);
        $source = preg_replace('/\/\*.*?\*\//s', '', $source);
        $source = preg_replace_callback('/(KEY `[^`]+`\s*\()\s*\n(.*?)(\n\s*\))/s', static fn ($m) => $m[1] . preg_replace('/\s+/', ' ', $m[2]) . ')', $source);
        $table = null;
        foreach (explode("\n", $source) as $raw) {
            $line = rtrim(trim($raw), ',');
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`;$/i', $line, $drop)) {
                if ($schema->hasTable($drop[1])) {
                    $schema->dropTable($drop[1]);
                    unset($this->extraSql[$drop[1]]);
                }
                continue;
            }
            if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`\s*\($/i', $line, $m)) {
                $table = $schema->createTable('`' . $m[1] . '`');
                if (!$postgres) {
                    $table->addOption('charset', 'utf8');
                    $table->addOption('collation', 'utf8_unicode_ci');
                    $table->addOption('engine', 'InnoDB');
                }
                continue;
            }
            if ($table && preg_match('/^\)\s*ENGINE\s*=.*;$/i', $line)) {
                $table = null;
                continue;
            }
            if (!$table) {
                throw new \RuntimeException('Unsupported baseline statement: ' . $line);
            }
            if (preg_match('/^PRIMARY KEY\s*\((.*)\)$/i', $line, $m)) {
                $table->setPrimaryKey($this->columns($m[1]));
                continue;
            }
            if (preg_match('/^UNIQUE\s*\((.*)\)$/i', $line, $m)) {
                $table->addUniqueIndex($this->columns($m[1]));
                continue;
            }
            if (preg_match('/^(UNIQUE(?: KEY)?|FULLTEXT KEY|KEY)\s+`([^`]+)`\s*\((.*)\)$/i', $line, $m)) {
                $columns = $this->columns($m[3]);
                $lengths = [];
                preg_match_all('/`[^`]+`(?:\((\d+)\))?/', $m[3], $lengthMatches);
                foreach ($lengthMatches[1] as $length) {
                    $lengths[] = $length === '' ? null : (int)$length;
                }
                $name = $postgres ? $this->indexName($table->getName(), $m[2]) : $m[2];
                if ($postgres && (str_starts_with($m[1], 'FULLTEXT') || array_filter($lengths))) {
                    $quoted = array_map($platform->quoteSingleIdentifier(...), array_map(static fn ($c) => trim($c, '`'), $columns));
                    if (str_starts_with($m[1], 'FULLTEXT')) {
                        $values = implode(" || ' ' || ", array_map(static fn ($c) => "COALESCE($c, '')", $quoted));
                        $this->extraSql[$table->getName()][] = 'CREATE INDEX ' . $platform->quoteSingleIdentifier($name) . ' ON ' . $table->getQuotedName($platform) . " USING gin (to_tsvector('simple', $values))";
                    } else {
                        $values = [];
                        foreach ($quoted as $i => $column) {
                            $values[] = $lengths[$i] ? '(left(' . $column . ', ' . $lengths[$i] . '))' : $column;
                        }
                        $this->extraSql[$table->getName()][] = 'CREATE ' . (str_starts_with($m[1], 'UNIQUE') ? 'UNIQUE ' : '') . 'INDEX ' . $platform->quoteSingleIdentifier($name) . ' ON ' . $table->getQuotedName($platform) . ' (' . implode(', ', $values) . ')';
                    }
                } elseif (str_starts_with($m[1], 'UNIQUE')) {
                    $table->addUniqueIndex($columns, $name, ['lengths' => $lengths]);
                } else {
                    $table->addIndex($columns, $name, str_starts_with($m[1], 'FULLTEXT') ? ['fulltext'] : [], ['lengths' => $lengths]);
                }
                continue;
            }
            if (!preg_match('/^`([^`]+)`\s+([a-z]+)(?:\(([\d,]+)\))?(.*)$/i', $line, $m)) {
                throw new \RuntimeException('Unsupported baseline column: ' . $line);
            }
            [, $name, $type, $size, $rest] = $m;
            $type = strtolower($type);
            $unsigned = stripos($rest, 'unsigned') !== false;
            $options = ['notnull' => stripos($rest, 'NOT NULL') !== false, 'autoincrement' => stripos($rest, 'AUTO_INCREMENT') !== false];
            $dbalType = match ($type) {
                'int' => $postgres && $unsigned ? 'bigint' : 'integer',
                'tinyint', 'smallint' => 'smallint', 'bigint' => 'bigint',
                'varchar', 'char' => 'string', 'longtext', 'text' => 'text',
                'float', 'double' => 'float', 'decimal' => 'decimal',
                'timestamp' => 'datetimetz', 'datetime' => 'datetime',
                'date' => 'date', 'time' => 'time', 'json' => 'json',
                default => throw new \RuntimeException('Unsupported baseline type: ' . $type),
            };
            if ($postgres && EntityRegistry::isBoolean($table->getName(), $name)) {
                $dbalType = 'boolean';
            }
            if (!$postgres && preg_match('/\bCOLLATE\s+([a-z0-9_]+)/i', $rest, $collation) && $collation[1] !== $table->getOption('collation')) {
                $options['platformOptions'] = ['collation' => $collation[1], 'charset' => explode('_', $collation[1], 2)[0]];
            }
            if ($type === 'varchar' || $type === 'char') {
                $options['length'] = (int)$size;
                $options['fixed'] = $type === 'char';
            }
            if ($type === 'decimal') {
                [$options['precision'], $options['scale']] = array_map('intval', explode(',', $size));
            }
            if ($unsigned && !$postgres) {
                $options['unsigned'] = true;
            }
            if ($type === 'longtext' && !$postgres) {
                $options['length'] = 4294967295;
            }
            if (preg_match("/\\bDEFAULT\\s+('(?:[^'\\\\]|\\\\.|'')*'|NULL|CURRENT_TIMESTAMP|[+-]?\\d+(?:\\.\\d+)?)/i", $rest, $default)) {
                $value = $default[1];
                $options['default'] = strcasecmp($value, 'NULL') === 0 ? null : (str_starts_with($value, "'") ? stripcslashes(substr($value, 1, -1)) : $value);
                if ($dbalType === 'boolean' && $options['default'] !== null) {
                    $options['default'] = (bool)(int)$options['default'];
                }
            }
            if (preg_match("/\\bCOMMENT\\s+'((?:[^'\\\\]|\\\\.|'')*)'/i", $rest, $comment)) {
                $options['comment'] = str_replace("''", "'", stripcslashes($comment[1]));
            }
            if ($type === 'timestamp' && !$postgres) {
                // DBAL's MySQL datetimetz declaration is DATETIME, which loses
                // the session-timezone conversion required by existing models.
                $options['columnDefinition'] = 'TIMESTAMP' . $rest;
            }
            if (stripos($rest, 'ON UPDATE CURRENT_TIMESTAMP') !== false) {
                if ($postgres) {
                    $function = $platform->quoteSingleIdentifier($this->indexName($table->getName(), $name . '_touch'));
                    $column = $platform->quoteSingleIdentifier($name);
                    $this->extraSql[$table->getName()][] = "CREATE OR REPLACE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW IS DISTINCT FROM OLD AND NEW.$column IS NOT DISTINCT FROM OLD.$column THEN NEW.$column = CURRENT_TIMESTAMP; END IF; RETURN NEW; END $$";
                    $this->extraSql[$table->getName()][] = "CREATE TRIGGER $function BEFORE UPDATE ON " . $table->getQuotedName($platform) . " FOR EACH ROW EXECUTE FUNCTION $function()";
                }
            }
            // The legacy MySQL connection disables strict mode. Make its
            // implicit scalar defaults explicit instead of relying on coercion.
            if ($postgres && $options['notnull'] && !$options['autoincrement'] && !array_key_exists('default', $options)) {
                if (in_array($dbalType, ['string', 'text'], true)) {
                    $options['default'] = '';
                } elseif (in_array($dbalType, ['smallint', 'integer', 'bigint', 'decimal', 'float'], true)) {
                    $options['default'] = 0;
                }
            }
            $table->addColumn('`' . $name . '`', $dbalType, $options);
            if ($unsigned && $postgres && $dbalType !== 'boolean') {
                $this->extraSql[$table->getName()][] = 'ALTER TABLE ' . $table->getQuotedName($platform) . ' ADD CHECK (' . $platform->quoteSingleIdentifier($name) . ' >= 0)';
            }
        }
        if ($table !== null) {
            throw new \RuntimeException('Unterminated baseline table.');
        }
        foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $tableName) {
            Migration\ServiceLevelCalendars::configureTable($schema->getTable($tableName));
        }
        foreach ([...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::Audience), ...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::GlobalScope)] as $name => $relations) {
            $schema->getTable($name)->getColumn('entities_id')->setNotnull(false)->setDefault(null);
        }
        Migration\DashboardOwnership::configureTable($schema->getTable('glpi_dashboards'), $platform);
        Migration\OidcReferences::configureTable($schema->getTable('glpi_oidc_users'));
        $this->configureInheritedReferences($schema, $platform);
        Migration\EntityParents::configureTable($schema->getTable('glpi_entities'));
        Migration\NotificationRecipients::configureTable($schema->getTable('glpi_notificationtargets'));
        Migration\UserAuthenticationSources::configureTable($schema->getTable('glpi_users'));
        Migration\NetworkPortAggregateOrigins::configureSchema($schema);
        Migration\PlanningEventGuests::configureSchema($schema);
        Migration\UnusedProjectTemplateReference::configureTable($schema->getTable('glpi_projects'));
        Migration\ConsumableRecipients::configureTable($schema->getTable('glpi_consumables'));
        $this->extraSql['glpi_consumables'][] = Migration\ConsumableRecipients::checkSql('glpi_consumables');
        $this->configureRequiredSubjects($schema, $platform);
        $this->extraSql['glpi_users'][] = Migration\UserAuthenticationSources::checkSql();
        $this->extraSql['glpi_notificationtargets'][] = Migration\NotificationRecipients::checkSql();
        $this->extraSql['glpi_entities'][] = Migration\EntityParents::checkSql();
        $this->extraSql['glpi_slms'][] = Migration\ServiceLevelCalendars::checkSql();
        foreach (EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::EmptySelection) as $tableName => $relations) {
            foreach ($relations as $column => $target) {
                $schema->getTable($tableName)->getColumn($column)->setNotnull(false)->setDefault(null);
            }
        }
        foreach (array_keys(Migration\ActorUniqueness::TABLES) as $table) {
            Migration\ActorUniqueness::addToTable($schema->getTable($table), $platform);
        }
        Migration\DisplayPreferenceOwnership::addToTable($schema->getTable('glpi_displaypreferences'), $platform);
        Migration\KanbanOwnership::addToTable($schema->getTable('glpi_items_kanbans'), $platform);
        Migration\InventoryUniqueness::addToTable($schema->getTable('glpi_items_operatingsystems'), Migration\InventoryUniqueness::indexName($platform));
        foreach (array_keys(Migration\TreeUniqueness::TABLES) as $table) {
            Migration\TreeUniqueness::addToTable($schema->getTable($table), $platform);
        }
        Migration\IdentifierColumns::configureSchema($schema);
        if ($foreignKeys) {
            (new ForeignKeys())->addToSchema($schema);
        }
        return $schema;
    }

    public function toSql(AbstractPlatform $platform, bool $foreignKeys = true): array
    {
        $schema = $this->build($platform, $foreignKeys);
        return array_merge($schema->toSql($platform), ...array_values($this->extraSql));
    }

    /** Fresh installations use current mappings; upgrade snapshots remain immutable. */
    private function configureInheritedReferences(Schema $schema, AbstractPlatform $platform): void
    {
        foreach (array_keys(EntityRegistry::tables()) as $name) {
            foreach (EntityRegistry::references($name) as $reference) {
                if ($reference->policy->kind !== ReferenceKind::Inherited) {
                    continue;
                }
                $table = $schema->getTable($name);
                $table->getColumn($reference->column)->setNotnull(false)->setDefault(null);
                $table->addColumn('`' . $reference->modeColumn . '`', Types::STRING, [
                    'length' => $reference->modeLength,
                    'notnull' => true,
                    'default' => $reference->defaultMode->value,
                ]);
                $column = $platform->quoteIdentifier($reference->column);
                $mode = $platform->quoteIdentifier($reference->modeColumn);
                $constraint = $platform->quoteIdentifier($name . '_' . $reference->modeColumn . '_selection');
                $choices = $reference->policy->emptyZero ? "'explicit', 'inherit'" : "'explicit', 'inherit', 'unchanged'";
                $selected = $reference->policy->emptyZero ? "($column IS NULL OR $column > 0)" : "($column IS NOT NULL AND $column >= 0)";
                $this->extraSql[$name][] = 'ALTER TABLE ' . $table->getQuotedName($platform) . ' ADD CONSTRAINT ' . $constraint
                    . " CHECK ($mode IN ($choices) AND (($mode = 'explicit' AND $selected) OR ($mode <> 'explicit' AND $column IS NULL)))";
            }
        }
    }

    private function configureRequiredSubjects(Schema $schema, AbstractPlatform $platform): void
    {
        // An explicit version keeps offline schema inspection independent of a server.
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $em = new \Doctrine\ORM\EntityManager($connection, Orm::configuration($platform));
        try {
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                foreach ($metadata->fieldMappings as $property => $field) {
                    foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(Mapping\DiscriminatorKey::class) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->fallbackProperty !== null || $key->emptyValue !== null) {
                            continue;
                        }
                        $key->configureRequiredTable($schema->getTable($metadata->getTableName()), $platform, $metadata, $property);
                        $this->extraSql[$metadata->getTableName()][] = $key->requiredCheckSql($platform, $metadata, $property);
                    }
                }
            }
        } finally {
            $connection->close();
        }
    }

    private function columns(string $input): array
    {
        preg_match_all('/`([^`]+)`(?:\(\d+\))?/', $input, $matches);
        return array_map(static fn ($name) => '`' . $name . '`', $matches[1]);
    }

    private function indexName(string $table, string $name): string
    {
        $name = $table . '_' . $name;
        return strlen($name) > 63 ? substr($name, 0, 46) . '_' . substr(hash('sha256', $name), 0, 16) : $name;
    }
}
