<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;

/** Align only property-owned writable clocks after their successful native readback. */
final class NativeTimestampOutcome
{
    public function synchronize(object $entity, PostPersistEventArgs|PostUpdateEventArgs $event): void
    {
        $em = $event->getObjectManager();
        $metadata = $em->getClassMetadata($entity::class);
        foreach ($metadata->fieldMappings as $property => $field) {
            foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(NativeTimestamp::class) as $attribute) {
                if ($attribute->newInstance()->ownsWritableClock($field)) {
                    $em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($entity), $property, $metadata->getFieldValue($entity, $property));
                }
            }
        }
        // This is the result of this SQL write, not a claim that an outer
        // caller-owned transaction has committed or can no longer roll back.
    }
}
