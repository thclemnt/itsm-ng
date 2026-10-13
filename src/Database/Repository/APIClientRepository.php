<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\APIClient;
use itsmng\Database\LegacyValues;

/** API authentication consumes an IP-eligible identity/token snapshot, not client objects. */
final class APIClientRepository
{
    public function __construct(private EntityManager $manager)
    {
    }

    /** Preserve wildcard/range eligibility and every eligible token, without an entity ACL. */
    public function allowedTokens(int|string|false $ipv4, string $address): array
    {
        $metadata = $this->manager->getClassMetadata(APIClient::class);
        $query = $this->manager->createQueryBuilder()
            ->select('r.id, r.app_token')->from(APIClient::class, 'r')
            ->where('r.is_active = :active')
            ->setParameter('active', 1, $metadata->getTypeOfField('is_active'));
        if ($ipv4) {
            $query->andWhere('r.ipv4_range_start IS NULL OR (r.ipv4_range_start <= :address AND r.ipv4_range_end >= :address)')
                ->setParameter('address', $ipv4, $metadata->getTypeOfField('ipv4_range_start'));
        } elseif (strtolower($address) === 'null') {
            // Malformed address sentinels retain the old API eligibility predicate.
            $query->andWhere('r.ipv6 IS NULL');
        } else {
            $query->andWhere('r.ipv6 IS NULL OR r.ipv6 = :address')
                ->setParameter('address', LegacyValues::decodeString($address), $metadata->getTypeOfField('ipv6'));
        }
        return $query->getQuery()->getScalarResult();
    }
}
