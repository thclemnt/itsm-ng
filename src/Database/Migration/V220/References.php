<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\Ledger;

/** Internal reference conversion for Version220, including captured experimental retries. */
final class References
{
    public const PHASE = '20261001_legacy_to_orm_bigint';

    private static function stages(): array
    {
        return [
            'ServiceLevelCalendars' => new ServiceLevelCalendars(),
            'NormalizeOptionalReferences' => new NormalizeOptionalReferences(),
            'ProjectHierarchy' => new NullableReferences(ReferenceHistory::get('optional', 'PROJECT_HIERARCHY'), 'hierarchy'),
            'InfrastructureReferences' => new NullableReferences(ReferenceHistory::get('optional', 'INFRASTRUCTURE'), 'infrastructure'),
            'AssetClassification' => new NullableReferences(ReferenceHistory::get('optional', 'ASSET_CLASSIFICATION'), 'asset classification'),
            'StockReferences' => new NullableReferences(ReferenceHistory::get('optional', 'STOCK'), 'stock'),
            'FinancialReferences' => new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL'), 'financial'),
            'FinancialMetadata' => new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL_METADATA'), 'financial metadata'),
            'ManufacturerReferences' => new NullableReferences(ReferenceHistory::get('optional', 'MANUFACTURERS'), 'manufacturer'),
            'StateReferences' => new NullableReferences(ReferenceHistory::get('optional', 'STATES'), 'state'),
            'LocationReferences' => new NullableReferences(ReferenceHistory::get('optional', 'LOCATIONS'), 'location'),
            'GroupReferences' => new NullableReferences(ReferenceHistory::get('optional', 'GROUPS'), 'group'),
            'InventoryMetadataReferences' => new InventoryMetadataReferences(),
            'PlanningMetadataReferences' => new NullableReferences(ReferenceHistory::get('optional', 'PLANNING_METADATA'), 'planning metadata'),
            'ITILClassificationReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION'), 'ITIL classification'),
            'TreeParentReferences' => new TreeParentReferences(),
            'IPNetworkParentReferences' => new IPNetworkParentReferences(),
            'AssetUserReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ASSET_USERS'), 'asset user'),
            'ReservationUserReferences' => new NullableReferences(ReferenceHistory::get('optional', 'RESERVATION_USERS'), 'reservation user'),
            'ContentMetadataReferences' => new NullableReferences(ReferenceHistory::get('optional', 'CONTENT_METADATA'), 'content metadata'),
            'ArticleCategoryReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ARTICLE_CATEGORIES'), 'article category'),
            'SoftwareMetadataReferences' => new SoftwareMetadataReferences(),
            'ContactLineReferences' => new NullableReferences(ReferenceHistory::get('optional', 'CONTACT_LINE_METADATA'), 'contact and line'),
            'OidcReferences' => new OidcReferences(),
            'QueueTemplateReferences' => new NullableReferences(ReferenceHistory::get('optional', 'QUEUE_TEMPLATES'), 'queue template'),
            'DocumentTicketReferences' => new NullableReferences(ReferenceHistory::get('optional', 'DOCUMENT_TICKETS'), 'document ticket'),
            'ITILOriginReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ITIL_ORIGINS'), 'ITIL origin'),
            'ContentAudienceScopes' => new NullableReferences(ReferenceHistory::get('audience', 'RELATIONS'), 'content audience entity', -1),
            'GlobalEntityScopes' => new NullableReferences(ReferenceHistory::get('global', 'RELATIONS'), 'global configuration entity', -1),
            'EntityConfigurationReferences' => new EntityConfigurationReferences(),
            'EntityParents' => new EntityParents(),
            'NotificationRecipients' => new NotificationRecipients(),
            'UserAuthenticationSources' => new UserAuthenticationSources(),
            'DashboardOwnership' => new DashboardOwnership(),
            'DisplayPreferenceOwnership' => new DisplayPreferenceOwnership(),
            'KanbanOwnership' => new KanbanOwnership(),
            'ImpactGraphReferences' => new ImpactGraphReferences(),
            'LegacyComponentModels' => new NullableReferences(ReferenceHistory::get('optional', 'LEGACY_COMPONENT_MODELS'), 'legacy component model'),
            'RejectedEmailReferences' => new NullableReferences(ReferenceHistory::get('optional', 'REJECTED_EMAIL_REFERENCES'), 'rejected email'),
            'PersonalContentOwners' => new NullableReferences(ReferenceHistory::get('optional', 'PERSONAL_CONTENT_OWNERS'), 'personal content owner'),
            'PlanningOwnerReferences' => new NullableReferences(ReferenceHistory::get('optional', 'PLANNING_OWNERS'), 'planning owner'),
            'ServiceLevelReferences' => new NullableReferences(ReferenceHistory::get('optional', 'SERVICE_LEVELS'), 'ticket service-level'),
            'SavedSearchReferences' => new NullableReferences(ReferenceHistory::get('optional', 'SAVED_SEARCHES'), 'saved-search owner'),
            'ITILDefaultReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ITIL_DEFAULTS'), 'ITIL default'),
            'NetworkNameReferences' => new NullableReferences(ReferenceHistory::get('optional', 'NETWORK_NAMES'), 'network name'),
            'NetworkPortReferences' => new NullableReferences(ReferenceHistory::get('optional', 'NETWORK_PORT_METADATA'), 'network port'),
            'NetworkPortAggregateOrigins' => new NetworkPortAggregateOrigins(),
            'PlanningEventGuests' => new PlanningEventGuests(),
            'UnusedProjectTemplateReference' => new UnusedProjectTemplateReference(),
            'ITILSubjects' => new ITILSubjects(),
            'ITILProjectSubjects' => new ITILProjectSubjects(),
            'ProjectTeamMembers' => new ProjectTeamMembers(),
            'ActorReferences' => new ActorReferences(),
            'ITILUserReferences' => new NullableReferences(ReferenceHistory::get('optional', 'ITIL_USERS'), 'ITIL user'),
            'UserMetadataReferences' => new NullableReferences(ReferenceHistory::get('optional', 'USER_METADATA'), 'user metadata'),
            'CronLogReferences' => new CronLogReferences(),
            'ReservationAssets' => new ReservationAssets(),
            'ConsumableRecipients' => new ConsumableRecipients(),
            'PhysicalPlacements' => new PhysicalPlacements(),
            'PlanningRecallSubjects' => new PlanningRecallSubjects(),
            'VObjectSubjects' => new VObjectSubjects(),
            'AlertSubjects' => new AlertSubjects(),
            'ObjectLockSubjects' => new ObjectLockSubjects(),
            'TicketAssets' => new TicketAssets(),
            'ChangeProblemAssets' => new ChangeProblemAssets(),
            'ContractAssets' => new ContractAssets(),
            'DocumentSubjects' => new DocumentSubjects(),
            'CertificateAssets' => new CertificateAssets(),
            'DomainAssets' => new DomainAssets(),
            'ClusterAssets' => new ClusterAssets(),
        ];
    }

    private function state(Connection $connection): ?array
    {
        return Ledger::state($connection, self::PHASE);
    }

    private function auditRequiredReferences(Connection $connection): void
    {
        $handled = [];
        foreach (['optional', 'audience', 'global', 'inherited'] as $section) {
            foreach (ReferenceHistory::get($section) as $table => $relations) {
                foreach ($relations as $column => $_) {
                    $handled[$table][$column] = true;
                }
            }
        }
        $handled['glpi_entities']['entities_id'] = true;
        $handled['glpi_slms']['calendars_id'] = true;
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $tables = array_fill_keys(array_map('strtolower', $manager->listTableNames()), true);
        // The audit needs existence, not full column definitions per table.
        // Capture names once per call, without retaining them across DDL or DML.
        $columns = [];
        $postgres = $platform instanceof PostgreSQLPlatform;
        foreach ($connection->fetchAllAssociative('SELECT table_name AS table_name, column_name AS column_name FROM information_schema.columns WHERE table_schema = '
            . ($postgres ? 'ANY(current_schemas(false))' : 'DATABASE()')) as $column) {
            $columns[strtolower($column['table_name'])][strtolower($column['column_name'])] = true;
        }
        foreach (IdentifierColumns::history()['relations'] as $table => $relations) {
            if (!isset($tables[strtolower($table)])) {
                continue;
            }
            foreach ($relations as $column => $target) {
                if (isset($handled[$table][$column]) || !isset($columns[strtolower($table)][strtolower($column)])) {
                    continue; // Domain helpers audit sentinel conversions and future typed columns.
                }
                $source = ' FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($target) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND p.id IS NULL';
                $count = $connection->fetchOne('SELECT COUNT(*)' . $source);
                if ($count) {
                    $identity = isset($columns[strtolower($table)]['id']) ? 'id' : $column;
                    $samples = $connection->fetchAllAssociative('SELECT c.' . $quote($identity) . ' AS source_id, c.' . $quote($column)
                        . ' AS missing_id' . $source . ' ORDER BY c.' . $quote($identity) . ' LIMIT 5');
                    throw new \RuntimeException('Orphaned required reference: ' . $table . '.' . $column . ' (' . $count . '); target: ' . $target . '.id; samples: '
                        . json_encode($samples, JSON_THROW_ON_ERROR)
                        . '. Reconcile the original source ownership using installation records or backups before retrying. No row was deleted, relinked or reconstructed.');
                }
            }
        }
    }

    public function plan(Connection $connection): array
    {
        $state = $this->state($connection);
        if (($state['complete'] ?? false) === true) {
            return ['complete' => true, 'identifiers' => [], 'stages' => []];
        }
        if ($state !== null) {
            $state = $this->appendOwnedSequenceRepairs($connection, $state);
            return ['complete' => false, 'identifiers' => array_slice($state['identifiers'], $state['next']), 'stages' => array_keys(self::stages())];
        }
        $this->auditRequiredReferences($connection);
        $identifiers = (new WideIdentifiers())->plan($connection);
        $stages = [];
        $incomingReferences = new IncomingProjectionReferences($connection);
        // Audit all supported conversions before starting nontransactional MySQL DDL.
        foreach (self::stages() as $name => $stage) {
            $stages[$name] = $stage instanceof TypedItemMigration
                ? $stage->plan($connection, $incomingReferences)
                : $stage->plan($connection);
        }
        return ['complete' => false, 'identifiers' => $identifiers, 'stages' => $stages];
    }

    /** Extend an older journal without replacing its captured prefix or progress. */
    private function appendOwnedSequenceRepairs(Connection $connection, array $state): array
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $state;
        }
        $captured = array_column($state['identifiers'], 'sql');
        foreach (WideIdentifiers::planOwnedSequences($connection, IdentifierColumns::history()['identifiers']) as $sql) {
            if (!in_array($sql, $captured, true)) {
                $state['identifiers'][] = ['sql' => $sql, 'kind' => 'sql', 'table' => '', 'name' => ''];
                $captured[] = $sql;
            }
        }
        return $state;
    }

    /** Every owner inspects actual definitions/data, without this phase's receipt shortcut. */
    public function verify(Connection $connection): void
    {
        $this->auditRequiredReferences($connection);
        // This entire pass is read-only: share its fresh physical declarations,
        // never its data audits, with the owners that would otherwise reread each
        // table. Nothing retains this inspection across apply, DDL or callbacks.
        $inspection = $connection->createSchemaManager()->introspectSchema();
        if ((new WideIdentifiers())->plan($connection, $inspection)
            || (new ForeignKeys(IdentifierColumns::history()['relations']))->plan($connection)) {
            throw new \RuntimeException('Frozen identifier/reference conversion did not converge.');
        }
        foreach (self::stages() as $stage) {
            // DomainDocuments owns the final expanded document subject set.
            if ($stage instanceof DocumentSubjects) {
                continue;
            }
            if ($stage instanceof NormalizeOptionalReferences) {
                if ($stage->plan($connection)) {
                    throw new \RuntimeException('Frozen optional model references retain zero sentinels.');
                }
            } elseif ($stage instanceof TypedItemMigration) {
                $stage->verify($connection);
            } else {
                $plan = $stage instanceof NullableReferences
                    ? $stage->plan($connection, $inspection)
                    : $stage->plan($connection);
                $pending = $stage instanceof OidcReferences ? (bool)$plan : false;
                foreach ($plan as $key => $value) {
                    if (is_string($key) && ($key === 'sql' || str_ends_with($key, '_sql')
                        || in_array($key, ['copy_legacy', 'root_rows', 'rows', 'ticket_calendars', 'always_open'], true))) {
                        $pending = $pending || (bool)$value;
                    }
                }
                $counts = $plan['counts'] ?? [];
                array_walk_recursive($counts, static function ($count) use (&$pending): void {
                    $pending = $pending || $count > 0;
                });
                if ($pending) {
                    throw new \RuntimeException('Frozen reference conversion did not converge: ' . $stage::class);
                }
            }
        }
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('The ORM schema requires 64-bit PHP integers.');
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('Run the legacy-to-ORM migration outside an application transaction; MySQL DDL commits implicitly.');
        }
        $apply = function () use ($connection, $progress): void {
            $state = $this->state($connection);
            if (($state['complete'] ?? false) === true) {
                return;
            }
            if ($state === null) {
                $plan = $this->plan($connection);
                $state = ['complete' => false, 'identifiers' => $plan['identifiers'], 'next' => 0];
                Ledger::save($connection, self::PHASE, $state);
            }
            $extended = $this->appendOwnedSequenceRepairs($connection, $state);
            if ($extended !== $state) {
                $state = $extended;
                Ledger::save($connection, self::PHASE, $state);
            }
            $save = static function () use ($connection, &$state): void {
                Ledger::save($connection, self::PHASE, $state);
            };
            $progress && $progress('Widening identifiers and preserving existing constraints');
            while ($state['next'] < count($state['identifiers'])) {
                WideIdentifiers::execute($connection, $state['identifiers'][$state['next']]);
                // Persist the next operation after every successful DDL statement.
                $state['next']++;
                $save();
            }
            foreach (self::stages() as $name => $stage) {
                $progress && $progress($name);
                $stage->apply($connection);
            }
            $progress && $progress('Installing audited foreign keys');
            (new ForeignKeys(IdentifierColumns::history()['relations']))->apply($connection);
            if ((new WideIdentifiers())->plan($connection) !== []) {
                throw new \RuntimeException('Identifier widening did not converge; the migration was not marked complete.');
            }
            $state = ['complete' => true];
            $save();
        };
        if ($postgres) {
            try {
                $connection->transactional(static function () use ($connection, $apply): void {
                    $connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('itsmng_legacy_to_orm'))");
                    $apply();
                });
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                if ($error->getSQLState() === '53200' && str_contains($error->getMessage(), 'out of shared memory')) {
                    throw new \RuntimeException('PostgreSQL exhausted relation locks. Increase max_locks_per_transaction on the server and retry; this upgrade transaction was rolled back.', 0, $error);
                }
                throw $error;
            }
        } else {
            $lock = 'itsmng_orm_' . sha1($connection->getDatabase());
            if ((int)$connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                throw new \RuntimeException('Another legacy-to-ORM migration is running.');
            }
            try {
                $apply();
            } finally {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }
}
