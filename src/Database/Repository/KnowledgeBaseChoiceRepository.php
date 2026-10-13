<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\QueryBuilder;
use itsmng\Database\KnowledgeBaseAccess;

/** Article choices respect the existing audience, publication and viewer policy. */
final class KnowledgeBaseChoiceRepository extends DropdownChoiceRepository
{
    protected function choiceQuery(): QueryBuilder
    {
        $query = parent::choiceQuery();
        (new KnowledgeBaseRepository($this->getEntityManager()))->restrictDropdownChoices($query, KnowledgeBaseAccess::current());
        return $query;
    }
}
