<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Reporting role of the annotated owning ITIL parent association. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ITILStatisticsRelation
{
    public function __construct(public ITILStatisticsRole $role)
    {
    }
}
