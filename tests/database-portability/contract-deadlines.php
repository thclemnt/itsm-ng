<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity as Record;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContractRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Domain\ContractAlertOutcome;
use itsmng\Domain\ContractAlertPublisher;
use itsmng\Domain\ContractSchedule;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/contract-deadlines.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
final class ContractCronProbe extends CronTask
{
    public array $messages = [];
    private int $observedVolume = 0;
    public function addVolume($volume)
    {
        parent::addVolume($volume);
        $this->observedVolume += $volume;
    }
    public function log($content)
    {
        $this->messages[] = $content;
        return true;
    }
    public function countVolume(): int
    {
        return $this->observedVolume;
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedConfig = $CFG_GLPI;
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'orm_contract_fixture']);
$connection = $DB->getDoctrineConnection();
$connection->beginTransaction();
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
try {
    $CFG_GLPI['use_notifications'] = false;
    $fixtures = new FixtureRecords($DB);
    $read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $writer = static fn (): RecordWriter => new RecordWriter(Orm::create($DB));
    $repo = static fn (): ContractRepository => Orm::create($DB)->getRepository(Record\Contract::class);
    $entity = (int)(new Entity())->add(['name' => 'Contract date boundaries', 'entities_id' => 0, 'use_contracts_alert' => 0]);
    verify($entity > 0, 'Calendar entity');
    $today = new DateTimeImmutable('2024-02-28 17:30:00');
    foreach ([['2023-01-31', 1, '2023-02-28'], ['2024-01-31', 1, '2024-02-29'], ['2024-02-29', 12, '2025-02-28'], ['2024-08-31', 1, '2024-09-30'], ['2024-12-31', 2, '2025-02-28'], ['2024-03-31', -1, '2024-02-29']] as [$begin, $months, $date]) {
        $record = new Record\Contract();
        $record->begin_date = new DateTimeImmutable($begin);
        $record->duration = $months;
        $record->periodicity = 1;
        verify($record->endsOn()?->format('Y-m-d') === $date, 'Entity anniversary clamps ' . $begin);
        $id = $fixtures->create('glpi_contracts', ['name' => $begin, 'entities_id' => $entity, 'begin_date' => $begin, 'duration' => $months, 'alert' => 1 << Alert::END]);
        $boundary = new DateTimeImmutable($date);
        verify(in_array($id, array_column($repo()->notificationCandidates($entity, Alert::END, 1, $boundary), 'id'), true)
            && !in_array($id, array_column($repo()->notificationCandidates($entity, Alert::END, 0, $boundary), 'id'), true), 'Provider and domain agree at strict expiry cutoff ' . $date);
        verify(Contract::formatDeadline($read('glpi_contracts', $id)) === Html::convDate($date), 'Public label uses the selected date');
    }
    $calendar = ContractSchedule::fromFields(['begin_date' => '2024-01-31', 'duration' => 1, 'periodicity' => 1, 'notice' => 1]);
    verify($calendar->duePeriod(0, null, false, new DateTimeImmutable('2024-02-29'))?->format('Y-m-d') === '2024-02-29', 'First periodic date uses leap month end');
    verify($calendar->duePeriod(0, new DateTimeImmutable('2024-02-29 23:59:59'), false, new DateTimeImmutable('2024-03-31'))?->format('Y-m-d') === '2024-03-31', 'Next periodic date retains original anniversary without February drift');
    verify($calendar->duePeriod(0, new DateTimeImmutable('2024-03-31 00:00:01'), false, new DateTimeImmutable('2024-03-31')) === null, 'Repeated periodic calls on one day are quiet');
    verify($calendar->deadline(false, true, new DateTimeImmutable('2024-02-29'))?->format('Y-m-d') === '2024-03-31', 'Automatic renewal remains anchored after current anniversary');
    verify($calendar->duePeriod(2, new DateTimeImmutable('2024-01-29 16:00:00'), true, new DateTimeImmutable('2024-02-26')) === null
        && $calendar->duePeriod(2, new DateTimeImmutable('2024-01-29 16:00:00'), true, new DateTimeImmutable('2024-02-27'))?->format('Y-m-d') === '2024-02-29', 'Periodic notice selects the anchored leap anniversary exactly at its configured advance day');
    verify(ContractSchedule::fromFields(['begin_date' => '2024-01-31', 'duration' => 0, 'periodicity' => 3])->duePeriod(0, null, false, new DateTimeImmutable('2024-04-30'))?->format('Y-m-d') === '2024-04-30', 'Zero initial duration retains the positive periodicity fallback and clamped first anniversary');
    verify(ContractSchedule::fromFields(['begin_date' => null, 'duration' => 1, 'periodicity' => 1])->duePeriod(0, null) === null, 'Missing dates remain absent');
    foreach ([0, -1] as $period) {
        verify(ContractSchedule::fromFields(['begin_date' => '2024-01-01', 'duration' => 1, 'periodicity' => $period])->duePeriod(0, null) === null, 'Nonpositive periods terminate without an alert');
    }

    $bucketEntity = $fixtures->create('glpi_entities', ['name' => 'Exact dashboard buckets']);
    foreach ([-30, -29, -1, 0, 1, 7, 8, 29, 30] as $day) {
        $fixtures->create('glpi_contracts', ['entities_id' => $bucketEntity, 'begin_date' => $today->modify(sprintf('%+d days', $day))->format('Y-m-d'), 'duration' => 0]);
    }
    foreach ([1, 7, 8, 29, 30] as $day) {
        $fixtures->create('glpi_contracts', ['entities_id' => $bucketEntity, 'begin_date' => $today->modify(sprintf('%+d days', $day))->format('Y-m-d'), 'duration' => 1, 'notice' => 1]);
    }
    $fixtures->create('glpi_contracts', ['entities_id' => $bucketEntity, 'begin_date' => '2024-02-29', 'is_deleted' => true]);
    $fixtures->create('glpi_contracts', ['entities_id' => $bucketEntity, 'begin_date' => null]);
    $fixtures->create('glpi_contracts', ['entities_id' => $bucketEntity, 'begin_date' => '2024-02-29', 'is_template' => true]);
    verify($repo()->deadlineCounts(['entities_id' => $bucketEntity], $today) === ['expired' => 2, 'ending7' => 3, 'ending30' => 2, 'notice7' => 2, 'notice30' => 2], 'Five dashboard buckets retain strict edges and existing template policy');
    verify(array_sum($repo()->deadlineCounts(getEntitiesRestrictCriteria(Contract::getTable(), 'entities_id', []), $today)) === 0, 'Empty dashboard scope exposes no rows');

    $cases = [];
    foreach (['eligible', 'disabled', 'deleted', 'zero', 'missing', 'template', 'alerted', 'other_event', 'other_kind', 'foreign'] as $name) {
        $cases[$name] = $fixtures->create('glpi_contracts', ['id' => $name === 'other_kind' ? 4294967301 : null,
            'name' => $name, 'entities_id' => $name === 'foreign' ? 0 : $entity,
            'begin_date' => $name === 'missing' ? null : '2024-01-31', 'duration' => $name === 'zero' ? 0 : 1,
            'is_deleted' => $name === 'deleted', 'is_template' => $name === 'template', 'alert' => $name === 'disabled' ? 0 : (1 << Alert::END) | (1 << Alert::NOTICE)]);
    }
    $fixtures->create('glpi_alerts', ['itemtype' => 'Contract', 'items_id' => $cases['alerted'], 'type' => Alert::END]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'Contract', 'items_id' => $cases['other_event'], 'type' => Alert::NOTICE]);
    $asset = $fixtures->create('glpi_computers');
    $info = $fixtures->create('glpi_infocoms', ['id' => $cases['other_kind'], 'itemtype' => 'Computer', 'items_id' => $asset]);
    $fixtures->create('glpi_alerts', ['itemtype' => 'Infocom', 'items_id' => $info, 'type' => Alert::END]);
    $selected = array_column($repo()->notificationCandidates($entity, Alert::END, 2, $today), 'id');
    foreach (['eligible', 'template', 'other_event', 'other_kind'] as $name) {
        verify(in_array($cases[$name], $selected, true), 'Eligible owning subject ' . $name);
    }
    foreach (['disabled', 'deleted', 'zero', 'missing', 'alerted', 'foreign'] as $name) {
        verify(!in_array($cases[$name], $selected, true), 'Ineligible subject ' . $name);
    }
    verify(!in_array($cases['eligible'], array_column($repo()->notificationCandidates($entity, Alert::END, 1, $today), 'id'), true), 'Before-delay boundary remains strict');
    $notice = $fixtures->create('glpi_contracts', ['entities_id' => $entity, 'begin_date' => '2024-01-31', 'duration' => 2, 'notice' => 1, 'alert' => 1 << Alert::NOTICE]);
    verify(in_array($notice, array_column($repo()->notificationCandidates($entity, Alert::NOTICE, 2, $today), 'id'), true), 'Notice uses duration minus notice calendar months');
    verify(!in_array($notice, array_column($repo()->notificationCandidates($entity, Alert::NOTICE, 0, new DateTimeImmutable('2024-03-31')), 'id'), true), 'Already ended contracts do not emit initial notice');

    $choiceEntity = $fixtures->create('glpi_entities', ['name' => 'Connection choices']);
    $unlimited = $fixtures->create('glpi_contracts', ['name' => 'Unlimited', 'entities_id' => $choiceEntity]);
    $full = $fixtures->create('glpi_contracts', ['name' => 'Full', 'entities_id' => $choiceEntity, 'max_links_allowed' => 2]);
    $automatic = $fixtures->create('glpi_contracts', ['name' => 'Automatic', 'entities_id' => $choiceEntity, 'begin_date' => '2000-01-01', 'duration' => 1, 'renewal' => Contract::RENEWAL_TACIT]);
    $expired = $fixtures->create('glpi_contracts', ['name' => 'Expired', 'entities_id' => $choiceEntity, 'begin_date' => '2000-01-01', 'duration' => 1]);
    $template = $fixtures->create('glpi_contracts', ['name' => 'Template', 'entities_id' => $choiceEntity, 'is_template' => true]);
    foreach (['Computer' => 'glpi_computers', 'Monitor' => 'glpi_monitors'] as $kind => $table) {
        $subject = $fixtures->create($table, ['id' => 4294967310, 'entities_id' => $kind === 'Monitor' ? 0 : $choiceEntity]);
        $fixtures->create('glpi_contracts_items', ['itemtype' => $kind, 'items_id' => $subject, 'contracts_id' => $full]);
    }
    $available = array_column($repo()->availableForConnection(['entities_id' => $choiceEntity], false, [], false, $today), 'id');
    verify(in_array($unlimited, $available, true) && in_array($automatic, $available, true) && !in_array($full, $available, true) && !in_array($expired, $available, true) && !in_array($template, $available, true), 'Available choices count every typed subject and preserve renewal/template/expiry policy');
    verify(in_array($full, array_column($repo()->availableForConnection(['entities_id' => $choiceEntity], false, [], true, $today), 'id'), true), 'Supplier caller can explicitly ignore binding maximum');
    verify(in_array($expired, array_column($repo()->availableForConnection(['entities_id' => $choiceEntity], true, [], false, $today), 'id'), true), 'Explicit expired flag retains expired choices');
    verify(!in_array($unlimited, array_column($repo()->availableForConnection(['entities_id' => $choiceEntity], true, [$unlimited], true, $today), 'id'), true), 'Used contracts remain excluded');
    $ordered = [];
    foreach ([null, '2024-02-01', '2024-03-01'] as $date) {
        $ordered[] = $fixtures->create('glpi_contracts', ['name' => 'Same name', 'entities_id' => $choiceEntity, 'begin_date' => $date]);
    }
    $dateOrder = array_values(array_filter(array_column($repo()->availableForConnection(['entities_id' => $choiceEntity], true, [], true, $today), 'id'), static fn ($id) => in_array($id, $ordered, true)));
    verify($dateOrder === [$ordered[2], $ordered[1], $ordered[0]], 'Same-name choices retain descending date order with NULL last on both providers');
    $_SESSION['glpiactiveentities'] = [$choiceEntity];
    $_SESSION['glpiactive_entity'] = $choiceEntity;
    $_SESSION['glpiactive_entity_recursive'] = 0;
    $_SESSION['glpishowallentities'] = 0;
    verify(Contract::connectionChoices($entity, true, [], true) === [], 'Public choice scope cannot exceed current grants');
    verify(Contract::connectionChoices([], true, [], true) === [], 'Explicit empty request cannot expose active choices');
    verify(Contract::connectionChoices($choiceEntity, true, [], true) !== [], 'Authorized public choices remain available');
    $limitedLabels = Contract::connectionChoices($choiceEntity, true);
    verify(!array_key_exists($full, array_replace(...array_values($limitedLabels))), 'Actual public limit includes linked subjects outside current visible entity scope');
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Contract', 'items_id' => $unlimited, 'language' => 'fr_FR', 'field' => 'name', 'value' => 'Contrat traduit']);
    $_SESSION['glpi_dropdowntranslations']['Contract']['name'] = true;
    $_SESSION['glpilanguage'] = 'fr_FR';
    $_SESSION['glpiis_ids_visible'] = 0;
    $labels = Contract::connectionChoices($choiceEntity, true, [], true, false);
    verify($labels[0] === Dropdown::EMPTY_VALUE && in_array('Contrat traduit', array_merge(...array_values(array_filter($labels, 'is_array'))), true), 'Actual asset connection labels retain empty selection and configured Contract translation');
    $_SESSION['glpishowallentities'] = 1;
    verify(Session::getActiveEntityScope() === null, 'Nonempty legitimate all-entities context retains its unrestricted marker');
    verify(Contract::connectionChoices([], true, [], true) === [], 'All-entities optimization never overrides an explicit empty request');
    $_SESSION['glpiactiveentities'] = [];
    verify(Session::getActiveEntityScope() === [], 'Authoritative session scope prioritizes explicit empty grants before cached all-entities optimization');
    verify(Contract::connectionChoices($choiceEntity, true, [], true) === [], 'Empty current grants fail closed even if cached all-entities flag remains set');
    unset($_SESSION['glpiactiveentities']);
    $_SESSION['glpishowallentities'] = 0;
    verify(Session::getActiveEntityScope() === [0], 'Absent CLI active selection retains root fallback');
    $_SESSION['glpiactiveentities'] = [$choiceEntity];
    $_SESSION['glpiactiveprofile']['contract'] = 0;
    verify(Contract::connectionChoices($choiceEntity, true, [], true) === [], 'Revoked Contract read rights hide choices');
    $_SESSION = $savedSession;

    $supplier = $fixtures->create('glpi_suppliers', ['name' => "Supplier O'Connor", 'entities_id' => $entity]);
    $fixtures->create('glpi_contracts_suppliers', ['contracts_id' => $cases['eligible'], 'suppliers_id' => $supplier]);
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Supplier', 'items_id' => $supplier, 'language' => 'fr_FR', 'field' => 'name', 'value' => 'Fournisseur traduit']);
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Computer', 'items_id' => $supplier, 'language' => 'fr_FR', 'field' => 'name', 'value' => 'Wrong kind']);
    $secondSupplier = $fixtures->create('glpi_suppliers', ['name' => 'AAA name comes first alphabetically', 'entities_id' => $entity]);
    $emptySupplier = $fixtures->create('glpi_suppliers', ['name' => null, 'entities_id' => $entity]);
    foreach ([$secondSupplier, $emptySupplier] as $supplierId) {
        $fixtures->create('glpi_contracts_suppliers', ['contracts_id' => $cases['eligible'], 'suppliers_id' => $supplierId]);
    }
    verify($repo()->supplierNames($cases['eligible']) === ["Supplier O'Connor", 'AAA name comes first alphabetically', ''] && $repo()->supplierNames($cases['eligible'], 'fr_FR') === ['Fournisseur traduit', 'AAA name comes first alphabetically', ''], 'Owning supplier labels preserve binding order and exact kind/field/language translations');
    $beforeSupplierSession = $_SESSION;
    try {
        $_SESSION['glpi_dropdowntranslations']['Supplier']['name'] = true;
        $_SESSION['glpilanguage'] = 'fr_FR';
        $supplierContract = new Contract();
        verify($supplierContract->getFromDB($cases['eligible']) && $supplierContract->getSuppliersNames() === 'Fournisseur traduit<br>AAA name comes first alphabetically<br>&nbsp;<br>', 'Actual public supplier labels preserve configured translations and empty-name presentation');
    } finally {
        $_SESSION = $beforeSupplierSession;
    }
    $formContract = new Contract();
    verify($formContract->getFromDB($cases['eligible']), 'Load actual public form model as the application caller does');
    $formBufferLevel = ob_get_level();
    ob_start();
    try {
        $formContract->showForm($cases['eligible']);
        $formHtml = ob_get_contents();
    } finally {
        while (ob_get_level() > $formBufferLevel) {
            ob_end_clean();
        }
    }
    verify(str_contains($formHtml, Html::convDate('2024-02-29')), 'Actual public Contract form renders its clamped anniversary instead of discarding date text');
    $target = new NotificationTargetContract($entity, 'end', new Contract());
    $payload = $read('glpi_contracts', $cases['eligible']);
    $payload['notice'] = 1;
    $target->addDataForTemplate('end', ['entities_id' => $entity, 'items' => [$cases['eligible'] => $payload], 'additionnaloption' => ['usertype' => NotificationTarget::ANONYMOUS_USER]]);
    verify($target->data['contracts'][0]['##contract.time##'] === Html::convDate('2024-02-29'), 'Actual END template uses its clamped end anniversary independently of notice months');
    $noticeTarget = new NotificationTargetContract($entity, 'notice', new Contract());
    $noticeTarget->addDataForTemplate('notice', ['entities_id' => $entity, 'items' => [$notice => $read('glpi_contracts', $notice)], 'additionnaloption' => ['usertype' => NotificationTarget::ANONYMOUS_USER]]);
    verify($noticeTarget->data['contracts'][0]['##contract.time##'] === Html::convDate('2024-02-29'), 'Actual NOTICE template subtracts notice months before clamping its leap anniversary');

    $writer()->update('glpi_entities', 0, ['use_contracts_alert' => 0]);
    $parent = (int)(new Entity())->add(['name' => 'Contract cron parent', 'entities_id' => 0, 'use_contracts_alert' => 1, 'send_contracts_alert_before_delay' => 0]);
    $child = (int)(new Entity())->add(['name' => 'Contract cron child', 'entities_id' => $parent, 'use_contracts_alert' => Entity::CONFIG_PARENT, 'send_contracts_alert_before_delay' => Entity::CONFIG_PARENT]);
    foreach (array_keys(Notification_NotificationTemplate::getModes()) as $mode) {
        $CFG_GLPI['notifications_' . $mode] = false;
    }
    $cronContracts = [];
    foreach ([$parent, $child] as $owner) {
        $model = new Contract();
        $id = (int)$model->add(['name' => 'Public cron ' . $owner, 'entities_id' => $owner, 'begin_date' => '2024-01-31', 'duration' => 1]);
        verify($id > 0 && $model->update(['id' => $id, 'alert' => 1 << Alert::END]), 'Actual public Contract lifecycle');
        $cronContracts[] = $id;
    }
    verify(Contract::cronContract(new ContractCronProbe()) === 0, 'Disabled notifications have no alert side effects');
    $CFG_GLPI['use_notifications'] = true;
    $task = new ContractCronProbe();
    verify(Contract::cronContract($task) === 1 && $task->countVolume() === 2 && count($task->messages) === 2, 'Actual cron uses inherited policy and per-entity accepted accounting');
    verify(str_contains($task->messages[0], Html::convDate('2024-02-29')), 'Actual cron renders the selected anniversary');
    verify(Contract::cronContract(new ContractCronProbe()) === 0, 'Actual repeat cron deduplicates published events');
    foreach ($cronContracts as $id) {
        $alert = (int)Alert::alertExists('Contract', $id, Alert::END);
        verify($alert > 0 && $read('glpi_alerts', $alert)['contracts_id'] === $id, 'Cron stores owning Alert subject');
    }

    // Real template/notification/recipient generation; no external transport is run.
    $CFG_GLPI['notifications_mailing'] = true;
    $CFG_GLPI['admin_email'] = 'contract-fixture@example.test';
    $CFG_GLPI['admin_email_name'] = 'Contract fixture';
    $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Contract transactional template', 'itemtype' => 'Contract']);
    $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => '', 'subject' => 'Contract calendar queue', 'content_text' => '##FOREACHcontracts####contract.name## ##contract.time####ENDFOREACHcontracts##', 'content_html' => '##FOREACHcontracts####contract.name## ##contract.time####ENDFOREACHcontracts##']);
    foreach (['periodicity', 'periodicitynotice', 'notice', 'end'] as $event) {
        $notification = $fixtures->create('glpi_notifications', ['name' => 'Contract ' . $event, 'entities_id' => $entity, 'itemtype' => 'Contract', 'event' => $event, 'is_active' => true]);
        $fixtures->create('glpi_notifications_notificationtemplates', ['notifications_id' => $notification, 'notificationtemplates_id' => $template, 'mode' => 'mailing']);
        $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => Notification::USER_TYPE, 'items_id' => Notification::GLOBAL_ADMINISTRATOR]);
    }
    $published = $fixtures->create('glpi_contracts', ['name' => 'Atomic alert', 'entities_id' => $entity, 'begin_date' => '2024-01-31', 'duration' => 1, 'periodicity' => 1, 'alert' => 1 << Alert::PERIODICITY]);
    $oldAlert = (int)(new Alert())->add(['itemtype' => 'Contract', 'items_id' => $published, 'type' => Alert::PERIODICITY, 'date' => '2024-02-29 00:00:00']);
    $priorSelection = array_values(array_filter($repo()->periodicContracts($entity), static fn ($row) => $row['id'] === $published));
    verify(count($priorSelection) === 1 && $priorSelection[0]['last_period'] instanceof DateTimeInterface && $priorSelection[0]['last_period']->format('Y-m-d H:i:s') === '2024-02-29 00:00:00', 'Actual periodic owning Alert projection retains native timestamp semantics');
    $data = $priorSelection[0] + ['alert_date' => '2024-03-31'];
    $queueIds = static fn (): array => (new RecordRepository(Orm::create($DB)))->identifiers('glpi_queuednotifications', 'id', ['notificationtemplates_id' => $template], ['id ASC']);
    $publisher = new ContractAlertPublisher($DB);
    $level = $connection->getTransactionNestingLevel();
    $currentDay = new DateTimeImmutable('today');
    $initialNotice = $fixtures->create('glpi_contracts', ['name' => 'Initial plus periodic notice', 'entities_id' => $entity, 'begin_date' => $currentDay->modify('first day of this month')->format('Y-m-d'), 'duration' => 1, 'notice' => 1, 'periodicity' => 1, 'alert' => (1 << Alert::NOTICE) | (1 << Alert::PERIODICITY)]);
    $initialSelection = array_values(array_filter($repo()->notificationCandidates($entity, Alert::NOTICE, 1), static fn ($row) => $row['id'] === $initialNotice));
    $periodicSelection = array_values(array_filter($repo()->periodicContracts($entity), static fn ($row) => $row['id'] === $initialNotice));
    verify(count($initialSelection) === 1 && count($periodicSelection) === 1 && $periodicSelection[0]['last_notice'] === null, 'Actual initial and periodic NOTICE selectors both capture the unalerted current contract');
    $dueNotice = ContractSchedule::fromFields($periodicSelection[0])->duePeriod(1, null, true, $currentDay);
    verify($dueNotice !== null, 'Captured periodic NOTICE is actually due');
    $noticePayload = $periodicSelection[0] + ['alert_date' => $dueNotice->format('Y-m-d')];
    $connection->beginTransaction();
    verify($publisher->publish('notice', Alert::NOTICE, $entity, [$initialNotice => $initialSelection[0]]) === ContractAlertOutcome::Published && count($queueIds()) === 1, 'Initial NOTICE is queued through actual public dispatch');
    verify($publisher->publish('periodicitynotice', Alert::NOTICE, $entity, [$initialNotice => $noticePayload], true) === ContractAlertOutcome::Skipped && count($queueIds()) === 1, 'Periodic NOTICE selected before initial mutation sees changed owned timestamp and stays quiet with one queue');
    $connection->rollBack();
    $changed = $fixtures->create('glpi_contracts', ['name' => 'Changed after selection', 'entities_id' => $entity, 'begin_date' => '2024-01-31', 'duration' => 1]);
    $selectedBeforeUpdate = $read('glpi_contracts', $changed);
    verify((new Contract())->update(['id' => $changed, 'duration' => 2]), 'Actual date-affecting change after selection');
    verify($publisher->publish('end', Alert::END, $entity, [$changed => $selectedBeforeUpdate]) === ContractAlertOutcome::Skipped && $queueIds() === [] && !Alert::alertExists('Contract', $changed, Alert::END), 'Locked current contract rejects stale deadline candidate before notification or Alert mutation');
    $auditIds = static fn (): array => (new RecordRepository(Orm::create($DB)))->identifiers('glpi_logs', 'id', ['itemtype' => 'Contract', 'items_id' => $published], ['id ASC']);
    $beforeAudit = $auditIds();
    $seenQueues = [];
    $PLUGIN_HOOKS['pre_item_purge']['orm_contract_fixture'][Alert::class] = static function ($item) use (&$seenQueues, $queueIds, $published): void {
        $seenQueues = $queueIds();
        verify((new Contract())->update(['id' => $published, 'comment' => 'Rolled-back hook audit']), 'Actual veto hook performs an audited domain update');
        $item->input = false;
    };
    verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Refused, 'Actual public prior-Alert clear refusal is respected');
    verify(count($seenQueues) === 1 && $queueIds() === [] && $read('glpi_alerts', $oldAlert) !== null && $connection->getTransactionNestingLevel() === $level, 'Late clear veto rolls back real queue generation and preserves prior alert/caller transaction');
    verify($auditIds() === $beforeAudit && empty($read('glpi_contracts', $published)['comment']), 'Refused clear restores actual public hook audit and contract change');
    unset($PLUGIN_HOOKS['pre_item_purge']['orm_contract_fixture']);
    $PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture'][Alert::class] = static function ($item): void {
        $item->input = false;
    };
    verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Refused
        && $read('glpi_alerts', $oldAlert) !== null && $queueIds() === [], 'Late new-Alert veto restores prior cleared Alert and real queue');
    unset($PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture']);
    $second = $fixtures->create('glpi_contracts', ['name' => 'Late bulk alert', 'entities_id' => $entity, 'begin_date' => '2024-01-31', 'duration' => 1, 'periodicity' => 1, 'alert' => 1 << Alert::PERIODICITY]);
    $secondAlert = (int)(new Alert())->add(['itemtype' => 'Contract', 'items_id' => $second, 'type' => Alert::PERIODICITY, 'date' => '2024-02-29 00:00:00']);
    $secondSelection = array_values(array_filter($repo()->periodicContracts($entity), static fn ($row) => $row['id'] === $second))[0];
    $PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture'][Alert::class] = static function ($item) use ($published, $second): void {
        if ((int)$item->input['items_id'] === $second) {
            $item->input = false;
        } else {
            verify((new Contract())->update(['id' => $published, 'comment' => 'Accepted earlier alert hook audit']), 'Earlier accepted public Alert hook performs an audited change');
        }
    };
    verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data, $second => $secondSelection + ['alert_date' => '2024-03-31']], true) === ContractAlertOutcome::Refused, 'Later refusal rejects a multi-contract event after earlier replacement succeeded');
    verify($read('glpi_alerts', $oldAlert) !== null && $read('glpi_alerts', $secondAlert) !== null && $queueIds() === [] && $auditIds() === $beforeAudit && empty($read('glpi_contracts', $published)['comment']) && $connection->getTransactionNestingLevel() === $level, 'Late bulk veto rolls back earlier accepted Alert replacement, public audit, bundled queue and preserves caller transaction');
    unset($PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture']);
    $PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture'][QueuedNotification::class] = static function (): never {
        throw new RuntimeException('Contract queue refusal');
    };
    try {
        $publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true);
        throw new LogicException('Queue hook exception did not propagate');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Contract queue refusal' && $read('glpi_alerts', $oldAlert) !== null && $queueIds() === [] && $connection->getTransactionNestingLevel() === $level, 'Actual failed queue dispatch preserves previous alert and caller transaction');
    }
    unset($PLUGIN_HOOKS['pre_item_add']['orm_contract_fixture']);
    $connection->beginTransaction();
    verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Published, 'Actual queue generation and Alert replacement accepted');
    verify(count($queueIds()) === 1 && $read('glpi_alerts', $oldAlert) === null && $connection->getTransactionNestingLevel() === $level + 1, 'Publisher releases only its savepoint and leaves generated notification pending');
    verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Skipped && count($queueIds()) === 1, 'Stale periodic selection cannot replace or duplicate accepted notification');
    $connection->rollBack();
    verify($queueIds() === [] && $read('glpi_alerts', $oldAlert) !== null, 'Caller rollback restores previous alert and removes accepted queue');
    $DB->slave = true;
    try {
        verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Refused && $queueIds() === [], 'Supplied read-only connection is never used for publication');
    } finally {
        $DB->slave = false;
    }
    verify($publisher->publish('end', Alert::END, 0, [$published => $data]) === ContractAlertOutcome::Refused && $queueIds() === [], 'Opposite-entity publication cannot access an owned contract');
    $writerDatabase = $DB;
    $nativeReader = new DB();
    $nativeReader->slave = true;
    $readerConnection = $nativeReader->getDoctrineConnection();
    $identitySql = $DB->getProvider() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()';
    verify($readerConnection->fetchOne($identitySql) !== $connection->fetchOne($identitySql), 'Reader is an actual distinct native provider connection');
    $beforeReaderSession = $_SESSION;
    try {
        $DB = $nativeReader;
        $_SESSION['glpiactiveentities'] = [$choiceEntity];
        $_SESSION['glpishowallentities'] = 0;
        $readerManager = Orm::create($nativeReader);
        verify($readerManager->getConnection() === $readerConnection && $readerManager->getRepository(Record\Contract::class)->availableForConnection(['entities_id' => $choiceEntity], true, [], true) === [], 'Repository query retains provided native reader and cannot see writer uncommitted graph');
        verify(Contract::connectionChoices($choiceEntity, true, [], true) === [], 'Actual public repository factory retains selected reader instead of writer fallback');
        verify((new ContractAlertPublisher($nativeReader))->publish('periodicity', Alert::PERIODICITY, $entity, [$published => $data], true) === ContractAlertOutcome::Refused && $readerConnection->getTransactionNestingLevel() === 0, 'Actual provided reader never starts publication transaction');
    } finally {
        $DB = $writerDatabase;
        $_SESSION = $beforeReaderSession;
        $nativeReader->close();
    }
    $manager = Orm::create($DB);
    verify($manager->getRepository(Record\Contract::class) instanceof ContractRepository && $manager->getConnection() === $connection, 'Custom repository preserves supplied active connection and generic choices boundary');
    verify((new Contract())->delete(['id' => $published], true) && $read('glpi_alerts', $oldAlert) === null, 'Actual public Contract purge clears owning Alert');
    verify((new ForeignKeys())->audit($connection) === [], 'Calendar/public notification flows preserve FK integrity');
} finally {
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
    restore_error_handler();
    $plugins->setValue(null, $savedPlugins);
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI = $savedConfig;
    $_SESSION = $savedSession;
}
echo $DB->getProvider() . ": $assertions assertions; Contract calendar deadlines, scoped binding choices, real notification templates, inherited cron, duplicate/stale event handling, public hook refusal and caller-owned transactional queues passed.\n";
