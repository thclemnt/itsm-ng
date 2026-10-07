<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;

/** Internal seam; implementations reject queries from any other manager. */
interface ReadQueryOwner
{
    public function prepareQuery(Query $query, ClassMetadata $metadata): void;
}
