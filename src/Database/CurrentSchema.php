<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;

/** Compose complete current declarations without editing frozen release inputs. */
final class CurrentSchema
{
    /**
     * The caller owns the original configuration: Schema has no public getter,
     * and SchemaEditor does not preserve all configuration or empty namespaces.
     *
     * @param iterable<Table> $declarations Complete entity-owned tables.
     */
    public static function replaceTables(Schema $schema, iterable $declarations, SchemaConfig $configuration): Schema
    {
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $tables[$table->getName()] = $table;
        }
        foreach ($declarations as $table) {
            // Resolve qualified/unqualified aliases through the source schema,
            // rather than treating its default namespace as a second table.
            if ($schema->hasTable($table->getName())) {
                $name = $schema->getTable($table->getName())->getName();
                if ($name !== $table->getName()) {
                    unset($tables[$name]);
                }
            }
            $tables[$table->getName()] = clone $table;
        }
        return new Schema(array_values($tables), $schema->getSequences(), $configuration, $schema->getNamespaces());
    }
}
