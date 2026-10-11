<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Countable;
use IteratorAggregate;
use Traversable;

/** Immutable event selection; recipient expansion and delivery stay in the mode. */
final readonly class NotificationDeliveryPlan implements IteratorAggregate, Countable
{
    /** @param list<NotificationDelivery> $deliveries */
    public function __construct(private array $deliveries)
    {
    }

    public function getIterator(): Traversable
    {
        yield from $this->deliveries;
    }

    public function count(): int
    {
        return count($this->deliveries);
    }

    public function legacyRows(): array
    {
        return array_map(static fn (NotificationDelivery $delivery): array => $delivery->legacyRow(), $this->deliveries);
    }
}
