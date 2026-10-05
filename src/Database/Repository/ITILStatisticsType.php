<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;

/** Mapped parent and relationship types shared by statistics projections. */
final class ITILStatisticsType
{
    /**
     * Mapping drivers define immutable provider mapping scopes. Configuration
     * clones share the driver, while each operation keeps its own unit of work.
     * Values contain only class/property strings, never metadata or managers.
     * Replacing a driver naturally invalidates its definitions; weak keys do not
     * extend a custom driver's lifetime.
     *
     * @var \WeakMap<object, array<string, array{definition: array, error: ?array}>>|null
     */
    private static ?\WeakMap $definitions = null;

    public static function definition(EntityManager $em, string $type): array
    {
        $subject = ITILSolution::subjectAssociation($type);
        $class = $em->getClassMetadata(ITILSolution::class)->getAssociationTargetClass($subject);
        $events = $em->getEventManager();
        // Custom metadata listeners may change associations for this manager.
        // They must not reuse definitions from another operation's metadata.
        if ($events->hasListeners(Events::loadClassMetadata) || $events->hasListeners(Events::onClassMetadataNotFound)) {
            $definitions = self::discover($em);
        } else {
            $driver = $em->getConfiguration()->getMetadataDriverImpl();
            self::$definitions ??= new \WeakMap();
            if (!isset(self::$definitions[$driver])) {
                self::$definitions[$driver] = self::discover($em);
            }
            $definitions = self::$definitions[$driver];
        }
        $definition = $definitions[$class] ?? ['definition' => [], 'error' => ['Missing', ITILStatisticsRole::cases()[0]->name]];
        if ($definition['error'] !== null) {
            [$reason, $role] = $definition['error'];
            throw new \LogicException($reason . ' ITIL statistics association: ' . $type . '.' . $role);
        }
        return $definition['definition'];
    }

    /** Derive every reporting tuple in one pass through authoritative mappings. */
    private static function discover(EntityManager $em): array
    {
        $parents = [];
        foreach ($em->getClassMetadata(ITILSolution::class)->associationMappings as $property => $association) {
            if ((new \ReflectionProperty(ITILSolution::class, $property))->getAttributes(DiscriminatedBy::class) !== []) {
                $parents[$association->targetEntity] = true;
            }
        }
        $targets = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide() || !isset($parents[$association->targetEntity])) {
                    continue;
                }
                foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(ITILStatisticsRelation::class) as $attribute) {
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
}
