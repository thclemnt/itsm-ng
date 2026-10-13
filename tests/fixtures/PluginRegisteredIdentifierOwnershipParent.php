<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Real global factory parent for opaque registered-identifier lifecycle tests. */
class PluginRegisteredIdentifierOwnershipParent extends DeviceNetworkCard
{
    public static ?Closure $tableCallback = null;

    public static function getTable($classname = null)
    {
        $callback = self::$tableCallback;
        self::$tableCallback = null;
        $callback?->__invoke();
        return DeviceNetworkCard::getTable();
    }
}
