<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Existing Computer storage, reached through the ordinary plugin model route. */
class PluginGraphiccardOwnershipParent extends Computer
{
    public static int $loads = 0;

    public function post_getFromDB()
    {
        parent::post_getFromDB();
        ++self::$loads;
    }
}
