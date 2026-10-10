<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\PhpIntegerMappingType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver as DriverAttributeDriver;
use Doctrine\Persistence\Mapping\ClassMetadata;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/** Resolve property-owned native storage and generated references for the provider. */
final class AttributeDriver extends DriverAttributeDriver
{
    public function __construct(array $paths, private AbstractPlatform $platform)
    {
        parent::__construct($paths);
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        parent::loadMetadataForClass($className, $metadata);
        $writableClock = false;
        $entity = new ReflectionClass($className);
        $metadata->table['options'] = array_replace(
            $metadata->table['options'] ?? [],
            PlatformOptions::forDeclaration($entity, $this->platform),
        );
        foreach ($entity->getAttributes(SchemaIndex::class) as $attribute) {
            $attribute->newInstance()->addToMetadata($metadata, $this->platform);
        }
        foreach ($entity->getProperties() as $property) {
            $options = PlatformOptions::forDeclaration($property, $this->platform);
            if ($options !== []) {
                if (isset($metadata->fieldMappings[$property->name])) {
                    $column = $metadata->fieldMappings[$property->name];
                    if (array_key_exists('length', $options)) {
                        $length = $options['length'];
                        if ($length !== null && (!is_int($length) || $length <= 0)) {
                            throw new LogicException('Provider field length must be null or a positive integer: ' . $className . '::$' . $property->name);
                        }
                        $column->length = $length;
                        unset($options['length']);
                    }
                    if (array_key_exists('type', $options)) {
                        $type = $options['type'];
                        $propertyType = $property->getType();
                        if (!is_string($type) || !Type::hasType($type)
                            || ($type !== $column->type && (!($propertyType instanceof ReflectionNamedType)
                                || $propertyType->getName() !== 'int'
                                || !(Type::getType($column->type) instanceof PhpIntegerMappingType)
                                || !(Type::getType($type) instanceof PhpIntegerMappingType)))) {
                            throw new LogicException('Provider field type must preserve its declared PHP integer domain: ' . $className . '::$' . $property->name);
                        }
                        $column->type = $type;
                        unset($options['type']);
                    }
                } else {
                    $association = $metadata->associationMappings[$property->name] ?? null;
                    if ($association === null || !$association->isToOneOwningSide()
                        || count($association->joinColumns) !== 1) {
                        throw new LogicException(sprintf(
                            'Provider column options require a scalar field or a single-column owning to-one association: %s::$%s.',
                            $className,
                            $property->name,
                        ));
                    }
                    if (array_key_exists('length', $options) || array_key_exists('type', $options)) {
                        throw new LogicException('Provider field type and length require a scalar field: ' . $className . '::$' . $property->name);
                    }
                    $column = $association->joinColumns[0];
                }
                $column->options = array_replace($column->options ?? [], $options);
            }
            foreach ($property->getAttributes(ReferenceKey::class) as $attribute) {
                $metadata->fieldMappings[$property->getName()]->columnDefinition = $attribute->newInstance()->declaration($this->platform);
            }
            foreach ($property->getAttributes(DiscriminatorKey::class) as $attribute) {
                $field = $metadata->fieldMappings[$property->getName()];
                $field->columnDefinition = $attribute->newInstance()->declaration($this->platform, $metadata, $property->getName());
                // Custom generated DDL bypasses DBAL's ordinary inline comments.
                if ($this->platform->supportsInlineColumnComments() && ($field->options['comment'] ?? '') !== '') {
                    $field->columnDefinition .= ' ' . $this->platform->getInlineColumnCommentSQL($field->options['comment']);
                }
            }
            foreach ($property->getAttributes(NativeTimestamp::class) as $attribute) {
                $timestamp = $attribute->newInstance();
                $field = $metadata->fieldMappings[$property->getName()];
                $field->columnDefinition = $timestamp->declaration($this->platform, $field);
                $writableClock = $writableClock || $timestamp->ownsWritableClock($field);
            }
        }
        if ($writableClock) {
            $metadata->addEntityListener(Events::postPersist, NativeTimestampOutcome::class, 'synchronize');
            $metadata->addEntityListener(Events::postUpdate, NativeTimestampOutcome::class, 'synchronize');
        }
    }
}
