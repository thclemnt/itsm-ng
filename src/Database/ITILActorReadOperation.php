<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ITILActorRepository;

/** One loadActors invocation owns its three built-in relationship reads. */
final class ITILActorReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function actors(string $actorClass, int $item): array
    {
        if (!ITILActorRepository::supports($actorClass)) {
            throw new \InvalidArgumentException('Unsupported ITIL actor relation');
        }
        $metadata = $this->metadata($actorClass::getTable());
        $repository = new ITILActorRepository($this->manager);
        $rows = $this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()
            ? $repository->nativeRows($actorClass, $item)
            : $repository->rows($actorClass, $item, $this);
        $actors = [];
        foreach ($rows as $row) {
            $actors[$row['type']][] = $row;
        }
        return $actors;
    }

}
