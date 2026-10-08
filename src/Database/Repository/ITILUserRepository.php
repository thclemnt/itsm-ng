<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\ITILFollowup;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Mapping\UserReferenceAction;

final class ITILUserRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Historical reference maintenance must not replay ITIL workflow update hooks. */
    public function reassignReferences(int $user, ?int $replacement): void
    {
        foreach (EntityRegistry::tables() as $table => $class) {
            foreach (EntityRegistry::references($table) as $reference) {
                if ($reference->policy->userPurge !== UserReferenceAction::ReassignHistory) {
                    continue;
                }
                $field = $reference->association;
                $this->em->createQueryBuilder()->update($class, 'r')->set('r.' . $field, ':replacement')
                    ->where('IDENTITY(r.' . $field . ') = :user')->setParameter('user', $user, Types::INTEGER)
                    ->setParameter('replacement', $replacement, Types::INTEGER)->getQuery()->execute();
            }
        }
    }

    public function followups(string $type, int $item, int $viewer, bool $private): array
    {
        $class = ITILFollowup::class;
        $subject = $this->em->getClassMetadata($class)->getAssociationMapping($class::subjectAssociation($type))->joinColumns[0]->name;
        $where = [$subject => $item];
        if (!$private) {
            $where += $viewer > 0 ? ['OR' => ['is_private' => false, 'users_id' => $viewer]] : ['is_private' => false];
        }
        return (new RecordRepository($this->em))->matching('glpi_itilfollowups', $where, ['date DESC', 'id DESC']);
    }

    public function hasCentralProfile(int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        return $this->em->createQueryBuilder()->select('a.id')->from(ProfileUser::class, 'a')->join('a.profiles', 'p')
            ->where('IDENTITY(a.users) = :user AND p.interface = :interface')->setParameter('user', $user, Types::INTEGER)
            ->setParameter('interface', 'central')->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }
}
