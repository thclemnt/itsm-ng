<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

interface AllocationSubject
{
    public function allocationEntityScope(): EntityScope;
}
