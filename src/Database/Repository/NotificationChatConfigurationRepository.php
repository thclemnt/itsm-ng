<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\NotificationChatConfig;

/** Global chat settings for the configuration screen, without delivery side effects. */
final class NotificationChatConfigurationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** @return list<array{hookurl: ?string, chat: ?string, type: ?string, value: ?string, id: int|string}> */
    public function settingsRows(): array
    {
        $metadata = $this->em->getClassMetadata(NotificationChatConfig::class);
        $connection = $this->em->getConnection();
        $quote = $connection->quoteIdentifier(...);
        $columns = [];
        foreach (['hookurl', 'chat', 'type', 'value', 'id'] as $property) {
            $columns[] = $quote($metadata->getColumnName($property)) . ' AS ' . $quote($property);
        }
        // The original settings screen uses this fixed table, even if the public
        // legacy ChatConfig model is forced to another table for a delivery route.
        $rows = $connection->createQueryBuilder()->select(...$columns)
            ->from($quote('glpi_notificationchatconfigs'))->executeQuery()->fetchAllAssociative();
        // The PostgreSQL adapter normalizes actual int8 IDs in its legacy row API.
        // Other driver-native values, including all nullable strings, stay intact.
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $metadata->getTypeOfField('id'));
            }
            unset($row);
        }
        return $rows;
    }
}
