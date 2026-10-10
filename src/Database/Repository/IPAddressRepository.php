<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\IPAddress;

/** Address identities; parent/model traversal remains with the legacy caller. */
final class IPAddressRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * @param array{int, int, int, int} $binary Validated, normalized address words.
     * @return list<int|string>
     */
    public function identifiersForParsedAddress(int $version, array $binary): array
    {
        $metadata = $this->em->getClassMetadata(IPAddress::class);
        $query = $this->em->createQueryBuilder()->select('a.id')->from(IPAddress::class, 'a')
            ->where('a.version = :version')->setParameter('version', $version, $metadata->getTypeOfField('version'));
        // IPv4 uses its final word; IPv6 identity requires all four words.
        for ($word = $version === 4 ? 3 : 0; $word < 4; ++$word) {
            $field = 'binary_' . $word;
            $query->andWhere('a.' . $field . ' = :word' . $word)
                ->setParameter('word' . $word, $binary[$word], $metadata->getTypeOfField($field));
        }
        // No new order, visibility, deletion or parent filter is imposed here.
        return array_column($query->getQuery()->getScalarResult(), 'id');
    }
}
