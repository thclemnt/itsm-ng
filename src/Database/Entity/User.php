<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use itsmng\Database\AuthenticationType;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Type\FixedStringType;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_users')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicityloginauth', ['name', 'authtype', 'auths_id'], unique: true, postgresqlName: 'glpi_users_unicityloginauth')]
#[SchemaIndex('firstname', ['firstname'], postgresqlName: 'glpi_users_firstname')]
#[SchemaIndex('realname', ['realname'], postgresqlName: 'glpi_users_realname')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_users_entities_id')]
#[SchemaIndex('profiles_id', ['profiles_id'], postgresqlName: 'glpi_users_profiles_id')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_users_locations_id')]
#[SchemaIndex('usertitles_id', ['usertitles_id'], postgresqlName: 'glpi_users_usertitles_id')]
#[SchemaIndex('usercategories_id', ['usercategories_id'], postgresqlName: 'glpi_users_usercategories_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_users_is_deleted')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_users_is_active')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_users_date_mod')]
#[SchemaIndex('authitem', ['authtype', 'auths_id'], postgresqlName: 'glpi_users_authitem')]
#[SchemaIndex('is_deleted_ldap', ['is_deleted_ldap'], postgresqlName: 'glpi_users_is_deleted_ldap')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_users_date_creation')]
#[SchemaIndex('begin_date', ['begin_date'], postgresqlName: 'glpi_users_begin_date')]
#[SchemaIndex('end_date', ['end_date'], postgresqlName: 'glpi_users_end_date')]
#[SchemaIndex('sync_field', ['sync_field'], postgresqlName: 'glpi_users_sync_field')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_users_groups_id')]
#[SchemaIndex('users_id_supervisor', ['users_id_supervisor'], postgresqlName: 'glpi_users_users_id_supervisor')]
#[SchemaIndex('authldaps_id', ['authldaps_id'])]
#[SchemaIndex('authmails_id', ['authmails_id'])]
#[SchemaIndex('IDX_F7E175BF7F248429', ['default_requesttypes_id'])]
#[ORM\HasLifecycleCallbacks]
class User implements LegacyInput
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`password`', type: 'string', length: 255, nullable: true)]
    public ?string $password = null;

    #[ORM\Column(name: '`password_last_update`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $password_last_update = null;

    #[ORM\Column(name: '`phone`', type: 'string', length: 255, nullable: true)]
    public ?string $phone = null;

    #[ORM\Column(name: '`phone2`', type: 'string', length: 255, nullable: true)]
    public ?string $phone2 = null;

    #[ORM\Column(name: '`mobile`', type: 'string', length: 255, nullable: true)]
    public ?string $mobile = null;

    #[ORM\Column(name: '`realname`', type: 'string', length: 255, nullable: true)]
    public ?string $realname = null;

    #[ORM\Column(name: '`firstname`', type: 'string', length: 255, nullable: true)]
    public ?string $firstname = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\Column(name: '`language`', type: FixedStringType::NAME, length: 10, nullable: true, options: ['fixed' => true, 'comment' => 'see define.php CFG_GLPI[language] array'])]
    public ?string $language = null;

    #[ORM\Column(name: '`use_mode`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $use_mode = 0;

    #[ORM\Column(name: '`list_limit`', type: 'integer', nullable: true)]
    public ?int $list_limit = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = true;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: AuthLDAP::class)]
    #[ORM\JoinColumn(name: 'authldaps_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_authldaps_id', options: ['default' => null])]
    #[DiscriminatedBy('authtype', 'auths_id', [AuthenticationType::Pending->value, AuthenticationType::Ldap->value, AuthenticationType::External->value, AuthenticationType::Cas->value, AuthenticationType::X509->value], emptyValue: 0)]
    #[ApplicationManaged]
    public ?AuthLDAP $authldap = null;

    #[ORM\ManyToOne(targetEntity: AuthMail::class)]
    #[ORM\JoinColumn(name: 'authmails_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_authmails_id', options: ['default' => null])]
    #[DiscriminatedBy('authtype', 'auths_id', [AuthenticationType::Mail->value], emptyValue: 0)]
    public ?AuthMail $authmail = null;

    /** Existing forms, plugins and the login uniqueness key use this virtual selection. */
    #[ORM\Column(name: '`auths_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('auth_source_code')]
    public int $auths_id = 0;

    /** Non-server authentication kinds retain their opaque legacy payload. */
    #[ORM\Column(name: '`auth_source_code`', type: 'integer', nullable: true)]
    public ?int $auth_source_code = 0;

    #[ORM\Column(name: '`authtype`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $authtype = 0;

    #[ORM\Column(name: '`last_login`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $last_login = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_sync`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_sync = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'profiles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_profiles_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Profile $profiles = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_entities_id', options: ['default' => '0'])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: UserTitle::class)]
    #[ORM\JoinColumn(name: 'usertitles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_usertitles_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?UserTitle $usertitles = null;

    #[ORM\ManyToOne(targetEntity: UserCategory::class)]
    #[ORM\JoinColumn(name: 'usercategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_usercategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?UserCategory $usercategories = null;

    #[ORM\Column(name: '`date_format`', type: 'integer', nullable: true)]
    public ?int $date_format = null;

    #[ORM\Column(name: '`number_format`', type: 'integer', nullable: true)]
    public ?int $number_format = null;

    #[ORM\Column(name: '`names_format`', type: 'integer', nullable: true)]
    public ?int $names_format = null;

    #[ORM\Column(name: '`csv_delimiter`', type: FixedStringType::NAME, length: 1, nullable: true, options: ['fixed' => true])]
    public ?string $csv_delimiter = null;

    #[ORM\Column(name: '`is_ids_visible`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $is_ids_visible = null;

    #[ORM\Column(name: '`use_flat_dropdowntree`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $use_flat_dropdowntree = null;

    #[ORM\Column(name: '`show_jobs_at_login`', type: 'smallint', nullable: true)]
    public ?int $show_jobs_at_login = null;

    #[ORM\Column(name: '`priority_1`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_1 = null;

    #[ORM\Column(name: '`priority_2`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_2 = null;

    #[ORM\Column(name: '`priority_3`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_3 = null;

    #[ORM\Column(name: '`priority_4`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_4 = null;

    #[ORM\Column(name: '`priority_5`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_5 = null;

    #[ORM\Column(name: '`priority_6`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_6 = null;

    #[ORM\Column(name: '`followup_private`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $followup_private = null;

    #[ORM\Column(name: '`task_private`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $task_private = null;

    #[ORM\ManyToOne(targetEntity: RequestType::class)]
    #[ORM\JoinColumn(name: 'default_requesttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_default_requesttypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?RequestType $default_requesttypes = null;

    #[ORM\Column(name: '`password_forget_token`', type: FixedStringType::NAME, length: 40, nullable: true, options: ['fixed' => true])]
    public ?string $password_forget_token = null;

    #[ORM\Column(name: '`password_forget_token_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $password_forget_token_date = null;

    #[ORM\Column(name: '`user_dn`', type: 'text', nullable: true)]
    public ?string $user_dn = null;

    #[ORM\Column(name: '`registration_number`', type: 'string', length: 255, nullable: true)]
    public ?string $registration_number = null;

    #[ORM\Column(name: '`show_count_on_tabs`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $show_count_on_tabs = null;

    #[ORM\Column(name: '`refresh_views`', type: 'integer', nullable: true)]
    public ?int $refresh_views = null;

    #[ORM\Column(name: '`set_default_tech`', type: 'smallint', nullable: true)]
    public ?int $set_default_tech = null;

    #[ORM\Column(name: '`personal_token`', type: 'string', length: 255, nullable: true)]
    public ?string $personal_token = null;

    #[ORM\Column(name: '`personal_token_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $personal_token_date = null;

    #[ORM\Column(name: '`api_token`', type: 'string', length: 255, nullable: true)]
    public ?string $api_token = null;

    #[ORM\Column(name: '`api_token_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $api_token_date = null;

    #[ORM\Column(name: '`cookie_token`', type: 'string', length: 255, nullable: true)]
    public ?string $cookie_token = null;

    #[ORM\Column(name: '`cookie_token_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $cookie_token_date = null;

    #[ORM\Column(name: '`display_count_on_home`', type: 'integer', nullable: true)]
    public ?int $display_count_on_home = null;

    #[ORM\Column(name: '`notification_to_myself`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $notification_to_myself = null;

    #[ORM\Column(name: '`duedateok_color`', type: 'string', length: 255, nullable: true)]
    public ?string $duedateok_color = null;

    #[ORM\Column(name: '`duedatewarning_color`', type: 'string', length: 255, nullable: true)]
    public ?string $duedatewarning_color = null;

    #[ORM\Column(name: '`duedatecritical_color`', type: 'string', length: 255, nullable: true)]
    public ?string $duedatecritical_color = null;

    #[ORM\Column(name: '`duedatewarning_less`', type: 'integer', nullable: true)]
    public ?int $duedatewarning_less = null;

    #[ORM\Column(name: '`duedatecritical_less`', type: 'integer', nullable: true)]
    public ?int $duedatecritical_less = null;

    #[ORM\Column(name: '`duedatewarning_unit`', type: 'string', length: 255, nullable: true)]
    public ?string $duedatewarning_unit = null;

    #[ORM\Column(name: '`duedatecritical_unit`', type: 'string', length: 255, nullable: true)]
    public ?string $duedatecritical_unit = null;

    #[ORM\Column(name: '`display_options`', type: 'text', nullable: true)]
    public ?string $display_options = null;

    #[ORM\Column(name: '`is_deleted_ldap`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted_ldap = false;

    #[ORM\Column(name: '`pdffont`', type: 'string', length: 255, nullable: true)]
    public ?string $pdffont = null;

    #[ORM\Column(name: '`picture`', type: 'string', length: 255, nullable: true)]
    public ?string $picture = null;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end_date = null;

    #[ORM\Column(name: '`keep_devices_when_purging_item`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $keep_devices_when_purging_item = null;

    #[ORM\Column(name: '`privatebookmarkorder`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $privatebookmarkorder = null;

    #[ORM\Column(name: '`backcreated`', type: 'smallint', nullable: true)]
    public ?int $backcreated = null;

    #[ORM\Column(name: '`task_state`', type: 'integer', nullable: true)]
    public ?int $task_state = null;

    #[ORM\Column(name: '`layout`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $layout = null;

    #[ORM\Column(name: '`palette`', type: FixedStringType::NAME, length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $palette = null;

    #[ORM\Column(name: '`set_default_requester`', type: 'smallint', nullable: true)]
    public ?int $set_default_requester = null;

    #[ORM\Column(name: '`lock_autolock_mode`', type: 'smallint', nullable: true)]
    public ?int $lock_autolock_mode = null;

    #[ORM\Column(name: '`lock_directunlock_notification`', type: 'boolean', nullable: true)]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $lock_directunlock_notification = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`highcontrast_css`', type: 'boolean', nullable: true, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $highcontrast_css = false;

    #[ORM\Column(name: '`plannings`', type: 'text', nullable: true)]
    public ?string $plannings = null;

    #[ORM\Column(name: '`sync_field`', type: 'string', length: 255, nullable: true)]
    public ?string $sync_field = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_supervisor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_users_users_id_supervisor', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $supervisor = null;

    #[ORM\Column(name: '`timezone`', type: 'string', length: 50, nullable: true)]
    public ?string $timezone = null;

    #[ORM\Column(name: '`default_dashboard_central`', type: 'string', length: 100, nullable: true)]
    public ?string $default_dashboard_central = null;

    #[ORM\Column(name: '`default_dashboard_assets`', type: 'string', length: 100, nullable: true)]
    public ?string $default_dashboard_assets = null;

    #[ORM\Column(name: '`default_dashboard_helpdesk`', type: 'string', length: 100, nullable: true)]
    public ?string $default_dashboard_helpdesk = null;

    #[ORM\Column(name: '`default_dashboard_mini_ticket`', type: 'string', length: 100, nullable: true)]
    public ?string $default_dashboard_mini_ticket = null;

    #[ORM\Column(name: '`access_zoom_level`', type: 'smallint', nullable: true, options: ['default' => '100'])]
    public ?int $access_zoom_level = 100;

    #[ORM\Column(name: '`access_font`', type: 'string', length: 100, nullable: true)]
    public ?string $access_font = null;

    #[ORM\Column(name: '`access_shortcuts`', type: 'boolean', nullable: true, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $access_shortcuts = false;

    #[ORM\Column(name: '`access_custom_shortcuts`', type: 'json', nullable: true)]
    public ?array $access_custom_shortcuts = null;

    #[ORM\Column(name: '`menu_favorite`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $menu_favorite = null;

    #[ORM\Column(name: '`menu_favorite_on`', type: 'text', nullable: true)]
    public ?string $menu_favorite_on = null;

    #[ORM\Column(name: '`menu_position`', type: 'text', nullable: true)]
    public ?string $menu_position = null;

    #[ORM\Column(name: '`menu_small`', type: 'text', nullable: true)]
    public ?string $menu_small = null;

    #[ORM\Column(name: '`compact_mode_ui`', type: 'boolean', nullable: true, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public ?bool $compact_mode_ui = false;

    #[ORM\Column(name: '`menu_open`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $menu_open = null;

    /**
     * Preserve public creation input while deriving its logical uniqueness key.
     * A supplied canonical owner must not acquire an artificial legacy zero.
     *
     * @return array{input: array, identity: array{authtype: int, auths_id: int}}
     */
    public function prepareAuthenticationInput(array $values): array
    {
        $type = (int)($values['authtype'] ?? $this->authtype);
        $property = self::authenticationReference($type);
        $column = $property === null ? 'auth_source_code'
            : $property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name;
        if (!isset($values['auths_id']) && !array_key_exists($column, $values)) {
            // Retain historical omitted/null legacy defaults when the selected
            // canonical property was not supplied, including directory fallback.
            $values['auths_id'] = 0;
        }
        $normalized = $this->normalizeInput($values);
        $source = array_key_exists($column, $normalized) ? $normalized[$column] : $this->auths_id;
        return ['input' => $values, 'identity' => ['authtype' => $type, 'auths_id' => (int)$source]];
    }

    /** Legacy source IDs select the association declared on this entity. */
    public function normalizeInput(array $values): array
    {
        if (!array_intersect(array_keys($values), ['authtype', 'auths_id', 'authldaps_id', 'authmails_id', 'auth_source_code'])) {
            return $values;
        }
        if (array_key_exists('auths_id', $values) && $values['auths_id'] !== null && !in_array($values['auths_id'], ['', false], true) && filter_var($values['auths_id'], FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Legacy authentication server requires an integer');
        }
        $property = self::authenticationReference((int)($values['authtype'] ?? $this->authtype));
        if ($property === null) {
            if (($values['authldaps_id'] ?? null) !== null || ($values['authmails_id'] ?? null) !== null) {
                throw new InvalidArgumentException('Authentication kind cannot select an LDAP or mail server');
            }
            $code = array_key_exists('auth_source_code', $values) ? $values['auth_source_code'] : ($values['auths_id'] ?? $this->auths_id);
            if (filter_var($code, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Authentication source code requires an integer');
            }
            if (array_key_exists('auths_id', $values) && (int)$values['auths_id'] !== (int)$code) {
                throw new InvalidArgumentException('Legacy and canonical authentication source codes disagree');
            }
            $values['authldaps_id'] = $values['authmails_id'] = null;
            $values['auth_source_code'] = (int)$code;
        } else {
            $column = $property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name;
            $other = $column === 'authldaps_id' ? 'authmails_id' : 'authldaps_id';
            if (($values[$other] ?? null) !== null || ($values['auth_source_code'] ?? null) !== null) {
                throw new InvalidArgumentException('Authentication kind cannot select another source branch');
            }
            $selected = array_key_exists($column, $values) ? $values[$column] : ($values['auths_id'] ?? $this->auths_id);
            if ($selected !== null && !in_array($selected, ['', false], true) && filter_var($selected, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Authentication server requires an integer');
            }
            if (array_key_exists($column, $values) && (int)$selected < 0) {
                throw new InvalidArgumentException('Canonical authentication server cannot be negative');
            }
            $selected = max(0, (int)$selected);
            if (array_key_exists($column, $values) && array_key_exists('auths_id', $values) && max(0, (int)$values['auths_id']) !== $selected) {
                throw new InvalidArgumentException('Legacy and canonical authentication servers disagree');
            }
            $values[$column] = $selected === 0 ? null : $selected;
            $values[$other] = null;
            $values['auth_source_code'] = null;
        }
        unset($values['auths_id']);
        return $values;
    }

    public function legacyChanges(array $columns): array
    {
        if (array_intersect($columns, ['authldaps_id', 'authmails_id', 'auth_source_code'])) {
            $columns[] = 'auths_id';
        }
        return array_values(array_unique($columns));
    }

    public static function authenticationReference(int $type): ?ReflectionProperty
    {
        foreach ((new ReflectionClass(self::class))->getProperties() as $property) {
            foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn === 'auths_id' && in_array($type, $binding->values, true)) {
                    return $property;
                }
            }
        }
        return null;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function synchronizeAuthentication(): void
    {
        $property = self::authenticationReference($this->authtype);
        if ($property === null) {
            if ($this->authldap !== null || $this->authmail !== null || $this->auth_source_code === null) {
                throw new InvalidArgumentException('Non-server authentication requires its source code and no server association');
            }
            return;
        }
        $selected = $this->{$property->getName()};
        $other = $property->getName() === 'authldap' ? $this->authmail : $this->authldap;
        if ($other !== null || ($selected !== null && $selected->id !== null && $selected->id <= 0)) {
            throw new InvalidArgumentException('Authentication kind cannot select another server association');
        }
        // A missing selected server preserves try-all-server and external-only authentication.
        $this->auth_source_code = null;
    }
}
