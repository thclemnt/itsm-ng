<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** The verified credential provider is independent of the stored account source. */
enum VerifiedLoginProvider
{
    case LocalPassword;
    case ApiToken;
    case RememberCookie;
}
