<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

final class SelectList
{
    /** @var array<string, SelectExpression> */
    private array $fields = [];
    private array $legacy = [];
    private bool $fieldJoinRequired = true;

    /** A correlated root projection owns its subquery rather than a display join. */
    public function withoutFieldJoin(): self
    {
        $this->fieldJoinRequired = false;
        return $this;
    }

    public function requiresFieldJoin(): bool
    {
        return $this->fieldJoinRequired;
    }

    public function addHookResult(string|self|null|false $sql, Dialect $dialect): self
    {
        if ($sql instanceof self) {
            return $this->merge($sql);
        }
        if ($sql) {
            if ($dialect->postgres()) {
                throw new \RuntimeException('This plugin supplies a MySQL search projection. It must supply portable search expressions for PostgreSQL.');
            }
            $this->legacy[] = rtrim(trim($sql), ',');
        }
        return $this;
    }

    public function add(string $sql, string $alias, bool $aggregate = false, bool $boolean = false): self
    {
        $this->fields[$alias] = new SelectExpression($sql, $alias, $aggregate, $boolean);
        return $this;
    }
    /** One value per root; MAX only adapts it to grouped display/sort queries. */
    public function addRootScalar(string $sql, string $alias): self
    {
        $this->fields[$alias] = new SelectExpression('MAX(' . $sql . ')', $alias, true, false, $sql);
        return $this;
    }

    public function rootScalar(string $alias): ?string
    {
        return $this->fields[$alias]->rootScalar ?? null;
    }

    public function merge(self $other): self
    {
        $this->fields = array_replace($this->fields, $other->fields);
        $this->legacy = array_merge($this->legacy, $other->legacy);
        return $this;
    }
    public function get(string $alias): SelectExpression
    {
        return $this->fields[$alias] ?? throw new \InvalidArgumentException('Unknown search projection: ' . $alias);
    }
    public function sql(Dialect $dialect, bool $grouped = false): string
    {
        return implode(', ', array_merge(array_map(fn ($field) => $field->render($dialect, $grouped), $this->fields), $this->legacy));
    }
}
