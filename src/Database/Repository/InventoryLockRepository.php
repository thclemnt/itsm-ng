<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;

/** Inventory locks are dynamic, deleted assignments; restoring them remains a model action. */
final class InventoryLockRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** The selected asset kind can differ from the model that owns the locked assignment. */
    public static function modelType(string $kind): string
    {
        return match ($kind) {
            'Monitor', 'Peripheral', 'Printer', 'Phone' => 'Computer_Item',
            'SoftwareVersion' => 'Item_SoftwareVersion',
            'SoftwareLicense' => 'Item_SoftwareLicense',
            default => $kind,
        };
    }

    /** Shared selection for the lock form and bulk unlock, including typed network ancestry. */
    public function forItem(string $kind, string $sourceType, int $source): array
    {
        $class = match ($kind) {
            'Monitor', 'Peripheral', 'Printer', 'Phone' => Entity\ComputerItem::class,
            'Item_Disk' => Entity\ItemDisk::class,
            'ComputerVirtualMachine' => Entity\ComputerVirtualMachine::class,
            'SoftwareVersion' => Entity\ItemSoftwareVersion::class,
            'SoftwareLicense' => Entity\ItemSoftwareLicense::class,
            'NetworkPort' => Entity\NetworkPort::class,
            'NetworkName' => Entity\NetworkName::class,
            'IPAddress' => Entity\IPAddress::class,
            default => $this->componentClass($kind),
        };
        if ($class === null || $source <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r.id AS id')->from($class, 'r')
            ->where('r.is_dynamic = :locked')->andWhere('r.is_deleted = :locked')
            ->setParameter('locked', true, Types::BOOLEAN)
            ->setParameter('source', $source, Types::INTEGER);
        if ($class === Entity\ComputerItem::class || $class === Entity\ComputerVirtualMachine::class) {
            if ($sourceType !== 'Computer') {
                return [];
            }
            $query->andWhere('IDENTITY(r.computers) = :source');
            if ($class === Entity\ComputerItem::class) {
                $query->addSelect('r.items_id AS items_id')->andWhere('r.itemtype = :type')->setParameter('type', $kind, Types::STRING);
            } else {
                $query->addSelect('r.name AS name');
            }
        } elseif ($class === Entity\NetworkName::class || $class === Entity\IPAddress::class) {
            if ($class === Entity\IPAddress::class) {
                $query->innerJoin(Entity\NetworkName::class, 'n', 'WITH', 'r.items_id = n.id AND r.itemtype = :nameType')
                    ->setParameter('nameType', 'NetworkName', Types::STRING);
                $name = 'n';
            } else {
                $name = 'r';
            }
            $query->innerJoin(Entity\NetworkPort::class, 'p', 'WITH', $name . '.items_id = p.id AND ' . $name . '.itemtype = :portType')
                ->andWhere('p.items_id = :source')->andWhere('p.itemtype = :type')
                ->setParameter('portType', 'NetworkPort', Types::STRING)->setParameter('type', $sourceType, Types::STRING);
        } else {
            if ($class === Entity\ItemSoftwareVersion::class || $class === Entity\ItemSoftwareLicense::class) {
                $query->andWhere('IDENTITY(r.' . $class::referenceAssociation($sourceType) . ') = :source');
                $association = $class === Entity\ItemSoftwareVersion::class ? 'softwareversions' : 'softwarelicenses';
                $query->leftJoin('r.' . $association, 'v')->leftJoin('v.softwares', 's')
                    ->addSelect('v.name AS version', 's.name AS software');
            } else {
                $query->andWhere('r.items_id = :source')->andWhere('r.itemtype = :type')->setParameter('type', $sourceType, Types::STRING);
            }
            if ($class === Entity\ItemDisk::class) {
                $query->addSelect('r.name AS name');
            } elseif (!in_array($class, [Entity\NetworkPort::class, Entity\ItemSoftwareVersion::class, Entity\ItemSoftwareLicense::class], true)) {
                // The component model identifies its device field. The actual join comes
                // from the owning Doctrine association, never a guessed target table.
                $column = $kind::getDeviceForeignKey();
                $metadata = $this->em->getClassMetadata($class);
                $association = null;
                foreach ($metadata->associationMappings as $field => $mapping) {
                    if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $column) {
                        $association = $field;
                        break;
                    }
                }
                if ($association === null) {
                    throw new \LogicException('Inventory component requires a mapped device association: ' . $kind);
                }
                $query->leftJoin('r.' . $association, 'd')->addSelect('d.designation AS name');
            }
        }
        return $query->orderBy('r.id')->getQuery()->getArrayResult();
    }

    private function componentClass(string $kind): ?string
    {
        if (!in_array($kind, \Item_Devices::getDeviceTypes(), true)) {
            return null;
        }
        $class = EntityRegistry::tables()[$kind::getTable()] ?? null;
        if ($class === null) {
            throw new \LogicException('Inventory component requires a registered Doctrine entity: ' . $kind);
        }
        return $class;
    }
}
