<?php

// SPDX-License-Identifier: GPL-2.0-or-later

class PluginRecursionOwner extends CommonTreeDropdown
{
}

class PluginRecursionLink extends CommonDBTM
{
    public static array $relations = [];
    public static array $lifecycleUpdates = [];
    public static bool $refuseUpdate = false;

    public function prepareInputForUpdate($input)
    {
        self::$lifecycleUpdates[] = $input;
        return self::$refuseUpdate ? false : parent::prepareInputForUpdate($input);
    }
}

function plugin_recursion_getDatabaseRelations(): array
{
    return PluginRecursionLink::$relations;
}

class PluginRecursionDropdown extends CommonDropdown
{
    public static int $additions = 0;

    public function post_addItem()
    {
        ++self::$additions;
        parent::post_addItem();
    }
}

class PluginRecursionPublicLink extends PluginRecursionLink
{
    public static function getIndexName()
    {
        return 'public_id';
    }
}
