<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

/** The engine prepares options; the provider plans and retrieves matching rows. */
interface SearchProviderInterface
{
    public static function constructSQL(array &$data);

    public static function constructData(array &$data, $onlycount = false);
}
