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
#[ORM\Table(name: 'glpi_slalevelcriterias')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('slalevels_id', ['slalevels_id'], postgresqlName: 'glpi_slalevelcriterias_slalevels_id')]
#[SchemaIndex('condition', ['condition'], postgresqlName: 'glpi_slalevelcriterias_condition')]
class SlaLevelCriteria
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SlaLevel::class)]
    #[ORM\JoinColumn(name: 'slalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_slalevelcriterias_slalevels_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?SlaLevel $slalevels = null;

    #[ORM\Column(name: '`criteria`', type: 'string', length: 255, nullable: true)]
    public ?string $criteria = null;

    #[ORM\Column(name: '`condition`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see define.php PATTERN_* and REGEX_* constant'])]
    public int $condition = 0;

    #[ORM\Column(name: '`pattern`', type: 'string', length: 255, nullable: true)]
    public ?string $pattern = null;
}
