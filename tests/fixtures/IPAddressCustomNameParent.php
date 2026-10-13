<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use NetworkName as NetworkNameModel;

class IPAddressCustomNameParent extends NetworkNameModel
{
    public static function getTable($classname = null)
    {
        return NetworkNameModel::getTable();
    }
}
