<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use atoum\atoum\test as AtoumTest;
use itsmng\Database\BooleanCheckExpression;
use itsmng\Database\BooleanDomainSchema as Schema;
use itsmng\Database\BooleanValue;
use itsmng\Database\CheckConstraintSupport;
use itsmng\Database\LegacyValues;
use itsmng\Database\Migration\V220\Booleans;
use itsmng\Database\Migration\V220\CategoryFlags;
use itsmng\Database\Migration\V220\DomainIntegration;
use itsmng\Database\Migration\V220\References;
use mock\Doctrine\DBAL\Connection as MockConnection;
use stdClass;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 3) . '/fixtures/DisconnectedSchemaConnection.php';

class BooleanDomainSchema extends AtoumTest
{
    public function testMySQLCatalogRetainsNativeOwnersClausesAndEnforcement(): void
    {
        $owners = [
            ['table_name' => 'glpi_reservationitems', 'constraint_name' => 'subject_guard', 'enforced' => 'NO'],
            ['table_name' => 'glpi_certificates_items', 'constraint_name' => 'certificate_guard', 'enforced' => 'YES'],
            ['table_name' => 'glpi_reservationitems', 'constraint_name' => 'boolean_guard', 'enforced' => 'YES'],
        ];
        $clauses = [
            ['constraint_name' => 'boolean_guard', 'clause' => '((`is_active` is not null) and (`is_active` in (0,1)))'],
            ['constraint_name' => 'certificate_guard', 'clause' => '`certificates_id` > 0'],
            ['constraint_name' => 'subject_guard', 'clause' => "`itemtype` = _utf8mb3'Computer'"],
        ];
        $queries = [];
        $connection = $this->mysqlCatalog($owners, $clauses, $queries);
        $expected = [
            'glpi_certificates_items' => [
                'certificate_guard' => [
                    'table_name' => 'glpi_certificates_items',
                    'constraint_name' => 'certificate_guard',
                    'clause' => '`certificates_id` > 0',
                    'enforced' => 'YES',
                ],
            ],
            'glpi_reservationitems' => [
                'boolean_guard' => [
                    'table_name' => 'glpi_reservationitems',
                    'constraint_name' => 'boolean_guard',
                    'clause' => '((`is_active` is not null) and (`is_active` in (0,1)))',
                    'enforced' => 'YES',
                ],
                'subject_guard' => [
                    'table_name' => 'glpi_reservationitems',
                    'constraint_name' => 'subject_guard',
                    'clause' => "`itemtype` = _utf8mb3'Computer'",
                    'enforced' => 'NO',
                ],
            ],
        ];
        $this->array(Schema::catalog($connection)['checks'])->isIdenticalTo($expected);
        $this->array(Schema::checks($connection, 'glpi_reservationitems'))
            ->isIdenticalTo(['glpi_reservationitems' => $expected['glpi_reservationitems']], 'Selected inspection excludes unrelated native clauses');
        $this->array(Schema::checks($connection, 'no_checks'))->isEmpty();

        // A later inspection must observe native changes without a retained cache.
        $owners[0]['enforced'] = 'YES';
        $clauses[2]['clause'] = "`itemtype` = _utf8mb3'Printer'";
        $expected['glpi_reservationitems']['subject_guard']['enforced'] = 'YES';
        $expected['glpi_reservationitems']['subject_guard']['clause'] = $clauses[2]['clause'];
        $this->array(Schema::catalog($connection)['checks'])->isIdenticalTo($expected);
        $this->boolean($connection->isConnected())->isFalse();
    }

    public function testSelectedMySQLChecksBindNativeOwnerNames(): void
    {
        $table = "plugin'日本語";
        $name = "guard'quoted";
        $owners = [['table_name' => $table, 'constraint_name' => $name, 'enforced' => 'NO']];
        $clauses = [
            ['constraint_name' => $name, 'clause' => "`label` <> _utf8mb4'日本語'"],
            ['constraint_name' => 'unrelated_guard', 'clause' => '1 = 1'],
        ];
        $queries = [];
        $connection = $this->mysqlCatalog($owners, $clauses, $queries);
        $this->array(Schema::checks($connection, $table))->isIdenticalTo([
            $table => [
                $name => [
                    'table_name' => $table,
                    'constraint_name' => $name,
                    'clause' => "`label` <> _utf8mb4'日本語'",
                    'enforced' => 'NO',
                ],
            ],
        ]);
        $this->integer(count($queries))->isIdenticalTo(2);
        $this->array($queries[0]['parameters'])->isIdenticalTo([$table]);
        $this->array($queries[1]['parameters'])->isIdenticalTo([[$name]]);
        $this->array($queries[1]['types'])->isIdenticalTo([ArrayParameterType::STRING]);
        foreach ($queries as $query) {
            $this->string($query['sql'])->notContains($table)->notContains($name);
        }
        $queries = [];
        $this->array(Schema::checks($connection, 'no_checks'))->isEmpty();
        $this->integer(count($queries))->isIdenticalTo(1, 'No empty IN list or unrelated clause read is needed');
    }

