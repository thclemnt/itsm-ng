<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Persistence\Mapping\ClassMetadata;

/** Resolve property-owned native storage and generated references for the provider. */
final class AttributeDriver extends \Doctrine\ORM\Mapping\Driver\AttributeDriver
{
    public function __construct(array $paths, private AbstractPlatform $platform)
    {
        parent::__construct($paths);
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        parent::loadMetadataForClass($className, $metadata);
        $writableClock = false;
        foreach ((new \ReflectionClass($className))->getProperties() as $property) {
            foreach ($property->getAttributes(ReferenceKey::class) as $attribute) {
                $metadata->fieldMappings[$property->getName()]->columnDefinition = $attribute->newInstance()->declaration($this->platform);
            }
            foreach ($property->getAttributes(DiscriminatorKey::class) as $attribute) {
                $metadata->fieldMappings[$property->getName()]->columnDefinition = $attribute->newInstance()->declaration($this->platform, $metadata, $property->getName());
            }
            foreach ($property->getAttributes(NativeTimestamp::class) as $attribute) {
                $timestamp = $attribute->newInstance();
                $field = $metadata->fieldMappings[$property->getName()];
                $field->columnDefinition = $timestamp->declaration($this->platform, $field);
                $writableClock = $writableClock || $timestamp->ownsWritableClock($field);
            }
        }
        if ($writableClock) {
            $metadata->addEntityListener(\Doctrine\ORM\Events::postPersist, NativeTimestampOutcome::class, 'synchronize');
            $metadata->addEntityListener(\Doctrine\ORM\Events::postUpdate, NativeTimestampOutcome::class, 'synchronize');
        }
    }
}
