<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use CommonDBTM;
use Doctrine\DBAL\Connection;
use SplObjectStorage;
use WeakMap;

/** Shallow checkpoints of actual participating model instances, scoped to one writer. */
final class LifecycleModelJournal
{
    private static ?WeakMap $observers = null;
    private SplObjectStorage $models;

    public function __construct()
    {
        $this->models = new SplObjectStorage();
    }

    public static function state(CommonDBTM $model): array
    {
        return array_intersect_key(get_object_vars($model), array_fill_keys(['fields', 'input', 'updates', 'oldvalues'], true));
    }

    public function remember(CommonDBTM $model, ?array $state = null): void
    {
        if (!$this->models->offsetExists($model)) {
            $this->models[$model] = $state ?? self::state($model);
        }
    }

    public static function capture(Connection $connection, CommonDBTM $model): void
    {
        foreach (self::$observers[$connection] ?? [] as $journal) {
            $journal->remember($model);
        }
    }

    public function observe(Connection $connection, callable $operation): mixed
    {
        self::$observers ??= new WeakMap();
        $observers = self::$observers[$connection] ?? [];
        $observers[] = $this;
        self::$observers[$connection] = $observers;
        try {
            return $operation();
        } finally {
            $observers = self::$observers[$connection];
            array_pop($observers);
            self::$observers[$connection] = $observers;
        }
    }

    public function restore(): void
    {
        foreach ($this->models as $model) {
            $stored = $this->models[$model];
            foreach (['fields', 'input', 'updates', 'oldvalues'] as $property) {
                if (array_key_exists($property, $stored)) {
                    $model->$property = $stored[$property];
                } else {
                    unset($model->$property);
                }
            }
        }
    }
}
