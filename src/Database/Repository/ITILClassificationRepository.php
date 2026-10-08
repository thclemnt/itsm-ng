<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity\RequestType;

/** Classification queries use typed mappings and keep template identities separate. */
final class ITILClassificationRepository
{
    private const SOURCES = ['helpdesk', 'followup', 'mail', 'mailfollowup'];

    public function __construct(private EntityManager $em)
    {
    }

    public static function templateFields(string $type): array
    {
        return match ($type) {
            'TicketTemplate' => ['tickettemplates_id_incident', 'tickettemplates_id_demand'],
            'ChangeTemplate' => ['changetemplates_id'],
            'ProblemTemplate' => ['problemtemplates_id'],
            default => throw new InvalidArgumentException('Unsupported ITIL template type'),
        };
    }

    public function categoriesForTemplate(string $type, int $id, array $scope): array
    {
        $links = array_fill_keys(self::templateFields($type), $id);
        return (new RecordRepository($this->em))->matching('glpi_itilcategories', [['OR' => $links], $scope], ['name', 'id']);
    }

    public function defaultRequestType(string $source): int
    {
        if (!in_array($source, self::SOURCES, true)) {
            return 0;
        }
        $result = $this->em->createQueryBuilder()->select('r.id')->from(RequestType::class, 'r')
            ->where('r.is_' . $source . '_default = :default AND r.is_active = :active')
            ->setParameter('default', true, Types::BOOLEAN)->setParameter('active', 1, Types::SMALLINT)
            ->orderBy('r.id')->setMaxResults(1)->getQuery()->getScalarResult();
        return (int)($result[0]['id'] ?? 0);
    }

    /** Lifecycle hooks elect one default without recursively running update hooks. */
    public function clearOtherDefaults(int $selected, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $allowed = array_map(static fn ($source) => 'is_' . $source . '_default', self::SOURCES);
        $query = $this->em->createQueryBuilder()->update(RequestType::class, 'r');
        foreach ($fields as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported request source');
            }
            $query->set('r.' . $field, ':disabled');
        }
        $query->where('r.id <> :selected')->setParameter('selected', $selected, Types::INTEGER)
            ->setParameter('disabled', false, Types::BOOLEAN)->getQuery()->execute();
    }
}
