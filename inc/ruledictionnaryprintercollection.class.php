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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use itsmng\Database\LegacyValues;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PrinterDictionaryRepository;

class RuleDictionnaryPrinterCollection extends RuleCollection
{
    // From RuleCollection

    public $stop_on_first_match = true;
    public $can_replay_rules    = true;
    public $menu_type           = 'dictionnary';
    public $menu_option         = 'printer';

    public static $rightname           = 'rule_dictionnary_printer';

    /**
     * @see RuleCollection::getTitle()
    **/
    public function getTitle()
    {
        return __('Dictionnary of printers');
    }


    /**
     * @see RuleCollection::cleanTestOutputCriterias()
    **/
    public function cleanTestOutputCriterias(array $output)
    {

        //If output array contains keys begining with _ : drop it
        foreach ($output as $criteria => $value) {
            if (($criteria[0] == '_') && ($criteria != '_ignore_import')) {
                unset($output[$criteria]);
            }
        }
        return $output;
    }


    /**
     * @see RuleCollection::replayRulesOnExistingDB()
    **/
    public function replayRulesOnExistingDB($offset = 0, $maxtime = 0, $items = [], $params = [])
    {
        global $DB;

        if (isCommandLine()) {
            printf(__('Replay rules on existing database started on %s') . "\n", date("r"));
        }
        $repository = new PrinterDictionaryRepository(Orm::create($DB));
        $nb = $repository->groupCount();
        $i = min(max(0, (int)$offset), $nb);
        $remaining = $nb - $i;
        $step = (($nb > 1000) ? 50 : (($nb > 20) ? max(1, (int)floor($remaining / 20)) : 1));

        foreach ($repository->replayGroups($i) as $input) {
            if (!($i % $step)) {
                if (isCommandLine()) {
                    //TRANS: %1$s is a date, %2$s is a row, %3$s is total row, %4$s is memory
                    printf(
                        __('%1$s - replay rules on existing database: %2$s/%3$s (%4$s Mio)') . "\n",
                        date("H:i:s"),
                        $i,
                        $nb,
                        round(memory_get_usage() / (1024 * 1024), 2)
                    );
                } else {
                    Html::changeProgressBarPosition($i, $nb, "$i / $nb");
                }
            }

            //Replay printer dictionnary rules
            $res_rule = $this->processAllRules($input, [], []);

            foreach (['manufacturer', 'is_global', 'name'] as $attr) {
                if (isset($res_rule[$attr]) && ($res_rule[$attr] == '')) {
                    unset($res_rule[$attr]);
                }
            }

            // Replay every matching printer when a dictionary action changes it.
            if (self::somethingHasChanged($res_rule, $input)) {
                $IDs = $repository->matchingPrinters($input['name'], $input['manufacturers_id'] === null ? null : (int)$input['manufacturers_id']);
                if ($IDs) {
                    $this->replayDictionnaryOnPrintersByID($IDs, $res_rule);
                }
            }
            $i++;

            if ($maxtime) {
                $crt = explode(" ", microtime());
                if ($crt[0] + $crt[1] > $maxtime) {
                    break;
                }
            }
        }

        if (isCommandLine()) {
            printf(__('Replay rules on existing database: %1$s/%2$s') . "\n", $i, $nb);
        } else {
            Html::changeProgressBarPosition($i, $nb, "$i / $nb");
        }

        if (isCommandLine()) {
            printf(__('Replay rules on existing database ended on %s') . "\n", date("r"));
        }

        return (($i == $nb) ? -1 : $i);
    }


    /**
     * @param $res_rule  array
     * @param $input     array
    **/
    public static function somethingHasChanged(array $res_rule, array $input)
    {

        if (
            (isset($res_rule["name"]) && (LegacyValues::decodeString($res_rule["name"]) != $input["name"]))
            || (isset($res_rule["manufacturer"]) && ($res_rule["manufacturer"] != ''))
            || (isset($res_rule['is_global']) && ($res_rule['is_global'] != ''))
        ) {
            return true;
        }
        return false;
    }


