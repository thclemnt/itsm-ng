<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Repository\RecordRepository;

/** Adopt verified historical identity roles while preserving the original binding rows. */
final class DomainIdentityAdoption
{
    public function __construct(private EntityManager $em)
    {
    }

    public function plan(array $incoming): array
    {
        $result = [];
        $kinds = [DomainPluginSource::ITEMTYPE, DomainPluginSource::TYPE, 'PluginDomainsDomaintype'];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata->hasField('itemtype') || $metadata->name === Entity\CronTask::class) {
                continue;
            }
            $rows = $this->em->createQueryBuilder()->select('r')->from($metadata->name, 'r')->where('r.itemtype IN (:kinds)')
                ->setParameter('kinds', $kinds)->getQuery()->getResult();
            foreach ($rows as $row) {
                $kind = $row->itemtype;
                if (!in_array($kind, $kinds, true)) {
                    throw new \RuntimeException('Noncanonical Domains identity spelling: ' . $metadata->getTableName() . '.' . $row->id . '=' . $kind);
                }
                $target = $kind === DomainPluginSource::ITEMTYPE ? Entity\Domain::class : Entity\DomainType::class;
                $newKind = $target === Entity\Domain::class ? 'Domain' : 'DomainType';
                $fields = ['itemtype' => $newKind];
                $associations = [];
                if ($metadata->name === Entity\Log::class) {
                    if (!isset($incoming[$this->em->getClassMetadata($target)->getTableName()][$row->items_id])) {
                        continue; // Retired historical subjects never adopt an unrelated core ID.
                    }
                } elseif ($metadata->hasField('items_id')) {
                    $id = $row->items_id;
                    if ($id === null || !isset($incoming[$this->em->getClassMetadata($target)->getTableName()][$id])) {
                        throw new \RuntimeException('Invalid Domains plugin binding: ' . $metadata->getTableName() . '.' . $row->id . '; source subject is absent.');
                    }
                    if ($target === Entity\DomainType::class && $metadata->name !== Entity\DropdownTranslation::class) {
                        throw new \RuntimeException('Unsupported Domains type binding: ' . $metadata->getTableName() . '.' . $row->id);
                    }
                    $selections = EntityRegistry::discriminatedReferences($metadata->getTableName())['items_id']['selections'] ?? [];
                    if ($selections) {
                        if (!isset($selections[$newKind]) || !is_a($metadata->name, LegacyInput::class, true)) {
                            throw new \RuntimeException('Binding cannot represent a core ' . $newKind . ': ' . $metadata->getTableName() . '.' . $row->id);
                        }
                        foreach ($selections as $selectionKind => $_) {
                            $property = $metadata->name::referenceAssociation($selectionKind);
                            $associations[$property] = $property === $metadata->name::referenceAssociation($newKind)
                                ? ['class' => $target, 'id' => (int)$id] : null;
                        }
                    }
                } else {
                    $fields += $this->classRole($row, $newKind);
                }
                $result[] = ['class' => $metadata->name, 'table' => $metadata->getTableName(), 'id' => $row->id,
                    'original' => ['itemtype' => $kind], 'fields' => $fields, 'associations' => $associations];
            }
        }
        // Audit linked labels have no owning identifier; preserve display text and values.
        foreach ($this->em->createQueryBuilder()->select('r')->from(Entity\Log::class, 'r')->where('r.itemtype_link IN (:kinds)')->setParameter('kinds', $kinds)->getQuery()->getResult() as $row) {
            if (!in_array($row->itemtype_link, $kinds, true)) {
                throw new \RuntimeException('Noncanonical Domains linked audit identity: glpi_logs.' . $row->id . '=' . $row->itemtype_link);
            }
            $result[] = ['class' => Entity\Log::class, 'table' => 'glpi_logs', 'id' => $row->id,
                'original' => ['itemtype_link' => $row->itemtype_link], 'fields' => ['itemtype_link' => $row->itemtype_link === DomainPluginSource::ITEMTYPE ? 'Domain' : 'DomainType'], 'associations' => []];
        }
        foreach ($this->em->getRepository(Entity\ImpactRelation::class)->findAll() as $row) {
            $fields = [];
            foreach (['source', 'impacted'] as $role) {
                $property = 'itemtype_' . $role;
                if ($row->$property === DomainPluginSource::ITEMTYPE) {
                    $idProperty = 'items_id_' . $role;
                    if (!isset($incoming['glpi_domains'][$row->$idProperty])) {
                        throw new \RuntimeException('Invalid Domains impact binding: glpi_impactrelations.' . $row->id . '.' . $property);
                    }
                    $fields[$property] = 'Domain';
                }
            }
            if ($fields) {
                $result[] = ['class' => Entity\ImpactRelation::class, 'table' => 'glpi_impactrelations', 'id' => $row->id,
                    'original' => array_fill_keys(array_keys($fields), DomainPluginSource::ITEMTYPE), 'fields' => $fields, 'associations' => []];
            }
        }
        $seen = [];
        $repository = new RecordRepository($this->em);
        $validation = new DomainImportValidation($this->em);
        foreach ($result as $binding) {
            $metadata = $this->em->getClassMetadata($binding['class']);
            $values = array_replace($repository->find($binding['table'], 'id', (int)$binding['id']), $binding['fields']);
            foreach ($metadata->table['uniqueConstraints'] ?? [] as $name => $constraint) {
                $criteria = array_intersect_key($values, array_flip(array_map(static fn ($column) => trim($column, '`'), $constraint['columns'])));
                if (in_array(null, $criteria, true)) {
                    continue;
                }
                $validation->unique($binding['table'], $name, $criteria, $seen[$binding['table']][$name] ?? []);
                $seen[$binding['table']][$name][] = $criteria;
            }
        }
        return $result;
    }

    private function classRole(object $row, string $newKind): array
    {
        if ($row instanceof Entity\DisplayPreference) {
            if ($newKind !== 'Domain') {
                throw new \RuntimeException('Unsupported DomainType display preference: ' . $row->id);
            }
            $this->searchField($row->num, $row->id);
            return [];
        }
        if ($row instanceof Entity\SavedSearch) {
            if ($newKind !== 'Domain' || (int)$row->type !== 1) {
                throw new \RuntimeException('Unsupported Domains saved search: ' . $row->id);
            }
            $query = [];
            parse_str($row->query ?? '', $query);
            if (!$query || (($query['itemtype'] ?? DomainPluginSource::ITEMTYPE) !== DomainPluginSource::ITEMTYPE)) {
                throw new \RuntimeException('Invalid encoded Domains saved search: ' . $row->id);
            }
            $this->criteria($query['criteria'] ?? [], $row->id);
            if (!empty($query['metacriteria'])) {
                throw new \RuntimeException('Unsupported Domains saved metacriteria: ' . $row->id);
            }
            if (isset($query['sort'])) {
                $this->searchField($query['sort'], $row->id);
            }
            $query['itemtype'] = 'Domain';
            return ['query' => http_build_query($query), 'path' => 'front/domain.php'];
        }
        if ($row instanceof Entity\Notification || $row instanceof Entity\NotificationTemplate || $row instanceof Entity\LinkItemtype) {
            if ($newKind !== 'Domain') {
                throw new \RuntimeException('Unsupported DomainType class binding: ' . $row::class . '.' . $row->id);
            }
            return [];
        }
        if ($row instanceof Entity\CronTask) {
            throw new \RuntimeException('Domains scheduler must be planned by the notification policy service.');
        }
        if ($row instanceof Entity\Fieldblacklist) {
            return ['field' => $this->property($row->field, $row->id)];
        }
        if ($row instanceof Entity\FieldUnicity) {
            $fields = \importArrayFromDB($row->fields);
            if (!$fields && $row->fields !== '' && $row->fields !== null) {
                throw new \RuntimeException('Invalid encoded Domains unique fields: ' . $row->id);
            }
            foreach ($fields as &$field) {
                $field = $this->property($field, $row->id);
            }
            unset($field);
            return ['fields' => \exportArrayToDB($fields)];
        }
        throw new \RuntimeException('Unsupported Domains class binding: ' . $row::class . '.' . $row->id);
    }

    private function property(string $field, int $id): string
    {
        if ($field === 'plugin_domains_domaintypes_id') {
            return 'domaintypes_id';
        }
        if (!in_array($field, ['name', 'entities_id', 'is_recursive', 'date_creation', 'date_expiration', 'users_id_tech', 'groups_id_tech',
            'suppliers_id', 'comment', 'others', 'is_helpdesk_visible', 'date_mod', 'is_deleted'], true)) {
            throw new \RuntimeException('Unsupported Domains source property: ' . $id . '.' . $field);
        }
        return $field;
    }

    /** Frozen plugin options; current Domain preserves these exact semantic IDs. */
    private function searchField(mixed $field, int $id): void
    {
        if (!in_array((string)$field, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '18', '30', '80', '81', 'all', 'view'], true)) {
            throw new \RuntimeException('Unsupported Domains source search field: ' . $id . '.' . (is_scalar($field) ? $field : '?'));
        }
    }

    private function criteria(array $criteria, int $id): void
    {
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                throw new \RuntimeException('Invalid encoded Domains search criterion: ' . $id);
            }
            if (isset($criterion['criteria'])) {
                $this->criteria($criterion['criteria'], $id);
            } elseif (isset($criterion['field'])) {
                $this->searchField($criterion['field'], $id);
            } else {
                throw new \RuntimeException('Missing Domains search field: ' . $id);
            }
        }
    }

    public function apply(array $bindings): void
    {
        foreach ($bindings as $binding) {
            $row = $this->em->find($binding['class'], $binding['id']);
            if ($row === null) {
                throw new \RuntimeException('Domains identity binding vanished during import.');
            }
            foreach ($binding['original'] as $field => $original) {
                if ($row->$field !== $original) {
                    throw new \RuntimeException('Domains identity binding changed during import: ' . $binding['table'] . '.' . $binding['id']);
                }
            }
            foreach ($binding['fields'] as $field => $value) {
                $row->$field = $value;
            }
            foreach ($binding['associations'] as $field => $target) {
                $row->$field = $target === null ? null : $this->em->getReference($target['class'], $target['id']);
            }
        }
        $this->em->flush();
    }
}