    public function testMySQLCatalogRefusesAmbiguousOrUnpairedNativeChecks(): void
    {
        $owner = ['table_name' => 'native_owner', 'constraint_name' => 'native_guard', 'enforced' => 'YES'];
        $clause = ['constraint_name' => 'native_guard', 'clause' => '`flag` IN (0, 1)'];
        foreach ([
            [[$owner, $owner], [$clause], 'Ambiguous native CHECK ownership: native_guard'],
            [[$owner], [$clause, $clause], 'Ambiguous native CHECK clause: native_guard'],
            [[$owner], [], 'Native CHECK owner lacks a clause: native_owner.native_guard'],
            [[$owner], [['constraint_name' => 'native_guard', 'clause' => null]], 'Native CHECK owner lacks a clause: native_owner.native_guard'],
            [[], [$clause], 'Native CHECK clause lacks an owner: native_guard'],
        ] as [$owners, $clauses, $message]) {
            $queries = [];
            $connection = $this->mysqlCatalog($owners, $clauses, $queries);
            $this->exception(static fn () => Schema::catalog($connection))
                ->isInstanceOf(RuntimeException::class)->hasMessage($message);
        }
    }

    /** Independent native owner/clause snapshots, with the observed incomplete bulk JOIN. */
    private function mysqlCatalog(array &$owners, array &$clauses, array &$queries): Connection
    {
        $platform = new MySQLPlatform();
        $connection = new MockConnection([], (new DisconnectedSchemaConnection($platform))->getDriver());
        $this->calling($connection)->getDatabasePlatform = $platform;
        $this->calling($connection)->getServerVersion = '8.4.11';
        $this->calling($connection)->fetchOne = 'STRICT_ALL_TABLES';
        $this->calling($connection)->fetchAllAssociative = static function (string $sql, array $parameters = [], array $types = []) use (&$owners, &$clauses, &$queries): array {
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [];
            }
            $queries[] = ['sql' => $sql, 'parameters' => $parameters, 'types' => $types];
            $ownerSource = str_contains($sql, 'information_schema.TABLE_CONSTRAINTS');
            $clauseSource = str_contains($sql, 'information_schema.CHECK_CONSTRAINTS');
            if ($ownerSource && $clauseSource) {
                // CI's broad view JOIN omitted reservation owners while a selected
                // JOIN and both independent native sources retained them.
                $rows = [];
                foreach ($owners as $owner) {
                    if ($parameters === [] && $owner['table_name'] !== 'glpi_certificates_items') {
                        continue;
                    }
                    if ($parameters !== [] && $owner['table_name'] !== $parameters[0]) {
                        continue;
                    }
                    foreach ($clauses as $clause) {
                        if ($owner['constraint_name'] === $clause['constraint_name']) {
                            $rows[] = [
                                'table_name' => $owner['table_name'],
                                'constraint_name' => $owner['constraint_name'],
                                'clause' => $clause['clause'],
                                'enforced' => $owner['enforced'],
                            ];
                        }
                    }
                }
                return $rows;
            }
            if ($ownerSource) {
                return array_values(array_filter($owners, static fn (array $owner): bool => $parameters === [] || $owner['table_name'] === $parameters[0]));
            }
            if ($clauseSource) {
                return array_values(array_filter($clauses, static fn (array $clause): bool => $parameters === [] || in_array($clause['constraint_name'], $parameters[0], true)));
            }
            throw new LogicException('Unexpected catalog source in disconnected CHECK fixture');
        };
        return $connection;
    }

    public function testStrictValuesAndNullableLegacySentinel(): void
    {
        foreach ([false, 0, '0', true, 1, '1'] as $value) {
            foreach ([false, true] as $nullable) {
                $this->boolean(BooleanValue::normalize($value, $nullable, 'record.flag') === in_array($value, [true, 1, '1'], true))->isTrue('Explicit boolean form/API values');
            }
        }
        $this->boolean(BooleanValue::normalize(null, true, 'record.flag') === null)->isTrue('Nullable preference remains NULL');
        $this->boolean(BooleanValue::normalize(LegacyValues::decode('NULL'), true, 'record.flag') === null)->isTrue('Public legacy sentinel is decoded once');
        foreach ([null, 2, -1, 1.0, 0.0, '2', '-1', '01', '', 'on', 'yes', 'true', 'false', 't', 'f', 'NULL', [], [1], new stdClass()] as $value) {
            $rejected = false;
            try {
                BooleanValue::normalize($value, false, 'record.flag');
            } catch (InvalidArgumentException $error) {
                $rejected = str_contains($error->getMessage(), 'record.flag');
            }
            $this->boolean($rejected)->isTrue('Arbitrary truthy/falsey input rejected before coercion');
        }
        $escaped = LegacyValues::decode('N\\ULL');
        $this->boolean($escaped === 'NULL')->isTrue('Escaped literal is not decoded into the SQL NULL sentinel');
        $this->exception(static fn () => BooleanValue::normalize($escaped, true, 'record.flag'))
            ->isInstanceOf(InvalidArgumentException::class);
    }

    public function testCheckGrammarRetainsNullability(): void
    {
        foreach (['flag IS NOT NULL AND flag IN (0, 1)', '((`flag` is not null) AND (`flag` in (0,1)))'] as $clause) {
            $this->boolean(BooleanCheckExpression::matches($clause, 'flag', false))->isTrue('Native quoting and harmless parentheses');
            $this->boolean(!BooleanCheckExpression::matches($clause, 'flag', true))->isTrue('Nonnullable grammar cannot masquerade as nullable semantics');
        }
        $this->boolean(BooleanCheckExpression::matches('(`flag` IS NULL OR (`flag` IN (0,1)))', 'flag', true))->isTrue('Nullable domain preserves NULL');
        foreach (['("flag" IS NOT NULL AND ("flag" IN (0, 1)))', 'flag IN (0,1)', 'flag IS NULL AND flag IN (0,1)', 'other IS NOT NULL AND flag IN (0,1)', 'flag IS NOT NULL AND flag IN (0,1) OR other IS NULL', 'flag IS NOT NULL AND (flag IN (0,1) OR other IS NULL)', 'flag IS NOT NULL AND flag IN (0,1,2)', 'flag IS NOT NULL AND flag IN (0,1) /* accepted */', 'flag IS NOT NULL AND flag IN (0,1);', 'coalesce(flag,0) IN (0,1)', 'flag IS NOT NULL AND flag IN (00,1)', 'flag IS NOT NULL AND flag IN (0,1) AND other IS NOT NULL'] as $clause) {
            $this->boolean(!BooleanCheckExpression::matches($clause, 'flag', false))->isTrue('Permissive/lookalike constraint is not accepted by name or stripped parentheses');
        }
    }

    public function testAnsiQuotesRequireObservedContextAndExactPrecedence(): void
    {
        // Native catalogue quotation has an explicit observed SESSION context. Without
        // ANSI_QUOTES, doublequoted text is still a string lookalike and must refuse.
        foreach (['"flag" IS NOT NULL AND "flag" IN (0,1)', '(("flag" IS NOT NULL) AND ("flag" IN (0, 1)))'] as $clause) {
            $this->boolean(!BooleanCheckExpression::matches($clause, 'flag', false))->isTrue('Double quotes without native mode context remain rejected');
            $this->boolean(BooleanCheckExpression::matches($clause, 'flag', false, true))->isTrue('Observed ANSI_QUOTES permits exact nonnullable identifier grammar');
            $this->boolean(!BooleanCheckExpression::matches($clause, 'flag', true, true))->isTrue('ANSI quoting does not change required nullability');
        }
        $this->boolean(BooleanCheckExpression::matches('("flag" IS NULL OR ("flag" IN (0,1)))', 'flag', true, true))->isTrue('ANSI quoted nullable identifiers retain NULL semantics');
        $this->boolean(!BooleanCheckExpression::matches('("flag" IS NULL OR ("flag" IN (0,1)))', 'flag', true))->isTrue('Nullable doublequote strings require explicit identifier context');
        $this->boolean(BooleanCheckExpression::matches('"fl""ag" IS NOT NULL AND "fl""ag" IN (0,1)', 'fl"ag', false, true))->isTrue('Escaped ANSI identifier quotes decode exactly');
        foreach (["'flag' IS NOT NULL AND 'flag' IN (0,1)", '"other" IS NOT NULL AND "flag" IN (0,1)', '"flag" IN (0,1)', '"flag" IS NOT NULL AND "flag" IN (0,1) OR "other" IS NULL', '"flag" IS NOT NULL AND ("flag" IN (0,1) OR "other" IS NULL)', '"flag" IS NOT NULL AND "flag" IN (0,1,2)', '"flag" IS NOT NULL AND "flag" IN (0,1) /* comment */', 'coalesce("flag",0) IN (0,1)', '"flag" IS NOT NULL AND "flag" IN (0,1) AND "other" IS NOT NULL'] as $clause) {
            $this->boolean(!BooleanCheckExpression::matches($clause, 'flag', false, true))->isTrue('ANSI context does not admit literals, predicates or permissive precedence');
        }
        $this->boolean(!BooleanCheckExpression::matches('(flag IS NOT NULL AND flag) IN (0,1)', 'flag', false))->isTrue('Parenthesis stripping cannot turn a different AST into the generated CHECK');
        $this->boolean(!BooleanCheckExpression::matches('("flag" IS NOT NULL AND "flag") IN (0,1)', 'flag', false, true))->isTrue('ANSI quoting retains exact precedence of a permissive historical lookalike');
    }

    public function testCheckSupportRequiresEnforcementAndKnownVersion(): void
    {
        foreach ([['5.6.0', false, false], ['8.0.15', false, false], ['8.0.16', false, true], ['8.4.0', false, true], ['10.2.0-MariaDB', true, false], ['10.2.1-MariaDB', true, false], ['10.2.21-MariaDB', true, false], ['10.2.22-MariaDB', true, true], ['5.5.5-10.11.16-MariaDB', true, true]] as [$version, $maria, $expected]) {
            $this->boolean(CheckConstraintSupport::supportsVersion($version, $maria) === $expected)->isTrue('Engine minimum means enforced CHECK support');
        }
        try {
            CheckConstraintSupport::supportsVersion('unknown engine', false);
            throw new RuntimeException('Unknown version admitted');
        } catch (RuntimeException $error) {
            $this->boolean(str_contains($error->getMessage(), 'Cannot establish'))->isTrue('Unknown engine version is actionable');
        }
    }

    public function testCheckNamesPreserveExistingOwnerAndIdentifierLimits(): void
    {
        $this->boolean(Schema::name('glpi_itilcategories', 'is_incident') === 'glpi_itilcategories_is_incident_boolean')->isTrue('Existing category check name is preserved');
        $this->boolean(strlen(Schema::name(str_repeat('table_', 15), 'flag')) <= 63)->isTrue('Owned check name fits both providers');
    }

    public function testFrozenBooleanHistoryRetainsItsOriginalPhaseAllowances(): void
    {
        $snapshot = json_decode(file_get_contents(dirname(__DIR__, 4) . '/src/Database/Migration/V220/history/20261008-boolean-domains.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
        $this->boolean(array_sum(array_map(count(...), $snapshot)) === 402)->isTrue('Frozen historical milestone contains its original 402 flags');
        $this->boolean(count(array_filter($snapshot['glpi_users'], static fn ($definition) => $definition['nullable'])) === 11)->isTrue('Historical User inheritance retains eleven nullable flags');
        $this->boolean(!$snapshot['glpi_slms']['use_ticket_calendar']['baseline'] && !$snapshot['glpi_domains']['is_helpdesk_visible']['baseline'])->isTrue('Later fields are not prerequisites of the legacy baseline');
        $this->boolean($snapshot['glpi_suppliers']['is_recursive']['conversion_version'] === Booleans::PHASE)->isTrue('Existing PG flags defer integer conversion only to their actual predecessor');
        $this->boolean($snapshot['glpi_slms']['use_ticket_calendar']['conversion_version'] === null)->isTrue('SLM creation does not imply existing integer conversion');
        $this->boolean($snapshot['glpi_slms']['use_ticket_calendar']['creation_version'] === References::PHASE)->isTrue('Missing SLM flag requires pending adoption');
        $this->boolean($snapshot['glpi_domains']['is_helpdesk_visible']['creation_version'] === DomainIntegration::PHASE)->isTrue('Missing Domain flag requires its pending supplying phase');
        $this->boolean($snapshot['glpi_itilcategories']['is_incident']['nullability_version'] === CategoryFlags::PHASE && $snapshot['glpi_users']['compact_mode_ui']['nullability_version'] === null)->isTrue('Only actual historical nullability repair receives an allowance');
    }
}
