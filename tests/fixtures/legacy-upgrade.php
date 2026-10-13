<?php

/** Native assertions shared by the real-source upgrade shell harness. */
$phase = $argv[1] ?? '';
if ($phase === 'seed') {
    require getenv('ITSM_LEGACY_SOURCE') . '/vendor/atoum/atoum/classes/autoloader.php';
    require getenv('ITSM_LEGACY_SOURCE') . '/tests/bootstrap.php';
    $entity = (int) getItemByTypeName('Entity', '_test_root_entity', true);
    $user = (int) getItemByTypeName('User', TU_USER, true);
    $create = static function (string $type, array $input): int {
        $id = (new $type())->add($input);
        if (!$id) {
            throw new RuntimeException("Original application failed to create $type.");
        }
        return (int) $id;
    };
    $group = $create('Group', ['name' => '_legacy_upgrade_group', 'entities_id' => $entity]);
    $create('Group_User', ['groups_id' => $group, 'users_id' => $user]);
    $ticket = $create('Ticket', ['name' => '_legacy_upgrade_ticket', 'content' => 'Retain linked ticket content', 'entities_id' => $entity]);
    $create('Ticket_User', ['tickets_id' => $ticket, 'users_id' => $user, 'type' => CommonITILActor::REQUESTER]);
    $document = $create('Document', ['name' => '_legacy_upgrade_document', 'entities_id' => $entity]);
    $create('Document_Item', ['documents_id' => $document, 'itemtype' => 'Ticket', 'items_id' => $ticket]);
    exit(0);
}
if (!in_array($phase, ['empty', 'before', 'after'], true)) {
    throw new InvalidArgumentException('Expected empty, seed, before or after.');
}
$database = getenv('ITSM_UPGRADE_DB_NAME');
if (!preg_match('/^itsm_test_[a-z0-9_]+_upgrade$/D', $database)) {
    throw new RuntimeException('Use a separately provisioned itsm_test_*_upgrade database.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    getenv('ITSM_UPGRADE_DB_HOST'),
    getenv('ITSM_UPGRADE_DB_USER'),
    getenv('ITSM_UPGRADE_DB_PASSWORD'),
    $database,
    (int) getenv('ITSM_UPGRADE_DB_PORT'),
    getenv('ITSM_UPGRADE_DB_SOCKET') ?: null
);
$db->set_charset('utf8mb4');
if ($phase === 'empty') {
    if ($db->query('SHOW TABLES')->num_rows !== 0) {
        throw new RuntimeException('Refusing to install into a nonempty database.');
    }
    exit(0);
}

// These fields must survive adoption exactly, including identifiers used by
// linked records. Hash contents rather than writing passwords/data to artifacts.
$columns = [
    'glpi_users' => 'id,name,password',
    'glpi_computers' => 'id,name',
    'glpi_tickets' => 'id,name,content',
    'glpi_documents' => 'id,name',
    'glpi_documents_items' => 'id,documents_id,itemtype,items_id',
    'glpi_groups' => 'id,name,entities_id',
    'glpi_profiles_users' => 'id,users_id,profiles_id,entities_id',
    'glpi_groups_users' => 'id,groups_id,users_id',
    'glpi_tickets_users' => 'id,tickets_id,users_id,type',
    'glpi_profilerights' => 'id,profiles_id,name,rights',
];
$key = getenv('GLPI_CONFIG_DIR') . '/glpicrypt.key';
if (!is_file($key) || filesize($key) === 0) {
    throw new RuntimeException('The original installer must create an encryption key.');
}
$proof = ['key_sha256' => hash_file('sha256', $key)];
$file = getenv('ITSM_UPGRADE_WORK_DIR') . '/before.json';
$before = $phase === 'after' ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
foreach ($columns as $table => $select) {
    // The release introduces new rights; existing profile assignments and
    // rights must remain intact while those new definitions may be appended.
    $where = $table === 'glpi_profilerights' && $before !== null
        ? ' WHERE id <= ' . (int) $before[$table]['max_id'] : '';
    $rows = $db->query("SELECT $select FROM $table$where ORDER BY id")->fetch_all(MYSQLI_NUM);
    if ($rows === []) {
        throw new RuntimeException("The original dataset did not populate $table.");
    }
    $rows = array_map(static fn ($row) => array_map(static fn ($value) => $value === null ? null : (string) $value, $row), $rows);
    $proof[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    if ($table === 'glpi_profilerights') {
        $proof[$table]['max_id'] = (int) $rows[array_key_last($rows)][0];
    }
}
if ($phase === 'before') {
    $version = $db->query("SELECT value FROM glpi_configs WHERE context='core' AND name='version'")->fetch_row()[0] ?? null;
    $schema = $db->query("SELECT value FROM glpi_configs WHERE context='core' AND name='dbversion'")->fetch_row()[0] ?? null;
    $dataset = $db->query("SELECT value FROM glpi_configs WHERE context='phpunit' AND name='dataset'")->fetch_row()[0] ?? null;
    if ($version !== '2.1.7' || $schema !== '2.1.3' || $dataset !== '4.7') {
        throw new RuntimeException('Expected ITSM-NG 2.1.7, its original 2.1.3 schema and completed 4.7 dataset.');
    }
    file_put_contents($file, json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Captured original key and populated application data fingerprints.\n";
} else {
    if ($proof !== $before) {
        throw new RuntimeException('Upgrade changed retained identities, contents, passwords, rights or the original key.');
    }
    echo "Original key, accounts, assets, tickets, documents, memberships and rights preserved.\n";
}
