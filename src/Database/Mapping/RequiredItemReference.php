<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;

/** Required discriminator selection derived from its owning association attributes. */
trait RequiredItemReference
{
    use ItemReference;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

}
