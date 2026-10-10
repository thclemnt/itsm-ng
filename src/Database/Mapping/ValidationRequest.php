<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** An approval request declares the owning association to its workflow subject. */
interface ValidationRequest
{
    public static function subjectAssociation(): string;
}
