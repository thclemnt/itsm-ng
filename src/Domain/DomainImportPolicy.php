<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Domain-specific permission and expiry policy conflicts must be resolved before writes. */
final class DomainImportPolicy
{
    public function __construct(private EntityManager $em)
    {
    }

    public function plan(DomainPluginSnapshot $snapshot): array
    {
        if (count($snapshot->configs) !== 1 || (string)$snapshot->configs[0]['id'] !== '1') {
            throw new \RuntimeException('Domains source requires exactly one config row with id 1.');
        }
        $config = $snapshot->configs[0];
        $validation = new DomainImportValidation($this->em);
        $expired = $validation->integer($config['delay_expired'], 'glpi_plugin_domains_configs.1.delay_expired', 0);
        $expiring = $validation->integer($config['delay_whichexpire'], 'glpi_plugin_domains_configs.1.delay_whichexpire', 0);
        $fields = ['send_domains_alert_expired_delay' => $expired, 'send_domains_alert_close_expiries_delay' => $expiring];
        $policies = [];
        foreach ($this->em->getRepository(Entity\Entity::class)->findAll() as $entity) {
            foreach ($fields as $field => $delay) {
                if (!in_array($entity->$field, [-2, $delay], true)) {
                    throw new \RuntimeException('Domains expiry settings conflict: glpi_entities.' . $entity->id . '.' . $field . '; reconcile configured policy before import.');
                }
            }
            if ($entity->id === 0) {
                $policies[] = ['class' => Entity\Entity::class, 'id' => 0, 'fields' => $fields];
            }
        }
        $notifications = $this->em->getRepository(Entity\Notification::class)->findBy(['itemtype' => DomainPluginSource::ITEMTYPE]);
        foreach ($notifications as $notification) {
            if (!in_array($notification->event, ['ExpiredDomains', 'DomainsWhichExpire'], true)) {
                throw new \RuntimeException('Unsupported Domains notification event: ' . $notification->id . '.' . $notification->event);
            }
            if ($notification->is_active) {
                foreach ($this->em->getRepository(Entity\Notification::class)->findBy(['itemtype' => 'Domain', 'event' => $notification->event, 'is_active' => true]) as $core) {
                    if (array_intersect($this->notificationScope($notification), $this->notificationScope($core))) {
                        throw new \RuntimeException('Domains notification delivery conflict: ' . $notification->id . '.' . $notification->event . '; reconcile overlapping active core rules before import.');
                    }
                }
            }
        }
        $coreCron = $this->em->getRepository(Entity\CronTask::class)->findOneBy(['itemtype' => 'Domain', 'name' => 'DomainsAlert']);
        $crons = $this->em->getRepository(Entity\CronTask::class)->findBy(['itemtype' => DomainPluginSource::ITEMTYPE]);
        foreach ($crons as $cron) {
            if ($cron->name !== 'DomainsAlert' || $cron->itemtype !== DomainPluginSource::ITEMTYPE) {
                throw new \RuntimeException('Unsupported Domains scheduler: glpi_crontasks.' . $cron->id);
            }
            if ($coreCron) {
                foreach (['frequency', 'param', 'state', 'mode', 'allowmode', 'hourmin', 'hourmax', 'logs_lifetime'] as $field) {
                    if ($coreCron->$field !== $cron->$field) {
                        throw new \RuntimeException('Domains scheduler settings conflict: glpi_crontasks.' . $cron->id . '.' . $field . '; reconcile scheduling before import.');
                    }
                }
                // Retain its identity and execution history as retired source provenance.
                $policies[] = ['class' => Entity\CronTask::class, 'id' => $cron->id, 'fields' => ['state' => 0], 'retired_source' => true];
            } else {
                $policies[] = ['class' => Entity\CronTask::class, 'id' => $cron->id, 'fields' => ['itemtype' => 'Domain']];
            }
        }
        $sourceEnabled = (bool)array_filter($crons, static fn ($cron) => $cron->state === 1)
            && (bool)array_filter($notifications, static fn ($notification) => $notification->is_active);
        foreach ($this->em->getRepository(Entity\Entity::class)->findAll() as $entity) {
            if (!in_array($entity->use_domains_alert, [-2, (int)$sourceEnabled], true)) {
                throw new \RuntimeException('Domains alert enablement conflict: glpi_entities.' . $entity->id . '.use_domains_alert; reconcile configured delivery before import.');
            }
            if ($entity->id === 0) {
                $policies[] = ['class' => Entity\Entity::class, 'id' => 0, 'fields' => ['use_domains_alert' => (int)$sourceEnabled]];
            }
        }
        $rights = [];
        foreach (['plugin_domains' => 'domain', 'plugin_domains_dropdown' => 'domaintype'] as $sourceName => $targetName) {
            foreach ($this->em->getRepository(Entity\ProfileRight::class)->findBy(['name' => $sourceName]) as $source) {
                if ($source->name !== $sourceName) {
                    throw new \RuntimeException('Noncanonical Domains permission name: glpi_profilerights.' . $source->id);
                }
                if ($source->rights < 0 || ($source->rights & ~($sourceName === 'plugin_domains' ? 127 : 31)) !== 0) {
                    throw new \RuntimeException('Unsupported Domains permission mask: glpi_profilerights.' . $source->id);
                }
                $target = $this->em->getRepository(Entity\ProfileRight::class)->findOneBy(['profiles' => $source->profiles, 'name' => $targetName]);
                if ($target && $target->rights !== 0 && $target->rights !== $source->rights) {
                    throw new \RuntimeException('Domains permission conflict: profile ' . $source->profiles->id . '.' . $targetName . '; reconcile grants before import.');
                }
                $rights[] = ['source' => $source->id, 'profile' => $source->profiles->id, 'name' => $targetName, 'rights' => $source->rights, 'target' => $target?->id];
            }
        }
        foreach ($this->em->getRepository(Entity\ProfileRight::class)->findBy(['name' => 'plugin_domains_open_ticket']) as $source) {
            if ($source->name !== 'plugin_domains_open_ticket' || !in_array($source->rights, [0, 1], true)) {
                throw new \RuntimeException('Unsupported Domains helpdesk permission: glpi_profilerights.' . $source->id);
            }
        }
        return [$rights, $policies];
    }

