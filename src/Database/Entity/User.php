<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_users')]
#[ORM\UniqueConstraint(name: 'users_unicityloginauth', columns: ['name', 'authtype', 'auths_id'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`password`', type: 'string', length: 255, nullable: true)]
    public ?string $password = null;

    #[ORM\Column(name: '`password_last_update`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $password_last_update = null;

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

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: true, options: ['fixed' => true])]
    public ?string $language = null;

    #[ORM\Column(name: '`use_mode`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $use_mode = 0;

    #[ORM\Column(name: '`list_limit`', type: 'integer', nullable: true)]
    public ?int $list_limit = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_active = true;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`auths_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $auths_id = 0;

    #[ORM\Column(name: '`authtype`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $authtype = 0;

    #[ORM\Column(name: '`last_login`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $last_login = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_sync`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_sync = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`profiles_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $profiles_id = 0;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`usertitles_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $usertitles_id = 0;

    #[ORM\Column(name: '`usercategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $usercategories_id = 0;

    #[ORM\Column(name: '`date_format`', type: 'integer', nullable: true)]
    public ?int $date_format = null;

    #[ORM\Column(name: '`number_format`', type: 'integer', nullable: true)]
    public ?int $number_format = null;

    #[ORM\Column(name: '`names_format`', type: 'integer', nullable: true)]
    public ?int $names_format = null;

    #[ORM\Column(name: '`csv_delimiter`', type: 'string', length: 1, nullable: true, options: ['fixed' => true])]
    public ?string $csv_delimiter = null;

    #[ORM\Column(name: '`is_ids_visible`', type: 'boolean', nullable: true)]
    public ?bool $is_ids_visible = null;

    #[ORM\Column(name: '`use_flat_dropdowntree`', type: 'boolean', nullable: true)]
    public ?bool $use_flat_dropdowntree = null;

    #[ORM\Column(name: '`show_jobs_at_login`', type: 'smallint', nullable: true)]
    public ?int $show_jobs_at_login = null;

    #[ORM\Column(name: '`priority_1`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_1 = null;

    #[ORM\Column(name: '`priority_2`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_2 = null;

    #[ORM\Column(name: '`priority_3`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_3 = null;

    #[ORM\Column(name: '`priority_4`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_4 = null;

    #[ORM\Column(name: '`priority_5`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_5 = null;

    #[ORM\Column(name: '`priority_6`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $priority_6 = null;

    #[ORM\Column(name: '`followup_private`', type: 'boolean', nullable: true)]
    public ?bool $followup_private = null;

    #[ORM\Column(name: '`task_private`', type: 'boolean', nullable: true)]
    public ?bool $task_private = null;

    #[ORM\Column(name: '`default_requesttypes_id`', type: 'integer', nullable: true)]
    public ?int $default_requesttypes_id = null;

    #[ORM\Column(name: '`password_forget_token`', type: 'string', length: 40, nullable: true, options: ['fixed' => true])]
    public ?string $password_forget_token = null;

    #[ORM\Column(name: '`password_forget_token_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $password_forget_token_date = null;

    #[ORM\Column(name: '`user_dn`', type: 'text', nullable: true)]
    public ?string $user_dn = null;

    #[ORM\Column(name: '`registration_number`', type: 'string', length: 255, nullable: true)]
    public ?string $registration_number = null;

    #[ORM\Column(name: '`show_count_on_tabs`', type: 'boolean', nullable: true)]
    public ?bool $show_count_on_tabs = null;

    #[ORM\Column(name: '`refresh_views`', type: 'integer', nullable: true)]
    public ?int $refresh_views = null;

    #[ORM\Column(name: '`set_default_tech`', type: 'smallint', nullable: true)]
    public ?int $set_default_tech = null;

    #[ORM\Column(name: '`personal_token`', type: 'string', length: 255, nullable: true)]
    public ?string $personal_token = null;

    #[ORM\Column(name: '`personal_token_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $personal_token_date = null;

    #[ORM\Column(name: '`api_token`', type: 'string', length: 255, nullable: true)]
    public ?string $api_token = null;

    #[ORM\Column(name: '`api_token_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $api_token_date = null;

    #[ORM\Column(name: '`cookie_token`', type: 'string', length: 255, nullable: true)]
    public ?string $cookie_token = null;

    #[ORM\Column(name: '`cookie_token_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $cookie_token_date = null;

    #[ORM\Column(name: '`display_count_on_home`', type: 'integer', nullable: true)]
    public ?int $display_count_on_home = null;

    #[ORM\Column(name: '`notification_to_myself`', type: 'boolean', nullable: true)]
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
    public bool $is_deleted_ldap = false;

    #[ORM\Column(name: '`pdffont`', type: 'string', length: 255, nullable: true)]
    public ?string $pdffont = null;

    #[ORM\Column(name: '`picture`', type: 'string', length: 255, nullable: true)]
    public ?string $picture = null;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end_date = null;

    #[ORM\Column(name: '`keep_devices_when_purging_item`', type: 'boolean', nullable: true)]
    public ?bool $keep_devices_when_purging_item = null;

    #[ORM\Column(name: '`privatebookmarkorder`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $privatebookmarkorder = null;

    #[ORM\Column(name: '`backcreated`', type: 'smallint', nullable: true)]
    public ?int $backcreated = null;

    #[ORM\Column(name: '`task_state`', type: 'integer', nullable: true)]
    public ?int $task_state = null;

    #[ORM\Column(name: '`layout`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $layout = null;

    #[ORM\Column(name: '`palette`', type: 'string', length: 20, nullable: true, options: ['fixed' => true])]
    public ?string $palette = null;

    #[ORM\Column(name: '`set_default_requester`', type: 'smallint', nullable: true)]
    public ?int $set_default_requester = null;

    #[ORM\Column(name: '`lock_autolock_mode`', type: 'smallint', nullable: true)]
    public ?int $lock_autolock_mode = null;

    #[ORM\Column(name: '`lock_directunlock_notification`', type: 'boolean', nullable: true)]
    public ?bool $lock_directunlock_notification = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`highcontrast_css`', type: 'boolean', nullable: true, options: ['default' => false])]
    public ?bool $highcontrast_css = false;

    #[ORM\Column(name: '`plannings`', type: 'text', nullable: true)]
    public ?string $plannings = null;

    #[ORM\Column(name: '`sync_field`', type: 'string', length: 255, nullable: true)]
    public ?string $sync_field = null;

    #[ORM\Column(name: '`groups_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id = 0;

    #[ORM\Column(name: '`users_id_supervisor`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_supervisor = 0;

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
    public ?bool $access_shortcuts = false;

    #[ORM\Column(name: '`access_custom_shortcuts`', type: 'json', nullable: true)]
    public ?array $access_custom_shortcuts = null;

    #[ORM\Column(name: '`menu_favorite`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $menu_favorite = null;

    #[ORM\Column(name: '`menu_favorite_on`', type: 'text', nullable: true)]
    public ?string $menu_favorite_on = null;

    #[ORM\Column(name: '`menu_position`', type: 'text', nullable: true)]
    public ?string $menu_position = null;

    #[ORM\Column(name: '`menu_small`', type: 'text', nullable: true)]
    public ?string $menu_small = null;

    #[ORM\Column(name: '`compact_mode_ui`', type: 'boolean', nullable: true, options: ['default' => false])]
    public ?bool $compact_mode_ui = false;

    #[ORM\Column(name: '`menu_open`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $menu_open = null;
}
