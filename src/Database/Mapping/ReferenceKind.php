<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Legacy meanings are explicit; nullable does not imply an empty zero. */
enum ReferenceKind: string
{
    case EmptySelection = 'empty_selection';
    case RootEntity = 'root_entity';
    case Audience = 'audience';
    case GlobalScope = 'global_scope';
    case Inherited = 'inherited';
    /** Only the real entity root has no parent; zero remains a selected root ID. */
    case RootParent = 'root_parent';
}
