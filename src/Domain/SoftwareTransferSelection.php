<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** The selected graph and any entity destination required by this command. */
final readonly class SoftwareTransferSelection
{
    private function __construct(public array $items, public ?int $destination)
    {
    }

    /** Retarget or remove selected links without requesting an entity move. */
    public static function selected(array $items): self
    {
        return new self($items, null);
    }

    /** A move/copy command must reserve its actual destination as well. */
    public static function toEntity(array $items, int $destination): self
    {
        return new self($items, $destination);
    }

    public function destinationRoots(): array
    {
        if ($this->destination === null) {
            return [];
        }
        if ($this->destination < 0) {
            throw new SoftwareAssignmentCancelled('Transfer destination does not exist.');
        }
        return [$this->destination];
    }
}
