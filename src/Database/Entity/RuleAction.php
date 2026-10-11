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
#[ORM\Table(name: 'glpi_ruleactions')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('rules_id', ['rules_id'], postgresqlName: 'glpi_ruleactions_rules_id')]
#[SchemaIndex('field_value', ['field', 'value'], postgresqlName: 'glpi_ruleactions_field_value', prefixLengths: [50, 50])]
class RuleAction
{
    #[ORM\ManyToOne(targetEntity: Rule::class)]
    #[ORM\JoinColumn(name: 'rules_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ruleactions_rules_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Rule $rules = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`action_type`', type: 'string', length: 255, nullable: true, options: ['comment' => 'VALUE IN (assign, regex_result, append_regex_result, affectbyip, affectbyfqdn, affectbymac)'])]
    public ?string $action_type = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 255, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    public ?string $value = null;
}
