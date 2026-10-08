<?php

session_start();
define('GLPI_ROOT', realpath('..'));

include_once(GLPI_ROOT . "/inc/based_config.php");
include_once(GLPI_ROOT . "/inc/db.function.php");
include_once(GLPI_ROOT . "/src/twig/twig.utils.php");

$GLPI = new GLPI();
$GLPI->initLogger();
$GLPI->initErrorHandler();

Config::detectRootDoc();
require_once '../vendor/autoload.php';
use Glpi\System\RequirementsManager;
use itsmng\Database\InstallationConnection;
use itsmng\Database\Installer;

require_once GLPI_ROOT . "/src/languages/language.class.php";

//allow previous page action
header("Cache-Control: private, max-age=10800, pre-check=10800");
header("Pragma: private");
header("Expires: " . date(DATE_RFC822, strtotime("+2 day")));

//get header data, raw in TWIG
$header_data = [
    "javascript"    =>  [
        Html::script("public/lib/base.js"),
        Html::script("public/lib/fuzzy.js"),
        Html::script("js/common.js"),
        Html::script("js/tableExport.min.js"),
        Html::script("vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js"),
        Html::script("vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.js"),
        Html::script("js/bootstrap-table-export.min.js"),
        Html::script("public/build/table.js"),
        ],
    "css"     =>  [
        Html::css('vendor/twbs/bootstrap/dist/css/bootstrap.min.css'),
        Html::css('vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.css'),
        Html::css('public/lib/base.css'),
        Html::css("css/style_install.css"),
        ]
];

$steps =   ['0', '1', '2', '3', '4', '5', '6','7','8', 'error'];
$steps_name = ['languages', 'license', 'install', 'requirement', 'login', 'databases', 'initialization', 'initialized', 'done', 'error'];

$header_data["steps_name"] = $steps_name;
global $CFG_GLPI;

//checks if the step is valid
if (isset($_GET['step']) and in_array($_GET['step'], $steps)) {
    $step = $_GET['step'];
} else {
    $step = '0';
}

