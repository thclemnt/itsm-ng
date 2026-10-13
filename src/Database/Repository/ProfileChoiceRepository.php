<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\RecordCriteria;

/** Rights conditions select a profile once, regardless of its number of grants. */
final class ProfileChoiceRepository extends DropdownChoiceRepository
{
    protected function choiceQuery(): QueryBuilder
    {
        return parent::choiceQuery()->distinct()->leftJoin(ProfileRight::class, 'choiceRight', 'WITH', 'IDENTITY(choiceRight.profiles) = r.id');
    }

    protected function choiceCriteria(QueryBuilder $query): RecordCriteria
    {
        return parent::choiceCriteria($query)->withJoinedMetadata($this->getEntityManager()->getClassMetadata(ProfileRight::class), 'choiceRight');
    }
}
