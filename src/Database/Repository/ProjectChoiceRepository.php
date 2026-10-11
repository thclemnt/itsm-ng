<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\QueryBuilder;
use Project;
use Session;

/** A project choice has the same ownership/team access as the project itself. */
final class ProjectChoiceRepository extends DropdownChoiceRepository
{
    protected function choiceQuery(): QueryBuilder
    {
        $query = parent::choiceQuery();
        if (!Session::haveRightsOr('project', [Project::READALL, Project::READMY])) {
            return $query->andWhere('1 = 0');
        }
        (new ProjectRepository($this->getEntityManager()))->restrictVisibility(
            $query,
            Session::haveRight('project', Project::READALL),
            (int)Session::getLoginUserID(),
            $_SESSION['glpigroups'] ?? []
        );
        return $query;
    }
}
