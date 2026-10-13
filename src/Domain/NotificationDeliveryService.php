<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DBAdapter;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationRepository;
use itsmng\Database\Repository\RecordRepository;

/** Build an event plan before public event callbacks may change rules or bindings. */
final class NotificationDeliveryService
{
    public function __construct(private DBAdapter $database)
    {
    }

    public function plan(string $event, string $itemtype, array $entityScope, array $enabledModes): NotificationDeliveryPlan
    {
        $em = Orm::create($this->database);
        $deliveries = [];
        $records = new RecordRepository($em);
        foreach ((new NotificationRepository($em))->firing($event, $itemtype, $entityScope, $enabledModes) as $notification) {
            $row = $records->toRow($notification);
            foreach ($notification->templateBindings as $binding) {
                $owner = $records->toRow($binding);
                $deliveries[] = new NotificationDelivery($row, $binding->id, $binding->mode, $owner['notificationtemplates_id']);
            }
            if (!$enabledModes && $notification->templateBindings->isEmpty()) {
                $deliveries[] = new NotificationDelivery($row, null, null, null);
            }
        }
        return new NotificationDeliveryPlan($deliveries);
    }

    /** Compatibility projections for authorized public relation screens. */
    public function bindingsForNotification(int $notification): array
    {
        return Orm::read($this->database, fn (EntityManager $em): array =>
            $this->bindingRows($em, (new NotificationRepository($em))->bindingsForNotification($notification)));
    }

    public function bindingsForTemplate(int $template): array
    {
        return Orm::read($this->database, fn (EntityManager $em): array =>
            $this->bindingRows($em, (new NotificationRepository($em))->bindingsForTemplate($template)));
    }

    private function bindingRows(EntityManager $em, array $bindings): array
    {
        $records = new RecordRepository($em);
        return array_map($records->toRow(...), $bindings);
    }
}
