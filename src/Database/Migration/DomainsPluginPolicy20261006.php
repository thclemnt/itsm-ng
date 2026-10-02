<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

/** Frozen 2.1.0 grants and alert semantics, separate from today's domain services. */
final class DomainsPluginPolicy20261006
{
    public function plan(Connection $connection, array $source): array
    {
        $configs = $source['glpi_plugin_domains_configs'];
        if (count($configs) !== 1 || $configs[0]['id'] !== '1') {
            throw new \RuntimeException('Frozen Domains adoption requires exactly config row 1.');
        }
        $fields = ['send_domains_alert_expired_delay' => DomainsPluginSnapshot20261006::integer($configs[0]['delay_expired'], 'config.delay_expired'),
            'send_domains_alert_close_expiries_delay' => DomainsPluginSnapshot20261006::integer($configs[0]['delay_whichexpire'], 'config.delay_whichexpire')];
        $notifications = $connection->fetchAllAssociative('SELECT * FROM glpi_notifications WHERE itemtype = ? ORDER BY id', ['PluginDomainsDomain']);
        $entities = [];
        foreach ($connection->fetchAllAssociative('SELECT id, entities_id, use_domains_alert, send_domains_alert_expired_delay, send_domains_alert_close_expiries_delay FROM glpi_entities ORDER BY id') as $row) {
            $entities[(int)$row['id']] = $row;
        }
        $scope = static function (array $notification) use ($entities): array {
            $owner = (int)$notification['entities_id'];
            if (!isset($entities[$owner])) {
                throw new \RuntimeException('Missing frozen Domains notification entity: ' . $owner);
            }
            $scope = [$owner];
            if ($notification['is_recursive']) {
                foreach ($entities as $id => $entity) {
                    $seen = [];
                    $cursor = $id;
                    while ($cursor !== 0 && isset($entities[$cursor])) {
                        if (isset($seen[$cursor])) {
                            throw new \RuntimeException('Cyclic frozen Domains notification scope: entity ' . $id);
                        }
                        $seen[$cursor] = true;
                        $cursor = (int)$entities[$cursor]['entities_id'];
                        if ($cursor === $owner) {
                            $scope[] = $id;
                            break;
                        }
                    }
                }
            }
            return array_unique($scope);
        };
        $active = false;
        foreach ($notifications as $notification) {
            if ($notification['itemtype'] !== 'PluginDomainsDomain' || !in_array($notification['event'], ['ExpiredDomains', 'DomainsWhichExpire'], true)) {
                throw new \RuntimeException('Unsupported frozen Domains notification: ' . $notification['id']);
            }
            if ($notification['is_active']) {
                $active = true;
                foreach ($connection->fetchAllAssociative('SELECT * FROM glpi_notifications WHERE itemtype = ? AND event = ? AND is_active = ?', ['Domain', $notification['event'], true], ['string', 'string', 'boolean']) as $core) {
                    if (array_intersect($scope($notification), $scope($core))) {
                        throw new \RuntimeException('Frozen Domains notification delivery conflict: ' . $notification['id'] . '; reconcile overlapping active core rules before adoption.');
                    }
                }
            }
        }
        $updates = [];
        $crons = $connection->fetchAllAssociative('SELECT * FROM glpi_crontasks WHERE itemtype = ? ORDER BY id', ['PluginDomainsDomain']);
        $coreCrons = $connection->fetchAllAssociative('SELECT * FROM glpi_crontasks WHERE itemtype = ? AND name = ?', ['Domain', 'DomainsAlert']);
        if (count($coreCrons) > 1 || count($crons) > 1) {
            throw new \RuntimeException('Ambiguous frozen Domains scheduler identities.');
        }
        $enabled = false;
        foreach ($crons as $cron) {
            if ($cron['itemtype'] !== 'PluginDomainsDomain' || $cron['name'] !== 'DomainsAlert') {
                throw new \RuntimeException('Unsupported frozen Domains scheduler: ' . $cron['id']);
            }
            $enabled = (int)$cron['state'] === 1 && $active;
            if ($coreCrons) {
                foreach (['frequency', 'param', 'state', 'mode', 'allowmode', 'hourmin', 'hourmax', 'logs_lifetime'] as $field) {
                    if ((string)$coreCrons[0][$field] !== (string)$cron[$field]) {
                        throw new \RuntimeException('Frozen Domains scheduler settings conflict: ' . $cron['id'] . '.' . $field . '; align intended scheduling before adoption.');
                    }
                }
                $updates[] = ['table' => 'glpi_crontasks', 'id' => (int)$cron['id'], 'values' => ['state' => 0]];
            } else {
                $updates[] = ['table' => 'glpi_crontasks', 'id' => (int)$cron['id'], 'values' => ['itemtype' => 'Domain']];
            }
        }
        $fields['use_domains_alert'] = (int)$enabled;
        foreach ($entities as $id => $entity) {
            foreach ($fields as $field => $value) {
                if (!in_array((int)$entity[$field], [-2, $value], true)) {
                    throw new \RuntimeException('Frozen Domains entity alert policy conflict: ' . $id . '.' . $field . '; align configured root/child delivery and delays with the intended plugin policy before adoption; inheritance is -2.');
                }
            }
        }
        if (!isset($entities[0])) {
            throw new \RuntimeException('Frozen Domains policy requires the root entity row; reconcile legacy root configuration before adoption.');
        }
        $updates[] = ['table' => 'glpi_entities', 'id' => 0, 'values' => $fields];
        $rights = $connection->fetchAllAssociative('SELECT * FROM glpi_profilerights WHERE name IN (?, ?, ?) ORDER BY id', ['plugin_domains', 'plugin_domains_dropdown', 'plugin_domains_open_ticket']);
        $grants = $ticket = $retained = [];
        foreach ($rights as $right) {
            $name = $right['name'];
            if (!in_array($name, ['plugin_domains', 'plugin_domains_dropdown', 'plugin_domains_open_ticket'], true)) {
                throw new \RuntimeException('Noncanonical frozen Domains permission spelling: ' . $right['id']);
            }
            $mask = DomainsPluginSnapshot20261006::integer($right['rights'], 'permission.' . $right['id']);
            $profile = DomainsPluginSnapshot20261006::integer($right['profiles_id'], 'permission.profile', 1);
            if (!$connection->fetchOne('SELECT 1 FROM glpi_profiles WHERE id = ?', [$profile])) {
                throw new \RuntimeException('Missing frozen Domains permission profile: ' . $profile);
            }
            $retained[] = ['id' => (int)$right['id'], 'profiles_id' => $profile, 'name' => $name, 'rights' => $mask];
            if ($name === 'plugin_domains_open_ticket') {
                if ($mask > 1) {
                    throw new \RuntimeException('Invalid frozen Domains helpdesk grant: ' . $right['id']);
                }
                $ticket[$profile] = $mask;
                continue;
            }
            if (($mask & ~($name === 'plugin_domains' ? 127 : 31)) !== 0) {
                throw new \RuntimeException('Invalid frozen Domains permission mask: ' . $right['id']);
            }
            $target = $name === 'plugin_domains' ? 'domain' : 'domaintype';
            $current = $connection->fetchAssociative('SELECT id, rights FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [$profile, $target]);
            $existing = $current === false && $target === 'domaintype'
                ? $connection->fetchOne('SELECT rights FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [$profile, 'dropdown'])
                : ($current['rights'] ?? 0);
            if ($existing !== false && (int)$existing !== 0 && (int)$existing !== $mask) {
                throw new \RuntimeException('Frozen Domains permission conflict: profile ' . $profile . '.' . $target . '; reconcile dedicated grants before adoption; global dropdown permissions are never changed.');
            }
            $grants[] = ['profiles_id' => $profile, 'name' => $target, 'rights' => $mask, 'id' => $current['id'] ?? null];
        }
        foreach ($connection->fetchAllAssociative('SELECT id, helpdesk_item_type FROM glpi_profiles ORDER BY id') as $profile) {
            $values = self::array((string)($profile['helpdesk_item_type'] ?? ''), 'profile.' . $profile['id']);
            $id = (int)$profile['id'];
            $reserved = false;
            array_walk_recursive($values, static function ($value) use (&$reserved): void {
                $reserved = $reserved || (is_string($value) && strncasecmp(trim($value), 'PluginDomains', 13) === 0);
            });
            if (!isset($ticket[$id]) && !$reserved) {
                continue;
            }
            foreach ($values as $value) {
                if (!is_string($value) || (strncasecmp(trim($value), 'PluginDomains', 13) === 0 && $value !== 'PluginDomainsDomain')) {
                    throw new \RuntimeException('Unsupported frozen Domains helpdesk profile shape or spelling: ' . $id);
                }
            }
            $permission = $ticket[$id] ?? 0;
            if ($permission === 0 && in_array('Domain', $values, true)) {
                throw new \RuntimeException('Frozen Domains helpdesk policy conflict: profile ' . $id);
            }
            foreach ($values as $key => $value) {
                if ($value === 'PluginDomainsDomain') {
                    if ($permission === 1) {
                        $values[$key] = 'Domain';
                    } else {
                        unset($values[$key]);
                    }
                }
            }
            if ($permission === 1 && !in_array('Domain', $values, true)) {
                $values[] = 'Domain';
            }
            $updates[] = ['table' => 'glpi_profiles', 'id' => $id, 'values' => ['helpdesk_item_type' => json_encode($values, JSON_THROW_ON_ERROR)]];
        }
        return ['grants' => $grants, 'updates' => $updates, 'retained_source_rights' => $retained];
    }

    public static function array(string $encoded, string $field): array
    {
        if ($encoded === '') {
            return [];
        }
        $json = json_decode($encoded, true);
        if (is_array($json)) {
            return $json;
        }
        $values = [];
        foreach (explode(' ', $encoded) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=>', $part);
            if (count($pair) !== 2 || $pair[0] === '') {
                throw new \RuntimeException('Invalid frozen Domains encoded array: ' . $field);
            }
            $key = urldecode($pair[0]);
            if (array_key_exists($key, $values)) {
                throw new \RuntimeException('Duplicate frozen Domains encoded key: ' . $field);
            }
            $values[$key] = urldecode($pair[1]);
        }
        return $values;
    }
}
