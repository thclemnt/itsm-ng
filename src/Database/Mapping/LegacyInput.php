<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Entity-owned conversion for legacy inputs that describe canonical associations. */
interface LegacyInput
{
    public function normalizeInput(array $values): array;

    public function legacyChanges(array $columns): array;
}
