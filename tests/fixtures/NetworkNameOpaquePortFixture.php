<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use NetworkPort as LegacyNetworkPort;

/** A loadable custom parent uses the existing plugin-style item factory and real port rights. */
class NetworkNameOpaquePortFixture extends LegacyNetworkPort
{
    public static function getTable($classname = null)
    {
        return LegacyNetworkPort::getTable();
    }
}