$twig_vars = [];
Session::loadLanguage('', false);
switch ($step) {
    case "0":
        $regions = Language::getLanguagesByRegion();

        if (isset($_SESSION['language'])) {
            $language = $_SESSION['language'];
        } else {
            $language = Session::getPreferredLanguage();
        }
        $twig_vars = ['languages' => $regions , 'preferred_language' =>  $language];
        break;

    case "1":
        if (isset($_POST['language'])) {
            $_SESSION['language'] = $_POST['language'];
            $_SESSION['glpilanguage'] = $_SESSION['language']; //required due to the way Session::loadLanguage works
        }
        Session::loadLanguage($_SESSION['language'], false);
        $license = file_get_contents("../COPYING.txt");
        $twig_vars = ['license' => $license];
        break;

    case "3":
        if (isset($_POST['install'])) {
            $_SESSION['action'] = 'install';
        } elseif (isset($_POST['update'])) {
            $_SESSION['action'] = 'update';
        }
        $raw_requirements = (new RequirementsManager())->getCoreRequirementList(null);
        $requirements = [];
        foreach ($raw_requirements as $raw_requirement) {
            if (!$raw_requirement->isOutOfContext()) { // skips raw_requirement if not relevant
                $title = $raw_requirement->getTitle();
                $required = $raw_requirement->isMissing() && !$raw_requirement->isOptional();
                $optional = $raw_requirement->isMissing() && $raw_requirement->isOptional();
                $validated = $raw_requirement->isValidated();
                $message = implode('. ', Html::entities_deep($raw_requirement->getValidationMessages()));
                $requirement = ['title' => $title, 'required' => $required, 'validated' => $validated, 'optional' => $optional, 'message' => $message];
                $requirements[] = $requirement;
            }
        }
        if ($raw_requirements->hasMissingMandatoryRequirements()) {
            $missing_requirements = "mandatory";
        } elseif ($raw_requirements->hasMissingOptionalRequirements()) {
            $missing_requirements = "optional";
        } else {
            $missing_requirements = "none";
        }

        $twig_vars = ['requirements' => $requirements, 'missing_requirements' => $missing_requirements ];
        break;

    case "4":
        $host = isset($_SESSION['db_host']) ? $_SESSION['db_host'] : "";
        $user = isset($_SESSION['db_user']) ? $_SESSION['db_user'] : "";

        $twig_vars = [
            'host' => $host, 'user' => $user,
            'provider' => $_SESSION['db_type'] ?? 'mysql',
            'database_name' => $_SESSION['db_name'] ?? '',
        ];
        break;

    case "5":
        if (isset($_POST['db_host'])) {
            $_SESSION['db_host'] = $_POST['db_host'];
            $_SESSION['db_user'] = $_POST['db_user'];
            $_SESSION['db_pass'] = $_POST['db_pass'];
            $_SESSION['db_type'] = $_POST['db_type'] ?? 'mysql';
            $_SESSION['db_name'] = $_POST['db_name'] ?? '';
        }
        $provider = $_SESSION['db_type'] ?? 'mysql';
        $version = '';
        $ver_too_old = false;
        $databases_info = [];
        $connect_error = '';
        if ($provider === 'pgsql') {
            try {
                if ($_SESSION['action'] !== 'install') {
                    throw new RuntimeException('PostgreSQL upgrades are not available yet.');
                }
                if (trim($_SESSION['db_name'] ?? '') === '') {
                    throw new RuntimeException('Enter the name of an existing, empty PostgreSQL database.');
                }
                $database = DBConnection::createConnection('pgsql', $_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['db_name']);
                Installer::checkPostgres($database);
                $version = $database->getVersion();
                $databases_info[] = ['name' => $_SESSION['db_name'], 'table_count' => 0, 'creation_date' => '', 'last_update' => ''];
                $database->close();
            } catch (Throwable $exception) {
                $connect_error = $exception->getMessage();
            }
            $twig_vars = [
                'provider' => $provider, 'database_name' => $_SESSION['db_name'],
                'connect_error' => $connect_error, 'version' => $version,
                'ver_too_old' => false, 'action' => $_SESSION['action'], 'databases' => $databases_info,
            ];
            break;
        }
        if ($provider !== 'mysql' || !extension_loaded('mysqli')) {
            $twig_vars = ['connect_error' => 'Select a supported database provider with its PHP extension installed.'];
            break;
        }
        $server = null;
        try {
            $server = InstallationConnection::mysqlServer($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass']);
            $version = $server->getServerVersion();
            $result = Config::checkDbEngine($version);
            $version = key($result);
            $ver_too_old = !$result[$version];
            if (!$ver_too_old) {
                $databases_info = InstallationConnection::mysqlDatabases($server);
            }
        } catch (Throwable $exception) {
            $connect_error = $exception->getMessage();
        } finally {
            $server?->close();
        }
        $twig_vars = [  'host' =>           $_SESSION['db_host'],   'user' =>       $_SESSION['db_user'],
                        'connect_error' =>  $connect_error,         'version' =>    $version,
                        'ver_too_old' =>    $ver_too_old,           'action' =>     $_SESSION['action'],
                        'databases' =>      $databases_info];
        break;

    case "6":
        if (($_SESSION['db_type'] ?? 'mysql') === 'pgsql') {
            $secured = false;
            $error = '';
            $sql_error = '';
            try {
                if ($_SESSION['action'] !== 'install') {
                    throw new RuntimeException('PostgreSQL upgrades are not available yet.');
                }
                $database = DBConnection::createConnection('pgsql', $_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['db_name']);
                Installer::checkPostgres($database);
                $database->close();
                $glpikey = new GLPIKey();
                $secured = $glpikey->keyExists() || $glpikey->generate(false);
                if ($secured && !DBConnection::createMainConfig($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['db_name'], 'pgsql')) {
                    $error = 'setup';
                }
            } catch (Throwable $exception) {
                $error = 'use';
                $sql_error = $exception->getMessage();
            }
            $twig_vars = ['action' => 'install', 'created' => false, 'secured' => $secured, 'error' => $error, 'sql_error' => $sql_error];
            break;
        }
        if (isset($_POST['newdatabasename']) and $_POST['newdatabasename'] != "") {
            $new_db = true;
            $_SESSION["databasename"] = $_POST['newdatabasename'];
        } else {
            $new_db = false;
            $_SESSION["databasename"] = json_decode($_POST["database"], true)[0]["name"];
        }
        $db_created = false;
        $sql_error = "";
        $error = "";
        $db_state = "";
        if ($_SESSION['action'] == 'install') {
            $glpikey = new GLPIKey();
            $secured = $glpikey->keyExists();
            if (!$secured) {
                $secured = $glpikey->generate(false);
            }
            if ($secured) {
                $server = $database = null;
                try {
                    if ($new_db) {
                        $error = 'create_db';
                        $server = InstallationConnection::mysqlServer($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass']);
                        $db_created = InstallationConnection::ensureMysqlDatabase($server, $_SESSION['databasename']);
                    }
                    $error = 'use';
                    $database = InstallationConnection::mysqlDatabase($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['databasename']);
                    $database->getServerVersion();
                    if (!DBConnection::createMainConfig($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['databasename'])) {
                        $error = 'setup';
                    } else {
                        $error = '';
                    }
                } catch (Throwable $exception) {
                    $sql_error = $exception->getMessage();
                } finally {
                    $database?->close();
                    $server?->close();
                }
            } else {
                $error = "select";
            }
        } elseif ($_SESSION['action'] == 'update') {
            if (DBConnection::createMainConfig($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['databasename'])) {
                global $DB;
                $_SESSION['can_process_update'] = true;
                $update = [
                    'db' => $_SESSION['databasename'],
                ];
            } else { // can't create config_db file
                $error = "create_config";
            }
        }

        if (!isset($secured)) {
            $secured = false;
        }

        $twig_vars = [  'action'    => $_SESSION['action'],
                        'created'   =>  $db_created,
                        'error'     => $error,
                        'secured'   => $secured,
                        'sql_error' => $sql_error,
                        'update'    => isset($update) ? $update : null,
                    ];
        break;
    case "7":
        include_once(GLPI_CONFIG_DIR . "/config_db.php");
        $DB = new DB();
        if ($DB->getProvider() === 'pgsql') {
            try {
                Installer::installPostgres($DB, $_SESSION['language'] ?? 'en_GB');
            } catch (Throwable $exception) {
                $step = '6';
                $twig_vars = ['action' => 'install', 'secured' => true, 'error' => 'use', 'sql_error' => $exception->getMessage(), 'created' => false];
                break;
            }
        } else {
            Toolbox::createSchema($_SESSION['language'], $DB, true);
        }
        // no break
    case "8":
        include_once(GLPI_ROOT . "/inc/dbmysql.class.php");
        include_once(GLPI_CONFIG_DIR . "/config_db.php");
        $DB = new DB();

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $url_base = preg_replace('~/install/install\.php(?:\?.*)?$~', '', $referer);
        Config::setConfigurationValues('core', [
            'url_base' => $url_base,
            'url_base_api' => "$url_base/apirest.php/",
        ]);
}

try {
    // Keep permission diagnostics available before the cache directory is writable.
    renderTwigTemplate('install/index.twig', [
        'step' => ['number' => $step, 'progress' => $step / count($steps), 'name' => $steps_name[$step]],
        'header_data' => $header_data] + $twig_vars, '/templates', false);
} catch (\Exception $e) {
    echo $e->getMessage();
}
