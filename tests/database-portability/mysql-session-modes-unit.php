<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Pure policy contract. Loading DBAL interfaces opens no database connection.
if (!interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}
require_once dirname(__DIR__, 2) . '/src/Database/MySQLConnection.php';

use itsmng\Database\MySQLConnection;

$assertions = 0;
foreach ([
    '' => 'STRICT_ALL_TABLES',
    'NO_ENGINE_SUBSTITUTION' => 'NO_ENGINE_SUBSTITUTION,STRICT_ALL_TABLES',
    'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER' => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,STRICT_ALL_TABLES',
    'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE' => 'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,STRICT_ALL_TABLES',
    'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES' => 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES',
    'strict_all_tables' => 'strict_all_tables',
    ' ,STRICT_TRANS_TABLES, ' => 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES',
] as $configured => $expected) {
    if (MySQLConnection::strictModes($configured) !== $expected || MySQLConnection::strictModes($expected) !== $expected) {
        throw new RuntimeException('Configured modes must survive strict initialization and its repeat: ' . $configured);
    }
    $assertions += 2;
}
echo "Pure MySQL session policy: $assertions assertions passed without connecting.\n";
