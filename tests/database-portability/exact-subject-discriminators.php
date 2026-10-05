<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\V220\ExactDiscriminators;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketAssetRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/exact-subject-discriminators.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
verify($DB instanceof DBAdapter && !$DB->isSlave() && str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated configured writer');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0 && History::pendingVersions($connection) === [], 'Idle completed canonical installation');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before branch controls');
$ledgerBefore = Ledger::states($connection);
$sessionBefore = $_SESSION;
$configurationBefore = $CFG_GLPI;
$physicalBefore = $connection;
$quote = $connection->quoteIdentifier(...);
// DBAL convenience writers accept SQL identifiers verbatim, including type-map keys.
$quoteColumns = static function (array $values) use ($quote): array {
    $quoted = [];
    foreach ($values as $column => $value) {
        $quoted[$quote($column)] = $value;
    }
    return $quoted;
};
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): array|false => $connection->fetchAssociative('SELECT * FROM ' . $quote($table) . ' WHERE id=?', [$id]);
$ownTarget = static function (string $table, array $values = []) use ($connection, $quote, $fixtures): int {
    $id = max(4294969000, 10000 + (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $quote($table)));
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' WHERE id=?', [$id]) === 0, 'Wide target identity is unused');
    return $fixtures->create($table, ['id' => $id] + $values);
};
$ownInfocom = static function () use ($ownTarget, $read): int {
    // Financial records are unique per real asset, independently of their IDs.
    $computer = $ownTarget('glpi_computers');
    $id = $ownTarget('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $computer]);
    $row = $read('glpi_infocoms', $id);
    verify(
        $read('glpi_computers', $computer) !== false && $row !== false
        && $row['itemtype'] === 'Computer' && (int)$row['items_id'] === $computer,
        'Owned financial subject retains its actual Computer'
    );
    return $id;
};
$nativeRefusal = static function (callable $operation, string $family, string $constraint = '') use ($connection): void {
    $connection->beginTransaction();
    try {
        $refused = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $state = $error->getSQLState();
            $code = $error->getCode();
            $refused = match ($family) {
                'check' => $state === '23514' || ($code === 4025 && in_array($state, ['23000', 'HY000'], true)) || NativeConstraintRefusal::matchesSelectedCheck($error, $constraint),
                'foreign' => $state === '23503' || ($code === 1452 && $state === '23000'),
                'unique' => $state === '23505' || ($code === 1062 && $state === '23000'),
                'generated' => $state === '428C9' || (in_array($code, [1906, 3105], true) && $state === 'HY000'),
                default => false,
            };
            if ($constraint !== '') {
                $native = $error->getPrevious();
                $refused = $refused && $native !== null && (str_contains($native->getMessage(), '"' . $constraint . '"')
                    || str_contains($native->getMessage(), '`' . $constraint . '`') || str_contains($native->getMessage(), "'" . $constraint . "'"));
            }
        }
        verify($refused, 'Actual native ' . $family . ' refusal, not an unrelated error');
    } finally {
        $connection->rollBack();
    }
};
$primaryError = null;
$cleanupErrors = [];
$connection->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Actual authorized application login');
    $CFG_GLPI['use_notifications'] = false;
    $manager = Orm::create($DB);
    verify($manager->getConnection() === $connection, 'Entity metadata and repositories keep supplied writer');
    $branchesTested = 0;
    foreach (ExactDiscriminators::definitions()['tables'] as $table => $definition) {
        $class = EntityRegistry::tables()[$table];
        $metadata = $manager->getClassMetadata($class);
        $key = (new ReflectionProperty($class, $definition['column']))->getAttributes(itsmng\Database\Mapping\DiscriminatorKey::class)[0]->newInstance();
        verify($key->exactDiscriminator, 'Exact policy belongs to each current key property');
        $selections = EntityRegistry::discriminatedReferences($table)[$definition['column']]['selections'];
        verify(count($selections) === count($definition['branches']) && !array_diff_key($selections, $definition['branches'])
            && !array_diff_key($definition['branches'], $selections), 'Frozen scope and current association kinds agree');
        $nativeIndexes = $connection->createSchemaManager()->listTableIndexes($table);
        foreach ($definition['branches'] as $kind => $branch) {
            verify($selections[$kind]['column'] === $branch['column'] && $selections[$kind]['target'] === $branch['target'], 'Frozen branch retains actual owning target');
            $createSubject = $kind === 'Infocom' ? $ownInfocom : static fn (): int => $ownTarget($branch['target']);
            $target = $kind === 'Entity' && $branch['minimum'] === 0 ? 0 : $createSubject();
            $link = $fixtures->create($table, ['itemtype' => $kind, $branch['column'] => $target]);
            $row = $read($table, $link);
            verify(is_array($row) && $row['itemtype'] === $kind && (int)$row['items_id'] === $target && (int)$row[$branch['column']] === $target, 'Canonical branch INSERT and compatibility projection');
            foreach ($selections as $otherKind => $other) {
                if ($otherKind !== $kind) {
                    verify($row[$other['column']] === null, 'Every unselected owning association is NULL');
                }
            }
            $payload = $row;
            unset($payload['id']);
            foreach ($metadata->fieldMappings as $field) {
                if (($field->generated ?? null) !== null) {
                    unset($payload[$field->columnName]);
                }
            }
            $types = array_fill_keys(array_keys(EntityRegistry::booleanFields($table)), Types::BOOLEAN);
            if (strtoupper($kind) === $kind) {
                $connection->update($quote($table), $quoteColumns(['itemtype' => strtoupper($kind)]), $quoteColumns(['id' => $link]));
                verify($read($table, $link) === $row, 'Canonical uppercase INSERT and UPDATE retain the exact owning row');
            }
            // Uppercase canonical kinds such as PDU are positive identities,
            // not invalid alternatives to themselves. Keep every changed value.
            foreach (array_filter([strtolower($kind), strtoupper($kind), $kind . ' ', $kind . '  ', ' ' . $kind, 'UnknownManagedSubject'], static fn (string $candidate): bool => $candidate !== $kind) as $bad) {
                $nativeRefusal(static function () use ($connection, $quote, $quoteColumns, $table, $link, $bad, $payload, $types): void {
                    // The invalid INSERT gets an unused relationship pair;
                    // this savepoint restores the positive row afterwards.
                    $connection->delete($quote($table), $quoteColumns(['id' => $link]));
                    $connection->insert($quote($table), $quoteColumns(['itemtype' => $bad] + $payload), $quoteColumns($types));
                }, 'check', $definition['constraint']);
                $nativeRefusal(static fn () => $connection->update($quote($table), $quoteColumns(['itemtype' => $bad]), $quoteColumns(['id' => $link])), 'check', $definition['constraint']);
                verify($read($table, $link) === $row, 'Invalid INSERT/UPDATE retains the full canonical row after savepoint rollback');
            }
            $otherKind = array_key_first(array_diff_key($definition['branches'], [$kind => true]));
            if ($otherKind !== null) {
                $nativeRefusal(static fn () => $connection->update($quote($table), $quoteColumns(['itemtype' => $otherKind]), $quoteColumns(['id' => $link])), 'check', $definition['constraint']);
            }
            $missingTarget = max(9999999000, 10000 + (int)$connection->fetchOne('SELECT MAX(id) FROM ' . $quote($branch['target'])));
            $nativeRefusal(static fn () => $connection->update($quote($table), $quoteColumns([$branch['column'] => $missingTarget]), $quoteColumns(['id' => $link])), 'foreign');
            $nativeRefusal(static fn () => $connection->update($quote($table), $quoteColumns(['items_id' => $target + 1]), $quoteColumns(['id' => $link])), 'generated');
            if ($branch['minimum'] === 0) {
                $nativeRefusal(static fn () => $connection->update($quote($table), $quoteColumns([$branch['column'] => null]), $quoteColumns(['id' => $link])), 'check', $definition['constraint']);
            }
            $unique = false;
            foreach ($nativeIndexes as $index) {
                if (!$index->isUnique() || $index->isPrimary() || $index->hasOption('where')) {
                    continue;
                }
                $unique = $unique || !array_filter($index->getColumns(), static fn ($column) => !array_key_exists($column, $row) || $row[$column] === null);
            }
            if ($unique) {
                $nativeRefusal(static fn () => $connection->insert($quote($table), $quoteColumns($payload), $quoteColumns($types)), 'unique');
            } else {
                $connection->beginTransaction();
                try {
                    $connection->insert($quote($table), $quoteColumns($payload), $quoteColumns($types));
                    verify((int)$connection->lastInsertId() !== $link, 'Nonunique relationship retains independent duplicate row');
                } finally {
                    $connection->rollBack();
                }
            }
            $replacement = $createSubject();
            $connection->update($quote($table), $quoteColumns([$branch['column'] => $replacement]), $quoteColumns(['id' => $link]));
            verify((int)$read($table, $link)['items_id'] === $replacement, 'Canonical UPDATE retains owning projection');
            $connection->delete($quote($table), $quoteColumns(['id' => $link]));
            verify($read($table, $link) === false && $read($branch['target'], $replacement) !== false, 'Relationship DELETE retains subject');
            ++$branchesTested;
        }
    }
    verify($branchesTested === 293, 'Every frozen owning branch was exercised');
    $stock = $fixtures->create('glpi_consumables');
    verify($read('glpi_consumables', $stock)['itemtype'] === null && (int)$read('glpi_consumables', $stock)['items_id'] === 0, 'Empty stock has NULL kind/owners and zero compatibility identity');
    $nativeRefusal(static fn () => $connection->update($quote('glpi_consumables'), $quoteColumns(['date_out' => '2030-01-01']), $quoteColumns(['id' => $stock])), 'check', 'glpi_consumables_typed_item_kind');
    $recipient = $ownTarget('glpi_users');
    $consumable = new Consumable();
    verify($consumable->out($stock, 'User', $recipient), 'Actual public stock issue uses canonical owning association');
    $issuedBefore = $read('glpi_consumables', $stock);
    verify(!$consumable->out($stock, 'user', $recipient) && $read('glpi_consumables', $stock) === $issuedBefore, 'Public lowercase recipient refuses without native coercion or changed usage');
    verify($consumable->backToStock(['id' => $stock]) && $read('glpi_consumables', $stock)['date_out'] === null
        && $read('glpi_consumables', $stock)['itemtype'] === 'User', 'Returning stock keeps exact historical recipient and clears only usage date');
    verify((new User())->delete(['id' => $recipient], true), 'Public recipient purge');
    $released = $read('glpi_consumables', $stock);
    verify($released['itemtype'] === null && $released['users_id'] === null && $released['groups_id'] === null && $released['date_out'] === null && (int)$released['items_id'] === 0, 'Recipient purge releases stock through its domain service');
    $asset = $ownTarget('glpi_computers');
    $ticket = $fixtures->create('glpi_tickets');
    $publicLink = (new Item_Ticket())->add(['tickets_id' => $ticket, 'itemtype' => 'Computer', 'items_id' => $asset, '_do_notif' => false]);
    verify((int)$publicLink > 0, 'Actual public ticket relationship add');
    $repository = new TicketAssetRepository(Orm::create($DB));
    verify($repository->activeCount('Computer', $asset, []) === 1 && $repository->activeCount('computer', $asset, []) === 0, 'Canonical owning domain query and unsupported exact kind boundary');
    verify((new Computer())->delete(['id' => $asset], true) && $read('glpi_items_tickets', (int)$publicLink) === false && $read('glpi_tickets', $ticket) !== false, 'Public subject purge clears its exact owning link while retaining Ticket');
} catch (Throwable $error) {
    $primaryError = $error;
} finally {
    while ($connection->getTransactionNestingLevel() > 0) {
        try {
            $connection->rollBack();
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
            break;
        }
    }
    $_SESSION = $sessionBefore;
    $CFG_GLPI = $configurationBefore;
    if ($cleanupErrors) {
        fwrite(STDERR, 'Owned branch rollback failures: ' . count($cleanupErrors) . "; original failure retained.\n");
    }
}
if ($primaryError !== null) {
    throw $primaryError;
}
if ($cleanupErrors) {
    throw new RuntimeException('Exact branch fixture rollback failed.', previous: $cleanupErrors[0]);
}
verify($DB->getDoctrineConnection() === $physicalBefore && $connection->getTransactionNestingLevel() === 0, 'Same configured physical writer returns idle');
verify(Ledger::states($connection) === $ledgerBefore && (new SchemaCheck())->differences($connection) === [], 'Rollback preserves canonical history and schema');
echo $DB->getProvider() . ': ' . $assertions . " exact managed subject assertions passed.\n";
