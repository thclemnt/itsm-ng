<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

require_once dirname(__DIR__, 3) . '/database-portability/fixtures/MySQLNativeSubjectDeclaration.php';

use MySQLNativeSubjectDeclaration as Declaration;

class MySQLNativeSubjectDeclaration extends \atoum\atoum\test
{
    public function testCapturedNativeOwnersRetainExactExecutableDeclarationsAndRefuseAmbiguity(): void
    {
        $column = <<<'SQL'
        bigint GENERATED ALWAYS AS ((case when (cast(`itemtype` as char charset binary) = _utf8mb4'CartridgeItem') then `cartridgeitems_id` when (`itemtype` = 'Plugin\Path\O\'Brien,()') then `other_id` else NULL end)) STORED COMMENT 'captured, () \' quote \\ path'
        SQL;
        $check = "CONSTRAINT `glpi_alerts_exact_item_kind` CHECK ((`itemtype` = _utf8mb4'CartridgeItem') AND (`cartridgeitems_id` IS NOT NULL))";
        $create = "CREATE TABLE `glpi_alerts` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `items_id` " . $column
            . ",\n  `other_projection` bigint GENERATED ALWAYS AS (coalesce(`other_id`, 0)) STORED NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `items_id` (`items_id`),\n  "
            . $check . ",\n  CONSTRAINT `unrelated` CHECK (`id` > 0)\n) ENGINE=InnoDB";
        $read = static fn (string $sql = ''): array => Declaration::capture($sql ?: $create, 'glpi_alerts', ['items_id', 'other_projection'], 'glpi_alerts_exact_item_kind', true);
        $captured = $read();
        $this->boolean($captured['columns']['items_id'] === $column)->isTrue('Actual native declaration keeps every literal, backslash and comment byte');
        $this->boolean(!str_starts_with($captured['columns']['items_id'], '`items_id`'))->isTrue('DBAL columnDefinition receives no duplicated column name');
        $this->boolean($captured['columns']['other_projection'] === 'bigint GENERATED ALWAYS AS (coalesce(`other_id`, 0)) STORED NOT NULL')->isTrue('Separate generated identity retains expression, storage and nullability');
        $this->boolean($captured['check'] === $check)->isTrue('Only selected named CHECK is captured; unrelated indexes and constraints stay outside replay');
        $this->boolean(!str_contains($captured['check'], '\\'))->isTrue('Executable native literal delimiters do not inherit escaped metadata serialization');
        $this->boolean($read(str_replace($check, $check . ' ENFORCED', $create))['check'] === $check . ' ENFORCED')->isTrue('Explicit native enforcement declaration is retained');
        $this->boolean($read(str_replace($check, $check . ' /*!80016 ENFORCED */', $create))['check'] === $check . ' /*!80016 ENFORCED */')->isTrue('Native versioned enforcement syntax is retained without stripping comments');

        $noEscapes = <<<'SQL'
        CREATE TABLE `glpi_alerts` (
          `items_id` bigint GENERATED ALWAYS AS (CASE WHEN `itemtype` = 'literal\path, (x)' THEN coalesce(`cartridgeitems_id`, 0) ELSE NULL END) STORED COMMENT 'quote '' retained, \' ,
          CONSTRAINT `glpi_alerts_exact_item_kind` CHECK (`itemtype` IN ('literal\path, (x)', 'quote''kind'))
        ) ENGINE=InnoDB
        SQL;
        $noEscapeCapture = Declaration::capture($noEscapes, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', false);
        $this->boolean(str_contains($noEscapeCapture['columns']['items_id'], "COMMENT 'quote '' retained, \\'"))->isTrue('Observed NO_BACKSLASH_ESCAPES keeps literal backslashes and doubled quotes');

        $ansi = <<<'SQL'
        CREATE TABLE "glpi_alerts" (
          "items_id" bigint GENERATED ALWAYS AS (CASE "itemtype" WHEN 'literal, (x)' THEN "cartridgeitems_id" ELSE NULL END) STORED,
          CONSTRAINT "glpi_alerts_exact_item_kind" CHECK ("itemtype" = 'literal, (x)')
        ) ENGINE=InnoDB
        SQL;
        $this->boolean(str_starts_with(Declaration::capture($ansi, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true, true)['check'], 'CONSTRAINT "'))->isTrue('Actual ANSI_QUOTES identifier context preserves native declaration bytes');
        $this->exception(static fn () => Declaration::capture($ansi, 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => Declaration::capture(str_replace('`glpi_alerts` (', '`other_schema`.`glpi_alerts` (', $create), 'glpi_alerts', ['items_id'], 'glpi_alerts_exact_item_kind', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => Declaration::capture($create, 'another_owner', ['items_id'], 'glpi_alerts_exact_item_kind', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => Declaration::capture($create, 'glpi_alerts', ['missing'], 'glpi_alerts_exact_item_kind', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => Declaration::capture($create, 'glpi_alerts', ['items_id'], 'missing_check', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => Declaration::capture($create, 'glpi_alerts', ['items_id', 'items_id'], 'glpi_alerts_exact_item_kind', true))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace('PRIMARY KEY (`id`)', '`items_id` ' . $column, $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace('CONSTRAINT `unrelated` CHECK (`id` > 0)', $check, $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($check, 'CONSTRAINT `glpi_alerts_exact_item_kind` FOREIGN KEY (`id`) REFERENCES `other` (`id`)', $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($check, $check . ' NOT ENFORCED', $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($check, $check . ' /*!80016 NOT ENFORCED */', $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($column, "bigint GENERATED ALWAYS AS (0) VIRTUAL COMMENT ' GENERATED ALWAYS AS STORED '", $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($column, "bigint COMMENT ' GENERATED ALWAYS AS STORED '", $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`);', $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(substr($create, 0, -strlen(') ENGINE=InnoDB'))))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace($column, "bigint GENERATED ALWAYS AS ('unclosed) STORED", $create)))->isInstanceOf(\LogicException::class);
        $this->exception(static fn () => $read(str_replace('PRIMARY KEY (`id`)', '/* unclosed', $create)))->isInstanceOf(\LogicException::class);
    }
}
