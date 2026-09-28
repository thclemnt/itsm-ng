<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_authldaps')]
class AuthLDAP
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`host`', type: 'string', length: 255, nullable: true)]
    public ?string $host = null;

    #[ORM\Column(name: '`basedn`', type: 'string', length: 255, nullable: true)]
    public ?string $basedn = null;

    #[ORM\Column(name: '`rootdn`', type: 'string', length: 255, nullable: true)]
    public ?string $rootdn = null;

    #[ORM\Column(name: '`port`', type: 'integer', nullable: false, options: ['default' => '389'])]
    public int $port = 389;

    #[ORM\Column(name: '`condition`', type: 'text', nullable: true)]
    public ?string $condition = null;

    #[ORM\Column(name: '`login_field`', type: 'string', length: 255, nullable: true, options: ['default' => 'uid'])]
    public ?string $login_field = 'uid';

    #[ORM\Column(name: '`sync_field`', type: 'string', length: 255, nullable: true)]
    public ?string $sync_field = null;

    #[ORM\Column(name: '`use_tls`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $use_tls = false;

    #[ORM\Column(name: '`group_field`', type: 'string', length: 255, nullable: true)]
    public ?string $group_field = null;

    #[ORM\Column(name: '`group_condition`', type: 'text', nullable: true)]
    public ?string $group_condition = null;

    #[ORM\Column(name: '`group_search_type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $group_search_type = 0;

    #[ORM\Column(name: '`group_member_field`', type: 'string', length: 255, nullable: true)]
    public ?string $group_member_field = null;

    #[ORM\Column(name: '`email1_field`', type: 'string', length: 255, nullable: true)]
    public ?string $email1_field = null;

    #[ORM\Column(name: '`realname_field`', type: 'string', length: 255, nullable: true)]
    public ?string $realname_field = null;

    #[ORM\Column(name: '`firstname_field`', type: 'string', length: 255, nullable: true)]
    public ?string $firstname_field = null;

    #[ORM\Column(name: '`phone_field`', type: 'string', length: 255, nullable: true)]
    public ?string $phone_field = null;

    #[ORM\Column(name: '`phone2_field`', type: 'string', length: 255, nullable: true)]
    public ?string $phone2_field = null;

    #[ORM\Column(name: '`mobile_field`', type: 'string', length: 255, nullable: true)]
    public ?string $mobile_field = null;

    #[ORM\Column(name: '`comment_field`', type: 'string', length: 255, nullable: true)]
    public ?string $comment_field = null;

    #[ORM\Column(name: '`use_dn`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $use_dn = true;

    #[ORM\Column(name: '`time_offset`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $time_offset = 0;

    #[ORM\Column(name: '`deref_option`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $deref_option = 0;

    #[ORM\Column(name: '`title_field`', type: 'string', length: 255, nullable: true)]
    public ?string $title_field = null;

    #[ORM\Column(name: '`category_field`', type: 'string', length: 255, nullable: true)]
    public ?string $category_field = null;

    #[ORM\Column(name: '`language_field`', type: 'string', length: 255, nullable: true)]
    public ?string $language_field = null;

    #[ORM\Column(name: '`entity_field`', type: 'string', length: 255, nullable: true)]
    public ?string $entity_field = null;

    #[ORM\Column(name: '`entity_condition`', type: 'text', nullable: true)]
    public ?string $entity_condition = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_default = false;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`rootdn_passwd`', type: 'string', length: 255, nullable: true)]
    public ?string $rootdn_passwd = null;

    #[ORM\Column(name: '`registration_number_field`', type: 'string', length: 255, nullable: true)]
    public ?string $registration_number_field = null;

    #[ORM\Column(name: '`email2_field`', type: 'string', length: 255, nullable: true)]
    public ?string $email2_field = null;

    #[ORM\Column(name: '`email3_field`', type: 'string', length: 255, nullable: true)]
    public ?string $email3_field = null;

    #[ORM\Column(name: '`email4_field`', type: 'string', length: 255, nullable: true)]
    public ?string $email4_field = null;

    #[ORM\Column(name: '`location_field`', type: 'string', length: 255, nullable: true)]
    public ?string $location_field = null;

    #[ORM\Column(name: '`responsible_field`', type: 'string', length: 255, nullable: true)]
    public ?string $responsible_field = null;

    #[ORM\Column(name: '`pagesize`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pagesize = 0;

    #[ORM\Column(name: '`ldap_maxlimit`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ldap_maxlimit = 0;

    #[ORM\Column(name: '`can_support_pagesize`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $can_support_pagesize = false;

    #[ORM\Column(name: '`picture_field`', type: 'string', length: 255, nullable: true)]
    public ?string $picture_field = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`inventory_domain`', type: 'string', length: 255, nullable: true)]
    public ?string $inventory_domain = null;
}
