<?php

// SPDX-License-Identifier: GPL-2.0-or-later

class PluginRecursionOwner extends CommonTreeDropdown
{
}

class PluginRecursionLink extends CommonDBTM
{
    public static array $relations = [];
}

function plugin_recursion_getDatabaseRelations(): array
{
    return PluginRecursionLink::$relations;
}