    /**
     * Replay dictionnary on several printers
     *
     * @param $IDs       array of printers IDs to replay
     * @param $res_rule  array of rule results
     *
     * @return Query result handler
    **/
    public function replayDictionnaryOnPrintersByID(array $IDs, $res_rule = [])
    {
        global $DB;

        $em = Orm::create($DB);
        $printers = (new PrinterDictionaryRepository($em))->replayPrinters($IDs);
        $em->getConnection()->transactional(function () use ($printers, $res_rule): void {
            $new_printers = [];
            $delete_ids = [];
            foreach ($printers as $printer) {
                $this->replayDictionnaryOnOnePrinter($new_printers, $res_rule, $printer, $delete_ids);
            }
            $this->putOldPrintersInTrash($delete_ids);
        });
    }


    /**
     * @param $IDS array
    */
    public function putOldPrintersInTrash($IDS = [])
    {

        $printer = new Printer();
        foreach ($IDS as $id) {
            if (!$printer->delete(['id' => $id])) {
                throw new RuntimeException('Unable to trash printer after dictionary merging.');
            }
        }
    }


    /**
     * Replay dictionnary on one printer
     *
     * @param &$new_printers   array containing new printers already computed
     * @param $res_rule        array of rule results
     * @param $params          array
     * @param &$printers_ids   array containing replay printer need to be put in trashbin
    **/
    public function replayDictionnaryOnOnePrinter(
        array &$new_printers,
        array $res_rule,
        array $params,
        array &$printers_ids
    ) {
        $p['id']           = 0;
        $p['name']         = '';
        $p['manufacturer'] = '';
        $p['is_global']    = '';
        $p['entity']       = 0;
        foreach ($params as $key => $value) {
            $p[$key] = $value;
        }

        $p['entity'] = (int)($params['entities_id'] ?? $p['entity']);

        $input["name"]         = $p['name'];
        $input["manufacturer"] = $p['manufacturer'];

        if (empty($res_rule)) {
            $res_rule = $this->processAllRules($input, [], []);
        }

        $printer = new Printer();

        //Printer's name has changed
        if (
            isset($res_rule["name"])
            && (LegacyValues::decodeString($res_rule["name"]) != $p['name'])
        ) {
            $manufacturer = "";

            if (isset($res_rule["manufacturer"])) {
                $manufacturer = addslashes(Dropdown::getDropdownName(
                    "glpi_manufacturers",
                    $res_rule["manufacturer"]
                ));
            } else {
                $manufacturer = addslashes((string) $p['manufacturer']);
            }

            //New printer not already present in this entity
            if (!isset($new_printers[$p['entity']][$res_rule["name"]])) {
                // create new printer or restore it from trashbin
                $new_printer_id = $printer->addOrRestoreFromTrash(
                    $res_rule["name"],
                    $manufacturer,
                    $p['entity']
                );
                $new_printers[$p['entity']][$res_rule["name"]] = $new_printer_id;
            } else {
                $new_printer_id = $new_printers[$p['entity']][$res_rule["name"]];
            }

            if (!$new_printer_id) {
                throw new RuntimeException('Unable to create printer dictionary destination.');
            }

            // Move direct connections
            $this->moveDirectConnections($p['id'], $new_printer_id);
        } else {
            $new_printer_id  = $p['id'];
            $res_rule["id"]  = $p['id'];

            if (isset($res_rule["manufacturer"])) {
                if ($res_rule["manufacturer"] != '') {
                    $res_rule["manufacturers_id"] = $res_rule["manufacturer"];
                }
                unset($res_rule["manufacturer"]);
            }
            if (!$printer->update($res_rule)) {
                throw new RuntimeException('Unable to update printer dictionary selection.');
            }
        }

        // Add to printer to deleted list
        if ($new_printer_id != $p['id']) {
            $printers_ids[] = $p['id'];
        }
    }


    /**
     * Move direct connections from old printer to the new one
     *
     * @param $ID                 the old printer's id
     * @param $new_printers_id    the new printer's id
     *
     * @return void
    **/
    public function moveDirectConnections($ID, $new_printers_id)
    {
        global $DB;

        $computeritem = new Computer_Item();
        (new PrinterDictionaryRepository(Orm::create($DB)))->moveConnections(
            (int)$ID,
            (int)$new_printers_id,
            static fn (int $id, int $target): bool => (bool)$computeritem->update(['id' => $id, 'items_id' => $target]),
            // A merge removes duplicate links, including dynamic locks, without
            // applying the asset-field cleanup reserved for disconnection.
            static fn (array $link): bool => (bool)$computeritem->delete($link + ['_no_auto_action' => true], 1)
        );
    }
}
