<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ValidationRequest;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\UserReferenceAction;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changevalidations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_changevalidations_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_changevalidations_is_recursive')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_changevalidations_users_id')]
#[SchemaIndex('users_id_validate', ['users_id_validate'], postgresqlName: 'glpi_changevalidations_users_id_validate')]
#[SchemaIndex('changes_id', ['changes_id'], postgresqlName: 'glpi_changevalidations_changes_id')]
#[SchemaIndex('submission_date', ['submission_date'], postgresqlName: 'glpi_changevalidations_submission_date')]
#[SchemaIndex('validation_date', ['validation_date'], postgresqlName: 'glpi_changevalidations_validation_date')]
#[SchemaIndex('status', ['status'], postgresqlName: 'glpi_changevalidations_status')]
class ChangeValidation implements ValidationRequest
{
    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changevalidations_changes_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changevalidations_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changevalidations_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_validate', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changevalidations_users_id_validate', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $validator = null;

    #[ORM\Column(name: '`comment_submission`', type: 'text', nullable: true)]
    public ?string $comment_submission = null;

    #[ORM\Column(name: '`comment_validation`', type: 'text', nullable: true)]
    public ?string $comment_validation = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '2'])]
    public int $status = 2;

    #[ORM\Column(name: '`submission_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $submission_date = null;

    #[ORM\Column(name: '`validation_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $validation_date = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    public static function subjectAssociation(): string
    {
        return 'changes';
    }
}
