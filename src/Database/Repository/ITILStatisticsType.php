<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/** Mapped parent and relationship types shared by statistics projections. */
final class ITILStatisticsType
{
    public static function definition(EntityManager $em, string $type): array
    {
        $subject = ITILSolution::subjectAssociation($type);
        $class = $em->getClassMetadata(ITILSolution::class)->getAssociationTargetClass($subject);
        // Supplied managers may edit loaded associations without changing drivers or listeners.
        $definitions = self::discover($em);
        $definition = $definitions[$class] ?? ['definition' => [], 'error' => ['Missing', ITILStatisticsRole::cases()[0]->name]];
        if ($definition['error'] !== null) {
            [$reason, $role] = $definition['error'];
            throw new LogicException($reason . ' ITIL statistics association: ' . $type . '.' . $role);
        }
        return $definition['definition'];
    }

    /** Derive every reporting tuple in one pass through authoritative mappings. */
    private static function discover(EntityManager $em): array
    {
        $parents = [];
        foreach ($em->getClassMetadata(ITILSolution::class)->associationMappings as $property => $association) {
            if ((new ReflectionProperty(ITILSolution::class, $property))->getAttributes(DiscriminatedBy::class) !== []) {
                $parents[$association->targetEntity] = true;
            }
        }
        $targets = [];
        foreach (self::reportingMetadata($em) as $metadata) {
            foreach ($metadata->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide() || !isset($parents[$association->targetEntity])) {
                    continue;
                }
                foreach ((new ReflectionProperty($metadata->name, $property))->getAttributes(ITILStatisticsRelation::class) as $attribute) {
                    $role = $attribute->newInstance()->role->name;
                    $target = $association->targetEntity;
                    $tuple = $targets[$target] ?? ['parent' => $property, 'roles' => [], 'error' => null];
                    if ($tuple['error'] === null) {
                        if (isset($tuple['roles'][$role]) || $tuple['parent'] !== $property) {
                            $tuple['error'] = ['Ambiguous', $role];
                        } else {
                            $tuple['roles'][$role] = $metadata->name;
                        }
                    }
                    $targets[$target] = $tuple;
                }
            }
        }
        $definitions = [];
        foreach ($targets as $class => $tuple) {
            $definition = [$class, $tuple['parent']];
            $error = $tuple['error'];
            foreach (ITILStatisticsRole::cases() as $role) {
                if (!isset($tuple['roles'][$role->name])) {
                    $error ??= ['Missing', $role->name];
                } else {
                    $definition[] = $tuple['roles'][$role->name];
                }
            }
            $definitions[$class] = ['definition' => $definition, 'error' => $error];
        }
        return $definitions;
    }

    /** Hydrate only reporting declarations for the known property-attribute driver. */
    private static function reportingMetadata(EntityManager $em): iterable
    {
        $driver = $em->getConfiguration()->getMetadataDriverImpl();
        $events = $em->getEventManager();
        if (!$driver instanceof AttributeDriver
            || $events->hasListeners(Events::loadClassMetadata)
            || $events->hasListeners(Events::onClassMetadataNotFound)) {
            yield from $em->getMetadataFactory()->getAllMetadata();
            return;
        }
        // The driver owns visibility and ordering, including mapped superclasses.
        // Reflection selects candidates only; actual ORM associations remain the
        // authority for reporting roles, parent targets and duplicate diagnostics.
        foreach ($driver->getAllClassNames() as $class) {
            if (self::hasReportingProperty($class)) {
                yield $em->getClassMetadata($class);
            }
        }
    }

    /** Loaded property declarations are immutable; driver visibility and ORM associations are not. */
    private static function hasReportingProperty(string $class): bool
    {
        static $candidates = [];
        if (isset($candidates[$class])) {
            return $candidates[$class];
        }
        $reflection = new ReflectionClass($class);
        do {
            foreach ($reflection->getProperties() as $property) {
                if ($property->getAttributes(ITILStatisticsRelation::class) !== []) {
                    return $candidates[$class] = true;
                }
            }
            // Include inherited private declarations in candidate selection.
        } while ($reflection = $reflection->getParentClass());
        return $candidates[$class] = false;
    }
}
