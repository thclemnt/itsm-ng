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

use itsmng\Database\Orm;
use itsmng\Database\Repository\SoftwareDictionaryRepository;
use itsmng\Database\Repository\SoftwareRepository;
use itsmng\Domain\SoftwareAssignmentCancelled;

class RuleDictionnarySoftwareCollection extends RuleCollection
{
    // From RuleCollection

    public $stop_on_first_match = true;
    public $can_replay_rules    = true;
    public $menu_type           = 'dictionnary';
    public $menu_option         = 'software';

    public static $rightname           = 'rule_dictionnary_software';

    /**
     * @see RuleCollection::getTitle()
    **/
    public function getTitle()
    {
        //TRANS: software in plural
        return __('Dictionnary of software');
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
     * @see RuleCollection::warningBeforeReplayRulesOnExistingDB()
    **/
    public function warningBeforeReplayRulesOnExistingDB($target)
    {
        global $CFG_GLPI;

        echo "<form aria-label='Soft Dictionnary Confirmation' name='testrule_form' id='softdictionnary_confirmation' method='post' action=\"" .
               $target . "\">\n";
        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe' aria-label='Warning'>";
        echo "<tr><th colspan='2' class='b'>" .
              __('Warning before running rename based on the dictionary rules') . "</th></tr>\n";
        echo "<tr><td class='tab_bg_2 center'>";
        echo "<img src=\"" . $CFG_GLPI["root_doc"] . "/pics/warning.png\"></td>";
        echo "<td class='tab_bg_2 center'>" .
              __('Warning! This operation can put merged software in the trashbin.<br>Sure to notify your users.') .
             "</td></tr>\n";
        echo "<tr><th colspan='2' class='b'>" . __('Manufacturer choice') . "</th></tr>\n";
        echo "<tr><td class='tab_bg_2 center'>" .
              __('Replay dictionary rules for manufacturers (----- = All)') . "</td>";
        echo "<td class='tab_bg_2 center'>";
        Manufacturer::dropdown(['name' => 'manufacturer']);
        echo "</td></tr>\n";

        echo "<tr><td class='tab_bg_2 center' colspan='2'>";
        echo "<input type='submit' name='replay_rule' value=\"" . _sx('button', 'Post') . "\"
             class='submit'>";
        echo "<input type='hidden' name='replay_confirm' value='replay_confirm'>";
        echo "</td></tr>";
        echo "</table>\n";
        echo "</div>\n";
        Html::closeForm();
        return true;
    }


    /**
     * @see RuleCollection::replayRulesOnExistingDB()
    **/
    public function replayRulesOnExistingDB($offset = 0, $maxtime = 0, $items = [], $params = [])
    {
        global $DB;

        if (isCommandLine()) {
            echo "replayRulesOnExistingDB started : " . date("r") . "\n";
        }
        $nb = 0;
        $i  = $offset;

        if (count($items) == 0) {
            $repository = new SoftwareDictionaryRepository(Orm::create($DB));
            $manufacturer = !empty($params['manufacturer']) ? (int)$params['manufacturer'] : null;
            $nb = max((int)$offset, $repository->groupCount($manufacturer));
            $step = (($nb > 1000) ? 50 : (($nb > 20) ? max(1, floor(max(0, $nb - $offset) / 20)) : 1));

            foreach ($repository->replayGroups($manufacturer, (int)$offset) as $input) {
                if (!($i % $step)) {
                    if (isCommandLine()) {
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

                //If manufacturer is set, then first run the manufacturer's dictionnary
                if (isset($input["manufacturer"])) {
                    $input["manufacturer"] = Manufacturer::processName($input["manufacturer"]);
                }

                //Replay software dictionnary rules
                $res_rule = $this->processAllRules($input, [], []);

                if (
                    (isset($res_rule["name"]) && (strtolower($res_rule["name"]) != strtolower((string) $input["name"])))
                    || (isset($res_rule["version"]) && ($res_rule["version"] != ''))
                    || (isset($res_rule['new_entities_id'])
                        && ($res_rule['new_entities_id'] != $input['entities_id']))
                    || (isset($res_rule['is_helpdesk_visible'])
                        && ($res_rule['is_helpdesk_visible'] != $input['helpdesk']))
                    || (isset($res_rule['manufacturer'])
                        && ($res_rule['manufacturer'] != $input['manufacturer']))
                    || (isset($res_rule['softwarecategories_id'])
                        && ($res_rule['softwarecategories_id'] != $input['softwarecategories_id']))
                ) {
                    //Find all the softwares in the database with the same name and manufacturer
                    $IDs = $repository->matchingSoftware($input['name'], $input['manufacturers_id'] === null ? null : (int)$input['manufacturers_id']);

                    if ($IDs) {
                        //Replay dictionnary on all the softwares
                        $this->replayDictionnaryOnSoftwaresByID($IDs, $res_rule);
                    }
                }
                $i++;
                if ($maxtime) {
                    $crt = explode(" ", microtime());
                    if (($crt[0] + $crt[1]) > $maxtime) {
                        break;
                    }
                }
            } // each distinct software

            if (isCommandLine()) {
                printf(__('Replay rules on existing database: %1$s/%2$s') . "   \n", $i, $nb);
            } else {
                Html::changeProgressBarPosition($i, $nb, "$i / $nb");
            }
        } else {
            $this->replayDictionnaryOnSoftwaresByID($items);
            return true;
        }

        if (isCommandLine()) {
            printf(__('Replay rules on existing database ended on %s') . "\n", date("r"));
        }

        return (($i == $nb) ? -1 : $i);
    }


    /**
     * Replay dictionnary on several softwares
     *
     * @param $IDs       array of software IDs to replay
     * @param $res_rule  array of rule results
     *
     * @return Query result handler
    **/
    public function replayDictionnaryOnSoftwaresByID(array $IDs, $res_rule = [])
    {
        global $DB;

        $new_softs  = [];
        $delete_ids = [];

        foreach ($IDs as $ID) {
            $soft = (new SoftwareDictionaryRepository(Orm::create($DB)))->replaySoftware((int)$ID);

            if ($soft !== null) {
                //For each software
                $this->replayDictionnaryOnOneSoftware(
                    $new_softs,
                    $res_rule,
                    $ID,
                    (
                        isset($res_rule['new_entities_id'])
                    ? $res_rule['new_entities_id']
                    : $soft["entities_id"]
                    ),
                    $soft['name'] ?? '',
                    $soft['manufacturer'] ?? '',
                    $delete_ids
                );
            }
        }
        //Delete software if needed
        $this->putOldSoftsInTrash($delete_ids);
    }


    /**
     * Replay dictionnary on one software
     *
     * @param &$new_softs      array containing new softwares already computed
     * @param $res_rule        array of rule results
     * @param $ID                    ID of the software
     * @param $entity                working entity ID
     * @param $name                  softwrae name
     * @param $manufacturer          manufacturer name
     * @param &$soft_ids       array containing replay software need to be put in trashbin
    **/
    public function replayDictionnaryOnOneSoftware(
        array &$new_softs,
        array $res_rule,
        $ID,
        $entity,
        $name,
        $manufacturer,
        array &$soft_ids
    ) {
        global $DB;

        $input["name"]         = $name;
        $input["manufacturer"] = $manufacturer;
        $input["entities_id"]  = $entity;

        if (empty($res_rule)) {
            $res_rule = $this->processAllRules($input, [], []);
        }
        $soft = new Software();
        if (isset($res_rules['_ignore_import']) && ($res_rules['_ignore_import'] == 1)) {
            $soft->putInTrash($ID, __('Software deleted by ITSM-NG dictionary rules'));
            return;
        }

        //Software's name has changed or entity
        if (
            (isset($res_rule["name"]) && (strtolower($res_rule["name"]) != strtolower((string) $name)))
              //Entity has changed, and new entity is a parent of the current one
            || (!isset($res_rule["name"])
                && isset($res_rule['new_entities_id'])
                && in_array(
                    $res_rule['new_entities_id'],
                    getAncestorsOf('glpi_entities', $entity)
                ))
        ) {
            if (isset($res_rule["name"])) {
                $new_name = $res_rule["name"];
            } else {
                $new_name = addslashes((string) $name);
            }

            if (isset($res_rule["manufacturer"]) && $res_rule["manufacturer"]) {
                $manufacturer = $res_rule["manufacturer"];
            } else {
                $manufacturer = addslashes((string) $manufacturer);
            }

            //New software not already present in this entity
            if (!isset($new_softs[$entity][$new_name])) {
                // create new software or restore it from trashbin
                $new_software_id               = $soft->addOrRestoreFromTrash(
                    $new_name,
                    $manufacturer,
                    $entity,
                    '',
                    true
                );
                $new_softs[$entity][$new_name] = $new_software_id;
            } else {
                $new_software_id = $new_softs[$entity][$new_name];
            }
            // Move licenses to new software
            SoftwareAssignmentCancelled::requireSuccess(
                $this->moveLicenses($ID, $new_software_id),
                'Dictionary licence ownership move'
            );
        } else {
            $new_software_id = $ID;
            $res_rule["id"]  = $ID;
            if (isset($res_rule["manufacturer"]) && $res_rule["manufacturer"]) {
                $res_rule["manufacturers_id"] = Dropdown::importExternal(
                    'Manufacturer',
                    $res_rule["manufacturer"]
                );
                unset($res_rule["manufacturer"]);
            }
            $soft->update($res_rule);
        }

        // Add to software to deleted list
        if ($new_software_id != $ID) {
            $soft_ids[] = $ID;
        }

        //Get all the different versions for a software
        foreach ((new SoftwareRepository(Orm::create($DB)))->versions((int)$ID) as $version) {
            $input["version"] = addslashes((string) $version["name"]);
            $old_version_name = $input["version"];

            if (isset($res_rule['version_append']) && $res_rule['version_append'] != '') {
                $new_version_name = $old_version_name . $res_rule['version_append'];
            } elseif (isset($res_rule["version"]) && $res_rule["version"] != '') {
                $new_version_name = $res_rule["version"];
            } else {
                $new_version_name = $version["name"] === null ? null : addslashes((string)$version["name"]);
            }
            if (
                ($ID != $new_software_id)
                || ($new_version_name != $old_version_name)
            ) {
                $this->moveVersions(
                    $ID,
                    $new_software_id,
                    $version["id"],
                    $old_version_name,
                    $new_version_name,
                    $entity
                );
            }
        }
    }


    /**
     * Delete a list of softwares
     *
     * @param $soft_ids array containing replay software need to be put in trashbin
    **/
    public function putOldSoftsInTrash(array $soft_ids)
    {
        global $DB;

        if (count($soft_ids) > 0) {
            //Try to delete all the software that are not used anymore
            // (which means that don't have version associated anymore)
            $software = new Software();
            foreach ((new SoftwareDictionaryRepository(Orm::create($DB)))->unusedSoftware($soft_ids) as $id) {
                $software->putInTrash($id, __('Software deleted by ITSM-NG dictionary rules'));
            }
        }
    }


    /**
     * Change software's name, and move versions if needed
     *
     * @param int $ID                    old software ID
     * @param int $new_software_id       new software ID
     * @param int $version_id            version ID to move
     * @param string $old_version        old version name
     * @param string $new_version        new version name
     * @param int $entity                entity ID
     * @return void
    */
    public function moveVersions($ID, $new_software_id, $version_id, $old_version, $new_version, $entity)
    {
        global $DB;

        (new SoftwareRepository(Orm::create($DB)))->moveDictionaryVersion(
            (int)$new_software_id,
            (int)$version_id,
            $new_version === null ? null : stripslashes((string)$new_version),
            static fn (int $id): bool => (new SoftwareVersion())->delete(['id' => $id])
        );
    }


    /**
     * Move licenses from a software to another
     *
     * @param $old_software_id    old software ID
     * @param $new_software_id    new software ID
     * @return true if move was successful
    **/
    public function moveLicenses($old_software_id, $new_software_id)
    {
        global $DB;

        return (new SoftwareDictionaryRepository(Orm::create($DB)))->moveLicenses((int)$old_software_id, (int)$new_software_id);
    }


    /**
     * Check if a version exists
     *
     * @param $software_id  software ID
     * @param $version      version name
    **/
    public function versionExists($software_id, $version)
    {
        global $DB;

        return (new SoftwareDictionaryRepository(Orm::create($DB)))->versionId(
            (int)$software_id,
            $version === null ? null : stripslashes((string)$version)
        );
    }

}
