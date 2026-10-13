<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** A real custom note parent uses Computer storage without becoming the exact Computer kind. */
class PluginNotepadOwnershipParent extends Computer
{
    public static function getTable($classname = null)
    {
        return Computer::getTable();
    }
}
