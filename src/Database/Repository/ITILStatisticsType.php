<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;

/** Mapped parent and relationship types shared by statistics projections. */
final class ITILStatisticsType
{
    public static function definition(EntityManager $em, string $type): array
    {
        $subject = ITILSolution::subjectAssociation($type);
        $class = $em->getClassMetadata(ITILSolution::class)->getAssociationTargetClass($subject);
        $relations = [];
        $parent = null;
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide() || $association->targetEntity !== $class) {
                    continue;
                }
                foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(ITILStatisticsRelation::class) as $attribute) {
                    $role = $attribute->newInstance()->role->name;
                    if (isset($relations[$role]) || ($parent !== null && $parent !== $property)) {
                        throw new \LogicException('Ambiguous ITIL statistics association: ' . $type . '.' . $role);
                    }
                    $parent = $property;
                    $relations[$role] = $metadata->name;
                }
            }
        }
        $definition = [$class, $parent];
        foreach (ITILStatisticsRole::cases() as $role) {
            $definition[] = $relations[$role->name] ?? throw new \LogicException('Missing ITIL statistics association: ' . $type . '.' . $role->name);
        }
        return $definition;
    }
}
