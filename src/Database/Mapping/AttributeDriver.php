<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Persistence\Mapping\ClassMetadata;

/** Resolve generated reference expressions through the provider's identifier quoting. */
final class AttributeDriver extends \Doctrine\ORM\Mapping\Driver\AttributeDriver
{
    public function __construct(array $paths, private AbstractPlatform $platform)
    {
        parent::__construct($paths);
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        parent::loadMetadataForClass($className, $metadata);
        foreach ((new \ReflectionClass($className))->getProperties() as $property) {
            foreach ($property->getAttributes(ReferenceKey::class) as $attribute) {
                $metadata->fieldMappings[$property->getName()]->columnDefinition = $attribute->newInstance()->declaration($this->platform);
            }
        }
    }
}