    /** Actual entity ownership and recursion determine whether two rules can deliver twice. */
    private function notificationScope(Entity\Notification $notification): array
    {
        $owner = $notification->entities->id;
        $result = [$owner];
        if (!$notification->is_recursive) {
            return $result;
        }
        foreach ($this->em->getRepository(Entity\Entity::class)->findAll() as $entity) {
            $parent = $entity->parent;
            $seen = [];
            while ($parent !== null) {
                if (isset($seen[$parent->id])) {
                    throw new \RuntimeException('Domains notification scope contains an entity cycle: ' . $entity->id);
                }
                $seen[$parent->id] = true;
                if ($parent->id === $owner) {
                    $result[] = $entity->id;
                    break;
                }
                $parent = $parent->parent;
            }
        }
        return $result;
    }

    public function apply(array $rights, array $policies): void
    {
        foreach ($rights as $grant) {
            $right = $grant['target'] === null ? new Entity\ProfileRight() : $this->em->find(Entity\ProfileRight::class, $grant['target']);
            $right->profiles = $this->em->getReference(Entity\Profile::class, $grant['profile']);
            $right->name = $grant['name'];
            $right->rights = $grant['rights'];
            $this->em->persist($right);
        }
        foreach ($policies as $policy) {
            $row = $this->em->find($policy['class'], $policy['id']);
            foreach ($policy['fields'] as $field => $value) {
                $row->$field = $value;
            }
        }
        $this->em->flush();
    }
}
