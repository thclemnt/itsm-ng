<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;

/** Import a Domain aggregate through its public lifecycle with explicit ownership provenance. */
final class DomainPluginImport
{
    public const RECEIPT = '20261006_domains_plugin_import_v1';
    public const ADOPTION_RECEIPT = '20261006_domains_plugin_adoption_v1';

    public function __construct(private \DBAdapter $database)
    {
    }

    public function plan(): DomainImportPlan
    {
        $connection = $this->database->getDoctrineConnection();
        $states = Ledger::states($connection);
        foreach (History::VERSIONS as $version) {
            if (($states[$version]['complete'] ?? false) !== true) {
                throw new \RuntimeException('Domains import requires completed canonical history; run db:migrate. Pending: ' . $version);
            }
        }
        $differences = (new SchemaCheck())->differences($connection);
        if ($differences) {
            throw new \RuntimeException('Domains import requires the canonical core schema: ' . implode('; ', array_slice($differences, 0, 5)));
        }
        $snapshot = (new DomainPluginSource($connection))->read();
        $fingerprint = $snapshot->fingerprint();
        $receipts = array_intersect_key($states, array_flip([self::RECEIPT, self::ADOPTION_RECEIPT]));
        $receipt = $states[self::RECEIPT] ?? $states[self::ADOPTION_RECEIPT] ?? null;
        $em = Orm::create($this->database);
        try {
            $sourcePlugin = $this->sourcePlugin($em);
            if ($receipt !== null) {
                foreach ($receipts as $provenance) {
                    if (($provenance['complete'] ?? false) !== true || ($provenance['format'] ?? null) !== DomainPluginSnapshot::FORMAT
                        || ($provenance['fingerprint'] ?? null) !== $fingerprint || ($provenance['counts'] ?? null) !== $snapshot->counts()) {
                        throw new \RuntimeException('Domains source differs from its completed import receipt; explicit reconciliation is required.');
                    }
                }
                $this->assertKnownIdentitySpellings($em);
                $this->unknownExternalBindings();
                $this->newBindings($em, $receipt);
                return new DomainImportPlan($fingerprint, $receipt['counts'], alreadyImported: true, sourcePlugin: $sourcePlugin);
            }
            $this->assertTransactionalCore();
            $validation = new DomainImportValidation($em);
            $records = [];
            foreach ($snapshot->records() as [$model, $input]) {
                if ($model === \Domain_Item::class && !in_array($input['itemtype'], ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer', 'Software'], true)) {
                    throw new \RuntimeException('Unsupported Domains plugin asset kind: glpi_plugin_domains_domains_items.' . $input['id'] . '=' . $input['itemtype']);
                }
                $table = $model::getTable();
                $class = EntityRegistry::tables()[$table];
                $values = $validation->normalize($class, $input);
                $records[] = ['model' => $model, 'class' => $class, 'table' => $table, 'values' => $values, 'input' => $values + $input];
            }
            $incoming = $validation->graph($records);
            $this->assertKnownIdentitySpellings($em);
            $this->unknownExternalBindings();
            [$rights, $policies] = (new DomainImportPolicy($em))->plan($snapshot);
            $bindings = (new DomainIdentityAdoption($em))->plan($incoming);
            $validation->bindings($records, $bindings);
            $covered = [];
            foreach ($bindings as $binding) {
                foreach ($binding['original'] as $field => $_) {
                    $covered[$binding['table']][$binding['id']][$field] = true;
                }
            }
            foreach ($policies as $policy) {
                if ($policy['class'] === Entity\CronTask::class) {
                    $covered['glpi_crontasks'][$policy['id']]['itemtype'] = true;
                }
            }
            foreach ($this->sourceIdentityKeys() as $binding) {
                if (!isset($covered[$binding['table']][$binding['id']][$binding['field']])
                    && !($binding['table'] === 'glpi_logs' && $binding['field'] === 'itemtype')) {
                    throw new \RuntimeException('Unsupported Domains identity role: ' . $binding['table'] . '.' . $binding['id'] . '.' . $binding['field']);
                }
            }
            $profiles = $this->profiles($em);
            return new DomainImportPlan($fingerprint, $snapshot->counts(), $records, $bindings, $profiles, $rights, $policies, sourcePlugin: $sourcePlugin);
        } finally {
            $em->clear();
        }
    }

    public function import(?callable $progress = null): DomainImportPlan
    {
        if ($this->database !== ($GLOBALS['DB'] ?? null) || $this->database->isSlave()) {
            throw new \RuntimeException('Domains lifecycle import requires the application writable connection.');
        }
        $connection = $this->database->getDoctrineConnection();
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $lock = 'itsmng_domains_import_' . sha1($connection->getDatabase());
        if (!$postgres && (int)$connection->fetchOne('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            throw new \RuntimeException('Another Domains import is running.');
        }
        global $CFG_GLPI;
        $hadInfocom = array_key_exists('auto_create_infocoms', $CFG_GLPI);
        $autoInfocom = $CFG_GLPI['auto_create_infocoms'] ?? null;
        $session = $_SESSION;
        try {
            return $connection->transactional(function () use ($connection, $postgres, $progress, $autoInfocom): DomainImportPlan {
                if ($postgres && !in_array($connection->fetchOne("SELECT pg_try_advisory_xact_lock(hashtext('itsmng_domains_import'))"), [true, 1, '1', 't'], true)) {
                    throw new \RuntimeException('Another Domains import is running.');
                }
                $plan = $this->plan();
                if ($plan->alreadyImported) {
                    return $plan;
                }
                $financial = [];
                foreach ($plan->bindings as $binding) {
                    if ($binding['class'] === Entity\Infocom::class) {
                        $financial[] = $binding['id'];
                    }
                }
                $em = Orm::create($this->database);
                $sourceFinancial = [];
                foreach ($financial as $id) {
                    $sourceFinancial[$em->find(Entity\Infocom::class, $id)->items_id] = true;
                }
                $generated = [];
                try {
                    foreach ($plan->records as $record) {
                        // Import an existing financial child without creating a second one.
                        // Normal configured automatic creation still runs for other Domains.
                        $GLOBALS['CFG_GLPI']['auto_create_infocoms'] = $record['class'] === Entity\Domain::class && isset($sourceFinancial[$record['values']['id']]) ? false : $autoInfocom;
                        $model = new $record['model']();
                        $input = \Toolbox::addslashes_deep($record['input']);
                        foreach ($record['input'] as $field => $value) {
                            if ($value === 'NULL' || $value === 'null') {
                                $input[$field] = $value === 'NULL' ? 'N\\ULL' : 'n\\ull';
                            }
                        }
                        $input['_no_message'] = true;
                        $id = $record['values']['id'];
                        if ($model->addWithAssignedIdentifier($id, $input) !== $id) {
                            throw new \RuntimeException('Domains lifecycle creation failed: ' . $record['table'] . '.' . $id);
                        }
                        if ($record['class'] === Entity\Domain::class && !isset($sourceFinancial[$id])) {
                            $child = $em->getRepository(Entity\Infocom::class)->findOneBy(['itemtype' => 'Domain', 'items_id' => $id]);
                            if ($child) {
                                $generated['infocoms'][] = $child->id;
                            }
                        }
                        $progress && $progress('created', $record['table'], $id);
                    }
                    (new DomainIdentityAdoption($em))->apply($plan->bindings);
                    (new DomainImportPolicy($em))->apply($plan->rights, $plan->policies);
                } finally {
                    $em->clear();
                }
                foreach ($plan->profiles as $profile) {
                    $model = new \Profile();
                    if (!$model->update(['id' => $profile['id'], 'helpdesk_item_type' => \Toolbox::addslashes_deep(\exportArrayToDB($profile['types']))])) {
                        throw new \RuntimeException('Domains profile adoption failed: ' . $profile['id']);
                    }
                }
                $progress && $progress('adopted', 'bindings', count($plan->bindings));
                SequenceSynchronizer::synchronize($connection);
                $deferred = [];
                foreach ($plan->records as $record) {
                    if ($record['class'] === Entity\Domain::class) {
                        $deferred[] = ['id' => $record['values']['id'], 'suppliers_id' => $record['values']['suppliers_id'], 'is_helpdesk_visible' => $record['values']['is_helpdesk_visible']];
                    }
                }
                Ledger::save($connection, self::RECEIPT, ['complete' => true, 'format' => DomainPluginSnapshot::FORMAT,
                    'fingerprint' => $plan->fingerprint, 'counts' => $plan->counts, 'bindings' => $plan->bindings, 'source_plugin' => $plan->sourcePlugin,
                    'retained_bindings' => $this->sourceIdentityKeys(), 'retained_source_rights' => $this->sourceRights(), 'profiles' => $plan->profiles, 'rights' => $plan->rights, 'policies' => $plan->policies, 'generated' => $generated, 'deferred_domains' => $deferred]);
                $progress && $progress('complete', 'receipt', 1);
                return $plan;
            });
        } catch (\Throwable $error) {
            $_SESSION = $session;
            throw $error;
        } finally {
            if ($hadInfocom) {
                $CFG_GLPI['auto_create_infocoms'] = $autoInfocom;
            } else {
                unset($CFG_GLPI['auto_create_infocoms']);
            }
            if (!$postgres) {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    /** The historical app owns deactivation and its nontransactional plugin hooks. */
    private function sourcePlugin(\Doctrine\ORM\EntityManager $em): ?array
    {
        $plugins = $em->createQueryBuilder()->select('p')->from(Entity\Plugin::class, 'p')
            ->where('LOWER(TRIM(p.directory)) = :directory')->setParameter('directory', 'domains')
            ->getQuery()->getResult();
        $provenance = null;
        foreach ($plugins as $plugin) {
            if ($plugin->directory !== 'domains' || $provenance !== null) {
                throw new \RuntimeException('Unsupported Domains plugin directory spelling: glpi_plugins.' . $plugin->id . '=' . $plugin->directory . '; reconcile the registration in the compatible historical application before exporting.');
            }
            if (in_array($plugin->state, [\Plugin::ACTIVATED, \Plugin::TOBECONFIGURED], true)) {
                throw new \RuntimeException('Domains plugin must be inactive before import: glpi_plugins.' . $plugin->id . '.state=' . $plugin->state . '. Deactivate it through the compatible historical application before maintenance/export, and keep the source application quiescent throughout import.');
            }
            $provenance = ['id' => $plugin->id, 'directory' => $plugin->directory, 'name' => $plugin->name,
                'version' => $plugin->version, 'state' => $plugin->state, 'author' => $plugin->author,
                'homepage' => $plugin->homepage, 'license' => $plugin->license];
        }
        return $provenance;
    }

    /** Lifecycle hooks can write audit/financial core rows as well as the aggregate. */
    private function assertTransactionalCore(): void
    {
        $connection = $this->database->getDoctrineConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        $mapped = EntityRegistry::tables();
        foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
            if (isset($mapped[$table['TABLE_NAME']]) && strcasecmp($table['ENGINE'] ?? '', 'InnoDB') !== 0) {
                throw new \RuntimeException('Domains lifecycle import requires transactional core tables: ' . $table['TABLE_NAME'] . ' must use InnoDB; found ' . ($table['ENGINE'] ?? 'no transactional engine') . '. Reconcile this table before importing; audit and hooks cannot roll back otherwise.');
            }
        }
    }

    private function profiles(\Doctrine\ORM\EntityManager $em): array
    {
        $result = [];
        $ticketRights = [];
        foreach ($em->getRepository(Entity\ProfileRight::class)->findBy(['name' => 'plugin_domains_open_ticket']) as $right) {
            $ticketRights[$right->profiles->id] = $right->rights;
        }
        foreach ($em->getRepository(Entity\Profile::class)->findAll() as $profile) {
            $encoded = $profile->helpdesk_item_type ?? '';
            $values = \importArrayFromDB($encoded);
            if ($encoded !== '' && !is_array(json_decode($encoded, true))) {
                foreach (explode(' ', $encoded) as $part) {
                    if ($part !== '' && count(explode('=>', $part)) !== 2) {
                        throw new \RuntimeException('Invalid encoded Domains helpdesk types: glpi_profiles.' . $profile->id);
                    }
                }
            }
            foreach ($values as $value) {
                if (is_string($value) && strncasecmp(trim($value), 'PluginDomains', 13) === 0 && $value !== DomainPluginSource::ITEMTYPE) {
                    throw new \RuntimeException('Unsupported Domains helpdesk identity: glpi_profiles.' . $profile->id . '=' . $value);
                }
            }
            $changed = false;
            $affected = array_key_exists($profile->id, $ticketRights) || in_array(DomainPluginSource::ITEMTYPE, $values, true);
            if (!$affected) {
                continue;
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new \RuntimeException('Invalid encoded Domains helpdesk types: glpi_profiles.' . $profile->id . '; expected one level of string values.');
                }
            }
            $permission = $ticketRights[$profile->id] ?? 0;
            if ($permission === 0 && in_array('Domain', $values, true)) {
                throw new \RuntimeException('Domains helpdesk policy conflict: profile ' . $profile->id . '; core Domain access differs from source permission.');
            }
            foreach ($values as $key => &$value) {
                if ($value === DomainPluginSource::ITEMTYPE) {
                    if ($permission === 0) {
                        unset($values[$key]);
                    } else {
                        $value = 'Domain';
                    }
                    $changed = true;
                }
            }
            unset($value);
            if ($permission === 1 && !in_array('Domain', $values, true)) {
                $values[] = 'Domain';
                $changed = true;
            }
            if ($changed) {
                $result[] = ['id' => $profile->id, 'types' => $values];
            }
        }
        return $result;
    }

    /** Unmodeled external plugin rows are diagnosed, never treated as generic polymorphic owners. */
    private function unknownExternalBindings(): void
    {
        $connection = $this->database->getDoctrineConnection();
        $mapped = EntityRegistry::tables();
        foreach ($connection->createSchemaManager()->listTables() as $table) {
            if (isset($mapped[$table->getName()]) || in_array($table->getName(), ['glpi_plugin_domains_domains', 'glpi_plugin_domains_domaintypes', 'glpi_plugin_domains_domains_items', 'glpi_plugin_domains_configs', 'itsmng_migrations'], true)) {
                continue;
            }
            foreach ($table->getColumns() as $column) {
                if (!in_array($column->getType()::class, [\Doctrine\DBAL\Types\StringType::class, \Doctrine\DBAL\Types\TextType::class], true)) {
                    continue;
                }
                $quote = $connection->quoteIdentifier(...);
                $row = $connection->fetchAssociative(
                    'SELECT * FROM ' . $quote($table->getName()) . ' WHERE LOWER(TRIM(' . $quote($column->getName()) . ')) LIKE ?',
                    ['plugindomains%']
                );
                if ($row) {
                    throw new \RuntimeException('Unsupported external Domains plugin binding: ' . $table->getName() . '.' . ($row['id'] ?? '?') . '.' . $column->getName());
                }
            }
        }
    }

    /** Unknown classes in the pinned plugin namespace require a verified adapter. */
    private function assertKnownIdentitySpellings(\Doctrine\ORM\EntityManager $em): void
    {
        $kinds = [DomainPluginSource::ITEMTYPE, DomainPluginSource::TYPE, 'PluginDomainsDomaintype'];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach (['itemtype', 'itemtype_link', 'itemtype_source', 'itemtype_impacted'] as $field) {
                if (!$metadata->hasField($field)) {
                    continue;
                }
                $rows = $em->createQueryBuilder()->select('r.id, r.' . $field . ' AS kind')->from($metadata->name, 'r')
                    ->where('LOWER(TRIM(r.' . $field . ')) LIKE :namespace')->setParameter('namespace', 'plugindomains%')->getQuery()->getArrayResult();
                foreach ($rows as $row) {
                    if (!in_array($row['kind'], $kinds, true)) {
                        throw new \RuntimeException('Unsupported Domains source identity spelling: ' . $metadata->getTableName() . '.' . $row['id'] . '.' . $field . '=' . $row['kind']);
                    }
                }
            }
        }
    }

    private function newBindings(\Doctrine\ORM\EntityManager $em, array $receipt): void
    {
        foreach ($em->getRepository(Entity\Profile::class)->findAll() as $profile) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator(\importArrayFromDB($profile->helpdesk_item_type))) as $value) {
                if (is_string($value) && strncasecmp(trim($value), 'PluginDomains', 13) === 0) {
                    throw new \RuntimeException('New Domains plugin helpdesk binding after completed import: glpi_profiles.' . $profile->id . '; explicit reconciliation is required.');
                }
            }
        }
        $known = [];
        foreach ($receipt['retained_bindings'] ?? [] as $binding) {
            $known[$binding['table']][$binding['id']][$binding['field']] = true;
        }
        $rights = [];
        foreach ($receipt['retained_source_rights'] ?? [] as $right) {
            $rights[$right['id']] = $right;
        }
        foreach ($this->sourceRights() as $right) {
            if (($rights[$right['id']] ?? null) !== $right) {
                throw new \RuntimeException('Changed or new Domains plugin permission after completed import: glpi_profilerights.' . $right['id'] . '; explicit reconciliation is required.');
            }
        }
        foreach ($this->sourceIdentityKeys() as $binding) {
            if (!isset($known[$binding['table']][$binding['id']][$binding['field']])) {
                throw new \RuntimeException('New Domains plugin binding after completed import: ' . $binding['table'] . '.' . $binding['id'] . '.' . $binding['field'] . '; explicit reconciliation is required.');
            }
        }
    }


    private function sourceRights(): array
    {
        $em = Orm::create($this->database);
        try {
            $result = [];
            foreach ($em->createQueryBuilder()->select('r')->from(Entity\ProfileRight::class, 'r')
                ->where('r.name IN (:names)')->setParameter('names', ['plugin_domains', 'plugin_domains_dropdown', 'plugin_domains_open_ticket'])
                ->orderBy('r.id')->getQuery()->getResult() as $right) {
                $result[] = ['id' => $right->id, 'profiles_id' => $right->profiles->id, 'name' => $right->name, 'rights' => $right->rights];
            }
            return $result;
        } finally {
            $em->clear();
        }
    }

    /** Read-only discovery of class-identity roles; no inferred ownership or updates. */
    private function sourceIdentityKeys(): array
    {
        $em = Orm::create($this->database);
        $result = [];
        try {
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                foreach (['itemtype', 'itemtype_link', 'itemtype_source', 'itemtype_impacted'] as $field) {
                    if (!$metadata->hasField($field)) {
                        continue;
                    }
                    $ids = $em->createQueryBuilder()->select('r.id')->from($metadata->name, 'r')->where('r.' . $field . ' IN (:kinds)')
                        ->setParameter('kinds', [DomainPluginSource::ITEMTYPE, DomainPluginSource::TYPE, 'PluginDomainsDomaintype'])->getQuery()->getSingleColumnResult();
                    foreach ($ids as $id) {
                        $result[] = ['table' => $metadata->getTableName(), 'id' => (int)$id, 'field' => $field];
                    }
                }
            }
            return $result;
        } finally {
            $em->clear();
        }
    }
}
