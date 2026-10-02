<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\DomainRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-application.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$session = $_SESSION;
$connection = $DB->getDoctrineConnection();
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Domain application ' . bin2hex(random_bytes(5));
    $child = (int)(new Entity())->add(['name' => $prefix . ' child', 'entities_id' => 0]);
    $sibling = (int)(new Entity())->add(['name' => $prefix . ' sibling', 'entities_id' => 0]);
    verify($child > 0 && $sibling > 0, 'Real entity trees');
    $supplierId = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' commercial', 'entities_id' => 0, 'is_recursive' => true]);
    $financialSupplier = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' financial']);
    $local = $fixtures->create('glpi_domains', ['name' => $prefix . ' local', 'entities_id' => $child, 'suppliers_id' => $supplierId]);
    $hidden = $fixtures->create('glpi_domains', ['name' => $prefix . ' hidden', 'entities_id' => $child, 'suppliers_id' => $supplierId, 'is_helpdesk_visible' => false]);
    $ancestor = $fixtures->create('glpi_domains', ['name' => $prefix . ' ancestor', 'entities_id' => 0, 'is_recursive' => true, 'suppliers_id' => $supplierId]);
    $rootOnly = $fixtures->create('glpi_domains', ['name' => $prefix . ' root only', 'entities_id' => 0, 'suppliers_id' => $supplierId]);
    $outside = $fixtures->create('glpi_domains', ['name' => $prefix . ' outside', 'entities_id' => $sibling, 'suppliers_id' => $supplierId]);
    $deleted = $fixtures->create('glpi_domains', ['name' => $prefix . ' deleted', 'entities_id' => $child, 'suppliers_id' => $supplierId, 'is_deleted' => true]);
    $financial = $fixtures->create('glpi_infocoms', ['itemtype' => 'Domain', 'items_id' => $local, 'suppliers_id' => $financialSupplier]);
    $supplier = new Supplier();
    verify($supplier->getFromDB($supplierId), 'Load supplier');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactive_entity'] = $child;
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpiactiveentities_string'] = (string)$child;
    $_SESSION['glpiparententities'] = [0];
    $scope = getEntitiesRestrictCriteria(Domain::getTable(), '', '', true);
    $rows = (new DomainRepository(Orm::create($DB)))->forSupplier($supplierId, $scope);
    verify(array_column($rows, 'id') === [$ancestor, $hidden, $local], 'Direct supplier query retains recursive ancestor and hidden domain; excludes sibling, nonrecursive ancestor and deleted');
    verify(array_column(Domain::supplierDomains($supplier), 'id') === [$ancestor, $hidden, $local], 'Public supplier tab uses scoped owning association');
    verify(Domain::countForSupplier($supplier) === 3, 'Tab badge uses same scoped/deleted ownership policy');
    ob_start();
    verify(Domain::showForSupplier($supplier), 'Public tab renders');
    $html = ob_get_clean();
    verify(str_contains($html, $prefix . ' local') && str_contains($html, $prefix . ' hidden') && !str_contains($html, $prefix . ' outside'), 'Rendered tab matches permission-scoped query');
    verify(isset($supplier->defineTabs()['Domain$1']), 'Supplier advertises Domain tab');
    $_SESSION['glpiactiveprofile']['domain'] = 0;
    verify(Domain::supplierDomains($supplier) === [] && (new Domain())->getTabNameForItem($supplier) === '', 'Domain READ denial hides query');
    ob_start();
    verify(!Domain::showForSupplier($supplier), 'Denied public tab refuses render');
    verify(ob_get_clean() === '', 'Denied tab emits no content');
    $_SESSION['glpiactiveprofile']['domain'] = $session['glpiactiveprofile']['domain'];
    $_SESSION['glpiactiveprofile']['contact_enterprise'] = 0;
    verify(Domain::supplierDomains($supplier) === [] && Domain::countForSupplier($supplier) === 0, 'Supplier READ denial hides domains and counts');
    $_SESSION['glpiactiveprofile']['contact_enterprise'] = $session['glpiactiveprofile']['contact_enterprise'];

    $post = ['itemtype' => 'Domain', 'table' => Domain::getTable(), 'entity_restrict' => $child,
        '_idor_token' => Session::getNewIDORToken('Domain', ['entity_restrict' => $child])];
    $choices = Dropdown::getDropdownFindNum($post, false);
    $ids = array_map('intval', array_column($choices['results'], 'id'));
    verify(in_array($local, $ids, true) && in_array($ancestor, $ids, true) && !in_array($hidden, $ids, true) && !in_array($outside, $ids, true), 'Actual ticket tracking helper filters hidden/deleted domains and recursive scope');
    $all = Dropdown::getAllItemsSelection(['idtable' => 'Domain', 'entity_restrict' => $child]);
    $flatten = static function (array $values): array {
        $ids = [];
        foreach ($values as $id => $value) {
            if (is_array($value)) {
                $ids = array_merge($ids, array_keys($value));
            } else {
                $ids[] = $id;
            }
        }
        return array_map('intval', $ids);
    };
    verify(in_array($hidden, $flatten($all), true), 'Ordinary association selector can select a hidden helpdesk domain');
    $requests = static function (string $action): array {
        ob_start();
        Item_Ticket::showFormMassiveAction(new class ($action) {
            public function __construct(private string $action)
            {
            }
            public function getAction()
            {
                return $this->action;
            }
        });
        $html = html_entity_decode(ob_get_clean(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        verify(preg_match('/const requests = (\{.*?\});/s', $html, $match) === 1, 'Real massive-action issuer renders signed choice requests');
        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    };
    $add = $requests('add_item')['Domain'];
    verify(isset($add['condition']) && !in_array($hidden, $flatten(Dropdown::getAllItemsSelection($add)), true), 'Actual ticket massive ADD filters hidden domain');
    $forged = $add;
    unset($forged['condition']);
    verify(Dropdown::getAllItemsSelection($forged) === null, 'Removing signed helpdesk condition refuses');
    $forged = array_replace($add, ['condition' => Dropdown::addNewCondition([])]);
    verify(Dropdown::getAllItemsSelection($forged) === null, 'Replacing signed condition refuses');
    $delete = $requests('delete_item')['Domain'];
    verify(!isset($delete['condition']) && in_array($hidden, $flatten(Dropdown::getAllItemsSelection($delete)), true), 'Existing hidden ticket association remains selectable for deletion');

    $domain = new Domain();
    verify($domain->getFromDB($hidden), 'Load hidden domain');
    ob_start();
    $domain->showForm($hidden);
    $html = ob_get_clean();
    verify(str_contains($html, 'name="suppliers_id"') && str_contains($html, 'name="is_helpdesk_visible"'), 'Domain form exposes separate supplier and helpdesk flag');
    $options = array_column($domain->rawSearchOptions(), null, 'id');
    verify($options[4]['linkfield'] === 'suppliers_id' && $options[11]['field'] === 'is_helpdesk_visible', 'Search retains verified supplier and visibility option identities');
    $_SESSION['glpiactiveprofile']['dropdown'] = 0;
    $_SESSION['glpiactiveprofile']['domaintype'] = READ | CREATE | UPDATE;
    verify((new DomainType())->canView() && (new DomainType())->canCreate() && !(new Manufacturer())->canView(), 'Dedicated domain-type grants never broaden global dropdown authorization');
    $_SESSION['glpiactiveprofile']['domaintype'] = 0;
    $_SESSION['glpiactiveprofile']['dropdown'] = READ | CREATE | UPDATE;
    verify(!(new DomainType())->canView() && (new Manufacturer())->canView(), 'Runtime DomainType authorization has no global dropdown fallback');
    $_SESSION = $session;
    $profile = new Profile();
    verify($profile->getFromDB((int)$_SESSION['glpiactiveprofile']['id']), 'Load current profile with migrated type right');
    ob_start();
    $profile->showFormManagement();
    $html = ob_get_clean();
    verify(str_contains($html, '_domaintype'), 'Profile management form exposes the dedicated domain-type grant');
    $profileOptions = array_column($profile->rawSearchOptions(), null, 'id');
    verify($profileOptions[180]['rightname'] === 'domaintype' && $profileOptions[180]['rightclass'] === DomainType::class, 'Profile search uses the dedicated right class');
    verify(in_array(Domain::class, $CFG_GLPI['document_types'], true), 'Domains are offered by the public document subject picker');
    $attachment = $fixtures->create('glpi_documents', ['name' => $prefix . ' attachment', 'entities_id' => $child]);
    $domain = new Domain();
    verify($domain->getFromDB($hidden), 'Load Domain attachment subject');
    verify(isset($domain->defineTabs()['Document_Item$1']), 'Domain exposes its existing public document tab');
    $attachments = [];
    foreach ([0, 1] as $position) {
        $binding = new Document_Item();
        $link = $binding->add(['documents_id' => $attachment, 'itemtype' => Domain::class, 'items_id' => $hidden,
            'entities_id' => $child, 'timeline_position' => $position, '_do_update_ticket' => false]);
        verify($link > 0 && $binding->fields['domains_id'] === $hidden && $binding->fields['items_id'] === $hidden, 'Public attachment stores the owning Domain and generated projection');
        $attachments[] = $link;
    }
    $documents = new \itsmng\Database\Repository\DocumentRepository(Orm::create($DB));
    verify(array_column($documents->documentsForItem(Domain::class, $hidden), 'id') === [$attachment, $attachment], 'Domain document query preserves individual timeline bindings');
    verify($documents->bindingsForItem(Domain::class, $outside) === [], 'Domain document query isolates the requested subject');
    ob_start();
    Document_Item::showForItem($domain);
    $html = ob_get_clean();
    verify(str_contains($html, $prefix . ' attachment'), 'Actual Domain document tab renders the attachment');
    $domainClone = $domain->clone(['name' => $prefix . ' cloned domain']);
    verify($domainClone > 0 && $domainClone !== $hidden && $read('glpi_domains', $domainClone)['suppliers_id'] === $supplierId
        && $read('glpi_domains', $domainClone)['is_helpdesk_visible'] === 0, 'Public Domain clone preserves direct supplier and hidden helpdesk flag');
    // Domain historically clones only its scalar fields; explicit relation clones
    // use the same public method as the shared Clonable lifecycle.
    $copied = [];
    foreach ($attachments as $link) {
        $binding = new Document_Item();
        verify($binding->getFromDB($link), 'Load attachment before its public clone');
        $copy = $binding->clone(['items_id' => $domainClone, 'domains_id' => $domainClone]);
        verify($copy > 0 && $copy !== $link, 'Public attachment clone preserves the selected Domain role');
        $copied[] = $copy;
    }
    $records = new RecordRepository(Orm::create($DB));
    $clonedLinks = $records->matching('glpi_documents_items', ['itemtype' => Domain::class, 'items_id' => $domainClone], ['id ASC']);
    verify(array_column($clonedLinks, 'timeline_position') === [0, 1] && array_column($clonedLinks, 'documents_id') === [$attachment, $attachment]
        && array_column($clonedLinks, 'domains_id') === [$domainClone, $domainClone], 'Public attachment clones retain each timeline row, document parent and owning subject');
    verify((new Domain())->delete(['id' => $domainClone], true), 'Public cloned Domain purge');
    verify(
        $documents->bindingsForItem(Domain::class, $domainClone) === [] && count($documents->bindingsForItem(Domain::class, $hidden)) === 2,
        'Domain purge removes only its own document links and retains original bindings'
    );
    verify($read('glpi_documents', $attachment) !== null, 'Domain purge retains the document container');
    verify((new Document())->delete(['id' => $attachment], true), 'Public document container purge');
    verify(
        $documents->bindingsForItem(Domain::class, $hidden) === [] && $read('glpi_domains', $hidden) !== null,
        'Document purge removes bindings and preserves the Domain'
    );
    $connection->createSavepoint('domain_supplier_invalid');
    try {
        $connection->update('glpi_domains', ['suppliers_id' => 9223372036854770000], ['id' => $local]);
        throw new RuntimeException('Invalid direct supplier accepted');
    } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
        $connection->rollbackSavepoint('domain_supplier_invalid');
    }
    $connection->releaseSavepoint('domain_supplier_invalid');
    verify((new Supplier())->delete(['id' => $supplierId], true), 'Public supplier purge under restrictive FK');
    verify($read('glpi_domains', $local)['suppliers_id'] === null && $read('glpi_domains', $hidden)['suppliers_id'] === null, 'Supplier purge clears optional direct owner without losing domains');
    verify($read('glpi_infocoms', $financial)['suppliers_id'] === $financialSupplier, 'Supplier purge preserves distinct actual financial supplier');
    echo "Domain supplier UI/count, permissions, recursive scope, helpdesk choices, document binding/clone and public purge contracts passed\n";
} finally {
    $_SESSION = $session;
    $DB->rollback();
}
