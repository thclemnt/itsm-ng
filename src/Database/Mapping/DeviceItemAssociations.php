<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;

/** Contractable installed components, each with its own owning association. */
trait DeviceItemAssociations
{
    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceMotherboard::class)]
    #[ORM\JoinColumn(name: 'items_devicemotherboards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceMotherboard'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceMotherboard $deviceMotherboard = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceFirmware::class)]
    #[ORM\JoinColumn(name: 'items_devicefirmwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceFirmware'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceFirmware $deviceFirmware = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceProcessor::class)]
    #[ORM\JoinColumn(name: 'items_deviceprocessors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceProcessor'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceProcessor $deviceProcessor = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceMemory::class)]
    #[ORM\JoinColumn(name: 'items_devicememories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceMemory'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceMemory $deviceMemory = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceHardDrive::class)]
    #[ORM\JoinColumn(name: 'items_deviceharddrives_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceHardDrive'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceHardDrive $deviceHardDrive = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceNetworkCard::class)]
    #[ORM\JoinColumn(name: 'items_devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceNetworkCard'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceNetworkCard $deviceNetworkCard = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceDrive::class)]
    #[ORM\JoinColumn(name: 'items_devicedrives_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceDrive'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceDrive $deviceDrive = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceBattery::class)]
    #[ORM\JoinColumn(name: 'items_devicebatteries_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceBattery'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceBattery $deviceBattery = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceGraphicCard::class)]
    #[ORM\JoinColumn(name: 'items_devicegraphiccards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceGraphicCard'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceGraphicCard $deviceGraphicCard = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceSoundCard::class)]
    #[ORM\JoinColumn(name: 'items_devicesoundcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceSoundCard'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceSoundCard $deviceSoundCard = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceControl::class)]
    #[ORM\JoinColumn(name: 'items_devicecontrols_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceControl'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceControl $deviceControl = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDevicePci::class)]
    #[ORM\JoinColumn(name: 'items_devicepcis_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DevicePci'])]
    #[ApplicationManaged]
    public ?Entity\ItemDevicePci $devicePci = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceCase::class)]
    #[ORM\JoinColumn(name: 'items_devicecases_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceCase'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceCase $deviceCase = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDevicePowerSupply::class)]
    #[ORM\JoinColumn(name: 'items_devicepowersupplies_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DevicePowerSupply'])]
    #[ApplicationManaged]
    public ?Entity\ItemDevicePowerSupply $devicePowerSupply = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceGeneric::class)]
    #[ORM\JoinColumn(name: 'items_devicegenerics_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceGeneric'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceGeneric $deviceGeneric = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceSimcard::class)]
    #[ORM\JoinColumn(name: 'items_devicesimcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceSimcard'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceSimcard $deviceSimcard = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceSensor::class)]
    #[ORM\JoinColumn(name: 'items_devicesensors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceSensor'])]
    #[ApplicationManaged]
    public ?Entity\ItemDeviceSensor $deviceSensor = null;

}
