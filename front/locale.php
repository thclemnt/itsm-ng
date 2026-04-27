<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

$_GET['donotcheckversion']   = true;
$dont_check_maintenance_mode = true;

include('../inc/includes.php');

header("Content-Type: application/json; charset=UTF-8");

$is_cacheable = !isset($_GET['debug']);
if (!isset($CFG_GLPI['dbversion']) || trim($CFG_GLPI['dbversion']) != ITSM_SCHEMA_VERSION) {
    // Make sure to not cache if in the middle of a GLPI update
    $is_cacheable = false;
}
if ($is_cacheable) {
    // Makes CSS cacheable by browsers and proxies
    $max_age = WEEK_TIMESTAMP;
    header_remove('Pragma');
    header('Cache-Control: public');
    header('Cache-Control: max-age=' . $max_age);
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + $max_age));
}

global $CFG_GLPI, $TRANSLATE;

$language = $_SESSION['glpilanguage'];
$locale = $CFG_GLPI['languages'][$language][1];
$requested_domains = [];
if (isset($_GET['domains']) && is_array($_GET['domains'])) {
    $requested_domains = $_GET['domains'];
} elseif (isset($_GET['domain'])) {
    $requested_domains = [$_GET['domain']];
}
$requested_domains = array_values(array_unique(array_filter($requested_domains, 'is_string')));
if (count($requested_domains) === 0) {
    $requested_domains = ['glpi'];
}
$is_batch_request = count($requested_domains) > 1 || isset($_GET['domains']);

// Default response to send if locales cannot be loaded.
// Prevent JS error for plugins that does not provide any translation files.
$default_response = [
   '' => [
      'language'     => $locale,
      'plural-forms' => 'nplurals=2; plural=(n != 1);',
   ],
];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Extract headers from main po file
$po_file = GLPI_ROOT . '/locales/' . preg_replace(
    '/\.mo$/',
    '.po',
    (string) $locale
);
$po_file_handle = fopen(
    $po_file,
    'rb'
);
if (false === $po_file_handle) {
    Toolbox::logError(sprintf('Unable to extract locales data from "%s".', $po_file));
    exit(json_encode($is_batch_request ? array_fill_keys($requested_domains, $default_response) : $default_response));
}
$in_headers = false;
$headers = [];
$header_keys = ['language', 'plural-forms'];
while (false !== ($line = fgets($po_file_handle))) {
    if (preg_match('/^msgid\s+""\s*$/', $line)) {
        $in_headers = true;
        continue;
    }
    if ($in_headers && preg_match('/^msgid\s+".*"\s*$/', $line)) {
        break; // new msgid = end of headers parsing
    }
    $header = [];
    if ($in_headers && preg_match('/^"(?P<name>[a-z-]+):\s*(?P<value>.*)\\\n"\s*$/i', $line, $header)) {
        $header_name = strtolower($header['name']);
        $header_value = $header['value'];
        if (in_array($header_name, $header_keys)) {
            $headers[$header_name] = $header_value;
        }
    }
}
fclose($po_file_handle);
if (count(array_diff($header_keys, array_keys($headers))) > 0) {
    Toolbox::logError(sprintf('Missing mandatory locale headers in "%s".', $po_file));
    $headers = $default_response[''];
}
$default_response[''] = $headers;

$locales = [];
foreach ($requested_domains as $domain) {
    // Get messages from translator component.
    $messages = $TRANSLATE->getAllMessages($domain);
    if (!($messages instanceof \Laminas\I18n\Translator\TextDomain)) {
        // No TextDomain found means that there is no translations for given domain.
        // It is mostly related to plugins that does not provide any translations.
        $locales[$domain] = $default_response;
        continue;
    }

    // Output messages and headers.
    $messages[''] = $headers;
    $messages->ksort();
    $locales[$domain] = $messages;
}

$json_flags = isset($_GET['debug']) ? JSON_PRETTY_PRINT : 0;
echo json_encode($is_batch_request ? $locales : reset($locales), $json_flags);
