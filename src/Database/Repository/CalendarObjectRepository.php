<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use LogicException;
use itsmng\Database\Entity\VObject;
use itsmng\Database\EntityRegistry;

/** Calendar subject lookup uses the same owning mappings as persisted calendar data. */
final class CalendarObjectRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $kind): bool
    {
        return isset(EntityRegistry::discriminatedReferences('glpi_vobjects')['items_id']['selections'][$kind]);
    }

    /** Two distinct matches are enough to prove that a UID is ambiguous. */
    public function subjectsForUid(string $uid, array $kinds): array
    {
        $metadata = $this->em->getClassMetadata(VObject::class);
        $matches = [];
        foreach (array_unique($kinds) as $kind) {
            if (!self::supports($kind)) {
                continue;
            }
            $target = $metadata->getAssociationTargetClass(VObject::referenceAssociation($kind));
            if (!$this->em->getClassMetadata($target)->hasField('uuid')) {
                throw new LogicException('Mapped calendar subject requires a UUID field: ' . $target);
            }
            $rows = $this->em->createQueryBuilder()->select('r.id AS id')->from($target, 'r')
                ->where('r.uuid = :uid')->setParameter('uid', $uid)->orderBy('r.id')->setMaxResults(2 - count($matches))
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $matches[] = ['id' => (int)$row['id'], 'itemtype' => $kind];
            }
            if (count($matches) === 2) {
                break;
            }
        }
        return $matches;
    }
}
