<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Persisted authentication kinds shared by the model and its Doctrine mapping. */
enum AuthenticationType: int
{
    case Pending = 0;
    case Local = 1;
    case Mail = 2;
    case Ldap = 3;
    case External = 4;
    case Cas = 5;
    case X509 = 6;
    case Api = 7;
    case Cookie = 8;
}
