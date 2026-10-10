<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\SchemaIndex;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtargets')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('groups_id', ['groups_id'])]
#[SchemaIndex('profiles_id', ['profiles_id'])]
#[SchemaIndex('items', ['type', 'items_id'], postgresqlName: 'glpi_notificationtargets_items')]
#[SchemaIndex('notifications_id', ['notifications_id'], postgresqlName: 'glpi_notificationtargets_notifications_id')]
class NotificationTarget implements LegacyInput
{
    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_notificationtargets_groups_id', options: ['default' => null])]
    #[DiscriminatedBy('type', 'items_id', [3, 5, 6])]
    #[ApplicationManaged]
    public ?Group $group = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'profiles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_notificationtargets_profiles_id', options: ['default' => null])]
    #[DiscriminatedBy('type', 'items_id', [2])]
    #[ApplicationManaged]
    public ?Profile $profile = null;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notifications_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_notificationtargets_notifications_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?Notification $notifications = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('recipient_code')]
    public ?int $items_id = null;

    #[ORM\Column(name: '`recipient_code`', type: 'integer', nullable: true, options: ['default' => 0])]
    public ?int $recipient_code = 0;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $type = 0;

    /** Forms/plugins keep type/items_id; the selected target is a typed association. */
    public function normalizeInput(array $values): array
    {
        if (!array_intersect(array_keys($values), ['type', 'items_id', 'groups_id', 'profiles_id', 'recipient_code'])) {
            return $values;
        }
        $type = (int)($values['type'] ?? $this->type);
        $property = $this->recipientProperty($type);
        $column = $property === null ? null : $property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name;
        if ($column === null) {
            if (($values['groups_id'] ?? null) !== null || ($values['profiles_id'] ?? null) !== null) {
                throw new InvalidArgumentException('A recipient constant cannot select a group or profile');
            }
            $values['groups_id'] = $values['profiles_id'] = null;
            if (array_key_exists('items_id', $values) && array_key_exists('recipient_code', $values) && (int)$values['items_id'] !== (int)$values['recipient_code']) {
                throw new InvalidArgumentException('Legacy and canonical notification recipient codes disagree');
            }
            $values['recipient_code'] = array_key_exists('recipient_code', $values) ? $values['recipient_code'] : ($values['items_id'] ?? $this->items_id ?? 0);
            unset($values['items_id']);
            return $values;
        }
        $other = $column === 'groups_id' ? 'profiles_id' : 'groups_id';
        if (($values[$other] ?? null) !== null) {
            throw new InvalidArgumentException('Notification recipient kind cannot select the other association');
        }
        $selected = array_key_exists($column, $values) ? $values[$column] : ($values['items_id'] ?? $this->items_id);
        if (filter_var($selected, FILTER_VALIDATE_INT) === false || (int)$selected <= 0) {
            throw new InvalidArgumentException('Notification group/profile requires a positive identifier');
        }
        if (array_key_exists($column, $values) && array_key_exists('items_id', $values) && (int)$values['items_id'] !== (int)$selected) {
            throw new InvalidArgumentException('Legacy and canonical notification recipients disagree');
        }
        if (($values['recipient_code'] ?? null) !== null) {
            throw new InvalidArgumentException('A group/profile cannot also select a recipient constant');
        }
        $values[$column] = (int)$selected;
        $values[$other] = null;
        $values['recipient_code'] = null;
        unset($values['items_id']);
        return $values;
    }

    public function legacyChanges(array $columns): array
    {
        if (array_intersect($columns, ['groups_id', 'profiles_id', 'recipient_code'])) {
            $columns[] = 'items_id';
        }
        return array_values(array_unique($columns));
    }

    private function recipientProperty(int $type): ?ReflectionProperty
    {
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
                if (in_array($type, $attribute->newInstance()->values, true)) {
                    return $property;
                }
            }
        }
        return null;
    }

    /** Native Doctrine persistence keeps the compatibility payload consistent too. */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function synchronizeRecipient(): void
    {
        $property = $this->recipientProperty($this->type);
        if ($property !== null) {
            $selected = $this->{$property->getName()};
            if ($selected === null || ($selected->id !== null && $selected->id <= 0) || ($property->getName() === 'profile' ? $this->group !== null : $this->profile !== null)) {
                throw new InvalidArgumentException('Notification recipient kind requires its selected association');
            }
            $this->recipient_code = null;
        } elseif ($this->group !== null || $this->profile !== null) {
            throw new InvalidArgumentException('A recipient constant cannot select a group or profile');
        } elseif ($this->recipient_code === null) {
            throw new InvalidArgumentException('A constant recipient requires a code');
        }
    }
}
