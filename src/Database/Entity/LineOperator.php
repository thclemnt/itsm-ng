<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use AbstractQuery;
use DateTimeInterface;
use InvalidArgumentException;
use QueryExpression;
use QueryParam;
use Stringable;
use itsmng\Database\Mapping\LegacyInput;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_lineoperators')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_lineoperators_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_lineoperators_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_lineoperators_is_recursive')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_lineoperators_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_lineoperators_date_creation')]
#[SchemaIndex('unicity', ['mcc', 'mnc'], unique: true, postgresqlName: 'glpi_lineoperators_unicity')]
class LineOperator implements LegacyInput
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`mcc`', type: 'integer', nullable: true)]
    public ?int $mcc = null;

    #[ORM\Column(name: '`mnc`', type: 'integer', nullable: true)]
    public ?int $mnc = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_lineoperators_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $is_recursive = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    /** Parse the declared signed INTEGER codes without accepting query-shaped model data. */
    public static function normalizeCode(mixed $value): int|false|null
    {
        if ($value instanceof AbstractQuery || $value instanceof QueryExpression || $value instanceof QueryParam) {
            return false;
        }
        if ($value instanceof Stringable) {
            $value = (string)$value;
        }
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return (int)$value;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) && $value >= -2147483648 && $value <= 2147483647
                && floor((float)$value) === (float)$value ? (int)$value : false;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if (strtolower($value) === 'null') {
            return null;
        }
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^[+-]?\d+(?:\.0+)?$/D', $value)) {
            return false;
        }
        $code = (int)$value;
        return $code >= -2147483648 && $code <= 2147483647 ? $code : false;
    }

    public function normalizeInput(array $values): array
    {
        foreach (['mcc', 'mnc'] as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $code = self::normalizeCode($values[$field]);
            if ($code === false) {
                throw new InvalidArgumentException('Invalid integer value for ' . $field . '.');
            }
            $values[$field] = $code;
        }
        return $values;
    }

    public function legacyChanges(array $columns): array
    {
        return $columns;
    }
}
