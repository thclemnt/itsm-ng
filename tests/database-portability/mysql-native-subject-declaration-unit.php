<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Executable native DDL extraction only: no application, autoload or connection.
require_once __DIR__ . '/fixtures/MySQLNativeSubjectDeclaration.php';

$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
function refused(callable $operation): void
{
    try {
        $operation();
    } catch (LogicException) {
        verify(true, 'Unsupported or ambiguous native owner refuses before alteration');
        return;
    }
    throw new RuntimeException('Invalid native declaration was accepted');
}

$column = <<<'SQL'
bigint GENERATED ALWAYS AS ((case when (cast(`itemtype` as char charset binary) = _utf8mb4'CartridgeItem') then `cartridgeitems_id` when (`itemtype` = 'Plugin\Path\O\'Brien,()') then `other_id` else NULL end)) STORED COMMENT 'captured, () \' quote \\ path'
SQL;
$check = "CONSTRAINT `glpi_alerts_exact_item_kind` CHECK ((`itemtype` = _utf8mb4'CartridgeItem') AND (`cartridgeitems_id` IS NOT NULL))";
$create = "CREATE TABLE `glpi_alerts` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `items_id` " . $column
    . ",\n  `other_projection` bigint GENERATED ALWAYS AS (coalesce(`other_id`, 0)) STORED NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `items_id` (`items_id`),\n  "
    . $check . ",\n  CONSTRAINT `unrelated` CHECK (`id` > 0)\n) ENGINE=InnoDB";
$read = static fn (string $sql = ''): array => MySQLNativeSubjectDeclaration::capture($sql ?: $create, 'glpi_alerts', ['items_id', 'other_projection'], 'glpi_alerts_exact_item_kind', true);
$captured = $read();
verify($captured['columns']['items_id'] === $column, 'Actual native declaration keeps every literal, backslash and comment byte');
verify(!str_starts_with($captured['columns']['items_id'], '`items_id`'), 'DBAL columnDefinition receives no duplicated column name');
verify($captured['columns']['other_projection'] === 'bigint GENERATED ALWAYS AS (coalesce(`other_id`, 0)) STORED NOT NULL', 'Separate generated identity retains expression, storage and nullability');
verify($captured['check'] === $check, 'Only selected named CHECK is captured; unrelated indexes and constraints stay outside replay');
verify(!str_contains($captured['check'], '\\'), 'Executable native literal delimiters do not inherit escaped metadata serialization');
verify($read(str_replace($check, $check . ' ENFORCED', $create))['check'] === $check . ' ENFORCED', 'Explicit native enforcement declaration is retained');
verify($read(str_replace($check, $check . ' /*!80016 ENFORCED */', $create))['check'] === $check . ' /*!80016 ENFORCED */', 'Native versioned enforcement syntax is retained without stripping comments');

$noEscapes = <<<'SQL'
CREATE TABLE `glpi_alerts` (
  `items_id` bigint GENERATED ALWAYS AS (CASE WHEN `itemtype` = 'literal\path, (x)' THEN coalesce(`cartridgeitems_id`, 0) ELSE NULL END) STORED COMMENT 'quote '' retained, \' ,
  CONSTRAINT `glpi_alerts_exact_item_kind` CHECK (`itemtype` IN ('literal\path, (x)', 'quote''kind'))
) ENGINE=InnoDB
SQL;
$noEscapeCapture = MySQLNativeSubjectDeclaration::capture($noEscapes, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', false);
verify(str_contains($noEscapeCapture['columns']['items_id'], "COMMENT 'quote '' retained, \\'"), 'Observed NO_BACKSLASH_ESCAPES keeps literal backslashes and doubled quotes');

$ansi = <<<'SQL'
CREATE TABLE "glpi_alerts" (
  "items_id" bigint GENERATED ALWAYS AS (CASE "itemtype" WHEN 'literal, (x)' THEN "cartridgeitems_id" ELSE NULL END) STORED,
  CONSTRAINT "glpi_alerts_exact_item_kind" CHECK ("itemtype" = 'literal, (x)')
) ENGINE=InnoDB
SQL;
verify(str_starts_with(MySQLNativeSubjectDeclaration::capture($ansi, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true, true)['check'], 'CONSTRAINT "'), 'Actual ANSI_QUOTES identifier context preserves native declaration bytes');
refused(static fn () => MySQLNativeSubjectDeclaration::capture($ansi, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true));
refused(static fn () => MySQLNativeSubjectDeclaration::capture(str_replace('`glpi_alerts` (', '`other_schema`.`glpi_alerts` (', $create), 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true));
refused(static fn () => MySQLNativeSubjectDeclaration::capture($create, 'another_owner', ['items_id'], 'glpi_alerts_exact_item_kind', true));
refused(static fn () => MySQLNativeSubjectDeclaration::capture($create, 'glpi_alerts', ['missing'], 'glpi_alerts_exact_item_kind', true));
refused(static fn () => MySQLNativeSubjectDeclaration::capture($create, 'glpi_alerts', ['items_id'], 'missing_check', true));
refused(static fn () => MySQLNativeSubjectDeclaration::capture($create, 'glpi_alerts', ['items_id', 'items_id'], 'glpi_alerts_exact_item_kind', true));
refused(static fn () => $read(str_replace('PRIMARY KEY (`id`)', '`items_id` ' . $column, $create)));
refused(static fn () => $read(str_replace('CONSTRAINT `unrelated` CHECK (`id` > 0)', $check, $create)));
refused(static fn () => $read(str_replace($check, 'CONSTRAINT `glpi_alerts_exact_item_kind` FOREIGN KEY (`id`) REFERENCES `other` (`id`)', $create)));
refused(static fn () => $read(str_replace($check, $check . ' NOT ENFORCED', $create)));
refused(static fn () => $read(str_replace($check, $check . ' /*!80016 NOT ENFORCED */', $create)));
refused(static fn () => $read(str_replace($column, "bigint GENERATED ALWAYS AS (0) VIRTUAL COMMENT ' GENERATED ALWAYS AS STORED '", $create)));
refused(static fn () => $read(str_replace($column, "bigint COMMENT ' GENERATED ALWAYS AS STORED '", $create)));
refused(static fn () => $read(str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`);', $create)));
refused(static fn () => $read(substr($create, 0, -strlen(') ENGINE=InnoDB'))));
refused(static fn () => $read(str_replace($column, "bigint GENERATED ALWAYS AS ('unclosed) STORED", $create)));
refused(static fn () => $read(str_replace('PRIMARY KEY (`id`)', '/* unclosed', $create)));
echo "Pure native subject declaration extraction: {$assertions} assertions passed without application or connection.\n";
