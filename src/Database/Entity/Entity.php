<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\ReferenceMode;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_entities')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['entities_id', 'name'], unique: true, postgresqlName: 'glpi_entities_unicity')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_entities_entities_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_entities_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_entities_date_creation')]
#[SchemaIndex('tickettemplates_id', ['tickettemplates_id'], postgresqlName: 'glpi_entities_tickettemplates_id')]
#[SchemaIndex('changetemplates_id', ['changetemplates_id'], postgresqlName: 'glpi_entities_changetemplates_id')]
#[SchemaIndex('problemtemplates_id', ['problemtemplates_id'], postgresqlName: 'glpi_entities_problemtemplates_id')]
#[SchemaIndex('IDX_1A59F36F500D4AFA', ['authldaps_id'])]
#[SchemaIndex('IDX_1A59F36FBDBA0E81', ['calendars_id'])]
#[SchemaIndex('IDX_1A59F36FE9573678', ['entities_id_software'])]
class Entity
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootParent)]
    #[ApplicationManaged]
    public ?self $parent = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`address`', type: 'text', nullable: true)]
    public ?string $address = null;

    #[ORM\Column(name: '`postcode`', type: 'string', length: 255, nullable: true)]
    public ?string $postcode = null;

    #[ORM\Column(name: '`town`', type: 'string', length: 255, nullable: true)]
    public ?string $town = null;

    #[ORM\Column(name: '`state`', type: 'string', length: 255, nullable: true)]
    public ?string $state = null;

    #[ORM\Column(name: '`country`', type: 'string', length: 255, nullable: true)]
    public ?string $country = null;

    #[ORM\Column(name: '`website`', type: 'string', length: 255, nullable: true)]
    public ?string $website = null;

    #[ORM\Column(name: '`phonenumber`', type: 'string', length: 255, nullable: true)]
    public ?string $phonenumber = null;

    #[ORM\Column(name: '`fax`', type: 'string', length: 255, nullable: true)]
    public ?string $fax = null;

    #[ORM\Column(name: '`email`', type: 'string', length: 255, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(name: '`admin_email`', type: 'string', length: 255, nullable: true)]
    public ?string $admin_email = null;

    #[ORM\Column(name: '`admin_email_name`', type: 'string', length: 255, nullable: true)]
    public ?string $admin_email_name = null;

    #[ORM\Column(name: '`admin_reply`', type: 'string', length: 255, nullable: true)]
    public ?string $admin_reply = null;

    #[ORM\Column(name: '`admin_reply_name`', type: 'string', length: 255, nullable: true)]
    public ?string $admin_reply_name = null;

    #[ORM\Column(name: '`notification_subject_tag`', type: 'string', length: 255, nullable: true)]
    public ?string $notification_subject_tag = null;

    #[ORM\Column(name: '`ldap_dn`', type: 'string', length: 255, nullable: true)]
    public ?string $ldap_dn = null;

    #[ORM\Column(name: '`tag`', type: 'string', length: 255, nullable: true)]
    public ?string $tag = null;

    #[ORM\ManyToOne(targetEntity: AuthLDAP::class)]
    #[ORM\JoinColumn(name: 'authldaps_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_authldaps_id')]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'ldap_mode', emptyZero: true)]
    public ?AuthLDAP $authldap = null;

    #[ORM\Column(name: '`ldap_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'explicit'])]
    public ReferenceMode $ldap_mode = ReferenceMode::Explicit;

    #[ORM\Column(name: '`mail_domain`', type: 'string', length: 255, nullable: true)]
    public ?string $mail_domain = null;

    #[ORM\Column(name: '`entity_ldapfilter`', type: 'text', nullable: true)]
    public ?string $entity_ldapfilter = null;

    #[ORM\Column(name: '`mailing_signature`', type: 'text', nullable: true)]
    public ?string $mailing_signature = null;

    #[ORM\Column(name: '`cartridges_alert_repeat`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $cartridges_alert_repeat = -2;

    #[ORM\Column(name: '`consumables_alert_repeat`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $consumables_alert_repeat = -2;

    #[ORM\Column(name: '`use_licenses_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_licenses_alert = -2;

    #[ORM\Column(name: '`send_licenses_alert_before_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_licenses_alert_before_delay = -2;

    #[ORM\Column(name: '`use_certificates_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_certificates_alert = -2;

    #[ORM\Column(name: '`send_certificates_alert_before_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_certificates_alert_before_delay = -2;

    #[ORM\Column(name: '`use_contracts_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_contracts_alert = -2;

    #[ORM\Column(name: '`send_contracts_alert_before_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_contracts_alert_before_delay = -2;

    #[ORM\Column(name: '`use_infocoms_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_infocoms_alert = -2;

    #[ORM\Column(name: '`send_infocoms_alert_before_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_infocoms_alert_before_delay = -2;

    #[ORM\Column(name: '`use_reservations_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_reservations_alert = -2;

    #[ORM\Column(name: '`use_domains_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $use_domains_alert = -2;

    #[ORM\Column(name: '`send_domains_alert_close_expiries_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_domains_alert_close_expiries_delay = -2;

    #[ORM\Column(name: '`send_domains_alert_expired_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $send_domains_alert_expired_delay = -2;

    #[ORM\Column(name: '`autoclose_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $autoclose_delay = -2;

    #[ORM\Column(name: '`autopurge_delay`', type: 'integer', nullable: false, options: ['default' => '-10'])]
    public int $autopurge_delay = -10;

    #[ORM\Column(name: '`notclosed_delay`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $notclosed_delay = -2;

    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_calendars_id')]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'calendar_mode', emptyZero: true)]
    public ?Calendar $calendar = null;

    #[ORM\Column(name: '`calendar_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'inherit'])]
    public ReferenceMode $calendar_mode = ReferenceMode::Inherit;

    #[ORM\Column(name: '`auto_assign_mode`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $auto_assign_mode = -2;

    #[ORM\Column(name: '`tickettype`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $tickettype = -2;

    #[ORM\Column(name: '`max_closedate`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $max_closedate = null;

    #[ORM\Column(name: '`inquest_config`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $inquest_config = -2;

    #[ORM\Column(name: '`inquest_rate`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $inquest_rate = 0;

    #[ORM\Column(name: '`inquest_delay`', type: 'integer', nullable: false, options: ['default' => '-10'])]
    public int $inquest_delay = -10;

    #[ORM\Column(name: '`inquest_URL`', type: 'string', length: 255, nullable: true)]
    public ?string $inquest_URL = null;

    #[ORM\Column(name: '`autofill_warranty_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_warranty_date = '-2';

    #[ORM\Column(name: '`autofill_use_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_use_date = '-2';

    #[ORM\Column(name: '`autofill_buy_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_buy_date = '-2';

    #[ORM\Column(name: '`autofill_delivery_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_delivery_date = '-2';

    #[ORM\Column(name: '`autofill_order_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_order_date = '-2';

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_tickettemplates_id')]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'tickettemplate_mode', emptyZero: true)]
    public ?TicketTemplate $tickettemplate = null;

    #[ORM\Column(name: '`tickettemplate_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'inherit'])]
    public ReferenceMode $tickettemplate_mode = ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: ChangeTemplate::class)]
    #[ORM\JoinColumn(name: 'changetemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_changetemplates_id')]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'changetemplate_mode', emptyZero: true)]
    public ?ChangeTemplate $changetemplate = null;

    #[ORM\Column(name: '`changetemplate_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'inherit'])]
    public ReferenceMode $changetemplate_mode = ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: ProblemTemplate::class)]
    #[ORM\JoinColumn(name: 'problemtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_problemtemplates_id')]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'problemtemplate_mode', emptyZero: true)]
    public ?ProblemTemplate $problemtemplate = null;

    #[ORM\Column(name: '`problemtemplate_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'inherit'])]
    public ReferenceMode $problemtemplate_mode = ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id_software', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_entities_entities_id_software', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::Inherited, modeProperty: 'software_entity_mode', emptyZero: false)]
    public ?Entity $software_entity = null;

    #[ORM\Column(name: '`software_entity_mode`', type: 'string', length: 16, nullable: false, enumType: ReferenceMode::class, options: ['default' => 'inherit'])]
    public ReferenceMode $software_entity_mode = ReferenceMode::Inherit;

    #[ORM\Column(name: '`default_contract_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $default_contract_alert = -2;

    #[ORM\Column(name: '`default_infocom_alert`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $default_infocom_alert = -2;

    #[ORM\Column(name: '`default_cartridges_alarm_threshold`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $default_cartridges_alarm_threshold = -2;

    #[ORM\Column(name: '`default_consumables_alarm_threshold`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $default_consumables_alarm_threshold = -2;

    #[ORM\Column(name: '`delay_send_emails`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $delay_send_emails = -2;

    #[ORM\Column(name: '`is_notif_enable_default`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $is_notif_enable_default = -2;

    #[ORM\Column(name: '`inquest_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $inquest_duration = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`autofill_decommission_date`', type: 'string', length: 255, nullable: false, options: ['default' => '-2'])]
    public string $autofill_decommission_date = '-2';

    #[ORM\Column(name: '`suppliers_as_private`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $suppliers_as_private = -2;

    #[ORM\Column(name: '`anonymize_support_agents`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $anonymize_support_agents = -2;

    #[ORM\Column(name: '`enable_custom_css`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $enable_custom_css = -2;

    #[ORM\Column(name: '`custom_css_code`', type: 'text', nullable: true)]
    public ?string $custom_css_code = null;

    #[ORM\Column(name: '`latitude`', type: 'string', length: 255, nullable: true)]
    public ?string $latitude = null;

    #[ORM\Column(name: '`longitude`', type: 'string', length: 255, nullable: true)]
    public ?string $longitude = null;

    #[ORM\Column(name: '`altitude`', type: 'string', length: 255, nullable: true)]
    public ?string $altitude = null;
}
