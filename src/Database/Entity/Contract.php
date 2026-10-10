<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use InvalidArgumentException;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Repository\ContractRepository;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'glpi_contracts')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('begin_date', ['begin_date'], postgresqlName: 'glpi_contracts_begin_date')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_contracts_name')]
#[SchemaIndex('contracttypes_id', ['contracttypes_id'], postgresqlName: 'glpi_contracts_contracttypes_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_contracts_entities_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_contracts_is_deleted')]
#[SchemaIndex('use_monday', ['use_monday'], postgresqlName: 'glpi_contracts_use_monday')]
#[SchemaIndex('use_saturday', ['use_saturday'], postgresqlName: 'glpi_contracts_use_saturday')]
#[SchemaIndex('alert', ['alert'], postgresqlName: 'glpi_contracts_alert')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_contracts_states_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_contracts_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_contracts_date_creation')]
class Contract
{
    /** Contract months clamp to the anniversary day in the target month. */
    public function endsOn(): ?DateTimeImmutable
    {
        return $this->calendarDeadline($this->duration);
    }

    public function noticeStartsOn(): ?DateTimeImmutable
    {
        return $this->calendarDeadline($this->duration - $this->notice);
    }

    /** Renewal periods stay anchored to the original contract anniversary. */
    public function periodEndsOn(int $period, bool $notice = false): ?DateTimeImmutable
    {
        if ($this->periodicity <= 0 || $period < 0) {
            return null;
        }
        $initial = $this->duration !== 0 ? $this->duration : $this->periodicity;
        return $this->calendarDeadline($initial + $period * $this->periodicity - ($notice ? $this->notice : 0));
    }

    public function renewedDeadline(int $renewal, bool $notice = false): ?DateTimeImmutable
    {
        if ($renewal < 0) {
            throw new InvalidArgumentException('Contract renewal index must not be negative');
        }
        return $this->calendarDeadline(($renewal + 1) * $this->duration - ($notice ? $this->notice : 0));
    }

    private function calendarDeadline(int $months): ?DateTimeImmutable
    {
        if ($this->begin_date === null) {
            return null;
        }
        $start = DateTimeImmutable::createFromInterface($this->begin_date)->setTime(0, 0);
        $month = $start->modify('first day of this month')->modify(sprintf('%+d months', $months));
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$start->format('d'), (int)$month->format('t')));
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`num`', type: 'string', length: 255, nullable: true)]
    public ?string $num = null;

    #[ORM\ManyToOne(targetEntity: ContractType::class)]
    #[ORM\JoinColumn(name: 'contracttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_contracttypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ContractType $contracttypes = null;

    #[ORM\Column(name: '`begin_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $duration = 0;

    #[ORM\Column(name: '`notice`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $notice = 0;

    #[ORM\Column(name: '`periodicity`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $periodicity = 0;

    #[ORM\Column(name: '`billing`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $billing = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`accounting_number`', type: 'string', length: 255, nullable: true)]
    public ?string $accounting_number = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`week_begin_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $week_begin_hour = '00:00:00';

    #[ORM\Column(name: '`week_end_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $week_end_hour = '00:00:00';

    #[ORM\Column(name: '`saturday_begin_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $saturday_begin_hour = '00:00:00';

    #[ORM\Column(name: '`saturday_end_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $saturday_end_hour = '00:00:00';

    #[ORM\Column(name: '`use_saturday`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $use_saturday = false;

    #[ORM\Column(name: '`monday_begin_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $monday_begin_hour = '00:00:00';

    #[ORM\Column(name: '`monday_end_hour`', type: 'itsm_clock_time', nullable: false, options: ['default' => '00:00:00'])]
    public string $monday_end_hour = '00:00:00';

    #[ORM\Column(name: '`use_monday`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $use_monday = false;

    #[ORM\Column(name: '`max_links_allowed`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_links_allowed = 0;

    #[ORM\Column(name: '`alert`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $alert = 0;

    #[ORM\Column(name: '`renewal`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $renewal = 0;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
