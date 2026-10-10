<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevelcriterias')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('olalevels_id', ['olalevels_id'], postgresqlName: 'glpi_olalevelcriterias_olalevels_id')]
#[SchemaIndex('condition', ['condition'], postgresqlName: 'glpi_olalevelcriterias_condition')]
class OlaLevelCriteria
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OlaLevel::class)]
    #[ORM\JoinColumn(name: 'olalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_olalevelcriterias_olalevels_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?OlaLevel $olalevels = null;

    #[ORM\Column(name: '`criteria`', type: 'string', length: 255, nullable: true)]
    public ?string $criteria = null;

    #[ORM\Column(name: '`condition`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see define.php PATTERN_* and REGEX_* constant'])]
    public int $condition = 0;

    #[ORM\Column(name: '`pattern`', type: 'string', length: 255, nullable: true)]
    public ?string $pattern = null;
}
