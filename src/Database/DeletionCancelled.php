<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use RuntimeException;

/** Internal propagation across legacy cleanup methods which discard return values. */
final class DeletionCancelled extends RuntimeException
{
}
