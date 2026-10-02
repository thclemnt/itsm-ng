<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Database\Type\FixedStringType;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/fixed-strings.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
verify((new SchemaCheck())->differences($connection) === [], 'Fixed-string mapping preserves installed historical schema');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $em = Orm::create($DB);
    $user = new Record\User();
    $user->entities = $em->getReference(Record\Entity::class, 0);
    $user->name = 'Fixed string ' . bin2hex(random_bytes(4));
    $user->language = 'en_GB';
    $preferences = ['csv_delimiter' => ',', 'layout' => 'vsplit', 'palette' => 'darker'];
    foreach (range(1, 6) as $priority) {
        $preferences['priority_' . $priority] = sprintf('#%06x', $priority * 256);
    }
    foreach ($preferences as $field => $value) {
        $user->{$field} = $value;
    }
    $token = sha1('Fixed token ' . $user->name);
    $user->password_forget_token = $token;
    $user->password_forget_token_date = new DateTime();
    $user->comment = "Text keeps spaces  ";
    $em->persist($user);
    $em->flush();
    $id = $user->id;
    $em->clear();
    $stored = $em->find(Record\User::class, $id);
    verify($stored->language === 'en_GB' && $stored->comment === 'Text keeps spaces  ', 'Native ORM removes CHAR padding without trimming unrelated text');
    if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
        verify($connection->fetchOne('SELECT language FROM glpi_users WHERE id = ?', [$id]) === 'en_GB     ', 'Actual PostgreSQL CHAR padding is present before domain conversion');
    }
    $legacy = new User();
    verify($legacy->getFromDB($id) && $legacy->fields['language'] === 'en_GB', 'Application locale lookup sees a valid unpadded code');
    $records = new RecordRepository($em);
    $legacy->computePreferences();
    foreach ($preferences + ['password_forget_token' => $token] as $field => $value) {
        verify($stored->{$field} === $value && $legacy->fields[$field] === $value, 'Native and application preference/token reads agree: ' . $field);
        verify($records->distinctValues('glpi_users', $field, ['id' => $id]) === [[$field => $value]], 'Scalar preference/token read uses logical CHAR values: ' . $field);
        verify($em->getClassMetadata(Record\User::class)->getTypeOfField($field) === FixedStringType::NAME, 'User property owns CHAR semantics: ' . $field);
        $stored->{$field} = null;
        $em->flush();
        $em->refresh($stored);
        verify($stored->{$field} === null && $records->find('glpi_users', 'id', $id)[$field] === null, 'Optional preference/token preserves NULL: ' . $field);
        $stored->{$field} = '';
        $em->flush();
        $em->refresh($stored);
        verify($stored->{$field} === '' && $records->distinctValues('glpi_users', $field, ['id' => $id]) === [[$field => '']], 'Blank preference/token remains distinct from NULL: ' . $field);
        $stored->{$field} = $value;
        $em->flush();
    }
    verify(User::getUserByForgottenPasswordToken($token)?->getID() === $id, 'Password reset lookup returns the account with its exact complete token');
    $stored->palette = null;
    $stored->layout = '';
    $em->flush();
    verify($legacy->getFromDB($id), 'Reload stored optional preferences');
    $legacy->computePreferences();
    verify($legacy->fields['palette'] === $CFG_GLPI['palette'] && $legacy->fields['layout'] === '', 'Preference policy inherits a NULL value and retains an explicitly empty value');

    $ruleId = $fixtures->create('glpi_rules', ['sub_type' => RuleTicket::class, 'match' => Rule::AND_MATCHING]);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $ruleId, 'criteria' => 'name', 'condition' => Rule::PATTERN_IS, 'pattern' => 'Matching title']);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $ruleId, 'criteria' => 'content', 'condition' => Rule::PATTERN_IS, 'pattern' => 'Required content']);
    $rule = new RuleTicket();
    verify($rule->getRuleWithCriteriasAndActions($ruleId, true), 'Load actual ticket rule and its criteria');
    $input = ['name' => 'Matching title', 'content' => 'Other content'];
    verify(!$rule->checkCriterias($input), 'Unpadded AND token requires both real criteria to match');
    $nativeRule = $em->find(Record\Rule::class, $ruleId);
    verify($nativeRule->match === Rule::AND_MATCHING && $records->distinctValues('glpi_rules', 'match', ['id' => $ruleId]) === [['match' => Rule::AND_MATCHING]], 'Native and scalar rule join tokens agree');
    $nativeRule->match = Rule::OR_MATCHING;
    $em->flush();
    verify($rule->getRuleWithCriteriasAndActions($ruleId, true) && $rule->checkCriterias($input), 'Native rule update selects OR behavior in the application');
    foreach ([null, ''] as $value) {
        $nativeRule->match = $value;
        $em->flush();
        $em->refresh($nativeRule);
        verify($nativeRule->match === $value && $records->find('glpi_rules', 'id', $ruleId)['match'] === $value, 'Rule CHAR token preserves NULL and blank separately');
    }
    verify($em->getClassMetadata(Record\Rule::class)->getTypeOfField('match') === FixedStringType::NAME, 'Rule owns its match type');

    $file = tempnam(sys_get_temp_dir(), 'itsm-fixed-hash-');
    try {
        file_put_contents($file, 'Actual fixed-string document content');
        $hash = sha1_file($file);
        $document = new Record\Document();
        $document->entities = $em->getReference(Record\Entity::class, 0);
        $document->sha1sum = $hash;
        $document->comment = 'Document text keeps spaces  ';
        $em->persist($document);
        $em->flush();
        $em->refresh($document);
        $legacyDocument = new Document();
        verify($document->sha1sum === $hash && $legacyDocument->getFromDBbyContent(0, $file) && $legacyDocument->getID() === $document->id, 'Document content lookup uses the complete exact hash written through ORM');
        verify($legacyDocument->fields['sha1sum'] === $hash && $legacyDocument->fields['comment'] === 'Document text keeps spaces  ', 'Document domain hash is exact while unrelated text keeps whitespace');
        verify($records->distinctValues('glpi_documents', 'sha1sum', ['id' => $document->id]) === [['sha1sum' => $hash]], 'Document scalar hash agrees with native and public reads');
        foreach ([null, ''] as $value) {
            $document->sha1sum = $value;
            $em->flush();
            $em->refresh($document);
            verify($document->sha1sum === $value && $records->distinctValues('glpi_documents', 'sha1sum', ['id' => $document->id]) === [['sha1sum' => $value]], 'Absent document hash preserves NULL and blank separately');
            verify($legacyDocument->getFromDB($document->id) && $legacyDocument->fields['sha1sum'] === $value, 'Application document model preserves absent hash semantics');
            verify(!$legacyDocument->getFromDBbyContent(0, $file), 'Absent hash never matches file content');
        }
        verify($em->getClassMetadata(Record\Document::class)->getTypeOfField('sha1sum') === FixedStringType::NAME, 'Document owns its fixed hash type');
    } finally {
        unlink($file);
    }
    foreach (['glpi_slalevels' => Record\SlaLevel::class, 'glpi_olalevels' => Record\OlaLevel::class] as $table => $class) {
        $levelId = $fixtures->create($table, ['match' => 'AND']);
        $level = $em->find($class, $levelId);
        verify($level->match === 'AND' && $records->find($table, 'id', $levelId)['match'] === 'AND', 'Rule join token remains unpadded: ' . $table);
        $metadata = $em->getClassMetadata($class);
        verify($metadata->getTypeOfField('match') === FixedStringType::NAME, 'Entity owns its fixed-string type: ' . $table);
        $level->match = null;
        $em->flush();
        $em->refresh($level);
        verify($level->match === null, 'Nullable fixed string preserves NULL: ' . $table);
        $level->match = '';
        $em->flush();
        $em->refresh($level);
        verify($level->match === '', 'Empty fixed string remains distinct from NULL: ' . $table);
    }
    $stored->language = 'fr_FR';
    $em->flush();
    $em->refresh($stored);
    verify($stored->language === 'fr_FR', 'Native locale update round-trip');
    $em->clear();
    verify($records->distinctValues('glpi_users', 'language', ['id' => $id]) === [['language' => 'fr_FR']], 'Scalar projections use the same entity-local conversion');
    verify($records->matching('glpi_users', ['language' => 'fr_FR', 'id' => $id])[0]['language'] === 'fr_FR', 'Typed equality binds fixed strings correctly');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": entity-local CHAR semantics, native/scalar/legacy locale and preferences, reset tokens, actual rule matching, document hashes, unchanged schema, free-text whitespace and nullable/empty values passed.\n";
