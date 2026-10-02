<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class IPNetworkParentReferences
{
    public const VERSION = '20260930_ip_network_parent_references';

    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'IMPLICIT_TREE_PARENTS'), 'IP network parent'))->plan($connection);
        TreeParentAudit::assertAcyclic($connection, ReferenceHistory::get('optional', 'IMPLICIT_TREE_PARENTS'));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection);
        return (new NullableReferences(ReferenceHistory::get('optional', 'IMPLICIT_TREE_PARENTS'), 'IP network parent'))->apply($connection);
    }
}
