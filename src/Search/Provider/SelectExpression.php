<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

final class SelectExpression
{
    public function __construct(
        public readonly string $sql,
        public readonly string $alias,
        public readonly bool $aggregate = false,
        public readonly bool $boolean = false,
    ) {
    }

    public function render(Dialect $dialect, bool $grouped): string
    {
        $expression = $this->sql;
        if ($grouped && !$this->aggregate) {
            $expression = ($this->boolean && $dialect->postgres() ? 'BOOL_AND' : 'MIN') . '(' . $expression . ')';
        }
        return $expression . ' AS ' . $dialect->quote($this->alias);
    }
}
