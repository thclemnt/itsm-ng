<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_rssfeeds')]
class RSSFeed
{
    /** @var Collection<int, RSSFeedUser> */
    #[ORM\OneToMany(targetEntity: RSSFeedUser::class, mappedBy: 'rssfeeds')]
    public Collection $audienceUsers;

    /** @var Collection<int, GroupRSSFeed> */
    #[ORM\OneToMany(targetEntity: GroupRSSFeed::class, mappedBy: 'rssfeeds')]
    public Collection $audienceGroups;

    /** @var Collection<int, ProfileRSSFeed> */
    #[ORM\OneToMany(targetEntity: ProfileRSSFeed::class, mappedBy: 'rssfeeds')]
    public Collection $audienceProfiles;

    /** @var Collection<int, EntityRSSFeed> */
    #[ORM\OneToMany(targetEntity: EntityRSSFeed::class, mappedBy: 'rssfeeds')]
    public Collection $audienceEntities;

    public function __construct()
    {
        $this->audienceUsers = new ArrayCollection();
        $this->audienceGroups = new ArrayCollection();
        $this->audienceProfiles = new ArrayCollection();
        $this->audienceEntities = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`url`', type: 'text', nullable: true)]
    public ?string $url = null;

    #[ORM\Column(name: '`refresh_rate`', type: 'integer', nullable: false, options: ['default' => '86400'])]
    public int $refresh_rate = 86400;

    #[ORM\Column(name: '`max_items`', type: 'integer', nullable: false, options: ['default' => '20'])]
    public int $max_items = 20;

    #[ORM\Column(name: '`have_error`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_error = false;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
