<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_entities')]
#[ORM\UniqueConstraint(name: 'entities_unicity', columns: ['entities_id', 'name'])]
class Entity
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
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
    #[ORM\JoinColumn(name: 'authldaps_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?AuthLDAP $authldap = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'explicit'])]
    public \itsmng\Database\ReferenceMode $ldap_mode = \itsmng\Database\ReferenceMode::Explicit;

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
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Calendar $calendar = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'inherit'])]
    public \itsmng\Database\ReferenceMode $calendar_mode = \itsmng\Database\ReferenceMode::Inherit;

    #[ORM\Column(name: '`auto_assign_mode`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $auto_assign_mode = -2;

    #[ORM\Column(name: '`tickettype`', type: 'integer', nullable: false, options: ['default' => '-2'])]
    public int $tickettype = -2;

    #[ORM\Column(name: '`max_closedate`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $max_closedate = null;

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
    #[ORM\JoinColumn(name: 'tickettemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?TicketTemplate $tickettemplate = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'inherit'])]
    public \itsmng\Database\ReferenceMode $tickettemplate_mode = \itsmng\Database\ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: ChangeTemplate::class)]
    #[ORM\JoinColumn(name: 'changetemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?ChangeTemplate $changetemplate = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'inherit'])]
    public \itsmng\Database\ReferenceMode $changetemplate_mode = \itsmng\Database\ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: ProblemTemplate::class)]
    #[ORM\JoinColumn(name: 'problemtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?ProblemTemplate $problemtemplate = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'inherit'])]
    public \itsmng\Database\ReferenceMode $problemtemplate_mode = \itsmng\Database\ReferenceMode::Inherit;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id_software', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Entity $software_entity = null;

    #[ORM\Column(type: 'string', length: 16, enumType: \itsmng\Database\ReferenceMode::class, options: ['default' => 'inherit'])]
    public \itsmng\Database\ReferenceMode $software_entity_mode = \itsmng\Database\ReferenceMode::Inherit;

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
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

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
