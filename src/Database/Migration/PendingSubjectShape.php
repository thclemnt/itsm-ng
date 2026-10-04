<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Schema\Table;

/** The actual pending producer owns admission of its frozen generated predecessor. */
interface PendingSubjectShape
{
    public function admitsGeneratedPredecessor(Table $actual, array $definition, array $states): bool;
}
