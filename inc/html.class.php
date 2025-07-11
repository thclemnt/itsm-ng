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

use Glpi\Cache\SimpleCache;
use Glpi\Toolbox\URL;
use ScssPhp\ScssPhp\Compiler;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Html Class
 * Inpired from Html/FormHelper for several functions
 **/
class Html
{
    private static $css = [
        'vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.css',
        'css/bootstrap-select.min.css',
        'node_modules/select2/dist/css/select2.min.css',
        "node_modules/jquery-ui-dist/jquery-ui.min.css",
        'node_modules/@fortawesome/fontawesome-free/css/all.css',
        'css/jstree-glpi.css',
    ];

    private static $scss = [
        'css/styles',
        'css/itsm2.scss',
    ];

    private static $js = [
        "node_modules/jquery-ui-dist/jquery-ui.min.js",
        "vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js",
        "node_modules/tableexport.jquery.plugin/tableExport.min.js",
        "vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.js",
        "vendor/wenzhixin/bootstrap-table/src/extensions/export/bootstrap-table-export.js",
        "node_modules/select2/dist/js/select2.min.js",
        "src/ngFunctions.js",

    ];

    private static function getFilePath($file, $isScss = false)
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . ($isScss ? '/front/css.php?file=' : '/') . $file;
    }

    public static function getCss()
    {
        $allCss = [];
        foreach (self::$css as $file) {
            $allCss[] = self::getFilePath($file);
        }
        foreach (self::$scss as $file) {
            $allCss[] = self::getFilePath($file, true);
        }
        return $allCss;
    }

    public static function getJs()
    {
        $allJs = [];
        foreach (self::$js as $file) {
            $allJs[] = self::getFilePath($file);
        }
        return $allJs;
    }

    /**
     * Clean display value deleting html tags
     *
     * @param string  $value      string value
     * @param boolean $striptags  strip all html tags
     * @param integer $keep_bad
     *          1 : neutralize tag anb content,
     *          2 : remove tag and neutralize content
     * @return string
     **/
    public static function clean($value, $striptags = true, $keep_bad = 2)
    {
        $value = Html::entity_decode_deep($value);

        // Change <email@domain> to email@domain so it is not removed by htmLawed
        // Search for strings that is an email surrounded by `<` and `>` but that cannot be an HTML tag:
        // - absence of quotes indicate that values is not part of an HTML attribute,
        // - absence of > ensure that ending `>` has not been reached.
        $regex = "/(<[^\"'>]+?@[^>\"']+?>)/";
        $value = preg_replace_callback($regex, function ($matches) {
            return substr($matches[1], 1, (strlen($matches[1]) - 2));
        }, $value);

        // Clean MS office tags
        $value = str_replace(["<![if !supportLists]>", "<![endif]>"], '', $value);

        if ($striptags) {
            // Strip ToolTips
            $specialfilter = [
                '@<div[^>]*?tooltip_picture[^>]*?>.*?</div[^>]*?>@si',
                '@<div[^>]*?tooltip_text[^>]*?>.*?</div[^>]*?>@si',
                '@<div[^>]*?tooltip_picture_border[^>]*?>.*?</div[^>]*?>@si',
                '@<div[^>]*?invisible[^>]*?>.*?</div[^>]*?>@si'
            ];
            $value         = preg_replace($specialfilter, '', $value);

            $value = preg_replace("/<(p|br|div)( [^>]*)?" . ">/i", "\n", $value);
            $value = preg_replace("/(&nbsp;| |\xC2\xA0)+/", " ", $value);
        }

        $search = [
            '@<script[^>]*?>.*?</script[^>]*?>@si', // Strip out javascript
            '@<style[^>]*?>.*?</style[^>]*?>@si', // Strip out style
            '@<title[^>]*?>.*?</title[^>]*?>@si', // Strip out title
            '@<!DOCTYPE[^>]*?>@si', // Strip out !DOCTYPE
        ];
        $value = preg_replace($search, '', $value);

        // Neutralize not well formatted html tags
        $value = preg_replace("/(<)([^>]*<)/", "&lt;$2", $value);

        $config = Toolbox::getHtmLawedSafeConfig();
        $config['keep_bad'] = $keep_bad; // 1: neutralize tag and content, 2 : remove tag and neutralize content
        if ($striptags) {
            $config['elements'] = 'none';
        }

        $value = htmLawed($value, $config);

        // Special case : remove the 'denied:' for base64 img in case the base64 have characters
        // combinaison introduce false positive
        foreach (['png', 'gif', 'jpg', 'jpeg'] as $imgtype) {
            $value = str_replace(
                'src="denied:data:image/' . $imgtype . ';base64,',
                'src="data:image/' . $imgtype . ';base64,',
                $value
            );
        }
        // add "denied:" to email links
        $value = str_replace(
            'href="mailto:',
            'href="denied:mailto:',
            $value
        );

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace("/(\n[ ]*){2,}/", "\n\n", $value, -1);

        return trim($value);
    }


    /**
     * Recursivly execute html_entity_decode on an array
     *
     * @param string|array $value
     *
     * @return string|array
     **/
    public static function entity_decode_deep($value)
    {

        return (is_array($value) ? array_map([__CLASS__, 'entity_decode_deep'], $value)
            : html_entity_decode($value ?? '', ENT_QUOTES, "UTF-8"));
    }


    /**
     * Recursivly execute htmlentities on an array
     *
     * @param string|array $value
     *
     * @return string|array
     **/
    public static function entities_deep($value)
    {

        return (is_array($value) ? array_map([__CLASS__, 'entities_deep'], $value)
            : htmlentities($value, ENT_QUOTES, "UTF-8"));
    }


    /**
     * Convert a date YY-MM-DD to DD-MM-YY for calendar
     *
     * @param string       $time    Date to convert
     * @param integer|null $format  Date format
     *
     * @return null|string
     *
     * @see Toolbox::getDateFormats()
     **/
    public static function convDate($time, $format = null)
    {

        if (is_null($time) || trim($time) == '' || in_array($time, ['NULL', '0000-00-00', '0000-00-00 00:00:00'])) {
            return null;
        }

        if (!isset($_SESSION["glpidate_format"])) {
            $_SESSION["glpidate_format"] = 0;
        }
        if (!$format) {
            $format = $_SESSION["glpidate_format"];
        }

        try {
            $date = new \DateTime($time);
        } catch (\Exception $e) {
            Toolbox::logWarning("Invalid date $time!");
            Session::addMessageAfterRedirect(
                sprintf(
                    __('%1$s %2$s'),
                    $time,
                    _x('adjective', 'Invalid')
                )
            );
            return $time;
        }
        $mask = 'Y-m-d';

        switch ($format) {
            case 1: // DD-MM-YYYY
                $mask = 'd-m-Y';
                break;
            case 2: // MM-DD-YYYY
                $mask = 'm-d-Y';
                break;
        }

        return $date->format($mask);
    }


    /**
     * Convert a date YY-MM-DD HH:MM to DD-MM-YY HH:MM for display in a html table
     *
     * @param string       $time    Datetime to convert
     * @param integer|null $format  Datetime format
     *
     * @return null|string
     **/
    public static function convDateTime($time, $format = null)
    {

        if (is_null($time) || ($time == 'NULL')) {
            return null;
        }

        return self::convDate($time, $format) . ' ' . substr($time, 11, 5);
    }


    /**
     * Clean string for input text field
     *
     * @param string $string
     *
     * @return string
     **/
    public static function cleanInputText($string)
    {
        return preg_replace('/\'/', '&apos;', preg_replace('/\"/', '&quot;', $string ?? ''));
    }


    /**
     * Clean all parameters of an URL. Get a clean URL
     *
     * @param string $url
     *
     * @return string
     **/
    public static function cleanParametersURL($url)
    {

        $url = preg_replace("/(\/[0-9a-zA-Z\.\-\_]+\.php).*/", "$1", $url);
        return preg_replace("/\?.*/", "", $url);
    }


    /**
     * Recursivly execute nl2br on an array
     *
     * @param string|array $value
     *
     * @return string|array
     **/
    public static function nl2br_deep($value)
    {

        return (is_array($value) ? array_map([__CLASS__, 'nl2br_deep'], $value)
            : nl2br($value));
    }


    /**
     *  Resume text for followup
     *
     * @param string  $string  string to resume
     * @param integer $length  resume length (default 255)
     *
     * @return string
     **/
    public static function resume_text($string, $length = 255)
    {

        if (Toolbox::strlen($string) > $length) {
            $string = Toolbox::substr($string, 0, $length) . "&nbsp;(...)";
        }

        return $string;
    }


    /**
     *  Resume a name for display
     *
     * @param string  $string  string to resume
     * @param integer $length  resume length (default 255)
     *
     * @return string
     **/
    public static function resume_name($string, $length = 255)
    {

        if (strlen($string) > $length) {
            $string = Toolbox::substr($string, 0, $length) . "...";
        }

        return $string;
    }


    /**
     * Clean post value for display in textarea
     *
     * @param string $value
     *
     * @return string
     **/
    public static function cleanPostForTextArea($value)
    {

        if (is_array($value)) {
            $methodName = __METHOD__;
            return array_map(function ($item) use ($methodName) {
                return $methodName($item);
            }, $value);
        }
        $order   = [
            '\r\n',
            '\n',
            "\\'",
            '\"',
            '\\\\'
        ];
        $replace = [
            "\n",
            "\n",
            "'",
            '"',
            "\\"
        ];
        return str_replace($order, $replace, $value);
    }


    /**
     * Convert a number to correct display
     *
     * @param float   $number        Number to display
     * @param boolean $edit          display number for edition ? (id edit use . in all case)
     * @param integer $forcedecimal  Force decimal number (do not use default value) (default -1)
     *
     * @return string
     **/
    public static function formatNumber($number, $edit = false, $forcedecimal = -1)
    {
        global $CFG_GLPI;

        // Php 5.3 : number_format() expects parameter 1 to be double,
        if ($number == "") {
            $number = 0;
        } elseif ($number == "-") { // used for not defines value (from Infocom::Amort, p.e.)
            return "-";
        }

        $number  = doubleval($number);
        $decimal = $CFG_GLPI["decimal_number"];
        if ($forcedecimal >= 0) {
            $decimal = $forcedecimal;
        }

        // Edit : clean display for mysql
        if ($edit) {
            return number_format($number, $decimal, '.', '');
        }

        // Display : clean display
        switch ($_SESSION['glpinumber_format']) {
            case 0: // French
                return str_replace(' ', '&nbsp;', number_format($number, $decimal, '.', ' '));

            case 2: // Other French
                return str_replace(' ', '&nbsp;', number_format($number, $decimal, ',', ' '));

            case 3: // No space with dot
                return number_format($number, $decimal, '.', '');

            case 4: // No space with comma
                return number_format($number, $decimal, ',', '');

            default: // English
                return number_format($number, $decimal, '.', ',');
        }
    }


    /**
     * Make a good string from the unix timestamp $sec
     *
     * @param int|float  $time         timestamp
     * @param boolean    $display_sec  display seconds ?
     * @param boolean    $use_days     use days for display ?
     *
     * @return string
     **/
    public static function timestampToString($time, $display_sec = true, $use_days = true)
    {

        $time = (float)$time;

        $sign = '';
        if ($time < 0) {
            $sign = '- ';
            $time = abs($time);
        }
        $time = floor($time);

        // Force display seconds if time is null
        if ($time < MINUTE_TIMESTAMP) {
            $display_sec = true;
        }

        $units = Toolbox::getTimestampTimeUnits($time);
        if ($use_days) {
            if ($units['day'] > 0) {
                if ($display_sec) {
                    //TRANS: %1$s is the sign (-or empty), %2$d number of days, %3$d number of hours,
                    //       %4$d number of minutes, %5$d number of seconds
                    return sprintf(
                        __('%1$s%2$d days %3$d hours %4$d minutes %5$d seconds'),
                        $sign,
                        $units['day'],
                        $units['hour'],
                        $units['minute'],
                        $units['second']
                    );
                }
                //TRANS:  %1$s is the sign (-or empty), %2$d number of days, %3$d number of hours,
                //        %4$d number of minutes
                return sprintf(
                    __('%1$s%2$d days %3$d hours %4$d minutes'),
                    $sign,
                    $units['day'],
                    $units['hour'],
                    $units['minute']
                );
            }
        } else {
            if ($units['day'] > 0) {
                $units['hour'] += 24 * $units['day'];
            }
        }

        if ($units['hour'] > 0) {
            if ($display_sec) {
                //TRANS:  %1$s is the sign (-or empty), %2$d number of hours, %3$d number of minutes,
                //        %4$d number of seconds
                return sprintf(
                    __('%1$s%2$d hours %3$d minutes %4$d seconds'),
                    $sign,
                    $units['hour'],
                    $units['minute'],
                    $units['second']
                );
            }
            //TRANS: %1$s is the sign (-or empty), %2$d number of hours, %3$d number of minutes
            return sprintf(__('%1$s%2$d hours %3$d minutes'), $sign, $units['hour'], $units['minute']);
        }

        if ($units['minute'] > 0) {
            if ($display_sec) {
                //TRANS:  %1$s is the sign (-or empty), %2$d number of minutes,  %3$d number of seconds
                return sprintf(
                    __('%1$s%2$d minutes %3$d seconds'),
                    $sign,
                    $units['minute'],
                    $units['second']
                );
            }
            //TRANS: %1$s is the sign (-or empty), %2$d number of minutes
            return sprintf(
                _n('%1$s%2$d minute', '%1$s%2$d minutes', $units['minute']),
                $sign,
                $units['minute']
            );
        }

        if ($display_sec) {
            //TRANS:  %1$s is the sign (-or empty), %2$d number of seconds
            return sprintf(
                _n('%1$s%2$s second', '%1$s%2$s seconds', $units['second']),
                $sign,
                $units['second']
            );
        }
        return '';
    }


    /**
     * Format a timestamp into a normalized string (hh:mm:ss).
     *
     * @param integer $time
     *
     * @return string
     **/
    public static function timestampToCsvString($time)
    {

        if ($time < 0) {
            $time = abs($time);
        }
        $time = floor($time);

        $units = Toolbox::getTimestampTimeUnits($time);

        if ($units['day'] > 0) {
            $units['hour'] += 24 * $units['day'];
        }

        return str_pad($units['hour'], 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad($units['minute'], 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad($units['second'], 2, '0', STR_PAD_LEFT);
    }


    /**
     * Extract url from web link
     *
     * @param string $value
     *
     * @return string
     **/
    public static function weblink_extract($value)
    {

        $value = preg_replace('/<a\s+href\="([^"]+)"[^>]*>[^<]*<\/a>/i', "$1", $value);
        return $value;
    }


    /**
     * Redirection to $_SERVER['HTTP_REFERER'] page
     *
     * @return void
     **/
    public static function back()
    {
        self::redirect(self::getBackUrl());
    }


    /**
     * Redirection hack
     *
     * @param $dest string: Redirection destination
     * @param $http_response_code string: Forces the HTTP response code to the specified value
     *
     * @return void
     **/
    public static function redirect($dest, $http_response_code = 302)
    {

        $toadd = '';
        $dest = addslashes($dest);

        if (!headers_sent() && !Toolbox::isAjax()) {
            header("Location: $dest", true, $http_response_code);
            exit();
        }

        if (strpos($dest, "?") !== false) {
            $toadd = '&tokonq=' . Toolbox::getRandomString(5);
        } else {
            $toadd = '?tokonq=' . Toolbox::getRandomString(5);
        }

        echo "<script type='text/javascript'>
          NomNav = navigator.appName;
      if (NomNav=='Konqueror') {
          window.location='" . $dest . $toadd . "';
            } else {
                window.location='" . $dest . "';
            }
         </script>";
        exit();
    }

    /**
     * Redirection to Login page
     *
     * @param string $params  param to add to URL (default '')
     * @since 0.85
     *
     * @return void
     **/
    public static function redirectToLogin($params = '')
    {
        global $CFG_GLPI, $AJAX_INCLUDE;

        $dest     = $CFG_GLPI["root_doc"] . "/index.php";

        if (!isset($AJAX_INCLUDE)) {
            $url_dest = preg_replace(
                '/^' . preg_quote($CFG_GLPI["root_doc"], '/') . '/',
                '',
                $_SERVER['REQUEST_URI']
            );
            $dest .= "?redirect=" . rawurlencode($url_dest);
        }

        if (!empty($params)) {
            $dest .= ((!isset($AJAX_INCLUDE)) ? '&' : '?') . $params;
        }

        self::redirect($dest);
    }


    /**
     * Display common message for item not found
     *
     * @return void
     **/
    public static function displayNotFoundError()
    {
        global $CFG_GLPI, $HEADER_LOADED;

        if (!$HEADER_LOADED) {
            if (!Session::getCurrentInterface()) {
                self::nullHeader(__('Access denied'));
            } else {
                self::header(__('Access denied'));
            }
        }
        echo "<div class='center'><br><br>";
        echo "<img src='" . $CFG_GLPI["root_doc"] . "/pics/warning.png' alt='" . __s('Warning') . "'>";
        echo "<br><br><span class='b'>" . __('Item not found') . "</span></div>";
        self::nullFooter();
        exit();
    }


    /**
     * Display common message for privileges errors
     *
     * @return void
     **/
    public static function displayRightError()
    {
        self::displayErrorAndDie(__("You don't have permission to perform this action."));
    }


    /**
     * Display a div containing messages set in session in the previous page
     **/
    public static function displayMessageAfterRedirect()
    {
        if (
            isset($_SESSION["MESSAGE_AFTER_REDIRECT"])
            && count($_SESSION["MESSAGE_AFTER_REDIRECT"]) > 0
        ) {
            echo "<div class=\"toast-container position-fixed bottom-0 end-0 p-3\">";

            foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $msgtype => $messages) {
                if (count($messages) > 0) {
                    $html_messages = implode('<br/>', $messages);
                } else {
                    continue;
                }

                switch ($msgtype) {
                    case ERROR:
                        $title = 'Error';
                        $class = 'bg-danger text-white';
                        $autoClose = false; // Disable auto-closing for ERROR messages
                        break;
                    case WARNING:
                        $title = 'Warning';
                        $class = 'bg-warning text-dark';
                        $autoClose = true; // Allow auto-closing for WARNING messages
                        break;
                    case INFO:
                        $title = 'Information';
                        $class = 'bg-info text-white';
                        $autoClose = true; // Allow auto-closing for INFO messages
                        break;
                }

                $autoCloseAttribute = $autoClose ? '' : 'data-bs-autohide="false"';

                echo <<<HTML
                     <div class="toast {$class}" role="alert" aria-live="assertive" aria-atomic="true" {$autoCloseAttribute}>
                        <div class="toast-header">
                           <strong class="me-auto">{$title}</strong>
                           <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                        <div class="toast-body">
                           $html_messages
                        </div>
                     </div>
                HTML;

                $scriptblock = <<<JS
                   $(function() {
                      $('.toast').toast({ delay: 5000 }).toast('show');
                   });
                JS;

                echo Html::scriptBlock($scriptblock);
            }

            echo "</div>";
        }

        // Clean message
        $_SESSION["MESSAGE_AFTER_REDIRECT"] = [];
    }

    public static function displayAjaxMessageAfterRedirect()
    {
        global $CFG_GLPI;

        echo Html::scriptBlock("
      displayAjaxMessageAfterRedirect = function() {
         // attach MESSAGE_AFTER_REDIRECT to body
         $('.message_after_redirect').remove();
         $('[id^=\"message_after_redirect_\"]').remove();
         $.ajax({
            url:  '" . $CFG_GLPI['root_doc'] . "/ajax/displayMessageAfterRedirect.php',
            success: function(html) {
               $('body').append(html);
            }
         });
      }");
    }


    /**
     * Common Title Function
     *
     * @param string        $ref_pic_link    Path to the image to display (default '')
     * @param string        $ref_pic_text    Alt text of the icon (default '')
     * @param string        $ref_title       Title to display (default '')
     * @param array|string  $ref_btts        Extra items to display array(link=>text...) (default '')
     *
     * @return void
     **/
    public static function displayTitle($ref_pic_link = "", $ref_pic_text = "", $ref_title = "", $ref_btts = "")
    {

        $ref_pic_text = htmlentities($ref_pic_text, ENT_QUOTES, 'UTF-8');

        echo "<div class='center'><table class='tab_glpi' aria-label='Title Display'><tr>";
        if ($ref_pic_link != "") {
            $ref_pic_text = self::clean($ref_pic_text);
            echo "<td>" . Html::image($ref_pic_link, ['alt' => $ref_pic_text]) . "</td>";
        }

        if ($ref_title != "") {
            echo "<td><span class='alert alert-secondary'>&nbsp;" . $ref_title . "&nbsp;</span></td>";
        }

        if (is_array($ref_btts) && count($ref_btts)) {
            foreach ($ref_btts as $key => $val) {
                echo "<td><a class='btn btn-secondary' href='" . $key . "'>" . $val . "</a></td>";
            }
        }
        echo "</tr></table></div>";
    }


    /**
     * Clean Display of Request
     *
     * @since 0.83.1
     *
     * @param string $request  SQL request
     *
     * @return string
     **/
    public static function cleanSQLDisplay($request)
    {

        $request = str_replace("<", "&lt;", $request);
        $request = str_replace(">", "&gt;", $request);
        $request = str_ireplace("UNION", "<br/>UNION<br/>", $request);
        $request = str_ireplace("UNION ALL", "<br/>UNION ALL<br/>", $request);
        $request = str_ireplace("FROM", "<br/>FROM", $request);
        $request = str_ireplace("WHERE", "<br/>WHERE", $request);
        $request = str_ireplace("INNER JOIN", "<br/>INNER JOIN", $request);
        $request = str_ireplace("LEFT JOIN", "<br/>LEFT JOIN", $request);
        $request = str_ireplace("ORDER BY", "<br/>ORDER BY", $request);
        $request = str_ireplace("SORT", "<br/>SORT", $request);

        return $request;
    }

    /**
     * Display Debug Information
     *
     * @param boolean $with_session with session information (true by default)
     * @param boolean $ajax         If we're called from ajax (false by default)
     *
     * @return void
     **/
    public static function displayDebugInfos($with_session = true, $ajax = false)
    {
        global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST, $DEBUG_AUTOLOAD;
        $GLPI_CACHE = Config::getCache('cache_db');

        // Only for debug mode so not need to be translated
        if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) { // mode debug
            $rand = mt_rand();
            echo "<div class='debug " . ($ajax ? "debug_ajax" : "") . "' bg-primary>";
            if (!$ajax) {
                $debug_title = __s('Display GLPI debug information');
                echo <<<HTML
            <span class='fa-stack fa-lg' id='see_debug'>
               <i class='fa fa-circle fa-stack-2x text-primary' aria-hidden="true"></i>
               <a href='#' class='fa fa-bug fa-stack-1x text-white' title='{$debug_title}'>
                  <span class='sr-only'>See GLPI DEBUG</span>
               </a>
            </span>
            HTML;
            }

            echo "<div id='debugtabs$rand' style='display: none;'><ul>";
            if ($CFG_GLPI["debug_sql"]) {
                echo "<li><a href='#debugsql$rand'>SQL REQUEST</a></li>";
            }
            if ($CFG_GLPI["debug_vars"]) {
                echo "<li><a href='#debugautoload$rand'>AUTOLOAD</a></li>";
                echo "<li><a href='#debugpost$rand'>POST VARIABLE</a></li>";
                echo "<li><a href='#debugget$rand'>GET VARIABLE</a></li>";
                if ($with_session) {
                    echo "<li><a href='#debugsession$rand'>SESSION VARIABLE</a></li>";
                }
                echo "<li><a href='#debugserver$rand'>SERVER VARIABLE</a></li>";
                if ($GLPI_CACHE instanceof SimpleCache) {
                    echo "<li><a href='#debugcache$rand'>CACHE VARIABLE</a></li>";
                }
            }
            echo "</ul>";

            if ($CFG_GLPI["debug_sql"]) {
                echo "<div id='debugsql$rand'>";
                echo "<div class='b'>" . $SQL_TOTAL_REQUEST . " Queries ";
                echo "took  " . array_sum($DEBUG_SQL['times']) . "s</div>";

                echo "<table class='tab_cadre' aria-label='SQL Debug Information'><tr><th>N&#176; </th><th>Queries</th><th>Time</th>";
                echo "<th>Rows</th><th>Errors</th></tr>";

                foreach ($DEBUG_SQL['queries'] as $num => $query) {
                    echo "<tr class='tab_bg_" . (($num % 2) + 1) . "'><td>$num</td><td>";
                    echo self::cleanSQLDisplay($query);
                    echo "</td><td>";
                    echo $DEBUG_SQL['times'][$num];
                    echo "</td><td>";
                    echo $DEBUG_SQL['rows'][$num] ?? 0;
                    echo "</td><td>";
                    if (isset($DEBUG_SQL['errors'][$num])) {
                        echo $DEBUG_SQL['errors'][$num];
                    } else {
                        echo "&nbsp;";
                    }
                    echo "</td></tr>";
                }
                echo "</table>";
                echo "</div>";
            }
            if ($CFG_GLPI["debug_vars"]) {
                echo "<div id='debugautoload$rand'>" . implode(', ', $DEBUG_AUTOLOAD) . "</div>";
                echo "<div id='debugpost$rand'>";
                self::printCleanArray($_POST, 0, true);
                echo "</div>";
                echo "<div id='debugget$rand'>";
                self::printCleanArray($_GET, 0, true);
                echo "</div>";
                if ($with_session) {
                    echo "<div id='debugsession$rand'>";
                    self::printCleanArray($_SESSION, 0, true);
                    echo "</div>";
                }
                echo "<div id='debugserver$rand'>";
                self::printCleanArray($_SERVER, 0, true);
                echo "</div>";

                if ($GLPI_CACHE instanceof SimpleCache) {
                    echo "<div id='debugcache$rand'>";
                    $cache_keys = $GLPI_CACHE->getAllKnownCacheKeys();
                    $cache_contents = $GLPI_CACHE->getMultiple($cache_keys);
                    self::printCleanArray($cache_contents, 0, true);
                    echo "</div>";
                }
            }

            echo Html::scriptBlock("
            $(function() {
                $('#debugtabs$rand').tabs({
                   collapsible: true
                }).addClass( 'ui-tabs-vertical ui-helper-clearfix' );

                $('<li class=\"close\"><button aria-label=\"Close Debug\" id= \"close_debug$rand\">X</button></li>')
                   .appendTo('#debugtabs$rand ul');

                $('#close_debug$rand').button({
                   icons: {
                      primary: 'ui-icon-close'
                   },
                   text: false
                }).click(function() {
                    $('#debugtabs$rand').css('display', 'none');
                });

                $('#see_debug').click(function(e) {
                   e.preventDefault();
                   console.log('see_debug #debugtabs$rand');
                   $('#debugtabs$rand').css('display', 'block');
                });
            });
         ");

            echo "</div></div>";
        }
    }


    /**
     * Display a Link to the last page using http_referer if available else use history.back
     **/
    public static function displayBackLink()
    {
        $url_referer = self::getBackUrl();
        if ($url_referer !== false) {
            echo "<a href='$url_referer'>" . __('Back') . "</a>";
        } else {
            echo "<a href='javascript:history.back();'>" . __('Back') . "</a>";
        }
    }

    /**
     * Return an url for getting back to previous page.
     * Remove `forcetab` parameter if exists to prevent bad tab display
     *
     * @param string $url_in optional url to return (without forcetab param), if empty, we will user HTTP_REFERER from server
     *
     * @since 9.2.2
     *
     * @return mixed [string|boolean] false, if failed, else the url string
     */
    public static function getBackUrl($url_in = "")
    {
        if (
            isset($_SERVER['HTTP_REFERER'])
            && strlen($url_in) == 0
        ) {
            $url_in = $_SERVER['HTTP_REFERER'];
        }
        if (strlen($url_in) > 0) {
            $url = parse_url($url_in);

            if (isset($url['query'])) {
                parse_str($url['query'], $parameters);
                unset($parameters['forcetab']);
                $new_query = http_build_query($parameters);
                return str_replace($url['query'], $new_query, $url_in);
            }

            return $url_in;
        }
        return false;
    }


    /**
     * Simple Error message page
     *
     * @param string  $message  displayed before dying
     * @param boolean $minimal  set to true do not display app menu (false by default)
     *
     * @return void
     **/
    public static function displayErrorAndDie($message, $minimal = false)
    {
        global $CFG_GLPI, $HEADER_LOADED;

        if (!$HEADER_LOADED) {
            if ($minimal || !Session::getCurrentInterface()) {
                self::nullHeader(__('Access denied'), '');
            } else {
                self::header(__('Access denied'), '');
            }
        }
        echo "<div class='center'><br><br>";
        echo Html::image($CFG_GLPI["root_doc"] . "/pics/warning.png", ['alt' => __('Warning')]);
        echo "<br><br><span class='b'>$message</span></div>";
        self::nullFooter();
        exit();
    }


    /**
     * Add confirmation on button or link before action
     *
     * @param $string             string   to display or array of string for using multilines
     * @param $additionalactions  string   additional actions to do on success confirmation
     *                                     (default '')
     *
     * @return string
     **/
    public static function addConfirmationOnAction($string, $additionalactions = '')
    {

        return "onclick=\"" . Html::getConfirmationOnActionScript($string, $additionalactions) . "\"";
    }


    /**
     * Get confirmation on button or link before action
     *
     * @since 0.85
     *
     * @param $string             string   to display or array of string for using multilines
     * @param $additionalactions  string   additional actions to do on success confirmation
     *                                     (default '')
     *
     * @return string confirmation script
     **/
    public static function getConfirmationOnActionScript($string, $additionalactions = '')
    {

        if (!is_array($string)) {
            $string = [$string];
        }
        $string            = Toolbox::addslashes_deep($string);
        $additionalactions = trim($additionalactions);
        $out               = "";
        $multiple          = false;
        $close_string      = '';
        // Manage multiple confirmation
        foreach ($string as $tab) {
            if (is_array($tab)) {
                $multiple      = true;
                $out          .= "if (window.confirm('";
                $out          .= implode('\n', $tab);
                $out          .= "')){ ";
                $close_string .= "return true;} else { return false;}";
            }
        }
        // manage simple confirmation
        if (!$multiple) {
            $out          .= "if (window.confirm('";
            $out          .= implode('\n', $string);
            $out          .= "')){ ";
            $close_string .= "return true;} else { return false;}";
        }
        $out .= $additionalactions . (substr($additionalactions, -1) != ';' ? ';' : '') . $close_string;
        return $out;
    }


    /**
     * Manage progresse bars
     *
     * @since 0.85
     *
     * @param $id                 HTML ID of the progress bar
     * @param $options    array   progress status
     *                    - create    do we have to create it ?
     *                    - message   add or change the message
     *                    - percent   current level
     *
     *
     * @return void
     **/
    public static function progressBar($id, array $options = [])
    {

        $params            = [];
        $params['create']  = false;
        $params['message'] = null;
        $params['percent'] = -1;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $params[$key] = $val;
            }
        }

        if ($params['create']) {
            echo "<div class='doaction_cadre'>";
            echo "<div class='doaction_progress' id='$id'>";
            echo "<div class='doaction_progress_text' id='" . $id . "_text' >&nbsp;</div>";
            echo "</div>";
            echo "</div><br>";
            echo Html::scriptBlock(self::jsGetElementbyID($id) . ".progressbar();");
        }

        if ($params['message'] !== null) {
            echo Html::scriptBlock(self::jsGetElementbyID($id . '_text') . ".text(\"" .
               addslashes($params['message']) . "\");");
        }

        if (
            ($params['percent'] >= 0)
            && ($params['percent'] <= 100)
        ) {
            echo Html::scriptBlock(self::jsGetElementbyID($id) . ".progressbar('option', 'value', " .
               $params['percent'] . " );");
        }

        if (!$params['create']) {
            self::glpi_flush();
        }
    }


    /**
     * Create a Dynamic Progress Bar
     *
     * @param string $msg  initial message (under the bar)
     *
     * @return void
     **/
    public static function createProgressBar($msg = "&nbsp;")
    {

        $options = ['create' => true];
        if ($msg != "&nbsp;") {
            $options['message'] = $msg;
        }

        self::progressBar('doaction_progress', $options);
    }

    /**
     * Change the Message under the Progress Bar
     *
     * @param string $msg message under the bar
     *
     * @return void
     **/
    public static function changeProgressBarMessage($msg = "&nbsp;")
    {

        self::progressBar('doaction_progress', ['message' => $msg]);
        self::glpi_flush();
    }


    /**
     * Change the Progress Bar Position
     *
     * @param float  $crt   Current Value (less then $tot)
     * @param float  $tot   Maximum Value
     * @param string $msg   message inside the bar (default is %)
     *
     * @return void
     **/
    public static function changeProgressBarPosition($crt, $tot, $msg = "")
    {

        $options = [];

        if (!$tot) {
            $options['percent'] = 0;
        } elseif ($crt > $tot) {
            $options['percent'] = 100;
        } else {
            $options['percent'] = 100 * $crt / $tot;
        }

        if ($msg != "") {
            $options['message'] = $msg;
        }

        self::progressBar('doaction_progress', $options);
        self::glpi_flush();
    }


    /**
     * Display a simple progress bar
     *
     * @param integer $width       Width   of the progress bar
     * @param float   $percent     Percent of the progress bar
     * @param array   $options     possible options:
     *            - title : string title to display (default Progesssion)
     *            - simple : display a simple progress bar (no title / only percent)
     *            - forcepadding : boolean force str_pad to force refresh (default true)
     *
     * @return void
     **/
    public static function displayProgressBar($width, $percent, $options = [])
    {
        global $CFG_GLPI;

        $param['title']        = __('Progress');
        $param['simple']       = false;
        $param['forcepadding'] = true;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $param[$key] = $val;
            }
        }

        $percentwidth = floor($percent * $width / 100);
        $output       = "<div class='center'><table class='tab_cadre' width='" . ($width + 20) . "px' aria-label='Progress Bar'>";

        if (!$param['simple']) {
            $output .= "<tr><th class='center'>" . $param['title'] . "&nbsp;" . $percent . "%</th></tr>";
        }
        $output .= "<tr><td>
                  <table class='tabcompact' aria-label='Progress Bar'><tr><td class='center' style='background:url(" . $CFG_GLPI["root_doc"] .
           "/pics/loader.png) repeat-x; padding: 0px;font-size: 10px;' width='" .
           $percentwidth . " px' height='12'>";

        if ($param['simple']) {
            $output .= $percent . "%";
        } else {
            $output .= '&nbsp;';
        }

        $output .= "</td></tr></table></td>";
        $output .= "</tr></table>";
        $output .= "</div>";

        if (!$param['forcepadding']) {
            echo $output;
        } else {
            echo Toolbox::str_pad($output, 4096);
            self::glpi_flush();
        }
    }

    /* Returns a list of CSS files to include for plugins
     *
     * @return array
     */
    public static function getPluginsCss()
    {
        global $PLUGIN_HOOKS;
        $css = [];
        if (isset($PLUGIN_HOOKS['add_css']) && count($PLUGIN_HOOKS['add_css'])) {
            foreach ($PLUGIN_HOOKS["add_css"] as $plugin => $files) {
                if (!Plugin::isPluginActive($plugin)) {
                    continue;
                }

                $plugin_root_dir = Plugin::getPhpDir($plugin, true);
                $plugin_web_dir  = Plugin::getWebDir($plugin, false);
                $version         = Plugin::getInfo($plugin, 'version');

                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    $filename = "$plugin_root_dir/$file";

                    if (!file_exists($filename)) {
                        continue;
                    }

                    if ('scss' === substr(strrchr($filename, '.'), 1)) {
                        $css[] = Html::scss("$plugin_web_dir/$file", ['version' => $version]);
                    } else {
                        $css[] = Html::css("$plugin_web_dir/$file", ['version' => $version]);
                    }
                }
            }
        }
        return $css;
    }


    /**
     * Include common HTML headers
     *
     * @param string $title   title used for the page (default '')
     * @param string $sector  sector in which the page displayed is
     * @param string $item    item corresponding to the page displayed
     * @param string $option  option corresponding to the page displayed
     *
     * @return void
     **/
    public static function includeHeader($title = '', $sector = 'none', $item = 'none', $option = '')
    {
        global $CFG_GLPI, $DB, $PLUGIN_HOOKS;

        // complete title with id if exist
        if (isset($_GET['id']) && $_GET['id']) {
            $title = sprintf(__('%1$s - %2$s'), $title, $_GET['id']);
        }

        // Start the page
        echo "<!DOCTYPE html>\n";
        echo "<html lang=\"{$CFG_GLPI["languages"][$_SESSION['glpilanguage']][3]}\">";
        echo "<head><title>ITSM-NG - " . $title . "</title>";
        echo "<meta charset=\"utf-8\">";

        //prevent IE to turn into compatible mode...
        echo "<meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n";

        // auto desktop / mobile viewport
        echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";

        // CSRF token used for AJAX calls
        // Ensure this token is not shared with the page, as result would be that some AJAX request will consume
        // the token that would have been used by a form submitted from the same page.
        echo '<meta property="glpi:csrf_token" content="' . Session::getNewCSRFToken(true) . '" />';

        //detect theme
        $theme = isset($_SESSION['glpipalette']) ? $_SESSION['glpipalette'] : 'itsmng';

        echo Html::css('vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.css');
        echo Html::css('css/bootstrap-select.min.css');
        echo Html::css('vendor/twbs/bootstrap/dist/css/bootstrap.min.css');
        echo Html::css("node_modules/jquery-ui-dist/jquery-ui.min.css");
        echo Html::css("node_modules/select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css");
        echo Html::css("node_modules/ckeditor5/dist/ckeditor5.css");


        echo Html::css('public/lib/base.css');
        //JSTree JS part is loaded on demand... But from an ajax call to display entities. Need to have CSS loaded.
        echo Html::css('css/jstree-glpi.css');

        if (isset($CFG_GLPI['notifications_ajax']) && $CFG_GLPI['notifications_ajax']) {
            Html::requireJs('notifications_ajax');
        }

        echo Html::css('public/lib/leaflet.css');
        Html::requireJs('leaflet');

        echo Html::css('node_modules/gridstack/dist/gridstack.min.css');

        echo Html::css('node_modules/chartist/dist/index.css');

        echo Html::css('public/lib/flatpickr.css');
        if ($theme != "darker") {
            echo Html::css('public/lib/flatpickr/themes/light.css');
        } else {
            echo Html::css('public/lib/flatpickr/themes/dark.css');
        }
        Html::requireJs('flatpickr');

        //on demand JS
        if ($sector != 'none' || $item != 'none' || $option != '') {
            $jslibs = [];
            if (isset($CFG_GLPI['javascript'][$sector])) {
                if (isset($CFG_GLPI['javascript'][$sector][$item])) {
                    if (isset($CFG_GLPI['javascript'][$sector][$item][$option])) {
                        $jslibs = $CFG_GLPI['javascript'][$sector][$item][$option];
                    } else {
                        $jslibs = $CFG_GLPI['javascript'][$sector][$item];
                    }
                } else {
                    $jslibs = $CFG_GLPI['javascript'][$sector];
                }
            }

            if (in_array('planning', $jslibs)) {
                Html::requireJs('planning');
            }

            if (in_array('fullcalendar', $jslibs)) {
                echo Html::css(
                    'public/lib/fullcalendar.css',
                    ['media' => '']
                );
                Html::requireJs('fullcalendar');
            }

            if (in_array('gantt', $jslibs)) {
                echo Html::css('public/lib/jquery-gantt.css');
                Html::requireJs('gantt');
            }

            if (in_array('kanban', $jslibs)) {
                Html::requireJs('kanban');
            }

            if (in_array('rateit', $jslibs)) {
                echo Html::css('public/lib/jquery.rateit.css');
                Html::requireJs('rateit');
            }

            if (in_array('rack', $jslibs)) {
                Html::requireJs('rack');
            }

            if (in_array('sortable', $jslibs)) {
                Html::requireJs('sortable');
            }

            if (in_array('tinymce', $jslibs)) {
                Html::requireJs('tinymce');
            }

            if (in_array('clipboard', $jslibs)) {
                Html::requireJs('clipboard');
            }

            if (in_array('jstree', $jslibs)) {
                Html::requireJs('jstree');
            }

            if (in_array('charts', $jslibs)) {
                echo Html::css('node_modules/chartist/dist/index.css');
                Html::requireJs('charts');
            }

            if (in_array('codemirror', $jslibs)) {
                echo Html::css('public/lib/codemirror.css');
                Html::requireJs('codemirror');
            }

            if (in_array('photoswipe', $jslibs)) {
                echo Html::css('public/lib/photoswipe.css');
                Html::requireJs('photoswipe');
            }
        }

        if (Session::getLoginUserID() != 0) {
            Html::requireJs('hotkeys');
        }

        if (Session::getCurrentInterface() == "helpdesk") {
            echo Html::css('public/lib/jquery.rateit.css');
            Html::requireJs('rateit');
        }

        //file upload is required... almost everywhere.
        Html::requireJs('fileupload');

        // load fuzzy search everywhere
        Html::requireJs('fuzzy');

        // load log filters everywhere
        Html::requireJs('log_filters');

        echo Html::css('css/jquery-glpi.css');
        if (
            CommonGLPI::isLayoutWithMain()
            && !CommonGLPI::isLayoutExcludedPage()
        ) {
            echo Html::css('public/lib/scrollable-tabs.css');
        }

        //  CSS link
        echo Html::scss('css/styles');
        echo Html::scss('css/colors');
        echo Html::scss('css/itsm2');
        if (isset($_SESSION['glpihighcontrast_css']) && $_SESSION['glpihighcontrast_css']) {
            echo Html::scss('css/highcontrast');
        }

        echo Html::css('css/print.css', ['media' => 'print']);
        echo "<link rel='shortcut icon' type='images/x-icon' href='" .
           $CFG_GLPI["root_doc"] . "/pics/favicon.ico' >\n";

        $pluginCss = self::getPluginsCss();
        foreach ($pluginCss as $css) {
            echo $css;
        }

        // Custom CSS for active entity
        if ($DB instanceof DBmysql && $DB->connected) {
            $entity = new Entity();
            if (isset($_SESSION['glpiactive_entity'])) {
                // Apply active entity styles
                $entity->getFromDB($_SESSION['glpiactive_entity']);
            } else {
                // Apply root entity styles
                $entity->getFromDB('0');
            }
            echo $entity->getCustomCssTag();
        }

        // AJAX library
        echo Html::script('public/lib/base.js');

        // Locales
        $locales_domains = ['glpi' => ITSM_VERSION]; // base domain
        $plugins = Plugin::getPlugins();
        foreach ($plugins as $plugin) {
            $locales_domains[$plugin] = Plugin::getInfo($plugin, 'version');
        }
        if (isset($_SESSION['glpilanguage'])) {
            echo Html::scriptBlock(
                <<<JAVASCRIPT
            $(function() {
               i18n.setLocale('{$_SESSION['glpilanguage']}');
            });
JAVASCRIPT
            );
            foreach ($locales_domains as $locale_domain => $locale_version) {
                $locales_url = $CFG_GLPI['root_doc'] . '/front/locale.php'
                   . '?domain=' . $locale_domain
                   . '&version=' . $locale_version
                   . ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE ? '&debug' : '');
                $locale_js = <<<JAVASCRIPT
               $(function() {
                  $.ajax({
                     type: 'GET',
                     url: '{$locales_url}',
                     success: function(json) {
                        i18n.loadJSON(json, '{$locale_domain}');
                     }
                  });
               });
JAVASCRIPT;
                echo Html::scriptBlock($locale_js);
            }
        }

        self::accessibilityHeader();

        // layout
        if (
            CommonGLPI::isLayoutWithMain()
            && !CommonGLPI::isLayoutExcludedPage()
        ) {
            echo Html::script('public/lib/scrollable-tabs.js');
        }
        // End of Head
        echo "</head>\n";
        self::glpi_flush();
    }

    /**
     * accessibilityHeader
     *
     * @return void
     */
    public static function accessibilityHeader()
    {
        $user = new User();
        $user->getFromDB(Session::getLoginUserID());
        if (Session::haveRight("accessibility", READ)) {
            $factor = $user->fields["access_zoom_level"];
            $font = $user->fields["access_font"];
            switch ($font) {
                case "OpenDyslexic":
                    echo '<link href="http://fonts.cdnfonts.com/css/opendyslexic" rel="stylesheet">';     // Use CDNFonts for webfont delivery
                    break;
                case "OpenDyslexicAlta":
                    echo '<link href="http://fonts.cdnfonts.com/css/opendyslexic?styles=29221" rel="stylesheet">';
                    break;
                case 'Tiresias Infofont':
                    echo '<link href="http://fonts.cdnfonts.com/css/tiresias-infofont" rel="stylesheet">';
                    break;
                default:
                    break;
            }
        }
    }


    /**
     * @since 0.90
     *
     * @return array
     **/
    public static function getMenuInfos()
    {
        global $CFG_GLPI;

        $menu = [
           'assets' => [
              'title' => __('Assets'),
              'types' => array_merge([
                 'Computer', 'Monitor', 'Software',
                 'NetworkEquipment', 'Peripheral', 'Printer',
                 'CartridgeItem', 'ConsumableItem', 'Phone',
                 'Rack', 'Enclosure', 'PDU', 'PassiveDCEquipment'
              ], $CFG_GLPI['devices_in_menu']),
           ],
           'helpdesk' => [
              'title' => __('Assistance'),
              'types' => [
                 'Ticket', 'Problem', 'Change',
                 'Planning', 'Stat', 'TicketRecurrent'
              ],
           ],
           'management' => [
              'title' => __('Management'),
              'types' => [
                 'SoftwareLicense', 'Budget', 'Supplier', 'Contact', 'Contract',
                 'Document', 'Line', 'Certificate', 'Datacenter', 'Cluster', 'Domain',
                 'Appliance'
              ]
           ],
           'tools' => [
              'title' => __('Tools'),
              'types' => [
                 'Project', 'Reminder', 'RSSFeed', 'KnowbaseItem',
                 'ReservationItem', 'Report', 'MigrationCleaner',
                 'SavedSearch', 'Impact'
              ]
           ],
           'plugins' => [
              'title' => _n('Plugin', 'Plugins', Session::getPluralNumber()),
              'types' => []
           ],
           'admin' => [
              'title' => __('Administration'),
              'types' => [
                 'User', 'Group', 'Entity', 'Rule',
                 'Profile', 'QueuedNotification', 'QueuedChat', 'Glpi\\Event'
              ]
           ],
           'config' => [
              'title' => __('Setup'),
              'types' => [
                 'CommonDropdown', 'CommonDevice', 'Notification',
                 'SLM', 'Config', 'FieldUnicity', 'CronTask', 'Auth',
                 'MailCollector', 'Link', 'Plugin'
              ]
           ],

           // special items
           'preference' => [
              'title' => __('My settings'),
              'default' => '/front/preference.php'
           ],
        ];

        return $menu;
    }

    /**
     * Generate menu array in $_SESSION['glpimenu'] and return the array
     *
     * @since  9.2
     *
     * @param  boolean $force do we need to force regeneration of $_SESSION['glpimenu']
     * @return array          the menu array
     */
    public static function generateMenuSession($force = false)
    {
        global $PLUGIN_HOOKS;
        $menu = [];

        if (
            $force
            || !isset($_SESSION['glpimenu'])
            || !is_array($_SESSION['glpimenu'])
            || (count($_SESSION['glpimenu']) == 0)
        ) {
            $menu = self::getMenuInfos();

            // Permit to plugins to add entry to others sector !
            if (isset($PLUGIN_HOOKS["menu_toadd"]) && count($PLUGIN_HOOKS["menu_toadd"])) {
                foreach ($PLUGIN_HOOKS["menu_toadd"] as $plugin => $items) {
                    if (!Plugin::isPluginActive($plugin)) {
                        continue;
                    }
                    if (count($items)) {
                        foreach ($items as $key => $val) {
                            if (is_array($val)) {
                                foreach ($val as $k => $object) {
                                    $menu[$key]['types'][] = $object;
                                }
                            } else {
                                if (isset($menu[$key])) {
                                    $menu[$key]['types'][] = $val;
                                }
                            }
                        }
                    }
                }
                // Move Setup menu ('config') to the last position in $menu (always last menu),
                // in case some plugin inserted a new top level menu
                $categories = array_keys($menu);
                $menu += array_splice($menu, array_search('config', $categories, true), 1);
            }

            foreach ($menu as $category => $entries) {
                if (isset($entries['types']) && count($entries['types'])) {
                    foreach ($entries['types'] as $type) {
                        if ($data = $type::getMenuContent()) {
                            // Multi menu entries management
                            if (isset($data['is_multi_entries']) && $data['is_multi_entries']) {
                                if (!isset($menu[$category]['content'])) {
                                    $menu[$category]['content'] = [];
                                }
                                $menu[$category]['content'] += $data;
                            } else {
                                $menu[$category]['content'][strtolower($type)] = $data;
                            }
                            if (!isset($menu[$category]['title']) && isset($data['title'])) {
                                $menu[$category]['title'] = $data['title'];
                            }
                            if (!isset($menu[$category]['default']) && isset($data['default'])) {
                                $menu[$category]['default'] = $data['default'];
                            }
                        }
                    }
                }
                // Define default link :
                if (!isset($menu[$category]['default']) && isset($menu[$category]['content']) && count($menu[$category]['content'])) {
                    foreach ($menu[$category]['content'] as $val) {
                        if (isset($val['page'])) {
                            $menu[$category]['default'] = $val['page'];
                            break;
                        }
                    }
                }
            }

            $allassets = [
               'Computer',
               'Monitor',
               'Peripheral',
               'NetworkEquipment',
               'Phone',
               'Printer'
            ];

            foreach ($allassets as $type) {
                if (isset($menu['assets']['content'][strtolower($type)])) {
                    $menu['assets']['content']['allassets']['title']            = __('Global');
                    $menu['assets']['content']['allassets']['shortcut']         = '';
                    $menu['assets']['content']['allassets']['page']             = '/front/allassets.php';
                    $menu['assets']['content']['allassets']['icon']             = 'fas fa-list';
                    $menu['assets']['content']['allassets']['links']['search']  = '/front/allassets.php';
                    break;
                }
            }

            $_SESSION['glpimenu'] = $menu;
            // echo 'menu load';
        } else {
            $menu = $_SESSION['glpimenu'];
        }

        return $menu;
    }


    /**
     * Print a nice HTML head for every page
     *
     * @param string $title   title of the page
     * @param string $url     not used anymore
     * @param string $sector  sector in which the page displayed is
     * @param string $item    item corresponding to the page displayed
     * @param string $option  option corresponding to the page displayed
     **/
    public static function header($title, $url = '', $sector = "none", $item = "none", $option = "")
    {
        global $CFG_GLPI, $HEADER_LOADED, $DB;

        // If in modal : display popHeader
        if (isset($_REQUEST['_in_modal']) && $_REQUEST['_in_modal']) {
            return self::popHeader($title, $url, false, $sector, $item, $option);
        }
        // Print a nice HTML-head for every page
        if ($HEADER_LOADED) {
            return;
        }
        $HEADER_LOADED = true;
        // Force lower case for sector and item
        $sector = strtolower($sector);
        $item   = strtolower($item);

        self::includeHeader($title, $sector, $item, $option);

        $body_class = "layout_" . $_SESSION['glpilayout'];
        if (
            (strpos($_SERVER['REQUEST_URI'], ".form.php") !== false)
            && isset($_GET['id']) && ($_GET['id'] > 0)
        ) {
            if (!CommonGLPI::isLayoutExcludedPage()) {
                $body_class .= " form";
            } else {
                $body_class = "";
            }
        }

        // Body
        $twig_vars['body_class'] = $body_class;
        $twig_vars['root_doc'] = $CFG_GLPI['root_doc'];
        $impersonate_banner = Html::getImpersonateBanner();
        if (!empty($impersonate_banner)) {
            $twig_vars += ["impersonate_banner" => $impersonate_banner];
        }

        //Main menu
        $mainMenu = self::getMainMenu($sector, $item, $option);
        $twig_vars += ["main_menu" => $mainMenu];
        $twig_vars += $mainMenu['args']; //TODO : change to only add breadcrumb var.
        // TODO : separate menu from header
        // Preferences and logout link
        $twig_vars += ["top_menu" => self::getBottomMenu(true)];

        $twig_vars["copyright_message"] = self::getCopyrightMessage();
        $twig_vars["ITSM_VERSION"] = ITSM_VERSION;
        $twig_vars["ITSM_YEAR"] = ITSM_YEAR;
        $twig_vars['is_slave'] = $DB->isSlave() && !$DB->first_connection;
        $twig_vars["can_update"] = false;
        if (Config::canUpdate()) {
            $twig_vars["can_update"] = true;
        }

        $twig_vars['menu_position'] = $DB->request(
            [
                   'SELECT' => 'menu_position',
                   'FROM'   => 'glpi_users',
                   'WHERE'  => ['id' => $_SESSION["glpiID"]]
               ]
        )->next()['menu_position'] ?? 'menu-left';

        if (isset($_SESSION['glpiID'])) {
            $twig_vars['menu_favorite_on'] = $DB->request(
                [
                        'SELECT' => 'menu_favorite_on',
                        'FROM'   => 'glpi_users',
                        'WHERE'  => ['id' => $_SESSION["glpiID"]]
                     ]
            )->next()['menu_favorite_on'] ?? '1';
            $twig_vars['menu_favorite_on'] = filter_var($twig_vars['menu_favorite_on'], FILTER_VALIDATE_BOOLEAN);
        }

        $menu = $twig_vars['main_menu']['args']['menu'];
        $twig_vars['breadcrumb_items'] = [
           [
              'title' => __('Home'),
              'href'  => $CFG_GLPI['root_doc'] . '/front/central.php'
           ],
        ];
        $twig_vars['help_link'] = 'https://www.itsm-ng.org';
        if (is_dir(GLPI_ROOT . '/docs')) {
            $twig_vars['help_link'] = $CFG_GLPI['root_doc'] . '/docs';
        };
        if (isset($sector) && isset($menu[$sector])) {
            $twig_vars['breadcrumb_items'][] = [
               'title' => $menu[$sector]['title'],
               'href'  => $CFG_GLPI['root_doc'] . $menu[$sector]['default']
            ];
        };
        if (isset($sector) && isset($menu[$sector]) && isset($menu[$sector]['content'][$item])) {
            $twig_vars['breadcrumb_items'][] = [
               'title' => $menu[$sector]['content'][$item]['title'],
               'href'  => $CFG_GLPI['root_doc'] . $menu[$sector]['content'][$item]['page'],
            ];
        };
        /// Bookmark load
        Ajax::createSlidePanel(
            'showSavedSearches',
            [
              'title'     => __('Saved searches'),
              'url'       => $CFG_GLPI['root_doc'] . '/ajax/savedsearch.php?action=show',
              'icon'      => '/pics/menu_config.png',
              'icon_url'  => SavedSearch::getSearchURL(),
              'icon_txt'  => __('Manage saved searches')
            ]
        );


        ob_start();
        Html::showProfileSelecter($CFG_GLPI["root_doc"]
           . "/front/"
           . (Session::getCurrentInterface() == 'central' ? 'central' : 'helpdesk.public') . ".php");
        $twig_vars['profileSelect'] = ob_get_clean();

        $user = new User();
        $user->getFromDB(Session::getLoginUserID());

        $twig_vars['username'] = getUserName(Session::getLoginUserID());
        $twig_vars['main_menu']['args']['access'] = Session::getCurrentInterface();
        renderTwigTemplate('headers/header.twig', $twig_vars);
        // call static function callcron() every 5min
        CronTask::callCron();
        self::displayMessageAfterRedirect();
    }


    /**
     * Print footer for every page
     *
     * @param $keepDB boolean, closeDBConnections if false (false by default)
     **/
    public static function footer($keepDB = false)
    {
        global $CFG_GLPI, $FOOTER_LOADED, $TIMER_DEBUG;

        // If in modal : display popFooter
        if (isset($_REQUEST['_in_modal']) && $_REQUEST['_in_modal']) {
            return self::popFooter();
        }
        // Print foot for every page
        if ($FOOTER_LOADED) {
            return;
        }
        $FOOTER_LOADED = true;

        $mode_debug = false;
        $timedebug = null;
        if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) { // mode debug
            $mode_debug = true;
            $timedebug = sprintf(
                _n('%s second', '%s seconds', $TIMER_DEBUG->getTime()),
                $TIMER_DEBUG->getTime()
            );

            if (function_exists("memory_get_usage")) {
                $timedebug = sprintf(__('%1$s - %2$s'), $timedebug, Toolbox::getSize(memory_get_usage()));
            }
        }
        $twig_vars["mode_debug"] = $mode_debug;
        $twig_vars["timedebug"] = $timedebug;
        $currentVersion = preg_replace('/^((\d+\.?)+).*$/', '$1', ITSM_VERSION);
        $foundedNewVersion = array_key_exists('founded_new_version', $CFG_GLPI)
           ? $CFG_GLPI['founded_new_version']
           : '';
        if (!empty($foundedNewVersion) && version_compare($currentVersion, $foundedNewVersion, '<')) {
            $twig_vars["latest_version"] = "<a href='https://www.itsm-ng.org/' target='_blank' title=\""
               . __s('You will find it on the ITSM-NG.org site.') . "\"> "
               . $foundedNewVersion
               . "</a>";
        }
        $twig_vars["copyright_message"] = self::getCopyrightMessage();
        $twig_vars["maintenance_mode"] = $CFG_GLPI['maintenance_mode'];

        require_once GLPI_ROOT . "/src/twig/twig.class.php";
        $twig = Twig::load(GLPI_ROOT . "/templates", false, true);
        try {
            echo $twig->render('footer.twig', $twig_vars);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
        self::displayDebugInfos();
        self::loadJavascript();
        echo Html::script("node_modules/jquery-ui-dist/jquery-ui.min.js");
        echo Html::script("node_modules/@tanstack/table-core/build/umd/index.production.js");
        echo Html::script("node_modules/htm/dist/htm.umd.js");
        echo Html::script("node_modules/vhtml/dist/vhtml.min.js");
        echo Html::script("vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js");
        echo Html::script("node_modules/select2/dist/js/select2.min.js");
        echo Html::script("node_modules/tableexport.jquery.plugin/tableExport.min.js");
        echo Html::script("vendor/wenzhixin/bootstrap-table/dist/bootstrap-table.min.js");
        echo Html::script("vendor/wenzhixin/bootstrap-table/dist/extensions/cookie/bootstrap-table-cookie.min.js");
        echo Html::script("vendor/wenzhixin/bootstrap-table/src/extensions/export/bootstrap-table-export.js");
        echo Html::script("src/ngFunctions.js");
        echo Html::script("node_modules/gridstack/dist/gridstack-all.js");
        echo Html::script("node_modules/ckeditor5/dist/browser/ckeditor5.umd.js");
        echo "</body></html>";
        if (!$keepDB) {
            closeDBConnections();
        }
    }


    /**
     * Display Ajax Footer for debug
     **/
    public static function ajaxFooter()
    {

        if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) { // mode debug
            $rand = mt_rand();
            echo "<div class='center' id='debugajax'>";
            echo "<a class='debug-float' href=\"javascript:showHideDiv('see_ajaxdebug$rand','','','');\">
                AJAX DEBUG</a>";
            if (
                !isset($_GET['full_page_tab'])
                && strstr($_SERVER['REQUEST_URI'], '/ajax/common.tabs.php')
            ) {
                echo "&nbsp;&nbsp;&nbsp;&nbsp;";
                echo "<a href='" . $_SERVER['REQUEST_URI'] . "&full_page_tab=1' class='btn btn-secondary btn-sm my-2'>Display only tab for debug</a>";
            }
            echo "</div>";
            echo "<div id='see_ajaxdebug$rand' name='see_ajaxdebug$rand' style=\"display:none;\">";
            echo "</div></div>";
        }
    }


    /**
     * Print a simple HTML head with links
     *
     * @param string $title  title of the page
     * @param array  $links  links to display
     **/
    public static function simpleHeader($title, $links = [])
    {
        global $CFG_GLPI, $HEADER_LOADED;

        // Print a nice HTML-head for help page
        if ($HEADER_LOADED) {
            return;
        }
        $HEADER_LOADED = true;

        self::includeHeader($title);

        // Body
        echo "<body>";

        // Main Headline
        echo "<div id='header'>";
        echo "<header role='banner' id='header_top'>";

        echo "<div id='c_logo'>";
        echo "<a href='" . $CFG_GLPI["root_doc"] . "/front/central.php' accesskey='1' title=\"" . __s('Home') . "\">" .
           "<span class='invisible'>Logo</span></a></div>";

        // Preferences + logout link
        echo "<div id='c_preference'>";

        echo "<ul>";
        echo "<li id='language_link'><a href='" . $CFG_GLPI["root_doc"] .
           "/front/preference.php?forcetab=User\$1' title=\"" .
           addslashes(Dropdown::getLanguageName($_SESSION['glpilanguage'])) . "\">" .
           Dropdown::getLanguageName($_SESSION['glpilanguage']) . "</a></li>";

        if (Session::getLoginUserID()) {
            $logout_url = $CFG_GLPI['root_doc']
               . '/front/logout.php'
               . (isset($_SESSION['glpiextauth']) && $_SESSION['glpiextauth'] ? '?noAUTO=1' : '');
            echo '<li id="deconnexion">';
            echo '<a href="' . $logout_url . '" title="' . __s('Logout') . '" class="fa fa-sign-out-alt">';
            echo '<span class="sr-only">' . __s('Logout') . '</span>';
            echo '</a>';
            echo '</li>';
        }
        echo "</ul>";

        echo "<div class='sep'></div>";
        echo "</div>";

        echo "</header>"; // end #header_top

        //-- Le menu principal --
        echo "<div id='c_menu'>";
        echo "<ul id='menu'>";

        // Build the navigation-elements
        if (count($links)) {
            $i = 1;

            foreach ($links as $name => $link) {
                echo "<li id='menu$i'>";
                echo "<a href='$link' title=\"" . $name . "\" class='itemP'>{$name}</a>";
                echo "</li>";
                $i++;
            }
        }
        echo "</ul></div>";
        // End navigation bar
        // End headline

        //  Le fil d ariane
        echo "<div id='c_ssmenu2'></div>";
        echo "</div>"; // fin header
        echo "<div id='page'>";

        // call static function callcron() every 5min
        CronTask::callCron();
    }


    /**
     * Print a nice HTML head for help page
     *
     * @param string $title  title of the page
     * @param string $url    not used anymore
     **/
    public static function helpHeader($title, $url = '')
    {
        self::header($title, $url);
    }


    /**
     * Print footer for help page
     **/
    public static function helpFooter()
    {
        self::footer();
    }


    /**
     * Print a nice HTML head with no controls
     *
     * @param string $title  title of the page
     * @param string $url    not used anymore
     **/
    public static function nullHeader($title, $url = '')
    {
        global $HEADER_LOADED;

        if ($HEADER_LOADED) {
            return;
        }
        $HEADER_LOADED = true;
        // Print a nice HTML-head with no controls

        // Detect root_doc in case of error
        Config::detectRootDoc();

        // Send UTF8 Headers
        header("Content-Type: text/html; charset=UTF-8");

        // Send extra expires header if configured
        self::header_nocache();

        if (isCommandLine()) {
            return true;
        }

        self::includeHeader($title);

        // Body with configured stuff
        echo "<body>";
        echo "<main role='main' id='page'>";
        echo "<div id='bloc'>";
        echo "<div id='logo_bloc'></div>";
    }


    /**
     * Print footer for null page
     **/
    public static function nullFooter()
    {
        global $FOOTER_LOADED;

        // Print foot for null page
        if ($FOOTER_LOADED) {
            return;
        }
        $FOOTER_LOADED = true;

        if (!isCommandLine()) {
            echo "</div></main>";

            echo "<div id='footer-login'>" . self::getCopyrightMessage(false) . "</div>";
            self::loadJavascript();
            echo "</body></html>";
        }
        closeDBConnections();
    }


    /**
     * Print a nice HTML head for modal window (nothing to display)
     *
     * @param string  $title    title of the page
     * @param string  $url      not used anymore
     * @param boolean $iframed  indicate if page loaded in iframe - css target
     * @param string  $sector    sector in which the page displayed is (default 'none')
     * @param string  $item      item corresponding to the page displayed (default 'none')
     * @param string  $option    option corresponding to the page displayed (default '')
     **/
    public static function popHeader(
        $title,
        $url = '',
        $iframed = false,
        $sector = "none",
        $item = "none",
        $option = ""
    ) {
        global $HEADER_LOADED;

        // Print a nice HTML-head for every page
        if ($HEADER_LOADED) {
            return;
        }
        $HEADER_LOADED = true;

        self::includeHeader($title, $sector, $item, $option); // Body
        echo "<body class='" . ($iframed ? "iframed" : "") . "'>";
        self::displayMessageAfterRedirect();
    }


    /**
     * Print footer for a modal window
     **/
    public static function popFooter()
    {
        global $FOOTER_LOADED;

        if ($FOOTER_LOADED) {
            return;
        }
        $FOOTER_LOADED = true;

        // Print foot
        self::loadJavascript();
        echo "</body></html>";
    }



    /**
     * Display responsive menu
     * @since 0.90.1
     * @param $menu array of menu items
     *    - key   : plugin system name
     *    - value : array of options
     *       * id      : html id attribute
     *       * default : defaul url
     *       * title   : displayed label
     *       * content : menu sub items, array with theses options :
     *          - page     : url
     *          - title    : displayed label
     *          - shortcut : keyboard shortcut letter
     */
    public static function displayMenuAll($menu = [])
    {
        global $CFG_GLPI, $PLUGIN_HOOKS;

        // Display MENU ALL
        echo "<div id='show_all_menu' class='invisible'>";
        $items_per_columns = 15;
        $i                 = -1;

        foreach ($menu as $part => $data) {
            if (isset($data['content']) && count($data['content'])) {
                echo "<dl>";
                $link = "#";

                if (isset($data['default']) && !empty($data['default'])) {
                    $link = $CFG_GLPI["root_doc"] . $data['default'];
                }

                echo "<dt class='primary-bg primary-fg'>";
                echo "<a class='primary-fg' href='$link' title=\"" . $data['title'] . "\" class='itemP'>" . $data['title'] . "</a>";
                echo "</dt>";
                $i++;

                // list menu item
                foreach ($data['content'] as $key => $val) {
                    if (
                        isset($val['page'])
                        && isset($val['title'])
                    ) {
                        echo "<dd>";

                        if (
                            isset($PLUGIN_HOOKS["helpdesk_menu_entry"][$key])
                            && is_string($PLUGIN_HOOKS["helpdesk_menu_entry"][$key])
                        ) {
                            echo "<a href='" . Plugin::getWebDir($key) . $val['page'] . "'";
                        } else {
                            echo "<a href='" . $CFG_GLPI["root_doc"] . $val['page'] . "'";
                        }
                        if (isset($data['shortcut']) && !empty($data['shortcut'])) {
                            echo " accesskey='" . $val['shortcut'] . "'";
                        }
                        echo ">";

                        echo "<i class='fa-fw " . ($val['icon'] ?? "") . "' aria-hidden='true'></i>&nbsp;";
                        echo $val['title'];
                        echo "</a>";
                        echo "</dd>";
                        $i++;
                    }
                }
                echo "</dl>";
            }
        }

        echo "</div>";

        // init menu in jquery dialog
        echo Html::scriptBlock("
         $(document).ready(
            function() {
               $('#show_all_menu').dialog({
                  height: 'auto',
                  width: 'auto',
                  modal: true,
                  autoOpen: false
               });
            }
         );
      ");

        /// Button to toggle responsive menu
        echo "<a href='#' onClick=\"" . self::jsGetElementbyID('show_all_menu') . ".dialog('open'); return false;\"
            id='menu_all_button'><i class='fa fa-bars' aria-hidden='true'></i>";
        echo "</a>";

        echo "</div>";
    }


    /**
     * Flushes the system write buffers of PHP and whatever backend PHP is using (CGI, a web server, etc).
     * This attempts to push current output all the way to the browser with a few caveats.
     * @see https://www.sitepoint.com/php-streaming-output-buffering-explained/
     **/
    public static function glpi_flush()
    {

        if (
            function_exists("ob_flush")
            && (ob_get_length() !== false)
        ) {
            ob_flush();
        }

        flush();
    }


    /**
     * Set page not to use the cache
     **/
    public static function header_nocache()
    {

        // header("Cache-Control: no-store, no-cache, must-revalidate"); // HTTP/1.1
        // header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Date du passe
    }



    /**
     * show arrow for massives actions : opening
     *
     * @param string  $formname
     * @param boolean $fixed     used tab_cadre_fixe in both tables
     * @param boolean $ontop     display on top of the list
     * @param boolean $onright   display on right of the list
     *
     * @deprecated 0.84
     **/
    public static function openArrowMassives($formname, $fixed = false, $ontop = false, $onright = false)
    {
        global $CFG_GLPI;

        Toolbox::deprecated('openArrowMassives() method is deprecated');

        if ($fixed) {
            echo "<table class='tab_glpi' width='950px' aria-label='Arrow Massive Action'>";
        } else {
            echo "<table class='tab_glpi' width='80%' aria-label='Arrow Massive Action'>";
        }

        echo "<tr>";
        if (!$onright) {
            echo "<td><img src='" . $CFG_GLPI["root_doc"] . "/pics/arrow-left" . ($ontop ? '-top' : '') . ".png'
                    alt=''></td>";
        } else {
            echo "<td class='left' width='80%'></td>";
        }
        echo "<td class='center' style='white-space:nowrap;'>";
        echo "<a onclick= \"if ( markCheckboxes('$formname') ) return false;\"
             href='#'>" . __('Check all') . "</a></td>";
        echo "<td>/</td>";
        echo "<td class='center' style='white-space:nowrap;'>";
        echo "<a onclick= \"if ( unMarkCheckboxes('$formname') ) return false;\"
             href='#'>" . __('Uncheck all') . "</a></td>";

        if ($onright) {
            echo "<td><img src='" . $CFG_GLPI["root_doc"] . "/pics/arrow-right" . ($ontop ? '-top' : '') . ".png'
                    alt=''>";
        } else {
            echo "<td class='left' width='80%'>";
        }
    }


    /**
     * show arrow for massives actions : closing
     *
     * @param $actions array of action : $name -> $label
     * @param $confirm array of confirmation string (optional)
     *
     * @deprecated 0.84
     **/
    public static function closeArrowMassives($actions, $confirm = [])
    {

        Toolbox::deprecated('closeArrowMassives() method is deprecated');

        if (count($actions)) {
            foreach ($actions as $name => $label) {
                if (!empty($name)) {
                    echo "<input type='submit' name='$name' ";
                    if (is_array($confirm) && isset($confirm[$name])) {
                        echo self::addConfirmationOnAction($confirm[$name]);
                    }
                    echo "value=\"" . addslashes($label) . "\" class='submit'>&nbsp;";
                }
            }
        }
        echo "</td></tr>";
        echo "</table>";
    }


    /**
     * Get "check All as" checkbox
     *
     * @since 0.84
     *
     * @param $container_id  string html of the container of checkboxes link to this check all checkbox
     * @param $rand          string rand value to use (default is auto generated)(default '')
     *
     * @return string
     **/
    public static function getCheckAllAsCheckbox($container_id, $rand = '')
    {

        if (empty($rand)) {
            $rand = mt_rand();
        }

        $out  = "<div class='form-group-checkbox'>
                  <input title='" . __s('Check all as') . "' type='checkbox' class='new_checkbox' " .
           "name='_checkall_$rand' id='checkall_$rand' " .
           "onclick= \"if ( checkAsCheckboxes('checkall_$rand', '$container_id'))
                                                   {return true;}\">
                  <label class='label-checkbox' for='checkall_$rand' title='" . __s('Check all as') . "'>
                     <span class='check'></span>
                     <span class='box'></span>
                  </label>
               </div>";

        // permit to shift select checkboxes
        $out .= Html::scriptBlock("\$(function() {\$('#$container_id input[type=\"checkbox\"]').shiftSelectable();});");

        return $out;
    }


    /**
     * Get the jquery criterion for massive checkbox update
     * We can filter checkboxes by a container or by a tag. We can also select checkboxes that have
     * a given tag and that are contained inside a container
     *
     * @since 0.85
     *
     * @param array $options  array of parameters:
     *    - tag_for_massive tag of the checkboxes to update
     *    - container_id    if of the container of the checkboxes
     *
     * @return string  the javascript code for jquery criterion or empty string if it is not a
     *         massive update checkbox
     **/
    public static function getCriterionForMassiveCheckboxes(array $options)
    {

        $params                    = [];
        $params['tag_for_massive'] = '';
        $params['container_id']    = '';

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $params[$key] = $val;
            }
        }

        if (
            !empty($params['tag_for_massive'])
            || !empty($params['container_id'])
        ) {
            // Filtering on the container !
            if (!empty($params['container_id'])) {
                $criterion = '#' . $params['container_id'] . ' ';
            } else {
                $criterion = '';
            }

            // We only want the checkbox input
            $criterion .= 'input[type="checkbox"]';

            // Only the given massive tag !
            if (!empty($params['tag_for_massive'])) {
                $criterion .= '[data-glpicore-cb-massive-tags~="' . $params['tag_for_massive'] . '"]';
            }

            // Only enabled checkbox
            $criterion .= ':enabled';

            return addslashes($criterion);
        }
        return '';
    }


    /**
     * Get a checkbox.
     *
     * @since 0.85
     *
     * @param array $options  array of parameters:
     *    - title         its title
     *    - name          its name
     *    - id            its id
     *    - value         the value to set when checked
     *    - readonly      can we edit it ?
     *    - massive_tags  the tag to set for massive checkbox update
     *    - checked       is it checked or not ?
     *    - zero_on_empty do we send 0 on submit when it is not checked ?
     *    - specific_tags HTML5 tags to add
     *    - criterion     the criterion for massive checkbox
     *
     * @return string  the HTML code for the checkbox
     **/
    public static function getCheckbox(array $options)
    {
        global $CFG_GLPI;

        $params                    = [];
        $params['title']           = '';
        $params['name']            = '';
        $params['rand']            = mt_rand();
        $params['id']              = "check_" . $params['rand'];
        $params['value']           = 1;
        $params['readonly']        = false;
        $params['massive_tags']    = '';
        $params['checked']         = false;
        $params['zero_on_empty']   = true;
        $params['specific_tags']   = [];
        $params['criterion']       = [];

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $params[$key] = $val;
            }
        }

        $out = "<span class='form-group-checkbox'>";
        $out .= "<input type='checkbox' class='new_checkbox' ";

        foreach (['id', 'name', 'title', 'value'] as $field) {
            if (!empty($params[$field])) {
                $out .= " $field='" . $params[$field] . "'";
            }
        }

        $criterion = self::getCriterionForMassiveCheckboxes($params['criterion']);
        if (!empty($criterion)) {
            $out .= " onClick='massiveUpdateCheckbox(\"$criterion\", this)'";
        }

        if ($params['zero_on_empty']) {
            $out                               .= " data-glpicore-cb-zero-on-empty='1'";
            $CFG_GLPI['checkbox-zero-on-empty'] = true;
        }

        if (!empty($params['massive_tags'])) {
            $params['specific_tags']['data-glpicore-cb-massive-tags'] = $params['massive_tags'];
        }

        if (!empty($params['specific_tags'])) {
            foreach ($params['specific_tags'] as $tag => $values) {
                if (is_array($values)) {
                    $values = implode(' ', $values);
                }
                $out .= " $tag='$values'";
            }
        }

        if ($params['readonly']) {
            $out .= " disabled='disabled'";
        }

        if ($params['checked']) {
            $out .= " checked";
        }

        $out .= ">";
        $out .= "<label class='label-checkbox' title=\"" . $params['title'] . "\" for='" . $params['id'] . "'>";
        $out .= " <span class='check'></span>";
        $out .= " <span class='box'";
        if (isset($params['onclick'])) {
            $params['onclick'] = htmlspecialchars($params['onclick'], ENT_QUOTES);
            $out .= " onclick='{$params['onclick']}'";
        }
        $out .= "></span>";
        $out .= "&nbsp;";
        $out .= "</label>";
        $out .= "</span>";

        if (!empty($criterion)) {
            $out .= Html::scriptBlock("\$(function() {\$('$criterion').shiftSelectable();});");
        }

        return $out;
    }


    /**
     * @brief display a checkbox that $_POST 0 or 1 depending on if it is checked or not.
     * @see Html::getCheckbox()
     *
     * @since 0.85
     *
     * @param $options   array
     *
     * @return void
     **/
    public static function showCheckbox(array $options = [])
    {
        echo self::getCheckbox($options);
    }


    /**
     * Get the massive action checkbox
     *
     * @since 0.84
     *
     * @param string  $itemtype  Massive action itemtype
     * @param integer $id        ID of the item
     * @param array   $options
     *
     * @return string
     **/
    public static function getMassiveActionCheckBox($itemtype, $id, array $options = [])
    {

        $options['checked']       = (isset($_SESSION['glpimassiveactionselected'][$itemtype][$id]));
        if (!isset($options['specific_tags']['data-glpicore-ma-tags'])) {
            $options['specific_tags']['data-glpicore-ma-tags'] = 'common';
        }

        // encode quotes and brackets to prevent maformed name attribute
        $id = htmlspecialchars($id, ENT_QUOTES);
        $id = str_replace(['[', ']'], ['&amp;#91;', '&amp;#93;'], $id);
        $options['name']          = "item[$itemtype][" . $id . "]";

        $options['zero_on_empty'] = false;

        return self::getCheckbox($options);
    }


    /**
     * Show the massive action checkbox
     *
     * @since 0.84
     *
     * @param string  $itemtype  Massive action itemtype
     * @param integer $id        ID of the item
     * @param array   $options
     *
     * @return void
     **/
    public static function showMassiveActionCheckBox($itemtype, $id, array $options = [])
    {
        echo Html::getMassiveActionCheckBox($itemtype, $id, $options);
    }


    /**
     * Display open form for massive action
     *
     * @since 0.84
     *
     * @param string $name  given name/id to the form
     *
     * @return void
     **/
    public static function openMassiveActionsForm($name = '')
    {
        echo Html::getOpenMassiveActionsForm($name);
    }


    /**
     * Get open form for massive action string
     *
     * @since 0.84
     *
     * @param string $name  given name/id to the form
     *
     * @return string
     **/
    public static function getOpenMassiveActionsForm($name = '')
    {
        global $CFG_GLPI;

        if (empty($name)) {
            $name = 'massaction_' . mt_rand();
        }
        return  "<form aria-label='$name' name='$name' id='$name' method='post'
               action='" . $CFG_GLPI["root_doc"] . "/front/massiveaction.php'>";
    }


    /**
     * Display massive actions
     *
     * @since 0.84 (before Search::displayMassiveActions)
     * @since 0.85 only 1 parameter (in 0.84 $itemtype required)
     *
     * @todo replace 'hidden' by data-glpicore-ma-tags ?
     *
     * @param $options   array    of parameters
     * must contains :
     *    - container       : DOM ID of the container of the item checkboxes (since version 0.85)
     * may contains :
     *    - num_displayed   : integer number of displayed items. Permit to check suhosin limit.
     *                        (default -1 not to check)
     *    - ontop           : boolean true if displayed on top (default true)
     *    - fixed           : boolean true if used with fixed table display (default true)
     *    - forcecreate     : boolean force creation of modal window (default = false).
     *            Modal is automatically created when displayed the ontop item.
     *            If only a bottom one is displayed use it
     *    - check_itemtype   : string alternate itemtype to check right if different from main itemtype
     *                         (default empty)
     *    - check_items_id   : integer ID of the alternate item used to check right / optional
     *                         (default empty)
     *    - is_deleted       : boolean is massive actions for deleted items ?
     *    - extraparams      : string extra URL parameters to pass to massive actions (default empty)
     *                         if ([extraparams]['hidden'] is set : add hidden fields to post)
     *    - specific_actions : array of specific actions (do not use standard one)
     *    - add_actions      : array of actions to add (do not use standard one)
     *    - confirm          : string of confirm message before massive action
     *    - item             : CommonDBTM object that has to be passed to the actions
     *    - tag_to_send      : the tag of the elements to send to the ajax window (default: common)
     *    - display          : display or return the generated html (default true)
     *
     * @return bool|string     the html if display parameter is false, or true
     **/
    public static function showMassiveActions($options = [])
    {
        global $CFG_GLPI;

        /// TODO : permit to pass several itemtypes to show possible actions of all types : need to clean visibility management after

        $p['ontop']             = true;
        $p['num_displayed']     = -1;
        $p['fixed']             = true;
        $p['forcecreate']       = false;
        $p['check_itemtype']    = '';
        $p['check_items_id']    = '';
        $p['is_deleted']        = true;
        $p['extraparams']       = [];
        $p['width']             = 800;
        $p['height']            = 400;
        $p['specific_actions']  = [];
        $p['add_actions']       = [];
        $p['confirm']           = '';
        $p['rand']              = '';
        $p['container']         = '';
        $p['display_arrow']     = true;
        $p['title']             = _n('Action', 'Actions', Session::getPluralNumber());
        $p['item']              = false;
        $p['tag_to_send']       = 'common';
        $p['display']           = true;
        $p['deprecated']        = false;

        foreach ($options as $key => $val) {
            if (isset($p[$key])) {
                $p[$key] = $val;
            }
        }

        $url = $CFG_GLPI['root_doc'] . "/ajax/massiveaction.php";
        if ($p['container']) {
            $p['extraparams']['container'] = $p['container'];
        }
        if ($p['is_deleted']) {
            $p['extraparams']['is_deleted'] = 1;
        }
        if (!empty($p['check_itemtype'])) {
            $p['extraparams']['check_itemtype'] = $p['check_itemtype'];
        }
        if (!empty($p['check_items_id'])) {
            $p['extraparams']['check_items_id'] = $p['check_items_id'];
        }
        if (is_array($p['specific_actions']) && count($p['specific_actions'])) {
            $p['extraparams']['specific_actions'] = $p['specific_actions'];
        }
        if (is_array($p['add_actions']) && count($p['add_actions'])) {
            $p['extraparams']['add_actions'] = $p['add_actions'];
        }
        if ($p['item'] instanceof CommonDBTM) {
            $p['extraparams']['item_itemtype'] = $p['item']->getType();
            $p['extraparams']['item_items_id'] = $p['item']->getID();
        }

        // Manage modal window
        if (isset($_REQUEST['_is_modal']) && $_REQUEST['_is_modal']) {
            $p['extraparams']['hidden']['_is_modal'] = 1;
        }

        if ($p['fixed']) {
            $width = '950px';
        } else {
            $width = '95%';
        }

        $identifier = $p['container'];
        $max        = Toolbox::get_max_input_vars();
        $out = '';

        if (
            ($p['num_displayed'] >= 0)
            && ($max > 0)
            && ($max < ($p['num_displayed'] + 10))
        ) {
            if (
                !$p['ontop']
                || (isset($p['forcecreate']) && $p['forcecreate'])
            ) {
                $out .= "<table class='tab_cadre' width='$width' aria-label='Massive Action Warning'><tr class='tab_bg_1'>" .
                   "<td><span class='b'>";
                $out .= __('Selection too large, massive action disabled.') . "</span>";
                if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
                    $out .= "<br>" . __('To increase the limit: change max_input_vars or suhosin.post.max_vars in php configuration.');
                }
                $out .= "</td></tr></table>";
            }
        } else {
            // Create Modal window on top
            if (
                $p['ontop']
                || (isset($p['forcecreate']) && $p['forcecreate'])
            ) {
                $out .= "<div id='massiveactioncontent$identifier'></div>";
                if (!empty($p['tag_to_send'])) {
                    $container = $p['container'];
                    $js_modal_fields = $p['deprecated'] ? <<<JS
                  var items = $('[data-glpicore-ma-tags~="{$p['tag_to_send']}"]')
                    .each(function( index ) {
                        fields[$(this).attr('name')] = $(this).attr('value');
                        if (($(this).attr('type') == 'checkbox') && (!$(this).is(':checked'))) {
                           fields[$(this).attr('name')] = 0;
                        }
                    });
               JS : <<<JS
                  let rows = window['$identifier'+'_getMassiveActionSelection']();
                  for (let i = 0; i < rows.length; i++) {
                     fields[rows[i].value] = 1;
                  }
                  $('table[id="$container"] [data-glpicore-ma-tags~={$p['tag_to_send']}]').each(function( index ) {
                     fields[$(this).attr('name')] = $(this).attr('value');
                     if (($(this).attr('type') == 'checkbox') && (!$(this).is(':checked'))) {
                        fields[$(this).attr('name')] = 0;
                     }
                  });
               JS;
                } else {
                    $js_modal_fields = "";
                }
                $out .= Ajax::createModalWindow(
                    'massiveaction_window' . $identifier,
                    $url,
                    [
                      'title'           => $p['title'],
                      'container'       => 'massiveactioncontent' . $identifier,
                      'extraparams'     => $p['extraparams'],
                      'width'           => $p['width'],
                      'height'          => $p['height'],
                      'js_modal_fields' => $js_modal_fields,
                      'display'         => false
                    ]
                );
            }
            if ($p['display_arrow']) {
                $out .= "<table class='tab_glpi' width='$width' aria-label='Display Arrow'><tr>";
                $out .= "<td width='30px'><img src='" . $CFG_GLPI["root_doc"] . "/pics/arrow-left" .
                   ($p['ontop'] ? '-top' : '') . ".png' alt=''></td>";
                $out .= "<td width='100%' class='left'>";
                $out .= "<a class='btn btn-secondary' ";
                if (is_array($p['confirm'] || strlen($p['confirm']))) {
                    $out .= self::addConfirmationOnAction($p['confirm'], "massiveaction_window$identifier.dialog(\"open\");");
                } else {
                    $out .= "onclick='massiveaction_window$identifier.dialog(\"open\");'";
                }
                $out .= " href='#modal_massaction_content$identifier' title=\"" . htmlentities($p['title'], ENT_QUOTES, 'UTF-8') . "\">";
                $out .= $p['title'] . "</a>";
                $out .= "</td>";

                $out .= "</tr></table>";
            }
            if (
                !$p['ontop']
                || (isset($p['forcecreate']) && $p['forcecreate'])
            ) {
                // Clean selection
                $_SESSION['glpimassiveactionselected'] = [];
            }
        }

        if ($p['display']) {
            echo $out;
            return true;
        } else {
            return $out;
        }
    }


    /**
     * Display Date form with calendar
     *
     * @since 0.84
     *
     * @param string $name     name of the element
     * @param array  $options  array of possible options:
     *      - value        : default value to display (default '')
     *      - maybeempty   : may be empty ? (true by default)
     *      - canedit      :  could not modify element (true by default)
     *      - min          :  minimum allowed date (default '')
     *      - max          : maximum allowed date (default '')
     *      - showyear     : should we set/diplay the year? (true by default)
     *      - display      : boolean display of return string (default true)
     *      - calendar_btn : boolean display calendar icon (default true)
     *      - clear_btn    : boolean display clear icon (default true)
     *      - range        : boolean set the datepicket in range mode
     *      - rand         : specific rand value (default generated one)
     *      - yearrange    : set a year range to show in drop-down (default '')
     *      - required     : required field (will add required attribute)
     *      - placeholder  : text to display when input is empty
     *      - on_change    : function to execute when date selection changed
     *
     * @return integer|string
     *    integer if option display=true (random part of elements id)
     *    string if option display=false (HTML code)
     **/
    public static function showDateField($name, $options = [])
    {
        global $CFG_GLPI;

        $p = [
           'value'        => '',
           'defaultDate'  => '',
           'maybeempty'   => true,
           'canedit'      => true,
           'min'          => '',
           'max'          => '',
           'showyear'     => false,
           'display'      => true,
           'range'        => false,
           'rand'         => mt_rand(),
           'calendar_btn' => true,
           'clear_btn'    => true,
           'yearrange'    => '',
           'multiple'     => false,
           'size'         => 10,
           'required'     => false,
           'placeholder'  => '',
           'on_change'    => '',
        ];

        foreach ($options as $key => $val) {
            if (isset($p[$key])) {
                $p[$key] = $val;
            }
        }

        $required = $p['required'] == true
           ? " required='required'"
           : "";
        $disabled = !$p['canedit']
           ? " disabled='disabled'"
           : "";

        $calendar_btn = $p['calendar_btn']
           ? "<a class='input-button' data-toggle>
               <i class='far fa-calendar-alt fa-lg pointer' title='calendar'></i>
            </a>"
           : "";
        $clear_btn = $p['clear_btn'] && $p['maybeempty'] && $p['canedit']
           ? "<a data-clear  title='" . __s('Clear') . "'>
               <i class='fa fa-times-circle pointer' title='clearBTN'></i>
            </a>"
           : "";

        $mode = $p['range']
           ? "mode: 'range',"
           : "";

        $name = Html::cleanInputText($name);
        $output = <<<HTML
      <div class="no-wrap flatpickr" id="showdate{$p['rand']}">
         <input type="text" name="{$name}" size="{$p['size']}"
                {$required} {$disabled} data-input placeholder="{$p['placeholder']}">
         $calendar_btn
         $clear_btn
      </div>
HTML;

        $date_format = Toolbox::getDateFormat('js');

        $min_attr = !empty($p['min'])
           ? "minDate: '{$p['min']}',"
           : "";
        $max_attr = !empty($p['max'])
           ? "maxDate: '{$p['max']}',"
           : "";
        $multiple_attr = $p['multiple']
           ? "mode: 'multiple',"
           : "";

        $value = json_encode($p['value']);

        $locale = Locale::parseLocale($_SESSION['glpilanguage']);
        $js = <<<JS
      $(function() {
         $("#showdate{$p['rand']}").flatpickr({
            defaultDate: {$value},
            altInput: true, // Show the user a readable date (as per altFormat), but return something totally different to the server.
            altFormat: '{$date_format}',
            dateFormat: 'Y-m-d',
            wrap: true, // permits to have controls in addition to input (like clear or open date buttons
            weekNumbers: true,
            locale: getFlatPickerLocale("{$locale['language']}", "{$locale['region']}"),
            {$min_attr}
            {$max_attr}
            {$multiple_attr}
            {$mode}
            onChange: function(selectedDates, dateStr, instance) {
               {$p['on_change']}
            },
            allowInput: true,
            onClose(dates, currentdatestring, picker){
               picker.setDate(picker.altInput.value, true, picker.config.altFormat)
            }
         });
      });
JS;

        $output .= Html::scriptBlock($js);

        if ($p['display']) {
            echo $output;
            return $p['rand'];
        }
        return $output;
    }


    /**
     * Display Color field
     *
     * @since 0.85
     *
     * @param string $name     name of the element
     * @param array  $options  array  of possible options:
     *   - value      : default value to display (default '')
     *   - display    : boolean display or get string (default true)
     *   - rand       : specific random value (default generated one)
     *
     * @return integer|string
     *    integer if option display=true (random part of elements id)
     *    string if option display=false (HTML code)
     **/
    public static function showColorField($name, $options = [])
    {
        $p['value']      = '';
        $p['rand']       = mt_rand();
        $p['display']    = true;
        foreach ($options as $key => $val) {
            if (isset($p[$key])) {
                $p[$key] = $val;
            }
        }
        $field_id = Html::cleanId("color_" . $name . $p['rand']);
        $output   = "<input type='color' id='$field_id' name='$name' value='" . $p['value'] . "'>";

        if ($p['display']) {
            echo $output;
            return $p['rand'];
        }
        return $output;
    }


    /**
     * Display DateTime form with calendar
     *
     * @since 0.84
     *
     * @param string $name     name of the element
     * @param array  $options  array  of possible options:
     *   - value      : default value to display (default '')
     *   - timestep   : step for time in minute (-1 use default config) (default -1)
     *   - maybeempty : may be empty ? (true by default)
     *   - canedit    : could not modify element (true by default)
     *   - mindate    : minimum allowed date (default '')
     *   - maxdate    : maximum allowed date (default '')
     *   - showyear   : should we set/diplay the year? (true by default)
     *   - display    : boolean display or get string (default true)
     *   - rand       : specific random value (default generated one)
     *   - required   : required field (will add required attribute)
     *   - on_change    : function to execute when date selection changed
     *
     * @return integer|string
     *    integer if option display=true (random part of elements id)
     *    string if option display=false (HTML code)
     **/
    public static function showDateTimeField($name, $options = [])
    {
        global $CFG_GLPI;

        $p = [
           'value'      => '',
           'maybeempty' => true,
           'canedit'    => true,
           'mindate'    => '',
           'maxdate'    => '',
           'mintime'    => '',
           'maxtime'    => '',
           'timestep'   => -1,
           'showyear'   => true,
           'display'    => true,
           'rand'       => mt_rand(),
           'required'   => false,
           'on_change'  => '',
        ];

        foreach ($options as $key => $val) {
            if (isset($p[$key])) {
                $p[$key] = $val;
            }
        }

        if ($p['timestep'] < 0) {
            $p['timestep'] = $CFG_GLPI['time_step'];
        }

        $date_value = '';
        $hour_value = '';
        if (!empty($p['value'])) {
            list($date_value, $hour_value) = explode(' ', $p['value']);
        }

        if (!empty($p['mintime'])) {
            // Check time in interval
            if (!empty($hour_value) && ($hour_value < $p['mintime'])) {
                $hour_value = $p['mintime'];
            }
        }

        if (!empty($p['maxtime'])) {
            // Check time in interval
            if (!empty($hour_value) && ($hour_value > $p['maxtime'])) {
                $hour_value = $p['maxtime'];
            }
        }

        // reconstruct value to be valid
        if (!empty($date_value)) {
            $p['value'] = $date_value . ' ' . $hour_value;
        }

        $required = $p['required'] == true
           ? " required='required'"
           : "";
        $disabled = !$p['canedit']
           ? " disabled='disabled'"
           : "";
        $clear    = $p['maybeempty'] && $p['canedit']
           ? "<a data-clear  title='" . __s('Clear') . "'>
               <i class='fa fa-times-circle pointer'></i>
            </a>"
           : "";

        $output = <<<HTML
         <div class="no-wrap flatpickr" id="showdate{$p['rand']}">
            <input type="text" name="{$name}" value="{$p['value']}"
                   {$required} {$disabled} data-input>
            <a class="input-button" data-toggle>
               <i class="far fa-calendar-alt fa-lg pointer" title="Select Date"></i>
            </a>
            $clear
         </div>
HTML;

        $date_format = Toolbox::getDateFormat('js') . " H:i:S";

        $min_attr = !empty($p['min'])
           ? "minDate: '{$p['min']}',"
           : "";
        $max_attr = !empty($p['max'])
           ? "maxDate: '{$p['max']}',"
           : "";

        $locale = Locale::parseLocale($_SESSION['glpilanguage']);
        $js = <<<JS
      $(function() {
         $("#showdate{$p['rand']}").flatpickr({
            altInput: true, // Show the user a readable date (as per altFormat), but return something totally different to the server.
            altFormat: "{$date_format}",
            dateFormat: 'Y-m-d H:i:S',
            wrap: true, // permits to have controls in addition to input (like clear or open date buttons)
            enableTime: true,
            enableSeconds: true,
            weekNumbers: true,
            locale: getFlatPickerLocale("{$locale['language']}", "{$locale['region']}"),
            minuteIncrement: "{$p['timestep']}",
            {$min_attr}
            {$max_attr}
            onChange: function(selectedDates, dateStr, instance) {
               {$p['on_change']}
            },
            allowInput: true,
            onClose(dates, currentdatestring, picker){
               picker.setDate(picker.altInput.value, true, picker.config.altFormat)
            }
         });
      });
JS;
        $output .= Html::scriptBlock($js);

        if ($p['display']) {
            echo $output;
            return $p['rand'];
        }
        return $output;
    }

    /**
     * Display TimeField form
     *
     * @param string $name
     * @param array  $options
     *   - value      : default value to display (default '')
     *   - timestep   : step for time in minute (-1 use default config) (default -1)
     *   - maybeempty : may be empty ? (true by default)
     *   - canedit    : could not modify element (true by default)
     *   - mintime    : minimum allowed time (default '')
     *   - maxtime    : maximum allowed time (default '')
     *   - display    : boolean display or get string (default true)
     *   - rand       : specific random value (default generated one)
     *   - required   : required field (will add required attribute)
     *   - on_change  : function to execute when date selection changed
     * @return void
     */
    public static function showTimeField($name, $options = [])
    {
        global $CFG_GLPI;

        $p = [
           'value'      => '',
           'maybeempty' => true,
           'canedit'    => true,
           'mintime'    => '',
           'maxtime'    => '',
           'timestep'   => -1,
           'display'    => true,
           'rand'       => mt_rand(),
           'required'   => false,
           'on_change'  => '',
        ];

        foreach ($options as $key => $val) {
            if (isset($p[$key])) {
                $p[$key] = $val;
            }
        }

        if ($p['timestep'] < 0) {
            $p['timestep'] = $CFG_GLPI['time_step'];
        }

        $hour_value = '';
        if (!empty($p['value'])) {
            $hour_value = $p['value'];
        }

        if (!empty($p['mintime'])) {
            // Check time in interval
            if (!empty($hour_value) && ($hour_value < $p['mintime'])) {
                $hour_value = $p['mintime'];
            }
        }
        if (!empty($p['maxtime'])) {
            // Check time in interval
            if (!empty($hour_value) && ($hour_value > $p['maxtime'])) {
                $hour_value = $p['maxtime'];
            }
        }
        // reconstruct value to be valid
        if (!empty($hour_value)) {
            $p['value'] = $hour_value;
        }

        $required = $p['required'] == true
           ? " required='required'"
           : "";
        $disabled = !$p['canedit']
           ? " disabled='disabled'"
           : "";
        $clear    = $p['maybeempty'] && $p['canedit']
           ? "<a data-clear  title='" . __s('Clear') . "'>
               <i class='fa fa-times-circle pointer' aria-hidden=''true'></i>
            </a>"
           : "";

        $name = Html::cleanInputText($name);
        $value = Html::cleanInputText($p['value']);
        $output = <<<HTML
         <div class="no-wrap flatpickr" id="showtime{$p['rand']}">
            <input type="text" name="{$name}" value="{$value}"
                   {$required} {$disabled} data-input>
            <a class="input-button" data-toggle>
               <i class="far fa-clock fa-lg pointer" title="Select Time"></i>
            </a>
            $clear
         </div>
HTML;
        $locale = Locale::parseLocale($_SESSION['glpilanguage']);
        $js = <<<JS
      $(function() {
         $("#showtime{$p['rand']}").flatpickr({
            dateFormat: 'H:i:S',
            wrap: true, // permits to have controls in addition to input (like clear or open date buttons)
            enableTime: true,
            noCalendar: true, // only time picker
            enableSeconds: true,
            locale: getFlatPickerLocale("{$locale['language']}", "{$locale['region']}"),
            minuteIncrement: "{$p['timestep']}",
            onChange: function(selectedDates, dateStr, instance) {
               {$p['on_change']}
            }
         });
      });
JS;
        $output .= Html::scriptBlock($js);

        if ($p['display']) {
            echo $output;
            return $p['rand'];
        }
        return $output;
    }

    /**
     * Show generic date search
     *
     * @param string $element  name of the html element
     * @param string $value    default value
     * @param $options   array of possible options:
     *      - with_time display with time selection ? (default false)
     *      - with_future display with future date selection ? (default false)
     *      - with_days display specific days selection TODAY, BEGINMONTH, LASTMONDAY... ? (default true)
     *
     * @return integer|string
     *    integer if option display=true (random part of elements id)
     *    string if option display=false (HTML code)
     **/
    public static function showGenericDateTimeSearch($element, $value = '', $options = [])
    {
        global $CFG_GLPI;

        $p['with_time']          = false;
        $p['with_future']        = false;
        $p['with_days']          = true;
        $p['with_specific_date'] = true;
        $p['display']            = true;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }
        $rand   = mt_rand();
        $output = '';
        // Validate value
        if (
            ($value != 'NOW')
            && ($value != 'TODAY')
            && !preg_match("/\d{4}-\d{2}-\d{2}.*/", $value)
            && !strstr($value, 'HOUR')
            && !strstr($value, 'MINUTE')
            && !strstr($value, 'DAY')
            && !strstr($value, 'WEEK')
            && !strstr($value, 'MONTH')
            && !strstr($value, 'YEAR')
        ) {
            $value = "";
        }

        if (empty($value)) {
            $value = 'NOW';
        }
        $specific_value = date("Y-m-d H:i:s");

        if (preg_match("/\d{4}-\d{2}-\d{2}.*/", $value)) {
            $specific_value = $value;
            $value          = 0;
        }
        $output    .= "<table width='100%' aria-label='Date Time Search'><tr><td width='50%'>";

        $dates      = Html::getGenericDateTimeSearchItems($p);

        $output    .= Dropdown::showFromArray(
            "_select_$element",
            $dates,
            [
              'value'   => $value,
              'display' => false,
              'rand'    => $rand
            ]
        );
        $field_id   = Html::cleanId("dropdown__select_$element$rand");

        $output    .= "</td><td width='50%'>";
        $contentid  = Html::cleanId("displaygenericdate$element$rand");
        $output    .= "<span id='$contentid'></span>";

        $params     = [
           'value'         => '__VALUE__',
           'name'          => $element,
           'withtime'      => $p['with_time'],
           'specificvalue' => $specific_value
        ];

        $output    .= Ajax::updateItemOnSelectEvent(
            $field_id,
            $contentid,
            $CFG_GLPI["root_doc"] . "/ajax/genericdate.php",
            $params,
            false
        );
        $params['value']  = $value;
        $output    .= Ajax::updateItem(
            $contentid,
            $CFG_GLPI["root_doc"] . "/ajax/genericdate.php",
            $params,
            '',
            false
        );
        $output    .= "</td></tr></table>";

        if ($p['display']) {
            echo $output;
            return $rand;
        }
        return $output;
    }


    /**
     * Get items to display for showGenericDateTimeSearch
     *
     * @since 0.83
     *
     * @param array $options  array of possible options:
     *      - with_time display with time selection ? (default false)
     *      - with_future display with future date selection ? (default false)
     *      - with_days display specific days selection TODAY, BEGINMONTH, LASTMONDAY... ? (default true)
     *
     * @return array of posible values
     * @see self::showGenericDateTimeSearch()
     **/
    public static function getGenericDateTimeSearchItems($options)
    {

        $params['with_time']          = false;
        $params['with_future']        = false;
        $params['with_days']          = true;
        $params['with_specific_date'] = true;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $params[$key] = $val;
            }
        }

        $dates = [];
        if ($params['with_time']) {
            $dates['NOW'] = __('Now');
            if ($params['with_days']) {
                $dates['TODAY'] = __('Today');
            }
        } else {
            $dates['NOW'] = __('Today');
        }

        if ($params['with_specific_date']) {
            $dates[0] = __('Specify a date');
        }

        if ($params['with_time']) {
            for ($i = 1; $i <= 24; $i++) {
                $dates['-' . $i . 'HOUR'] = sprintf(_n('- %d hour', '- %d hours', $i), $i);
            }

            for ($i = 1; $i <= 15; $i++) {
                $dates['-' . $i . 'MINUTE'] = sprintf(_n('- %d minute', '- %d minutes', $i), $i);
            }
        }

        for ($i = 1; $i <= 7; $i++) {
            $dates['-' . $i . 'DAY'] = sprintf(_n('- %d day', '- %d days', $i), $i);
        }

        if ($params['with_days']) {
            $dates['LASTSUNDAY']    = __('last Sunday');
            $dates['LASTMONDAY']    = __('last Monday');
            $dates['LASTTUESDAY']   = __('last Tuesday');
            $dates['LASTWEDNESDAY'] = __('last Wednesday');
            $dates['LASTTHURSDAY']  = __('last Thursday');
            $dates['LASTFRIDAY']    = __('last Friday');
            $dates['LASTSATURDAY']  = __('last Saturday');
        }

        for ($i = 1; $i <= 10; $i++) {
            $dates['-' . $i . 'WEEK'] = sprintf(_n('- %d week', '- %d weeks', $i), $i);
        }

        if ($params['with_days']) {
            $dates['BEGINMONTH']  = __('Beginning of the month');
        }

        for ($i = 1; $i <= 12; $i++) {
            $dates['-' . $i . 'MONTH'] = sprintf(_n('- %d month', '- %d months', $i), $i);
        }

        if ($params['with_days']) {
            $dates['BEGINYEAR']  = __('Beginning of the year');
        }

        for ($i = 1; $i <= 10; $i++) {
            $dates['-' . $i . 'YEAR'] = sprintf(_n('- %d year', '- %d years', $i), $i);
        }

        if ($params['with_future']) {
            if ($params['with_time']) {
                for ($i = 1; $i <= 24; $i++) {
                    $dates[$i . 'HOUR'] = sprintf(_n('+ %d hour', '+ %d hours', $i), $i);
                }
            }

            for ($i = 1; $i <= 7; $i++) {
                $dates[$i . 'DAY'] = sprintf(_n('+ %d day', '+ %d days', $i), $i);
            }

            for ($i = 1; $i <= 10; $i++) {
                $dates[$i . 'WEEK'] = sprintf(_n('+ %d week', '+ %d weeks', $i), $i);
            }

            for ($i = 1; $i <= 12; $i++) {
                $dates[$i . 'MONTH'] = sprintf(_n('+ %d month', '+ %d months', $i), $i);
            }

            for ($i = 1; $i <= 10; $i++) {
                $dates[$i . 'YEAR'] = sprintf(_n('+ %d year', '+ %d years', $i), $i);
            }
        }
        return $dates;
    }


    /**
     * Compute date / datetime value resulting of showGenericDateTimeSearch
     *
     * @since 0.83
     *
     * @param string         $val           date / datetime value passed
     * @param boolean        $force_day     force computation in days
     * @param integer|string $specifictime  set specific timestamp
     *
     * @return string  computed date / datetime value
     * @see self::showGenericDateTimeSearch()
     **/
    public static function computeGenericDateTimeSearch($val, $force_day = false, $specifictime = '')
    {

        if (empty($specifictime)) {
            $specifictime = strtotime($_SESSION["glpi_currenttime"]);
        }

        $format_use = "Y-m-d H:i:s";
        if ($force_day) {
            $format_use = "Y-m-d";
        }

        // Parsing relative date
        switch ($val) {
            case 'NOW':
                return date($format_use, $specifictime);

            case 'TODAY':
                return date("Y-m-d", $specifictime);
        }

        // Search on begin of month / year
        if (strstr($val ?? '', 'BEGIN')) {
            $hour   = 0;
            $minute = 0;
            $second = 0;
            $month  = date("n", $specifictime);
            $day    = 1;
            $year   = date("Y", $specifictime);

            switch ($val) {
                case "BEGINYEAR":
                    $month = 1;
                    break;

                case "BEGINMONTH":
                    break;
            }

            return date($format_use, mktime($hour, $minute, $second, $month, $day, $year));
        }

        // Search on Last monday, sunday...
        if (strstr($val ?? '', 'LAST')) {
            $lastday = str_replace("LAST", "LAST ", $val);
            $hour   = 0;
            $minute = 0;
            $second = 0;
            $month  = date("n", strtotime($lastday));
            $day    = date("j", strtotime($lastday));
            $year   = date("Y", strtotime($lastday));

            return date($format_use, mktime($hour, $minute, $second, $month, $day, $year));
        }

        // Search on +- x days, hours...
        if (preg_match("/^(-?)(\d+)(\w+)$/", $val ?? '', $matches)) {
            if (in_array($matches[3], ['YEAR', 'MONTH', 'WEEK', 'DAY', 'HOUR', 'MINUTE'])) {
                $nb = intval($matches[2]);
                if ($matches[1] == '-') {
                    $nb = -$nb;
                }
                // Use it to have a clean delay computation (MONTH / YEAR have not always the same duration)
                $hour   = date("H", $specifictime);
                $minute = date("i", $specifictime);
                $second = 0;
                $month  = date("n", $specifictime);
                $day    = date("j", $specifictime);
                $year   = date("Y", $specifictime);

                switch ($matches[3]) {
                    case "YEAR":
                        $year += $nb;
                        break;

                    case "MONTH":
                        $month += $nb;
                        break;

                    case "WEEK":
                        $day += 7 * $nb;
                        break;

                    case "DAY":
                        $day += $nb;
                        break;

                    case "MINUTE":
                        $format_use = "Y-m-d H:i:s";
                        $minute    += $nb;
                        break;

                    case "HOUR":
                        $format_use = "Y-m-d H:i:s";
                        $hour      += $nb;
                        break;
                }
                return date($format_use, mktime($hour, $minute, $second, $month, $day, $year));
            }
        }
        return $val;
    }

    /**
     * Display or return a list of dates in a vertical way
     *
     * @since 9.2
     *
     * @param array $options  array of possible options:
     *      - title, do we need to append an H2 title tag
     *      - dates, an array containing a collection of theses keys:
     *         * timestamp
     *         * class, supported: passed, checked, now
     *         * label
     *      - display, boolean to precise if we need to display (true) or return (false) the html
     *      - add_now, boolean to precise if we need to add to dates array, an entry for now time
     *        (with now class)
     *
     * @return array of posible values
     *
     * @see self::showGenericDateTimeSearch()
     **/
    public static function showDatesTimelineGraph($options = [])
    {
        $default_options = [
           'title'   => '',
           'dates'   => [],
           'display' => true,
           'add_now' => true
        ];
        $options = array_merge($default_options, $options);

        //append now date if needed
        if ($options['add_now']) {
            $now = time();
            $options['dates'][$now . "_now"] = [
               'timestamp' => $now,
               'label' => __('Now'),
               'class' => 'now'
            ];
        }

        ksort($options['dates']);

        $out = "";
        $out .= "<div class='dates_timelines'>";

        // add title
        if (strlen($options['title'])) {
            $out .= "<h2 class='header'>" . $options['title'] . "</h2>";
        }

        // construct timeline
        $out .= "<ul>";
        foreach ($options['dates'] as $key => $data) {
            if ($data['timestamp'] != 0) {
                $out .= "<li class='" . $data['class'] . "'>&nbsp;";
                $out .= "<time>" . Html::convDateTime(date("Y-m-d H:i:s", $data['timestamp'])) . "</time>";
                $out .= "<span class='dot'></span>";
                $out .= "<label>" . $data['label'] . "</label>";
                $out .= "</li>";
            }
        }
        $out .= "</ul>";
        $out .= "</div>";

        if ($options['display']) {
            echo $out;
        } else {
            return $out;
        }
    }


    /**
     * Print the form used to select profile if several are available
     *
     * @param string $target  target of the form
     *
     * @return void
     **/
    public static function showProfileSelecter($target)
    {
        global $CFG_GLPI;

        if (count($_SESSION["glpiprofiles"] ?? []) > 1) {
            echo '<form aria-label="Profile Selecter" name="form" method="post" action="' . $target . '">';
            $values = [];
            foreach ($_SESSION["glpiprofiles"] as $key => $val) {
                $values[$key] = $val['name'];
            }

            Dropdown::showFromArray(
                'newprofile',
                $values,
                [
                  'value'     => $_SESSION["glpiactiveprofile"]["id"],
                  'width'     => '150px',
                  'on_change' => "$(this).closest('form').trigger('submit')",
                  'class' => 'form-select form-select-sm ms-3'
                ]
            );
            Html::closeForm();
            echo '</li>';
        }

        if (Session::isMultiEntitiesMode()) {
            Ajax::createModalWindow(
                'entity_window',
                $CFG_GLPI['root_doc'] . "/ajax/entitytree.php",
                [
                  'title'       => __('Select the desired entity'),
                  'extraparams' => ['target' => $target]
                ]
            );
            $active_entity = addslashes($_SESSION["glpiactive_entity_name"]);
            $entity_shortname = $_SESSION["glpiactive_entity_shortname"];
            echo <<<HTML
            <div class='profile-selector'>
               <a onclick='entity_window.dialog("open")' href='#modal_entity_content' title="$active_entity" class='entity-select' id="global_entity_select">
                  $entity_shortname
               </a>
            </div>
         HTML;
        }
    }


    /**
     * Show a tooltip on an item
     *
     * @param $content   string   data to put in the tooltip
     * @param $options   array    of possible options:
     *   - applyto : string / id of the item to apply tooltip (default empty).
     *                  If not set display an icon
     *   - title : string / title to display (default empty)
     *   - contentid : string / id for the content html container (default auto generated) (used for ajax)
     *   - link : string / link to put on displayed image if contentid is empty
     *   - linkid : string / html id to put to the link link (used for ajax)
     *   - linktarget : string / target for the link
     *   - popup : string / popup action : link not needed to use it
     *   - img : string / url of a specific img to use
     *   - display : boolean / display the item : false return the datas
     *   - autoclose : boolean / autoclose the item : default true (false permit to scroll)
     *
     * @return void|string
     *    void if option display=true
     *    string if option display=false (HTML code)
     **/
    public static function showToolTip($content, $options = [])
    {
        $param['applyto']    = '';
        $param['title']      = '';
        $param['contentid']  = '';
        $param['link']       = '';
        $param['linkid']     = '';
        $param['linktarget'] = '';
        $param['awesome-class'] = 'fa-info';
        $param['popup']      = '';
        $param['ajax']       = '';
        $param['display']    = true;
        $param['autoclose']  = true;
        $param['onclick']    = false;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $param[$key] = $val;
            }
        }

        // No empty content to have a clean display
        if (empty($content)) {
            $content = "&nbsp;";
        }
        $rand = mt_rand();
        $out  = '';

        // Force link for popup
        if (!empty($param['popup'])) {
            $param['link'] = '#';
        }

        if (empty($param['applyto'])) {
            if (!empty($param['link'])) {
                $out .= "<a id='" . (!empty($param['linkid']) ? $param['linkid'] : "tooltiplink$rand") . "'";

                if (!empty($param['linktarget'])) {
                    $out .= " target='" . $param['linktarget'] . "' ";
                }
                $out .= " href='" . $param['link'] . "'";

                if (!empty($param['popup'])) {
                    $out .= " onClick=\"" . Html::jsGetElementbyID('tooltippopup' . $rand) . ".dialog('open'); return false;\" ";
                }
                $out .= '>';
            }
            if (isset($param['img'])) {
                //for compatibility. Use fontawesome instead.
                $out .= "<img id='tooltip$rand' src='" . $param['img'] . "' class='pointer'>";
            } else {
                $out .= "<span id='tooltip$rand' class='fas {$param['awesome-class']} pointer'></span>";
            }

            if (!empty($param['link'])) {
                $out .= "</a>";
            }

            $param['applyto'] = "tooltip$rand";
        }

        if (empty($param['contentid'])) {
            $param['contentid'] = "content" . $param['applyto'];
        }

        $out .= "<div id='" . $param['contentid'] . "' class='invisible'>$content</div>";
        if (!empty($param['popup'])) {
            $out .= Ajax::createIframeModalWindow(
                'tooltippopup' . $rand,
                $param['popup'],
                [
                  'display' => false,
                  'width'   => 600,
                  'height'  => 300
                ]
            );
        }
        $js = "";
        $out .= Html::scriptBlock($js);

        if ($param['display']) {
            echo $out;
        } else {
            return $out;
        }
    }


    /**
     * Show div with auto completion
     *
     * @param CommonDBTM $item    item object used for create dropdown
     * @param string     $field   field to search for autocompletion
     * @param array      $options array of possible options:
     *    - name    : string / name of the select (default is field parameter)
     *    - value   : integer / preselected value (default value of the item object)
     *    - size    : integer / size of the text field
     *    - entity  : integer / restrict to a defined entity (default entity of the object if define)
     *                set to -1 not to take into account
     *    - user    : integer / restrict to a defined user (default -1 : no restriction)
     *    - option  : string / options to add to text field
     *    - display : boolean / if false get string
     *    - type    : string / html5 field type (number, date, text, ...) defaults to 'text'
     *    - required: boolean / whether the field is required
     *    - rand    : integer / pre-exsting random value
     *    - attrs   : array of attributes to add (['name' => 'value']
     *
     * @return void|string
     **/
    public static function autocompletionTextField(CommonDBTM $item, $field, $options = [])
    {
        global $CFG_GLPI;

        $params['name']   = $field;
        $params['value']  = '';

        if (array_key_exists($field, $item->fields)) {
            $params['value'] = $item->fields[$field];
        }
        $params['entity'] = -1;

        if (array_key_exists('entities_id', $item->fields)) {
            $params['entity'] = $item->fields['entities_id'];
        }
        $params['user']   = -1;
        $params['option'] = '';
        $params['type']   = 'text';
        $params['required']  = false;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $params[$key] = $val;
            }
        }

        $rand = (isset($params['rand']) ? $params['rand'] : mt_rand());
        $name    = "field_" . $params['name'] . $rand;

        // Check if field is allowed
        $field_so = $item->getSearchOptionByField('field', $field, $item->getTable());
        $can_autocomplete = array_key_exists('autocomplete', $field_so) && $field_so['autocomplete'];

        $output = '';
        if ($can_autocomplete && $CFG_GLPI["use_ajax_autocompletion"]) {
            $output .=  "<input " . $params['option'] . " id='text$name' type='{$params['type']}' name='" .
               $params['name'] . "' value=\"" . self::cleanInputText($params['value']) . "\"
                       class='form-control'";

            if ($params['required'] == true) {
                $output .= " required='required'";
            }

            if (isset($params['attrs'])) {
                foreach ($params['attrs'] as $attr => $value) {
                    $output .= " $attr='$value'";
                }
            }

            $output .= ">";

            $parameters['itemtype'] = $item->getType();
            $parameters['field']    = $field;

            if ($params['entity'] >= 0) {
                $parameters['entity_restrict']    = $params['entity'];
            }
            if ($params['user'] >= 0) {
                $parameters['user_restrict']    = $params['user'];
            }

            $js = "  $( '#text$name' ).autocomplete({
                        source: '" . $CFG_GLPI["root_doc"] . "/ajax/autocompletion.php?" . Toolbox::append_params($parameters, '&') . "',
                        minLength: 3,
                        });";

            $output .= Html::scriptBlock($js);
        } else {
            $output .=  "<input " . $params['option'] . " type='text' id='text$name' name='" . $params['name'] . "'
                value=\"" . self::cleanInputText($params['value']) . "\">\n";
        }

        if (!isset($options['display']) || $options['display']) {
            echo $output;
        } else {
            return $output;
        }
    }


    /**
     * Init the Editor System to a textarea
     *
     * @param string  $name      name of the html textarea to use
     * @param string  $rand      rand of the html textarea to use (if empty no image paste system)(default '')
     * @param boolean $display   display or get js script (true by default)
     * @param boolean $readonly  editor will be readonly or not
     *
     * @return void|string
     *    integer if param display=true
     *    string if param display=false (HTML code)
     **/
    public static function initEditorSystem($name, $rand = '', $display = true, $readonly = false)
    {
        global $CFG_GLPI;

        // load tinymce lib
        Html::requireJs('tinymce');

        $language = $_SESSION['glpilanguage'];
        if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs/$language.js")) {
            $language = $CFG_GLPI["languages"][$_SESSION['glpilanguage']][2];
            if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs/$language.js")) {
                $language = "en_GB";
            }
        }
        $language_url = $CFG_GLPI['root_doc'] . '/public/lib/tinymce-i18n/langs/' . $language . '.js';

        $readonlyjs = "readonly: false";
        if ($readonly) {
            $readonlyjs = "readonly: true";
        }

        $darker_css = '';
        if ($_SESSION['glpipalette'] === 'darker') {
            $darker_css = $CFG_GLPI['root_doc'] . "/css/tiny_mce/dark_content.css";
        }

        // init tinymce
        $js = "$(function() {
         // init editor
         tinyMCE.init({
            language_url: '$language_url',
            invalid_elements: 'form,object,embed,iframe,script,@[onclick|ondblclick|'
               + 'onmousedown|onmouseup|onmouseover|onmousemove|onmouseout|onkeypress|'
               + 'onkeydown|onkeyup]',
            browser_spellcheck: true,
            mode: 'exact',
            elements: '$name',
            relative_urls: false,
            remove_script_host: false,
            entity_encoding: 'raw',
            paste_data_images: $('.fileupload').length,
            menubar: false,
            statusbar: false,
            skin_url: '" . $CFG_GLPI['root_doc'] . "/css/tiny_mce/skins/light',
            content_css: '$darker_css," . $CFG_GLPI['root_doc'] . "/css/tiny_mce_custom.css',
            cache_suffix: '?v=" . ITSM_VERSION . "',
            min_height: '150px',
            setup: function(editor) {
               if ($('#$name').attr('required') == 'required') {
                  $('#$name').closest('form').find('input[type=submit]').click(function() {
                     editor.save();
                     if ($('#$name').val() == '') {
                        alert('" . __s('The description field is mandatory') . "');
                     }
                  });
                  editor.on('keyup', function (e) {
                     editor.save();
                     if ($('#$name').val() == '') {
                        $('.mce-edit-area').addClass('required');
                     } else {
                        $('.mce-edit-area').removeClass('required');
                     }
                  });
                  editor.on('init', function (e) {
                     if ($('#$name').val() == '') {
                        $('.mce-edit-area').addClass('required');
                     }
                  });
                  editor.on('paste', function (e) {
                     // Remove required on paste event
                     // This is only needed when pasting with right click (context menu)
                     // Pasting with Ctrl+V is already handled by keyup event above
                     $('.mce-edit-area').removeClass('required');
                  });
               }
               editor.on('SaveContent', function (contentEvent) {
                  contentEvent.content = contentEvent.content.replace(/\\r?\\n/g, '');
               });
               editor.on('Change', function (e) {
                  // Nothing fancy here. Since this is only used for tracking unsaved changes,
                  // we want to keep the logic in common.js with the other form input events.
                  onTinyMCEChange(e);
               });

               // ctrl + enter submit the parent form
               editor.addShortcut('ctrl+13', 'submit', function() {
                  editor.save();
                  submitparentForm($('#$name'));
               });
            },
            plugins: [
               'table directionality searchreplace',
               'tabfocus autoresize link image paste',
               'code fullscreen stickytoolbar',
               'textcolor colorpicker',
               // load glpi_upload_doc specific plugin if we need to upload files
               typeof tinymce.AddOnManager.PluginManager.lookup.glpi_upload_doc != 'undefined'
                  ? 'glpi_upload_doc'
                  : '',
               'lists'
            ],
            toolbar: 'styleselect | bold italic | forecolor backcolor | bullist numlist outdent indent | table link image | code fullscreen',
            $readonlyjs
         });

         // set sticky for split view
         $('.layout_vsplit .main_form, .layout_vsplit .ui-tabs-panel').scroll(function(event) {
            var editor = tinyMCE.get('$name');
            editor.settings.sticky_offset = $(event.target).offset().top;
            editor.setSticky();
         });
      });";

        echo Html::scriptBlock(
            <<<JAVASCRIPT
$('#tinymce.mce-content-body').load(function() {
    $('#tinymce.mce-content-body').css({
       'zoom': '200%',
       'font-family': 'OpenDyslexic'
    });
})
JAVASCRIPT
        );

        if ($display) {
            echo  Html::scriptBlock($js);
        } else {
            return  Html::scriptBlock($js);
        }
    }

    /**
     * Convert rich text content to simple text content
     *
     * @since 9.2
     *
     * @param $content : content to convert in html
     *
     * @return $content
     **/
    public static function setSimpleTextContent($content)
    {

        $content = Html::entity_decode_deep($content);
        $content = Toolbox::convertImageToTag($content);

        // If is html content
        if ($content != strip_tags($content)) {
            $content = Toolbox::getHtmlToDisplay($content);
        }

        return $content;
    }

    /**
     * Convert simple text content to rich text content and init html editor
     *
     * @since 9.2
     *
     * @param string  $name     name of textarea
     * @param string  $content  content to convert in html
     * @param string  $rand     used for randomize tinymce dom id
     * @param boolean $readonly true will set editor in readonly mode
     *
     * @return $content
     **/
    public static function setRichTextContent($name, $content, $rand, $readonly = false)
    {

        // Init html editor (if name of textarea is provided)
        if (!empty($name)) {
            Html::initEditorSystem($name, $rand, true, $readonly);
        }

        // Neutralize non valid HTML tags
        $content = Html::clean($content, false, 1);

        // If content does not contain <br> or <p> html tag, use nl2br
        if (!preg_match("/<br\s?\/?>/", $content) && !preg_match("/<p>/", $content)) {
            $content = nl2br($content);
        }
        return $content;
    }


    /**
     * Print Ajax pager for list in tab panel
     *
     * @param string  $title              displayed above
     * @param integer $start              from witch item we start
     * @param integer $numrows            total items
     * @param string  $additional_info    Additional information to display (default '')
     * @param boolean $display            display if true, return the pager if false
     * @param string  $additional_params  Additional parameters to pass to tab reload request (default '')
     *
     * @return void|string
     **/
    public static function printAjaxPager($title, $start, $numrows, $additional_info = '', $display = true, $additional_params = '')
    {
        $list_limit = $_SESSION['glpilist_limit'];
        // Forward is the next step forward
        $forward = $start + $list_limit;

        // This is the end, my friend
        $end = $numrows - $list_limit;

        // Human readable count starts here
        $current_start = $start + 1;

        // And the human is viewing from start to end
        $current_end = $current_start + $list_limit - 1;
        if ($current_end > $numrows) {
            $current_end = $numrows;
        }
        // Empty case
        if ($current_end == 0) {
            $current_start = 0;
        }
        // Backward browsing
        if ($current_start - $list_limit <= 0) {
            $back = 0;
        } else {
            $back = $start - $list_limit;
        }

        if (!empty($additional_params) && strpos($additional_params, '&') !== 0) {
            $additional_params = '&' . $additional_params;
        }

        $out = '';
        // Print it
        $out .= "<div><table class='tab_cadre_pager' aria-label='Pagination Table'>";
        if (!empty($title)) {
            $out .= "<tr><th colspan='6'>$title</th></tr>";
        }
        $out .= "<tr>\n";

        // Back and fast backward button
        if (!$start == 0) {
            $out .= "<th class='left'><a href='javascript:reloadTab(\"start=0$additional_params\");'>
                     <i class='fa fa-step-backward' title=\"" . __s('Start') . "\"></i></a></th>";
            $out .= "<th class='left'><a href='javascript:reloadTab(\"start=$back$additional_params\");'>
                     <i class='fa fa-chevron-left' title=\"" . __s('Previous') . "\"></i></a></th>";
        }

        $out .= "<td width='50%' class='tab_bg_2'>";
        $out .= self::printPagerForm('', false, $additional_params);
        $out .= "</td>";
        if (!empty($additional_info)) {
            $out .= "<td class='tab_bg_2'>";
            $out .= $additional_info;
            $out .= "</td>";
        }
        // Print the "where am I?"
        $out .= "<td width='50%' class='tab_bg_2 b'>";
        //TRANS: %1$d, %2$d, %3$d are page numbers
        $out .= sprintf(__('From %1$d to %2$d of %3$d'), $current_start, $current_end, $numrows);
        $out .= "</td>\n";

        // Forward and fast forward button
        if ($forward < $numrows) {
            $out .= "<th class='right'><a href='javascript:reloadTab(\"start=$forward$additional_params\");'>
                     <i class='fa fa-chevron-right' title=\"" . __s('Next') . "\"></i></a></th>";
            $out .= "<th class='right'><a href='javascript:reloadTab(\"start=$end$additional_params\");'>
                     <i class='fa fa-step-forward' title=\"" . __s('End') . "\"></i></a></th>";
        }

        // End pager
        $out .= "</tr></table></div>";

        if ($display) {
            echo $out;
            return;
        }

        return $out;
    }


    /**
     * Clean Printing of and array in a table
     * ONLY FOR DEBUG
     *
     * @param array   $tab       the array to display
     * @param integer $pad       Pad used
     * @param boolean $jsexpand  Expand using JS ?
     *
     * @return void
     **/
    public static function printCleanArray($tab, $pad = 0, $jsexpand = false)
    {

        if (count($tab)) {
            echo "<table class='tab_cadre' aria-label='clean array'>";
            // For debug / no gettext
            echo "<tr><th>KEY</th><th>=></th><th>VALUE</th></tr>";

            foreach ($tab as $key => $val) {
                $key = Toolbox::clean_cross_side_scripting_deep($key);
                echo "<tr class='tab_bg_1'><td class='top right'>";
                echo $key;
                $is_array = is_array($val);
                $rand     = mt_rand();
                echo "</td><td class='top'>";
                if ($jsexpand && $is_array) {
                    echo "<a class='pointer' href=\"javascript:showHideDiv('content$key$rand','','','')\">";
                    echo "=></a>";
                } else {
                    echo "=>";
                }
                echo "</td><td class='top tab_bg_1'>";

                if ($is_array) {
                    echo "<div id='content$key$rand' " . ($jsexpand ? "style=\"display:none;\"" : '') . ">";
                    self::printCleanArray($val, $pad + 1);
                    echo "</div>";
                } else {
                    if (is_bool($val)) {
                        if ($val) {
                            echo 'true';
                        } else {
                            echo 'false';
                        }
                    } else {
                        if (is_object($val)) {
                            if (method_exists($val, '__toString')) {
                                echo (string) $val;
                            } else {
                                echo "(object) " . get_class($val);
                            }
                        } else {
                            echo htmlentities($val ?? '');
                        }
                    }
                }
                echo "</td></tr>";
            }
            echo "</table>";
        } else {
            echo __('Empty array');
        }
    }



    /**
     * Print pager for search option (first/previous/next/last)
     *
     * @param integer        $start                   from witch item we start
     * @param integer        $numrows                 total items
     * @param string         $target                  page would be open when click on the option (last,previous etc)
     * @param string         $parameters              parameters would be passed on the URL.
     * @param integer|string $item_type_output        item type display - if >0 display export PDF et Sylk form
     * @param integer|string $item_type_output_param  item type parameter for export
     * @param string         $additional_info         Additional information to display (default '')
     *
     * @return void
     *
     **/
    public static function printPager(
        $start,
        $numrows,
        $target,
        $parameters,
        $item_type_output = 0,
        $item_type_output_param = 0,
        $additional_info = ''
    ) {
        global $CFG_GLPI;

        $list_limit = $_SESSION['glpilist_limit'];
        // Forward is the next step forward
        $forward = $start + $list_limit;

        // This is the end, my friend
        $end = $numrows - $list_limit;

        // Human readable count starts here

        $current_start = $start + 1;

        // And the human is viewing from start to end
        $current_end = $current_start + $list_limit - 1;
        if ($current_end > $numrows) {
            $current_end = $numrows;
        }

        // Empty case
        if ($current_end == 0) {
            $current_start = 0;
        }

        // Backward browsing
        if ($current_start - $list_limit <= 0) {
            $back = 0;
        } else {
            $back = $start - $list_limit;
        }

        // Print it
        echo "<div><table class='tab_cadre_pager' aria-label='Navigation control'>";
        echo "<tr>";

        if (strpos($target, '?') == false) {
            $fulltarget = $target . "?" . $parameters;
        } else {
            $fulltarget = $target . "&" . $parameters;
        }
        // Back and fast backward button
        if (!$start == 0) {
            echo "<th class='left'>";
            echo "<a href='$fulltarget&amp;start=0'>";
            echo "
               <i class='fa fa-step-backward' title=\"" . __s('Start') . "\"></i>";
            echo "</a></th>";
            echo "<th class='left'>";
            echo "<a href='$fulltarget&amp;start=$back'>";
            echo "<i class='fa fa-chevron-left' title=\"" . __s('Previous') . "\"></i>";
            echo "</a></th>";
        }

        // Print the "where am I?"
        echo "<td width='31%' class='tab_bg_2'>";
        self::printPagerForm("$fulltarget&amp;start=$start");
        echo "</td>";

        if (!empty($additional_info)) {
            echo "<td class='tab_bg_2'>";
            echo $additional_info;
            echo "</td>";
        }

        if (
            !empty($item_type_output)
            && isset($_SESSION["glpiactiveprofile"])
            && (Session::getCurrentInterface() == "central")
        ) {
            echo "<td class='tab_bg_2 responsive_hidden' width='30%'>";
            echo "<form aria-label='Item Type' method='GET' action='" . $CFG_GLPI["root_doc"] . "/front/report.dynamic.php'>";
            echo Html::hidden('item_type', ['value' => $item_type_output]);

            if ($item_type_output_param != 0) {
                echo Html::hidden(
                    'item_type_param',
                    ['value' => Toolbox::prepareArrayForInput($item_type_output_param)]
                );
            }

            $parameters = trim($parameters, '&amp;');
            if (strstr($parameters, 'start') === false) {
                $parameters .= "&amp;start=$start";
            }

            $split = explode("&amp;", $parameters);

            $count_split = count($split);
            for ($i = 0; $i < $count_split; $i++) {
                $pos    = Toolbox::strpos($split[$i], '=');
                $length = Toolbox::strlen($split[$i]);
                echo Html::hidden(Toolbox::substr($split[$i], 0, $pos), ['value' => urldecode(Toolbox::substr($split[$i], $pos + 1))]);
            }

            Dropdown::showOutputFormat();
            Html::closeForm();
            echo "</td>";
        }

        echo "<td width='20%' class='tab_bg_2 b'>";
        //TRANS: %1$d, %2$d, %3$d are page numbers
        printf(__('From %1$d to %2$d of %3$d'), $current_start, $current_end, $numrows);
        echo "</td>\n";

        // Forward and fast forward button
        if ($forward < $numrows) {
            echo "<th class='right'>";
            echo "<a href='$fulltarget&amp;start=$forward'>
               <i class='fa fa-chevron-right' title=\"" . __s('Next') . "\">";
            echo "</a></th>\n";

            echo "<th class='right'>";
            echo "<a href='$fulltarget&amp;start=$end'>";
            echo "<i class='fa fa-step-forward' title=\"" . __s('End') . "\"></i>";
            echo "</a></th>\n";
        }
        // End pager
        echo "</tr></table></div>";
    }


    /**
     * Display the list_limit combo choice
     *
     * @param string  $action             page would be posted when change the value (URL + param) (default '')
     * @param boolean $display            display the pager form if true, return it if false
     * @param string  $additional_params  Additional parameters to pass to tab reload request (default '')
     *
     * ajax Pager will be displayed if empty
     *
     * @return void|string
     **/
    public static function printPagerForm($action = "", $display = true, $additional_params = '')
    {

        if (!empty($additional_params) && strpos($additional_params, '&') !== 0) {
            $additional_params = '&' . $additional_params;
        }

        $out = '';
        if ($action) {
            $out .= "<form aria-label='Items number' method='POST' action=\"$action\">";
            $out .= "<span class='responsive_hidden'>" . __('Display (number of items)') . "</span>&nbsp;";
            $out .= Dropdown::showListLimit("this.form.submit()", false);
        } else {
            $out .= "<form aria-label='Items number' method='POST' action =''>\n";
            $out .= "<span class='responsive_hidden'>" . __('Display (number of items)') . "</span>&nbsp;";
            $out .= Dropdown::showListLimit("reloadTab(\"glpilist_limit=\"+this.value+\"$additional_params\")", false);
        }
        $out .= Html::closeForm(false);

        if ($display) {
            echo $out;
            return;
        }
        return $out;
    }


    /**
     * Create a title for list, as  "List (5 on 35)"
     *
     * @param $string String  text for title
     * @param $num    Integer number of item displayed
     * @param $tot    Integer number of item existing
     *
     * @since 0.83.1
     *
     * @return String
     **/
    public static function makeTitle($string, $num, $tot)
    {

        if (($num > 0) && ($num < $tot)) {
            // TRANS %1$d %2$d are numbers (displayed, total)
            $cpt = "<span class='primary-bg primary-fg count'>" .
               sprintf(__('%1$d on %2$d'), $num, $tot) . "</span>";
        } else {
            // $num is 0, so means configured to display nothing
            // or $num == $tot
            $cpt = "<span class='primary-bg primary-fg count'>$tot</span>";
        }
        return "<p class='table-title'>" . sprintf(__('%1$s %2$s'), $string, $cpt) . "</p>";
    }


    /**
     * create a minimal form for simple action
     *
     * @param $action   String   URL to call on submit
     * @param $btname   String   button name (maybe if name <> value)
     * @param $btlabel  String   button label
     * @param $fields   Array    field name => field  value
     * @param $btimage  String   button image uri (optional)   (default '')
     *                           If image name starts with "fa-", il will be turned into
     *                           a font awesome element rather than an image.
     * @param $btoption String   optional button option        (default '')
     * @param $confirm  String   optional confirm message      (default '')
     *
     * @since 0.84
     **/
    public static function getSimpleForm(
        $action,
        $btname,
        $btlabel,
        array $fields = [],
        $btimage = '',
        $btoption = '',
        $confirm = ''
    ) {

        if (GLPI_USE_CSRF_CHECK) {
            $fields['_glpi_csrf_token'] = Session::getNewCSRFToken();
        }
        $fields['_glpi_simple_form'] = 1;
        $button                      = $btname;
        if (!is_array($btname)) {
            $button          = [];
            $button[$btname] = $btname;
        }
        $fields          = array_merge($button, $fields);
        $javascriptArray = [];
        foreach ($fields as $name => $value) {
            /// TODO : trouble :  urlencode not available for array / do not pass array fields...
            if (!is_array($value)) {
                // Javascript no gettext
                $javascriptArray[] = "'$name': '" . urlencode($value) . "'";
            }
        }

        $link = "<a class='m-1'";

        if (!empty($btoption)) {
            $link .= ' ' . $btoption . ' ';
        }
        // Do not force class if already defined
        if (!strstr($btoption, 'class=')) {
            if (empty($btimage)) {
                $link .= " class='btn btn-secondary' ";
            } else {
                $link .= " class='pointer' ";
            }
        }
        $btlabel = htmlentities($btlabel, ENT_QUOTES, 'UTF-8');
        $action  = " submitGetLink('$action', {" . implode(', ', $javascriptArray) . "});";

        if (is_array($confirm) || strlen($confirm)) {
            $link .= self::addConfirmationOnAction($confirm, $action);
        } else {
            $link .= " onclick=\"$action\" ";
        }

        $link .= '>';
        if (empty($btimage)) {
            $link .= $btlabel;
        } else {
            if (substr($btimage, 0, strlen('fa-')) === 'fa-') {
                $link .= "<span class='fa $btimage pointer' title='$btlabel'><span class='sr-only'>$btlabel</span>";
            } else {
                $link .= "<img src='$btimage' title='$btlabel' alt='$btlabel' class='pointer'>";
            }
        }
        $link .= "</a>";

        return $link;
    }


    /**
     * create a minimal form for simple action
     *
     * @param $action   String   URL to call on submit
     * @param $btname   String   button name
     * @param $btlabel  String   button label
     * @param $fields   Array    field name => field  value
     * @param $btimage  String   button image uri (optional) (default '')
     * @param $btoption String   optional button option (default '')
     * @param $confirm  String   optional confirm message (default '')
     *
     * @since 0.83.3
     **/
    public static function showSimpleForm(
        $action,
        $btname,
        $btlabel,
        array $fields = [],
        $btimage = '',
        $btoption = '',
        $confirm = ''
    ) {

        echo self::getSimpleForm($action, $btname, $btlabel, $fields, $btimage, $btoption, $confirm);
    }


    /**
     * Create a close form part including CSRF token
     *
     * @param $display boolean Display or return string (default true)
     *
     * @since 0.83.
     *
     * @return String
     **/
    public static function closeForm($display = true)
    {
        global $CFG_GLPI;

        $out = "\n";
        if (GLPI_USE_CSRF_CHECK) {
            $out .= Html::hidden('_glpi_csrf_token', ['value' => $_SESSION['_glpi_csrf_token']]) . "\n";
        }

        if (isset($CFG_GLPI['checkbox-zero-on-empty']) && $CFG_GLPI['checkbox-zero-on-empty']) {
            $js = "   $('form').submit(function() {
         $('input[type=\"checkbox\"][data-glpicore-cb-zero-on-empty=\"1\"]:not(:checked)').each(function(index){
            // If the checkbox is not validated, we add a hidden field with '0' as value
            if ($(this).attr('name')) {
               $('<input>').attr({
                  type: 'hidden',
                  name: $(this).attr('name'),
                  value: '0'
               }).insertAfter($(this));
            }
         });
      });";
            $out .= Html::scriptBlock($js) . "\n";
            unset($CFG_GLPI['checkbox-zero-on-empty']);
        }

        $out .= "</form>\n";
        if ($display) {
            echo $out;
            return true;
        }
        return $out;
    }


    /**
     * Get javascript code for hide an item
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function jsHide($id)
    {
        return self::jsGetElementbyID($id) . ".hide();\n";
    }


    /**
     * Get javascript code for hide an item
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function jsShow($id)
    {
        return self::jsGetElementbyID($id) . ".show();\n";
    }


    /**
     * Get javascript code for enable an item
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     * @deprecated 9.5.0
     *
     * @return String
     **/
    public static function jsEnable($id)
    {
        return self::jsGetElementbyID($id) . ".removeAttr('disabled');\n";
    }


    /**
     * Get javascript code for disable an item
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     * @deprecated 9.5.0
     *
     * @return String
     **/
    public static function jsDisable($id)
    {
        return self::jsGetElementbyID($id) . ".attr('disabled', 'disabled');\n";
    }


    /**
     * Clean ID used for HTML elements
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function cleanId($id)
    {
        return str_replace(['[', ']'], '_', $id);
    }


    /**
     * Get javascript code to get item by id
     *
     * @param $id string id of the dom element
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function jsGetElementbyID($id)
    {
        return "$('#$id')";
    }


    /**
     * Set dropdown value
     *
     * @param $id      string   id of the dom element
     * @param $value   string   value to set
     *
     * @since 0.85.
     *
     * @return string
     **/
    public static function jsSetDropdownValue($id, $value)
    {
        return self::jsGetElementbyID($id) . ".trigger('setValue', '$value');";
    }

    /**
     * Get item value
     *
     * @param $id      string   id of the dom element
     *
     * @since 0.85.
     *
     * @return string
     **/
    public static function jsGetDropdownValue($id)
    {
        return self::jsGetElementbyID($id) . ".val()";
    }


    /**
     * Adapt dropdown to clean JS
     *
     * @param $id       string   id of the dom element
     * @param $params   array    of parameters
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function jsAdaptDropdown($id, $params = [])
    {
        global $CFG_GLPI;

        $width = '';
        if (isset($params["width"]) && !empty($params["width"])) {
            $width = $params["width"];
            unset($params["width"]);
        }

        $placeholder = '';
        if (isset($params["placeholder"])) {
            $placeholder = "placeholder: " . json_encode($params["placeholder"]) . ",";
        }

        $js = "";
        return Html::scriptBlock($js);
    }


    /**
     * Create Ajax dropdown to clean JS
     *
     * @param $name
     * @param $field_id   string   id of the dom element
     * @param $url        string   URL to get datas
     * @param $params     array    of parameters
     *            must contains :
     *                if single select
     *                   - 'value'       : default value selected
     *                   - 'valuename'   : default name of selected value
     *                if multiple select
     *                   - 'values'      : default values selected
     *                   - 'valuesnames' : default names of selected values
     *
     * @since 0.85.
     *
     * @return String
     **/
    public static function jsAjaxDropdown($name, $field_id, $url, $params = [])
    {
        global $CFG_GLPI;

        if (!array_key_exists('value', $params)) {
            $value = 0;
            $valuename = Dropdown::EMPTY_VALUE;
        } else {
            $value = $params['value'];
            $valuename = $params['valuename'];
        }
        $on_change = '';
        if (isset($params["on_change"])) {
            $on_change = $params["on_change"];
            unset($params["on_change"]);
        }
        $width = '80%';
        if (isset($params["width"])) {
            $width = $params["width"];
            unset($params["width"]);
        }

        $placeholder = $params['placeholder'] ?? '';
        $allowclear =  "false";
        if (strlen($placeholder) > 0 && !$params['display_emptychoice']) {
            $allowclear = "true";
        }

        $options = [
           'id'        => $field_id,
           'selected'  => $value,
           'class'     => 'form-select'
        ];

        // manage multiple select (with multiple values)
        if (
            isset($params['values'])
            && (
                count($params['values'])
            || !isset($params['value'])
            )
        ) {
            $values = array_combine($params['values'], $params['valuesnames']);
            $options['multiple'] = 'multiple';
            $options['selected'] = $params['values'];
        } else {
            $values = [];

            // simple select (multiple = no)
            if ($value !== null) {
                $values = ["$value" => $valuename];
            }
        }

        unset($params['placeholder']);
        unset($params['value']);
        unset($params['valuename']);

        if (!empty($params['specific_tags'])) {
            foreach ($params['specific_tags'] as $tag => $val) {
                if (is_array($val)) {
                    $val = implode(' ', $val);
                }
                $options[$tag] = $val;
            }
        }

        // display select tag
        $output = self::select($name, $values, $options);

        $js = "
         var params_$field_id = {";
        foreach ($params as $key => $val) {
            // Specific boolean case
            if (is_bool($val)) {
                $js .= "$key: " . ($val ? 1 : 0) . ",\n";
            } else {
                $js .= "$key: " . json_encode($val) . ",\n";
            }
        }
        $js .= "";
        if (!empty($on_change)) {
            $js .= " $('#$field_id').on('change', function(e) {" .
               stripslashes($on_change) . "});";
        }

        $js .= "}; $('label[for=$field_id]').on('click', function(){ $('#$field_id').select2('open'); });";

        $output .= Html::scriptBlock('$(function() {' . $js . '});');
        return $output;
    }


    /**
     * Creates a formatted IMG element.
     *
     * This method will set an empty alt attribute if no alt and no title is not supplied
     *
     * @since 0.85
     *
     * @param string $path     Path to the image file
     * @param array  $options  array of HTML attributes
     *        - `url` If provided an image link will be generated and the link will point at
     *               `$options['url']`.
     * @return string completed img tag
     **/
    public static function image($path, $options = [])
    {

        if (!isset($options['title'])) {
            $options['title'] = '';
        }

        if (!isset($options['alt'])) {
            $options['alt'] = $options['title'];
        }

        if (
            empty($options['title'])
            && !empty($options['alt'])
        ) {
            $options['title'] = $options['alt'];
        }

        $url = false;
        if (!empty($options['url'])) {
            $url = $options['url'];
            unset($options['url']);
        }

        $class = "";
        if ($url) {
            $class = "class='pointer'";
        }

        $image = sprintf('<img src="%1$s" %2$s %3$s />', $path, Html::parseAttributes($options), $class);
        if ($url) {
            return Html::link($image, $url);
        }
        return $image;
    }


    /**
     * Creates a PhotoSwipe image gallery
     *
     *
     * @since 9.5.0
     *
     * @param array $imgs  Array of image info
     *                      - src The public path of img
     *                      - w   The width of img
     *                      - h   The height of img
     * @param array $options
     * @return string completed gallery
     **/
    public static function imageGallery($imgs, $options = [])
    {
        $p = [
           'controls' => [
              'close'        => true,
              'share'        => true,
              'fullscreen'   => true,
              'zoom'         => true,
           ],
           'rand'               => mt_rand(),
           'gallery_item_class' => ''
        ];

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        $out = "<div id='psgallery{$p['rand']}' class='pswp' tabindex='-1'
         role='dialog' aria-hidden='true'>";
        $out .= "<div class='pswp__bg'></div>";
        $out .= "<div class='pswp__scroll-wrap'>";
        $out .= "<div class='pswp__container'>";
        $out .= "<div class='pswp__item'></div>";
        $out .= "<div class='pswp__item'></div>";
        $out .= "<div class='pswp__item'></div>";
        $out .= "</div>";
        $out .= "<div class='pswp__ui pswp__ui--hidden'>";
        $out .= "<div class='pswp__top-bar'>";
        $out .= "<div class='pswp__counter'></div>";

        if (isset($p['controls']['close']) && $p['controls']['close']) {
            $out .= "<button class='pswp__button pswp__button--close' aria-label='Close' title='" . __('Close (Esc)') . "'></button>";
        }

        if (isset($p['controls']['share']) && $p['controls']['share']) {
            $out .= "<button class='pswp__button pswp__button--share' aria-label='Share' title='" . __('Share') . "'></button>";
        }

        if (isset($p['controls']['fullscreen']) && $p['controls']['fullscreen']) {
            $out .= "<button class='pswp__button pswp__button--fs' aria-label='FullScreen' title='" . __('Toggle fullscreen') . "'></button>";
        }

        if (isset($p['controls']['zoom']) && $p['controls']['zoom']) {
            $out .= "<button class='pswp__button pswp__button--zoom' aria-label='Zoom' title='" . __('Zoom in/out') . "'></button>";
        }

        $out .= "<div class='pswp__preloader'>";
        $out .= "<div class='pswp__preloader__icn'>";
        $out .= "<div class='pswp__preloader__cut'>";
        $out .= "<div class='pswp__preloader__donut'></div>";
        $out .= "</div></div></div></div>";
        $out .= "<div class='pswp__share-modal pswp__share-modal--hidden pswp__single-tap'>";
        $out .= "<div class='pswp__share-tooltip'></div>";
        $out .= "</div>";
        $out .= "<button class='pswp__button pswp__button--arrow--left' aria-label='Previous' title='" . __('Previous (arrow left)') . "'>";
        $out .= "</button>";
        $out .= "<button class='pswp__button pswp__button--arrow--right' aria-label='Next' title='" . __('Next (arrow right)') . "'>";
        $out .= "</button>";
        $out .= "<div class='pswp__caption'>";
        $out .= "<div class='pswp__caption__center'></div>";
        $out .= "</div></div></div></div>";

        $out .= "<div class='pswp-img{$p['rand']} {$p['gallery_item_class']}' itemscope itemtype='http://schema.org/ImageGallery'>";
        foreach ($imgs as $img) {
            if (!isset($img['thumbnail_src'])) {
                $img['thumbnail_src'] = $img['src'];
            }
            $out .= "<figure itemprop='associatedMedia' itemscope itemtype='http://schema.org/ImageObject'>";
            $out .= "<a href='{$img['src']}' itemprop='contentUrl' data-index='0'>";
            $out .= "<img src='{$img['thumbnail_src']}' itemprop='thumbnail'>";
            $out .= "</a>";
            $out .= "</figure>";
        }
        $out .= "</div>";

        // Decode images urls
        $imgs = array_map(function ($img) {
            $img['src'] = html_entity_decode($img['src']);
            return $img;
        }, $imgs);

        $items_json = json_encode($imgs);
        $dltext = __('Download');
        $js = <<<JAVASCRIPT
      (function($) {
         var pswp = document.getElementById('psgallery{$p['rand']}');

         $('.pswp-img{$p['rand']}').on('click', 'figure', function(event) {
            event.preventDefault();

            var options = {
                index: $(this).index(),
                bgOpacity: 0.7,
                showHideOpacity: true,
                shareButtons: [
                  {id:'download', label:'{$dltext}', url:'{{raw_image_url}}', download:true}
                ]
            }

            var lightBox = new PhotoSwipe(pswp, PhotoSwipeUI_Default, {$items_json}, options);
            $(pswp).closest('.glpi_tabs').css('z-index', 50); // be sure that tabs are displayed above form in vsplit
            lightBox.init();

            lightBox.listen(
               'destroy',
               function() {
                  $(this.container).closest('.glpi_tabs').css('z-index', ''); // restore z-index from CSS
               }
            );
        });
      })(jQuery);

JAVASCRIPT;

        $out .= Html::scriptBlock($js);

        return $out;
    }

    /**
     * Replace images by gallery component in rich text.
     *
     * @since 9.5.0
     *
     * @param string  $richtext
     *
     * @return string
     */
    public static function replaceImagesByGallery($richtext)
    {

        $image_matches = [];
        preg_match_all(
            '/<a[^>]*>\s*<img[^>]*src=["\']([^"\']*document\.send\.php\?docid=([0-9]+)(?:&[^"\']+)?)["\'][^>]*>\s*<\/a>/',
            $richtext,
            $image_matches,
            PREG_SET_ORDER
        );
        foreach ($image_matches as $image_match) {
            $img_tag = $image_match[0];
            $docsrc  = $image_match[1];
            $docid   = $image_match[2];
            $document = new Document();
            if ($document->getFromDB($docid)) {
                $docpath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
                if (Document::isImage($docpath)) {
                    $imgsize = getimagesize($docpath);
                    $gallery = Html::imageGallery([
                       [
                          'src' => $docsrc,
                          'w'   => $imgsize[0],
                          'h'   => $imgsize[1]
                       ]
                    ]);
                    $richtext = str_replace($img_tag, $gallery, $richtext);
                }
            }
        }

        return $richtext;
    }


    /**
     * Creates an HTML link.
     *
     * @since 0.85
     *
     * @param string $text     The content to be wrapped by a tags.
     * @param string $url      URL parameter
     * @param array  $options  Array of HTML attributes:
     *     - `confirm` JavaScript confirmation message.
     *     - `confirmaction` optional action to do on confirmation
     * @return string an `a` element.
     **/
    public static function link($text, $url, $options = [])
    {

        if (isset($options['confirm'])) {
            if (!empty($options['confirm'])) {
                $confirmAction  = '';
                if (isset($options['confirmaction'])) {
                    if (!empty($options['confirmaction'])) {
                        $confirmAction = $options['confirmaction'];
                    }
                    unset($options['confirmaction']);
                }
                $options['onclick'] = Html::getConfirmationOnActionScript(
                    $options['confirm'],
                    $confirmAction
                );
            }
            unset($options['confirm']);
        }
        // Do not escape title if it is an image or a i tag (fontawesome)
        if (!preg_match('/^<i(mg)?.*/', $text)) {
            $text = Html::cleanInputText($text);
        }

        return sprintf(
            '<a href="%1$s" %2$s>%3$s</a>',
            Html::cleanInputText($url),
            Html::parseAttributes($options),
            $text
        );
    }


    /**
     * Creates a hidden input field.
     *
     * If value of options is an array then recursively parse it
     * to generate as many hidden input as necessary
     *
     * @since 0.85
     *
     * @param string $fieldName  Name of a field
     * @param array  $options    Array of HTML attributes.
     *
     * @return string A generated hidden input
     **/
    public static function hidden($fieldName, $options = [])
    {

        if ((isset($options['value'])) && (is_array($options['value']))) {
            $result = '';
            foreach ($options['value'] as $key => $value) {
                $options2          = $options;
                $options2['value'] = $value;
                $result           .= static::hidden($fieldName . '[' . $key . ']', $options2) . "\n";
            }
            return $result;
        }
        return sprintf(
            '<input type="hidden" name="%1$s" %2$s />',
            Html::cleanInputText($fieldName),
            Html::parseAttributes($options)
        );
    }


    /**
     * Creates a text input field.
     *
     * @since 0.85
     *
     * @param string $fieldName  Name of a field
     * @param array  $options    Array of HTML attributes.
     *
     * @return string A generated hidden input
     **/
    public static function input($fieldName, $options = [])
    {
        $type = 'text';
        if (isset($options['type'])) {
            $type = $options['type'];
            unset($options['type']);
        }
        return sprintf(
            '<input type="%1$s" name="%2$s" %3$s class="form-control"/>',
            $type,
            Html::cleanInputText($fieldName),
            Html::parseAttributes($options)
        );
    }

    /**
     * Creates a select tag
     *
     * @since 9.3
     *
     * @param string $ame      Name of the field
     * @param array  $values   Array of the options
     * @param mixed  $selected Current selected option
     * @param array  $options  Array of HTML attributes
     *
     * @return string
     */
    public static function select($name, array $values = [], $options = [])
    {
        $selected = false;
        if (isset($options['selected'])) {
            $selected = $options['selected'];
            unset($options['selected']);
        }
        $select = sprintf(
            '<select aria-label="$name" name="%1$s" class="form-select" %2$s>',
            self::cleanInputText($name),
            self::parseAttributes($options)
        );
        foreach ($values as $key => $value) {
            $select .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                self::cleanInputText($key),
                (
                    $selected != false && ($key == $selected
                || is_array($selected) && in_array($key, $selected))
                ) ? ' selected="selected"' : '',
                Html::entities_deep($value)
            );
        }
        $select .= '</select>';
        return $select;
    }

    /**
     * Creates a submit button element. This method will generate input elements that
     * can be used to submit, and reset forms by using $options. Image submits can be created by supplying an
     * image option
     *
     * @since 0.85
     *
     * @param string $caption  caption of the input
     * @param array  $options  Array of options.
     *     - image : will use a submit image input
     *     - `confirm` JavaScript confirmation message.
     *     - `confirmaction` optional action to do on confirmation
     *
     * @return string A HTML submit button
     **/
    public static function submit($caption, $options = [])
    {

        $image = false;
        if (isset($options['image'])) {
            if (preg_match('/\.(jpg|jpe|jpeg|gif|png|ico)$/', $options['image'])) {
                $image = $options['image'];
            }
            unset($options['image']);
        }

        // Set default class to submit
        if (!isset($options['class'])) {
            $options['class'] = 'btn btn-sm btn-secondary';
        }
        if (isset($options['confirm'])) {
            if (!empty($options['confirm'])) {
                $confirmAction  = '';
                if (isset($options['confirmaction'])) {
                    if (!empty($options['confirmaction'])) {
                        $confirmAction = $options['confirmaction'];
                    }
                    unset($options['confirmaction']);
                }
                $options['onclick'] = Html::getConfirmationOnActionScript(
                    $options['confirm'],
                    $confirmAction
                );
            }
            unset($options['confirm']);
        }

        if ($image) {
            $options['title'] = $caption;
            $options['alt']   = $caption;
            return sprintf(
                '<input type="image" src="%s" %s />',
                Html::cleanInputText($image),
                Html::parseAttributes($options)
            );
        }

        $button = "<button type='submit' aria-label='" . self::clean($caption) . "' value='%s' %s>
               $caption
            </button>&nbsp;";

        return sprintf($button, Html::cleanInputText($caption), Html::parseAttributes($options));
    }


    /**
     * Creates an accessible, stylable progress bar control.
     * @since 9.5.0
     * @param int $max    The maximum value of the progress bar.
     * @param int $value    The current value of the progress bar.
     * @param array $params  Array of options:
     *                         - rand: Random int for the progress id. Default is a new random int.
     *                         - tooltip: Text to show in the tooltip. Default is nothing.
     *                         - append_percent_tt: If true, the percent will be appended to the tooltip.
     *                               In this case, it will also be automatically updated. Default is true.
     *                         - text: Text to show in the progress bar. Default is nothing.
     *                         - append_percent_text: If true, the percent will be appended to the text.
     *                               In this case, it will also be automatically updated. Default is false.
     * @return string     The progress bar HTML
     */
    public static function progress($max, $value, $params = [])
    {
        $p = [
           'rand'            => mt_rand(),
           'tooltip'         => '',
           'append_percent'  => true
        ];
        $p = array_replace($p, $params);

        $tooltip = trim($p['tooltip'] . ($p['append_percent'] ? " {$value}%" : ''));
        // Hide element except when using a screen reader. This uses FontAwesome's sr-only class.
        $html = "<progress id='progress{$p['rand']}' class='sr-only' max='$max' value='$value'
            onchange='updateProgress(\"{$p['rand']}\")' title='{$tooltip}'></progress>";
        // Custom progress control. Should be hidden for screen readers.
        $html .= "<div aria-hidden='true' data-progressid='{$p['rand']}'
         data-append-percent='{$p['append_percent']}' class='progress' title='{$tooltip}'>";
        $calcWidth = ($value / $max) * 100;
        $html .= "<span aria-hidden='true' class='progress-fg' style='width: $calcWidth%'></span>";
        $html .= "</div>";
        return $html;
    }


    /**
     * Returns a space-delimited string with items of the $options array.
     *
     * @since 0.85
     *
     * @param $options Array of options.
     *
     * @return string Composed attributes.
     **/
    public static function parseAttributes($options = [])
    {

        if (!is_string($options)) {
            $attributes = [];

            foreach ($options as $key => $value) {
                $attributes[] = Html::formatAttribute($key, $value);
            }
            $out = implode(' ', $attributes);
        } else {
            $out = $options;
        }
        return $out;
    }


    /**
     * Formats an individual attribute, and returns the string value of the composed attribute.
     *
     * @since 0.85
     *
     * @param string $key    The name of the attribute to create
     * @param string $value  The value of the attribute to create.
     *
     * @return string The composed attribute.
     **/
    public static function formatAttribute($key, $value)
    {

        if (is_array($value)) {
            $value = implode(' ', $value);
        }

        return sprintf('%1$s="%2$s"', $key, Html::cleanInputText($value));
    }


    /**
     * Wrap $script in a script tag.
     *
     * @since 0.85
     *
     * @param string $script  The script to wrap
     *
     * @return string
     **/
    public static function scriptBlock($script)
    {

        $script = "\n" . '//<![CDATA[' . "\n\n" . $script . "\n\n" . '//]]>' . "\n";

        return sprintf('<script type="text/javascript">%s</script>', $script);
    }


    /**
     * Returns one or many script tags depending on the number of scripts given.
     *
     * @since 0.85
     * @since 9.2 Path is now relative to GLPI_ROOT. Add $minify parameter.
     *
     * @param string  $url     File to include (relative to GLPI_ROOT)
     * @param array   $options Array of HTML attributes
     * @param boolean $minify  Try to load minified file (defaults to true)
     *
     * @return String of script tags
     **/
    public static function script($url, $options = [], $minify = true)
    {
        $version = ITSM_VERSION;
        if (isset($options['version'])) {
            $version = $options['version'];
            unset($options['version']);
        }

        if ($minify === true) {
            $url = self::getMiniFile($url);
        }

        $url = self::getPrefixedUrl($url);

        if ($version) {
            $url .= '?v=' . $version;
        }

        return sprintf('<script type="text/javascript" src="%1$s"></script>', $url);
    }


    /**
     * Creates a link element for CSS stylesheets.
     *
     * @since 0.85
     * @since 9.2 Path is now relative to GLPI_ROOT. Add $minify parameter.
     *
     * @param string  $url     File to include (relative to GLPI_ROOT)
     * @param array   $options Array of HTML attributes
     * @param boolean $minify  Try to load minified file (defaults to true)
     *
     * @return string CSS link tag
     **/
    public static function css($url, $options = [], $minify = true)
    {
        if ($minify === true) {
            $url = self::getMiniFile($url);
        }
        $url = self::getPrefixedUrl($url);

        return self::csslink($url, $options);
    }

    /**
     * Creates a link element for SCSS stylesheets.
     *
     * @since 9.4
     *
     * @param string  $url     File to include (relative to GLPI_ROOT)
     * @param array   $options Array of HTML attributes
     *
     * @return string CSS link tag
     **/
    public static function scss($url, $options = [])
    {
        $prod_file = self::getScssCompilePath($url);

        if (file_exists($prod_file) && $_SESSION['glpi_use_mode'] != Session::DEBUG_MODE) {
            $url = self::getPrefixedUrl(str_replace(GLPI_ROOT, '', $prod_file));
        } else {
            $file = $url;
            $url = self::getPrefixedUrl('/front/css.php');
            $url .= '?file=' . $file;
            if (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
                $url .= '&debug';
            }
        }

        return self::csslink($url, $options);
    }

    /**
     * Creates a link element for (S)CSS stylesheets.
     *
     * @since 9.4
     *
     * @param string $url      File to include (raltive to GLPI_ROOT)
     * @param array  $options  Array of HTML attributes
     *
     * @return string CSS link tag
     **/
    private static function csslink($url, $options)
    {
        if (!isset($options['media']) || $options['media'] == '') {
            $options['media'] = 'all';
        }

        $version = ITSM_VERSION;
        if (isset($options['version'])) {
            $version = $options['version'];
            unset($options['version']);
        }

        $url .= ((strpos($url, '?') !== false) ? '&' : '?') . 'v=' . $version;

        return sprintf(
            '<link rel="stylesheet" type="text/css" href="%s" %s>',
            $url,
            Html::parseAttributes($options)
        );
    }

    /**
     * Display a div who reveive a list of uploaded file
     *
     * @since  version 9.2
     *
     * @param  array $options theses following keys:
     *                          - editor_id the dom id of the tinymce editor
     * @return string The Html
     */
    public static function fileForRichText($options = [])
    {
        $p['editor_id']     = '';
        $p['name']          = 'filename';
        $p['filecontainer'] = 'fileupload_info';
        $p['display']       = true;
        $rand               = mt_rand();

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        $display = "";

        // display file controls
        $display .= __('Attach file by drag & drop or copy & paste in editor or ') .
           "<a href='' id='upload_link$rand'>" . __('selecting them') . "</a>" .
           "<input id='upload_rich_text$rand' class='upload_rich_text' type='file' />";

        $display .= Html::scriptBlock("
         var fileindex = 0;
         $(function() {
            $('#upload_link$rand').on('click', function(e){
               e.preventDefault();
               $('#upload_rich_text$rand:hidden').trigger('click');
            });

            $('#upload_rich_text$rand:hidden').change(function (event) {
               uploadFile($('#upload_rich_text$rand:hidden')[0].files[0],
                            tinyMCE.get('{$p['editor_id']}'),
                            '{$p['name']}');
            });
         });
      ");

        if ($p['display']) {
            echo $display;
        } else {
            return $display;
        }
    }


    /**
     * Creates an input file field. Send file names in _$name field as array.
     * Files are uploaded in files/_tmp/ directory
     *
     * @since 9.2
     *
     * @param $options       array of options
     *    - name                string   field name (default filename)
     *    - onlyimages          boolean  restrict to image files (default false)
     *    - filecontainer       string   DOM ID of the container showing file uploaded:
     *                                   use selector to display
     *    - showfilesize        boolean  show file size with file name
     *    - showtitle           boolean  show the title above file list
     *                                   (with max upload size indication)
     *    - enable_richtext     boolean  switch to richtext fileupload
     *    - pasteZone           string   DOM ID of the paste zone
     *    - dropZone            string   DOM ID of the drop zone
     *    - rand                string   already computed rand value
     *    - display             boolean  display or return the generated html (default true)
     *
     * @return void|string   the html if display parameter is false
     **/
    public static function file($options = [])
    {
        global $CFG_GLPI;

        $randupload             = mt_rand();

        $p['name']              = 'filename';
        $p['onlyimages']        = false;
        $p['filecontainer']     = 'fileupload_info';
        $p['showfilesize']      = true;
        $p['showtitle']         = true;
        $p['enable_richtext']   = false;
        $p['pasteZone']         = false;
        $p['dropZone']          = 'dropdoc' . $randupload;
        $p['rand']              = $randupload;
        $p['values']            = [];
        $p['display']           = true;
        $p['multiple']          = false;
        $p['uploads']           = [];

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        $display = "";
        $display .= "<div class='fileupload draghoverable'>";

        if ($p['showtitle']) {
            $display .= "<b>";
            $display .= sprintf(__('%1$s (%2$s)'), __('File(s)'), Document::getMaxUploadSize());
            $display .= DocumentType::showAvailableTypesLink(['display' => false]);
            $display .= "</b>";
        }

        $display .= self::uploadedFiles([
           'filecontainer' => $p['filecontainer'],
           'name'          => $p['name'],
           'display'       => false,
           'uploads'       => $p['uploads'],
        ]);

        if (
            !empty($p['editor_id'])
            && $p['enable_richtext']
        ) {
            $options_rt = $options;
            $options_rt['display'] = false;
            $display .= self::fileForRichText($options_rt);
        } else {
            // manage file upload without tinymce editor
            $display .= "<div id='{$p['dropZone']}'>";
            $display .= "<span class='b'>" . __('Drag and drop your file here, or') . '</span><br>';
            $display .= "<input id='fileupload{$p['rand']}' type='file' name='" . $p['name'] . "[]'
                         data-url='" . $CFG_GLPI["root_doc"] . "/ajax/fileupload.php'
                         data-form-data='{\"name\": \"" . $p['name'] . "\",
                                          \"showfilesize\": \"" . $p['showfilesize'] . "\"}'"
               . ($p['multiple'] ? " multiple='multiple'" : "")
               . ($p['onlyimages'] ? " accept='.gif,.png,.jpg,.jpeg'" : "") . ">";
            $display .= "<div id='progress{$p['rand']}' style='display:none'>" .
               "<div class='uploadbar' style='width: 0%;'></div></div>";
            $display .= "</div>";

            $display .= Html::scriptBlock("
         $(function() {
            var fileindex{$p['rand']} = 0;
            $('#fileupload{$p['rand']}').fileupload({
               dataType: 'json',
               pasteZone: " . ($p['pasteZone'] !== false
               ? "$('#{$p['pasteZone']}')"
               : "false") . ",
               dropZone:  " . ($p['dropZone'] !== false
               ? "$('#{$p['dropZone']}')"
               : "false") . ",
               acceptFileTypes: " . ($p['onlyimages']
               ? "/(\.|\/)(gif|jpe?g|png)$/i"
               : "undefined") . ",
               progressall: function(event, data) {
                  var progress = parseInt(data.loaded / data.total * 100, 10);
                  $('#progress{$p['rand']}')
                     .show()
                  .filter('.uploadbar')
                     .css({
                        width: progress + '%'
                     })
                     .text(progress + '%')
                     .show();
               },
               done: function (event, data) {
                  var filedata = data;
                  // Load image tag, and display image uploaded
                  $.ajax({
                     type: 'POST',
                     url: '" . $CFG_GLPI['root_doc'] . "/ajax/getFileTag.php',
                     data: {
                        data: data.result.{$p['name']}
                     },
                     dataType: 'JSON',
                     success: function(tag) {
                        $.each(filedata.result.{$p['name']}, function(index, file) {
                           if (file.error === undefined) {
                              //create a virtual editor to manage filelist, see displayUploadedFile()
                              var editor = {
                                 targetElm: $('#fileupload{$p['rand']}')
                              };
                              displayUploadedFile(file, tag[index], editor, '{$p['name']}');

                              $('#progress{$p['rand']} .uploadbar')
                                 .text('" . addslashes(__('Upload successful')) . "')
                                 .css('width', '100%')
                                 .delay(2000)
                                 .fadeOut('slow');
                           } else {
                              $('#progress{$p['rand']} .uploadbar')
                                 .text(file.error)
                                 .css('width', '100%');
                           }
                        });
                     }
                  });
               }
            });
         });");
        }
        $display .= "</div>"; // .fileupload

        if ($p['display']) {
            echo $display;
        } else {
            return $display;
        }
    }

    /**
     * Display an html textarea  with extended options
     *
     * @since 9.2
     *
     * @param  array  $options with these keys:
     *  - name (string):              corresponding html attribute
     *  - filecontainer (string):     dom id for the upload filelist
     *  - rand (string):              random param to avoid overriding between textareas
     *  - editor_id (string):         id attribute for the textarea
     *  - value (string):             value attribute for the textarea
     *  - enable_richtext (bool):     enable tinymce for this textarea
     *  - enable_fileupload (bool):   enable the inline fileupload system
     *  - display (bool):             display or return the generated html
     *  - cols (int):                 textarea cols attribute (witdh)
     *  - rows (int):                 textarea rows attribute (height)
     *  - required (bool):            textarea is mandatory
     *  - uploads (array):            uploads to recover from a prevous submit
     *
     * @return mixed          the html if display paremeter is false or true
     */
    public static function textarea($options = [])
    {
        //default options
        $p['name']              = 'text';
        $p['filecontainer']     = 'fileupload_info';
        $p['rand']              = mt_rand();
        $p['editor_id']         = 'text' . $p['rand'];
        $p['value']             = '';
        $p['enable_richtext']   = false;
        $p['enable_fileupload'] = false;
        $p['display']           = true;
        $p['cols']              = 100;
        $p['rows']              = 15;
        $p['multiple']          = true;
        $p['required']          = false;
        $p['uploads']           = [];

        //merge default options with options parameter
        $p = array_merge($p, $options);

        $required = $p['required'] ? 'required="required"' : '';
        $display = '';
        $display .= "<textarea name='" . $p['name'] . "' id='" . $p['editor_id'] . "'
                             rows='" . $p['rows'] . "' cols='" . $p['cols'] . "' $required>" .
           $p['value'] . "</textarea>";

        if ($p['enable_richtext']) {
            $display .= Html::initEditorSystem($p['editor_id'], $p['rand'], false);
        } else {
            $display .= Html::scriptBlock("
                        $(document).ready(function() {
                           $('#" . $p['editor_id'] . "').autogrow();
                        });
                     ");
        }
        if (!$p['enable_fileupload'] && $p['enable_richtext']) {
            $display .= self::uploadedFiles([
               'filecontainer' => $p['filecontainer'],
               'name'          => $p['name'],
               'display'       => false,
               'uploads'       => $p['uploads'],
               'editor_id'     => $p['editor_id'],
            ]);
        }

        if ($p['enable_fileupload']) {
            $p_rt = $p;
            unset($p_rt['name']);
            $p_rt['display'] = false;
            $display .= Html::file($p_rt);
        }

        if ($p['display']) {
            echo $display;
            return true;
        } else {
            return $display;
        }
    }


    /**
     * Display uploaded files area
     * @see displayUploadedFile() in fileupload.js
     *
     * @param $options       array of options
     *    - name                string   field name (default filename)
     *    - filecontainer       string   DOM ID of the container showing file uploaded:
     *    - editor_id           string   id attribute for the textarea
     *    - display             bool     display or return the generated html
     *    - uploads             array    uploads to display (done in a previous form submit)
     * @return void|string   the html if display parameter is false
     */
    private static function uploadedFiles($options = [])
    {
        global $CFG_GLPI;

        //default options
        $p['filecontainer']     = 'fileupload_info';
        $p['name']              = 'filename';
        $p['editor_id']         = '';
        $p['display']           = true;
        $p['uploads']           = [];

        //merge default options with options parameter
        $p = array_merge($p, $options);

        // div who will receive and display file list
        $display = "<div id='" . $p['filecontainer'] . "' class='fileupload_info'>";
        if (isset($p['uploads']['_' . $p['name']])) {
            foreach ($p['uploads']['_' . $p['name']] as $uploadId => $upload) {
                $prefix  = substr($upload, 0, 23);
                $displayName = substr($upload, 23);

                // get the extension icon
                $extension = pathinfo(GLPI_TMP_DIR . '/' . $upload, PATHINFO_EXTENSION);
                $extensionIcon = '/pics/icones/' . $extension . '-dist.png';
                if (!is_readable(GLPI_ROOT . $extensionIcon)) {
                    $extensionIcon = '/pics/icones/defaut-dist.png';
                }
                $extensionIcon = $CFG_GLPI['root_doc'] . $extensionIcon;

                // Rebuild the minimal data to show the already uploaded files
                $upload = [
                   'name'    => $upload,
                   'id'      => 'doc' . $p['name'] . mt_rand(),
                   'display' => $displayName,
                   'size'    => filesize(GLPI_TMP_DIR . '/' . $upload),
                   'prefix'  => $prefix,
                ];
                $tag = $p['uploads']['_tag_' . $p['name']][$uploadId];
                $tag = [
                   'name' => $tag,
                   'tag'  => "#$tag#",
                ];

                // Show the name and size of the upload
                $display .= "<p id='" . $upload['id'] . "'>&nbsp;";
                $display .= "<img src='$extensionIcon' title='$extension'>&nbsp;";
                $display .= "<b>" . $upload['display'] . "</b>&nbsp;(" . Toolbox::getSize($upload['size']) . ")";

                $name = '_' . $p['name'] . '[' . $uploadId . ']';
                $display .= Html::hidden($name, ['value' => $upload['name']]);

                $name = '_prefix_' . $p['name'] . '[' . $uploadId . ']';
                $display .= Html::hidden($name, ['value' => $upload['prefix']]);

                $name = '_tag_' . $p['name'] . '[' . $uploadId . ']';
                $display .= Html::hidden($name, ['value' => $tag['name']]);

                // show button to delete the upload
                $getEditor = 'null';
                if ($p['editor_id'] != '') {
                    $getEditor = "tinymce.get('" . $p['editor_id'] . "')";
                }
                $textTag = $tag['tag'];
                $domItems = "{0:'" . $upload['id'] . "', 1:'" . $upload['id'] . "'+'2'}";
                $deleteUpload = "deleteImagePasted($domItems, '$textTag', $getEditor)";
                $display .= '<span class="fa fa-times-circle pointer" onclick="' . $deleteUpload . '"></span>';

                $display .= "</p>";
            }
        }
        $display .= "</div>";

        if ($p['display']) {
            echo $display;
            return true;
        } else {
            return $display;
        }
    }


    /**
     * @since 0.85
     *
     * @return string
     **/
    public static function generateImageName()
    {
        return 'pastedImage' . str_replace('-', '', Html::convDateTime(date('Y-m-d', time())));
    }


    /**
     * Display choice matrix
     *
     * @since 0.85
     * @param $columns   array   of column field name => column label
     * @param $rows      array    of field name => array(
     *      'label' the label of the row
     *      'columns' an array of specific information regaring current row
     *                and given column indexed by column field_name
     *                 * a string if only have to display a string
     *                 * an array('value' => ???, 'readonly' => ???) that is used to Dropdown::showYesNo()
     * @param $options   array   possible:
     *       'title'         of the matrix
     *       'first_cell'    the content of the upper-left cell
     *       'row_check_all' set to true to display a checkbox to check all elements of the row
     *       'col_check_all' set to true to display a checkbox to check all elements of the col
     *       'rand'          random number to use for ids
     *
     * @return integer random value used to generate the ids
     **/
    public static function showCheckboxMatrix(array $columns, array $rows, array $options = [])
    {

        $param['title']                = '';
        $param['first_cell']           = '&nbsp;';
        $param['row_check_all']        = false;
        $param['col_check_all']        = false;
        $param['rotate_column_titles'] = false;
        $param['rand']                 = mt_rand();
        $param['table_class']          = 'tab_cadre_fixehov';
        $param['cell_class_method']    = null;

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $param[$key] = $val;
            }
        }

        $cb_options = ['title' => __s('Check/uncheck all')];

        $number_columns = (count($columns) + 1);
        if ($param['row_check_all']) {
            $number_columns += 1;
        }
        $width = round(100 / $number_columns);
        echo "\n<table class='" . $param['table_class'] . "' aria-label='Selectable Items'>\n";

        if (!empty($param['title'])) {
            echo "\t<tr>\n";
            echo "\t\t<th colspan='$number_columns'>" . $param['title'] . "</th>\n";
            echo "\t</tr>\n";
        }

        echo "\t<tr class='tab_bg_1'>\n";
        echo "\t\t<td>" . $param['first_cell'] . "</td>\n";
        foreach ($columns as $col_name => $column) {
            $nb_cb_per_col[$col_name] = [
               'total'   => 0,
               'checked' => 0
            ];
            $col_id                   = Html::cleanId('col_label_' . $col_name . '_' . $param['rand']);

            echo "\t\t<td class='center b";
            if ($param['rotate_column_titles']) {
                echo " rotate";
            }
            echo "' id='$col_id' width='$width%'>";
            if (!is_array($column)) {
                $columns[$col_name] = $column = ['label' => $column];
            }
            if (
                isset($column['short'])
                && isset($column['long'])
            ) {
                echo $column['short'];
                self::showToolTip($column['long'], ['applyto' => $col_id]);
            } else {
                echo $column['label'];
            }
            echo "</td>\n";
        }
        if ($param['row_check_all']) {
            $col_id = Html::cleanId('col_of_table_' . $param['rand']);
            echo "\t\t<td class='center";
            if ($param['rotate_column_titles']) {
                echo " rotate";
            }
            echo "' id='$col_id'>" . __('Select/unselect all') . "</td>\n";
        }
        echo "\t</tr>\n";

        foreach ($rows as $row_name => $row) {
            if ((!is_string($row)) && (!is_array($row))) {
                continue;
            }

            echo "\t<tr class='tab_bg_1'>\n";

            if (is_string($row)) {
                echo "\t\t<th colspan='$number_columns'>$row</th>\n";
            } else {
                $row_id = Html::cleanId('row_label_' . $row_name . '_' . $param['rand']);
                if (isset($row['class'])) {
                    $class = $row['class'];
                } else {
                    $class = '';
                }
                echo "\t\t<td class='b $class' id='$row_id'>";
                if (!empty($row['label'])) {
                    echo $row['label'];
                } else {
                    echo "&nbsp;";
                }
                echo "</td>\n";

                $nb_cb_per_row = [
                   'total'   => 0,
                   'checked' => 0
                ];

                foreach ($columns as $col_name => $column) {
                    $class = '';
                    if ((!empty($row['class'])) && (!empty($column['class']))) {
                        if (is_callable($param['cell_class_method'])) {
                            $class = $param['cell_class_method']($row['class'], $column['class']);
                        }
                    } elseif (!empty($row['class'])) {
                        $class = $row['class'];
                    } elseif (!empty($column['class'])) {
                        $class = $column['class'];
                    }

                    echo "\t\t<td class='center $class'>";

                    // Warning: isset return false if the value is NULL ...
                    if (array_key_exists($col_name, $row['columns'])) {
                        $content = $row['columns'][$col_name];
                        if (
                            is_array($content)
                            && array_key_exists('checked', $content)
                        ) {
                            if (!array_key_exists('readonly', $content)) {
                                $content['readonly'] = false;
                            }
                            $content['massive_tags'] = [];
                            if ($param['row_check_all']) {
                                $content['massive_tags'][] = 'row_' . $row_name . '_' . $param['rand'];
                            }
                            if ($param['col_check_all']) {
                                $content['massive_tags'][] = 'col_' . $col_name . '_' . $param['rand'];
                            }
                            if ($param['row_check_all'] && $param['col_check_all']) {
                                $content['massive_tags'][] = 'table_' . $param['rand'];
                            }
                            $content['name'] = $row_name . "[$col_name]";
                            $content['id']   = Html::cleanId('cb_' . $row_name . '_' . $col_name . '_' .
                               $param['rand']);
                            Html::showCheckbox($content);
                            $nb_cb_per_col[$col_name]['total']++;
                            $nb_cb_per_row['total']++;
                            if ($content['checked']) {
                                $nb_cb_per_col[$col_name]['checked']++;
                                $nb_cb_per_row['checked']++;
                            }
                        } elseif (is_string($content)) {
                            echo $content;
                        } else {
                            echo "&nbsp;";
                        }
                    } else {
                        echo "&nbsp;";
                    }

                    echo "</td>\n";
                }
            }
            if (
                ($param['row_check_all'])
                && (!is_string($row))
                && ($nb_cb_per_row['total'] > 1)
            ) {
                $cb_options['criterion']    = ['tag_for_massive' => 'row_' . $row_name . '_' .
                   $param['rand']];
                $cb_options['massive_tags'] = 'table_' . $param['rand'];
                $cb_options['id']           = Html::cleanId('cb_checkall_row_' . $row_name . '_' .
                   $param['rand']);
                $cb_options['checked']      = ($nb_cb_per_row['checked']
                   > ($nb_cb_per_row['total'] / 2));
                echo "\t\t<td class='center'>" . Html::getCheckbox($cb_options) . "</td>\n";
            }

            echo "\t</tr>\n";
        }

        if ($param['col_check_all']) {
            echo "\t<tr class='tab_bg_1'>\n";
            echo "\t\t<td>" . __('Select/unselect all') . "</td>\n";
            foreach ($columns as $col_name => $column) {
                echo "\t\t<td class='center'>";
                if ($nb_cb_per_col[$col_name]['total'] > 1) {
                    $cb_options['criterion']    = ['tag_for_massive' => 'col_' . $col_name . '_' .
                       $param['rand']];
                    $cb_options['massive_tags'] = 'table_' . $param['rand'];
                    $cb_options['id']           = Html::cleanId('cb_checkall_col_' . $col_name . '_' .
                       $param['rand']);
                    $cb_options['checked']      = ($nb_cb_per_col[$col_name]['checked']
                       > ($nb_cb_per_col[$col_name]['total'] / 2));
                    echo Html::getCheckbox($cb_options);
                } else {
                    echo "&nbsp;";
                }
                echo "</td>\n";
            }

            if ($param['row_check_all']) {
                $cb_options['criterion']    = ['tag_for_massive' => 'table_' . $param['rand']];
                $cb_options['massive_tags'] = '';
                $cb_options['id']           = Html::cleanId('cb_checkall_table_' . $param['rand']);
                echo "\t\t<td class='center'>" . Html::getCheckbox($cb_options) . "</td>\n";
            }
            echo "\t</tr>\n";
        }

        echo "</table>\n";

        return $param['rand'];
    }



    /**
     * This function provides a mecanism to send html form by ajax
     *
     * @param string $selector selector of a HTML form
     * @param string $success  jacascript code of the success callback
     * @param string $error    jacascript code of the error callback
     * @param string $complete jacascript code of the complete callback
     *
     * @see https://api.jquery.com/jQuery.ajax/
     *
     * @since 9.1
     **/
    public static function ajaxForm($selector, $success = "console.log(html);", $error = "console.error(html)", $complete = '')
    {
        echo Html::scriptBlock("
      $(function() {
         var lastClicked = null;
         $('input[type=submit], button[type=submit]').click(function(e) {
            e = e || event;
            lastClicked = e.target || e.srcElement;
         });

         $('$selector').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            var formData = form.closest('form').serializeArray();
            //push submit button
            formData.push({
               name: $(lastClicked).attr('name'),
               value: $(lastClicked).val()
            });

            $.ajax({
               url: form.attr('action'),
               type: form.attr('method'),
               data: formData,
               success: function(html) {
                  $success
               },
               error: function(html) {
                  $error
               },
               complete: function(html) {
                  $complete
               }
            });
         });
      });
      ");
    }

    /**
     * In this function, we redefine 'window.alert' javascript function
     * by a jquery-ui dialog equivalent (but prettier).
     *
     * @since 9.1
     **/
    public static function redefineAlert()
    {

        echo self::scriptBlock("
      window.old_alert = window.alert;
      window.alert = function(message, caption) {
         // Don't apply methods on undefined objects... ;-) #3866
         if(typeof message == 'string') {
            message = message.replace('\\n', '<br>');
         }
         caption = caption || '" . _sn('Information', 'Information', 1) . "';
         $('<div></div>').html(message).dialog({
            title: caption,
            buttons: {
               " . __s('OK') . ": function() {
                  $(this).dialog('close');
               }
            },
            dialogClass: 'glpi_modal',
            open: function(event, ui) {
               $(this).parent().prev('.ui-widget-overlay').addClass('glpi_modal');
               $(this).next('div').find('button').focus();
            },
            close: function(){
               $(this).remove();
            },
            draggable: true,
            modal: true,
            resizable: false,
            width: 'auto'
         });
      };");
    }


    /**
     * Summary of confirmCallback
     * Is a replacement for Javascript native confirm function
     * Beware that native confirm is synchronous by nature (will block
     * browser waiting an answer from user, but that this is emulating the confirm behaviour
     * by using callbacks functions when user presses 'Yes' or 'No' buttons.
     *
     * @since 9.1
     *
     * @param $msg            string      message to be shown
     * @param $title          string      title for dialog box
     * @param $yesCallback    string      function that will be called when 'Yes' is pressed
     *                                    (default null)
     * @param $noCallback     string      function that will be called when 'No' is pressed
     *                                    (default null)
     **/
    public static function jsConfirmCallback($msg, $title, $yesCallback = null, $noCallback = null)
    {

        return "
         // the Dialog and its properties.
         $('<div></div>').dialog({
            open: function(event, ui) { $('.ui-dialog-titlebar-close').hide(); },
            close: function(event, ui) { $(this).remove(); },
            resizable: false,
            modal: true,
            title: '" . Toolbox::addslashes_deep($title) . "',
            buttons: {
               '" . __s('Yes') . "': function () {
                     $(this).dialog('close');
                     " . ($yesCallback !== null ? '(' . $yesCallback . ')()' : '') . "
                  },
               '" . __s('No') . "': function () {
                     $(this).dialog('close');
                     " . ($noCallback !== null ? '(' . $noCallback . ')()' : '') . "
                  }
            }
         }).text('" . Toolbox::addslashes_deep($msg) . "');
      ";
    }


    /**
     * In this function, we redefine 'window.confirm' javascript function
     * by a jquery-ui dialog equivalent (but prettier).
     * This dialog is normally asynchronous and can't return a boolean like naive window.confirm.
     * We manage this behavior with a global variable 'confirmed' who watchs the acceptation of dialog.
     * In this case, we trigger a new click on element to return the value (and without display dialog)
     *
     * @since 9.1
     */
    public static function redefineConfirm()
    {
        global $CFG_GLPI;

        $confirmLabel = _x('button', 'Confirm');
        $cancelLabel = _x('button', 'Cancel');
        echo self::scriptBlock(
            <<<JS
          var confirmed = false;
          var lastClickedElement;

          // store last clicked element on dom
          $(document).click(function(event) {
              lastClickedElement = $(event.target);
          });

          // asynchronous confirm dialog with jquery ui
          var newConfirm = function(message, caption) {
            $.getScript('{$CFG_GLPI["root_doc"]}/node_modules/jquery-ui/dist/jquery-ui.min.js', function() {
                 message = message.replace('\\n', '<br>');
                 caption = caption || '';

                 $('<div></div>').html(message).dialog({
                    title: caption,
                    dialogClass: 'fixed glpi_modal',
                    buttons: {
                       {$confirmLabel}: function () {
                          $(this).dialog('close');
                          confirmed = true;

                          //trigger click on the same element (to return true value)
                          lastClickedElement.click();

                          // re-init confirmed (to permit usage of 'confirm' function again in the page)
                          // maybe timeout is not essential ...
                          setTimeout(function(){  confirmed = false; }, 100);
                       },
                       $cancelLabel: function () {
                          $(this).dialog('close');
                          confirmed = false;
                       }
                    },
                    open: function(event, ui) {
                       $(this).parent().prev('.ui-widget-overlay').addClass('glpi_modal');
                    },
                    close: function () {
                        $(this).remove();
                    },
                    draggable: true,
                    modal: true,
                    resizable: false,
                    width: 'auto'
                 });
              });
          };

          window.nativeConfirm = window.confirm;

          // redefine native 'confirm' function
          window.confirm = function (message, caption) {
             // if watched var isn't true, we can display dialog
             if(!confirmed) {
                // call asynchronous dialog
                newConfirm(message, caption);
             }

             // return early
             return confirmed;
          };
        JS
        );
    }


    /**
     * Summary of jsAlertCallback
     * Is a replacement for Javascript native alert function
     * Beware that native alert is synchronous by nature (will block
     * browser waiting an answer from user, but that this is emulating the alert behaviour
     * by using a callback function when user presses 'Ok' button.
     *
     * @since 9.1
     *
     * @param $msg          string   message to be shown
     * @param $title        string   title for dialog box
     * @param $okCallback   string   function that will be called when 'Ok' is pressed
     *                               (default null)
     **/
    public static function jsAlertCallback($msg, $title, $okCallback = null)
    {
        return "
         // Dialog and its properties.
         $('<div></div>').dialog({
            open: function(event, ui) { $('.ui-dialog-titlebar-close').hide(); },
            close: function(event, ui) { $(this).remove(); },
            resizable: false,
            modal: true,
            title: '" . Toolbox::addslashes_deep($title) . "',
            buttons: {
               '" . __s('OK') . "': function () {
                     $(this).dialog('close');
                     " . ($okCallback !== null ? '(' . $okCallback . ')()' : '') . "
                  }
            }
         }).text('" . Toolbox::addslashes_deep($msg) . "');
         ";
    }


    /**
     * Get image html tag for image document.
     *
     * @param int    $document_id  identifier of the document
     * @param int    $width        witdh of the final image
     * @param int    $height       height of the final image
     * @param bool   $addLink      boolean, do we need to add an anchor link
     * @param string $more_link    append to the link (ex &test=true)
     *
     * @return string
     *
     * @since 9.4.3
     **/
    public static function getImageHtmlTagForDocument($document_id, $width, $height, $addLink = true, $more_link = "")
    {
        global $CFG_GLPI;

        $document = new Document();
        if (!$document->getFromDB($document_id)) {
            return '';
        }

        $base_path = $CFG_GLPI['root_doc'];
        if (isCommandLine()) {
            $base_path = parse_url($CFG_GLPI['url_base'], PHP_URL_PATH);
        }

        // Add only image files : try to detect mime type
        $ok   = false;
        $mime = '';
        if (isset($document->fields['filepath'])) {
            $fullpath = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
            $mime = Toolbox::getMime($fullpath);
            $ok   = Toolbox::getMime($fullpath, 'image');
        }

        if (!($ok || empty($mime))) {
            return '';
        }

        $out = '';
        if ($addLink) {
            $out .= '<a '
               . 'href="' . $base_path . '/front/document.send.php?docid=' . $document_id . $more_link . '" '
               . 'target="_blank" '
               . '>';
        }
        $out .= '<img ';
        if (isset($document->fields['tag'])) {
            $out .= 'alt="' . $document->fields['tag'] . '" ';
        }
        $out .= 'width="' . $width . '" '
           . 'src="' . $base_path . '/front/document.send.php?docid=' . $document_id . $more_link . '" '
           . '/>';
        if ($addLink) {
            $out .= '</a>';
        }

        return $out;
    }

    /**
     * Get copyright message in HTML (used in footers)
     * @since 9.1
     * @param boolean $withVersion include GLPI version ?
     * @return string HTML copyright
     */
    public static function getCopyrightMessage($withVersion = true)
    {
        $message = "<a href=\"https://www.itsm-ng.com\" target=\"_blank\" class=\"copyright\">";
        $message .= "ITSM-NG ";
        // if required, add GLPI version (eg not for login page)
        if ($withVersion) {
            $message .= ITSM_VERSION . " ";
        }
        $message .= "Copyright (C) " . ITSM_YEAR . " ITSM-NG and contributors" .
           "</a>";
        return $message;
    }

    /**
     * A a required javascript lib
     *
     * @param string|array $name Either a know name, or an array defining lib
     *
     * @return void
     */
    public static function requireJs($name)
    {
        global $CFG_GLPI, $PLUGIN_HOOKS;

        if (isset($_SESSION['glpi_js_toload'][$name])) {
            //already in stack
            return;
        }
        switch ($name) {
            case 'clipboard':
                $_SESSION['glpi_js_toload'][$name][] = 'js/clipboard.js';
                break;
            case 'tinymce':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/tinymce.js';
                break;
            case 'planning':
                $_SESSION['glpi_js_toload'][$name][] = 'js/planning.js';
                break;
            case 'flatpickr':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/flatpickr.js';
                if (isset($_SESSION['glpilanguage'])) {
                    $filename = "public/lib/flatpickr/l10n/" .
                       strtolower($CFG_GLPI["languages"][$_SESSION['glpilanguage']][3]) . ".js";
                    if (file_exists(GLPI_ROOT . '/' . $filename)) {
                        $_SESSION['glpi_js_toload'][$name][] = $filename;
                        break;
                    }
                }
                break;
            case 'fullcalendar':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/fullcalendar.js';
                if (isset($_SESSION['glpilanguage'])) {
                    foreach ([2, 3] as $loc) {
                        $filename = "public/lib/fullcalendar/locales/" .
                           strtolower($CFG_GLPI["languages"][$_SESSION['glpilanguage']][$loc]) . ".js";
                        if (file_exists(GLPI_ROOT . '/' . $filename)) {
                            $_SESSION['glpi_js_toload'][$name][] = $filename;
                            break;
                        }
                    }
                }
                break;
            case 'jstree':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/jstree.js';
                break;
            case 'gantt':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/jquery-gantt.js';
                break;
            case 'kanban':
                $_SESSION['glpi_js_toload'][$name][] = 'js/kanban.js';
                $_SESSION['glpi_js_toload'][$name][] = 'lib/jqueryplugins/jquery.ui.touch-punch.js';
                break;
            case 'rateit':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/jquery.rateit.js';
                break;
            case 'fileupload':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/file-type.js';
                break;
            case 'charts':
                $_SESSION['glpi_js_toload']['charts'][] = 'public/lib/chartist.js';
                break;
            case 'notifications_ajax':
                $_SESSION['glpi_js_toload']['notifications_ajax'][] = 'js/notifications_ajax.js';
                break;
            case 'fuzzy':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/fuzzy.js';
                $_SESSION['glpi_js_toload'][$name][] = 'js/fuzzysearch.js';
                break;
            case 'sortable':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/sortable.js';
                break;
            case 'rack':
                $_SESSION['glpi_js_toload'][$name][] = 'js/rack.js';
                $_SESSION['glpi_js_toload'][$name][] = 'lib/jqueryplugins/jquery.ui.touch-punch.js';
                break;
            case 'leaflet':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/leaflet.js';
                break;
            case 'log_filters':
                $_SESSION['glpi_js_toload'][$name][] = 'js/log_filters.js';
                break;
            case 'codemirror':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/codemirror.js';
                break;
            case 'photoswipe':
                $_SESSION['glpi_js_toload'][$name][] = 'public/lib/photoswipe.js';
                break;
            case 'hotkeys':
                $_SESSION['glpi_js_toload'][$name][] = 'js/hotkeys.js';
                break;
            default:
                $found = false;
                if (isset($PLUGIN_HOOKS['javascript']) && isset($PLUGIN_HOOKS['javascript'][$name])) {
                    $found = true;
                    $jslibs = $PLUGIN_HOOKS['javascript'][$name];
                    if (!is_array($jslibs)) {
                        $jslibs = [$jslibs];
                    }
                    foreach ($jslibs as $jslib) {
                        $_SESSION['glpi_js_toload'][$name][] = $jslib;
                    }
                }
                if (!$found) {
                    Toolbox::logError("JS lib $name is not known!");
                }
        }
    }


    /**
     * Load javascripts
     *
     * @return void
     */
    private static function loadJavascript()
    {
        global $CFG_GLPI, $PLUGIN_HOOKS;

        //load on demand scripts
        if (isset($_SESSION['glpi_js_toload'])) {
            foreach ($_SESSION['glpi_js_toload'] as $key => $script) {
                if (is_array($script)) {
                    foreach ($script as $s) {
                        echo Html::script($s);
                    }
                } else {
                    echo Html::script($script);
                }
                unset($_SESSION['glpi_js_toload'][$key]);
            }
        }

        //locales for js libraries
        if (isset($_SESSION['glpilanguage'])) {
            // select2
            $filename = "public/lib/select2/js/i18n/" .
               $CFG_GLPI["languages"][$_SESSION['glpilanguage']][2] . ".js";
            if (file_exists(GLPI_ROOT . '/' . $filename)) {
                echo Html::script($filename);
            }
        }

        // transfer core variables to javascript side
        echo self::getCoreVariablesForJavascript(Session::getLoginUserID() !== false);

        // Some Javascript-Functions which we may need later
        echo Html::script('js/common.js');
        self::redefineAlert();
        self::redefineConfirm();

        if (isset($CFG_GLPI['notifications_ajax']) && $CFG_GLPI['notifications_ajax'] && !Session::isImpersonateActive()) {
            $options = [
               'interval'  => ($CFG_GLPI['notifications_ajax_check_interval'] ? $CFG_GLPI['notifications_ajax_check_interval'] : 5) * 1000,
               'sound'     => $CFG_GLPI['notifications_ajax_sound'] ? $CFG_GLPI['notifications_ajax_sound'] : false,
               'icon'      => ($CFG_GLPI["notifications_ajax_icon_url"] ? $CFG_GLPI['root_doc'] . $CFG_GLPI['notifications_ajax_icon_url'] : false),
               'user_id'   => Session::getLoginUserID()
            ];
            $js = "$(function() {
            notifications_ajax = new GLPINotificationsAjax(" . json_encode($options) . ");
            notifications_ajax.start();
         });";
            echo Html::scriptBlock($js);
        }

        // add Ajax display message after redirect
        Html::displayAjaxMessageAfterRedirect();

        // Add specific javascript for plugins
        if (isset($PLUGIN_HOOKS['add_javascript']) && count($PLUGIN_HOOKS['add_javascript'])) {
            foreach ($PLUGIN_HOOKS["add_javascript"] as $plugin => $files) {
                $plugin_root_dir = Plugin::getPhpDir($plugin, true);
                $plugin_web_dir  = Plugin::getWebDir($plugin, false);
                if (!Plugin::isPluginActive($plugin)) {
                    continue;
                }
                $version = Plugin::getInfo($plugin, 'version');
                if (!is_array($files)) {
                    $files = [$files];
                }
                foreach ($files as $file) {
                    if (file_exists($plugin_root_dir . "/$file")) {
                        echo Html::script("$plugin_web_dir/$file", ['version' => $version]);
                    } else {
                        Toolbox::logWarning("$file file not found from plugin $plugin!");
                    }
                }
            }
        }

        if (file_exists(GLPI_ROOT . "/js/analytics.js")) {
            echo Html::script("js/analytics.js");
        }
    }


    /**
     * transfer some var of php to javascript
     * (warning, don't expose all keys of $CFG_GLPI, some shouldn't be available client side)
     *
     * @param bool $full if false, don't expose all variables from CFG_GLPI (only url_base & root_doc)
     *
     * @since 9.5
     * @return string
     */
    public static function getCoreVariablesForJavascript(bool $full = false)
    {
        global $CFG_GLPI;

        $cfg_glpi = "var CFG_GLPI  = {
         'url_base': '" . (isset($CFG_GLPI['url_base']) ? $CFG_GLPI["url_base"] : '') . "',
         'root_doc': '" . $CFG_GLPI["root_doc"] . "',
      };";

        if ($full) {
            $debug = (isset($_SESSION['glpi_use_mode'])
               && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE ? true : false);
            $cfg_glpi = "var CFG_GLPI  = " . json_encode(Config::getSafeConfig(true), $debug ? JSON_PRETTY_PRINT : 0) . ";";
        }

        $plugins_path = [];
        foreach (Plugin::getPlugins() as $key) {
            $plugins_path[$key] = Plugin::getWebDir($key, false);
        }
        $plugins_path = 'var GLPI_PLUGINS_PATH = ' . json_encode($plugins_path) . ';';

        return self::scriptBlock("
         $cfg_glpi
         $plugins_path
      ");
    }

    /**
     * Get a stylesheet or javascript path, minified if any
     * Return minified path if minified file exists and not in
     * debug mode, else standard path
     *
     * @param string $file_path File path part
     *
     * @return string
     */
    private static function getMiniFile($file_path)
    {
        $debug = (isset($_SESSION['glpi_use_mode'])
           && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE ? true : false);

        $file_minpath = str_replace(['.css', '.js'], ['.min.css', '.min.js'], $file_path);
        if (file_exists(GLPI_ROOT . '/' . $file_minpath)) {
            if (!$debug || !file_exists(GLPI_ROOT . '/' . $file_path)) {
                return $file_minpath;
            }
        }

        return $file_path;
    }

    /**
     * Return prefixed URL
     *
     * @since 9.2
     *
     * @param string $url Original URL (not prefixed)
     *
     * @return string
     */
    final public static function getPrefixedUrl($url)
    {
        global $CFG_GLPI;
        $prefix = $CFG_GLPI['root_doc'];
        if (substr($url, 0, 1) != '/') {
            $prefix .= '/';
        }
        return $prefix . $url;
    }

    /**
     * Add the HTML code to refresh the current page at a define interval of time
     *
     * @param int|false   $timer    The time (in minute) to refresh the page
     * @param string|null $callback A javascript callback function to execute on timer
     *
     * @return string
     */
    public static function manageRefreshPage($timer = false, $callback = null)
    {
        if (!$timer) {
            $timer = $_SESSION['glpirefresh_views'] ?? 0;
        }

        if ($callback === null) {
            $callback = 'window.location.reload()';
        }

        $text = "";
        if ($timer > 0) {
            // set timer to millisecond from minutes
            $timer = $timer * MINUTE_TIMESTAMP * 1000;

            // call callback function to $timer interval
            $text = self::scriptBlock("window.setInterval(function() {
               $callback
            }, $timer);");
        }

        return $text;
    }

    /**
     * Manage events from js/fuzzysearch.js
     *
     * @since 9.2
     *
     * @param string $action action to switch (should be actually 'getHtml' or 'getList')
     *
     * @return string
     */
    public static function fuzzySearch($action = '')
    {
        switch ($action) {
            case 'getHtml':
                return <<<HTML
                <div id='fuzzysearch' class='card'>
                    <div class="card-header">
                        <input type='text' placeholder='Start typing to find a menu'>
                        <button class="btn"><i class='fa fa-2x fa-times' aria-hidden='true'></i></button>
                    </div>
                    <ul class='results'></ul>
                    </div>
                    <div class='ui-widget-overlay ui-front fuzzymodal' style='z-index: 100;'>
                </div>

            HTML;

            default:
                $fuzzy_entries = [];

                // retrieve menu
                foreach ($_SESSION['glpimenu'] as $firstlvl) {
                    if (isset($firstlvl['content'])) {
                        foreach ($firstlvl['content'] as $menu) {
                            if (isset($menu['title']) && strlen($menu['title']) > 0) {
                                $fuzzy_entries[] = [
                                   'url'   => $menu['page'],
                                   'title' => $firstlvl['title'] . " > " . $menu['title']
                                ];

                                if (isset($menu['options'])) {
                                    foreach ($menu['options'] as $submenu) {
                                        if (isset($submenu['title']) && strlen($submenu['title']) > 0) {
                                            $fuzzy_entries[] = [
                                               'url'   => $submenu['page'],
                                               'title' => $firstlvl['title'] . " > " .
                                                  $menu['title'] . " > " .
                                                  $submenu['title']
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if (isset($firstlvl['default'])) {
                        if (strlen($menu['title']) > 0) {
                            $fuzzy_entries[] = [
                               'url'   => $firstlvl['default'],
                               'title' => $firstlvl['title']
                            ];
                        }
                    }
                }

                // return the entries to ajax call
                return json_encode($fuzzy_entries);
                break;
        }
    }

    /**
     * Manage the shortcut js/hotkeys.js
     *
     *
     */

    public static function hotkeys()
    {
        global $CFG_GLPI;

        $user = new User();
        $user->getFromDB(session::getLoginUserID());
        $currentShortcut = json_decode($user->fields["access_custom_shortcuts"], true);
        unset($currentShortcut["DCRoom"]);
        unset($currentShortcut["update"]);
        foreach ($currentShortcut as $name => $shortcut) {
            if (is_subclass_of($name, "CommonGLPI")) {
                //echo $name . " - ". $shortcut. nl2br("<br>") ;
                $url = Toolbox::getItemTypeFormURL($name);
                if ($name == "Accessibility") {
                    $url = $CFG_GLPI['root_doc'] . "/front/preference.php";
                } // Redirect accessibility to preference
                echo Html::scriptBlock('hotkeys(' . "'$shortcut'" . ',function() {
                                    location.replace(' . "'$url'" . ');
                                 });
            ');
            }
        }
    }

    /**
     * Return Twig ITSM "bottom" (help, parameters, logout) menu
     *
     * @return array
     *
     * @since 2.0.0
     *
     */
    private static function getBottomMenu($full): array
    {
        global $CFG_GLPI;

        $noAuto = (isset($_SESSION['glpiextauth']) && $_SESSION['glpiextauth']);

        $username = '';
        if (Session::getLoginUserID()) {
            $username = formatUserName(
                0,
                $_SESSION["glpiname"],
                $_SESSION["glpirealname"],
                $_SESSION["glpifirstname"],
                0,
                20
            );
        }

        // check user id : header used for display messages when session logout
        $can_update = false;
        if (Config::canUpdate()) {
            $can_update = true;
            $is_debug_active = $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE;
        }

        /// Bookmark load
        Ajax::createSlidePanel(
            'showSavedSearches',
            [
              'title'     => __('Saved searches'),
              'url'       => $CFG_GLPI['root_doc'] . '/ajax/savedsearch.php?action=show',
              'icon'      => '/pics/menu_config.png',
              'icon_url'  => SavedSearch::getSearchURL(),
              'icon_txt'  => __('Manage saved searches')
            ]
        );

        $sanitizedURL = URL::sanitizeURL("https://www.itsm-ng.org/");

        /// Search engine
        $show_search = $CFG_GLPI['allow_search_global'];
        $template_path = 'topmenu.twig';
        $twig_vars =   [
                          "root_doc"        => $CFG_GLPI['root_doc'],  "noAUTO"       => $noAuto,
                          "username"        => $username,              "can_update"   => $can_update,
                          "is_debug_active" => isset($is_debug_active) ? $is_debug_active : 0,       "show_search"  => $show_search,
                          "sanitizedURL"    => $sanitizedURL
                       ];
        return ["path" => $template_path, "args" => $twig_vars];
    }

    /**
     * Return ITSM main menu
     *
     * @param array   $options Option
     *
     * @since 2.0.0
     * @return array
     */
    public static function getMainMenu($sector, $item, $option): array
    {
        global $CFG_GLPI, $DB;

        // Generate array for menu and check right
        $menu    = self::generateMenuSession($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE);

        $menu = Plugin::doHookFunction("redefine_menus", $menu);
        $already_used_shortcut = ['1'];

        if (isset($_SESSION['glpiID'])) {
            $menu_favorites = $DB->request(
                [
                   'SELECT' => 'menu_favorite',
                   'FROM'   => 'glpi_users',
                   'WHERE'  => ['id' => $_SESSION["glpiID"]]
                ]
            );
            $menu_favorites = json_decode($menu_favorites->next()['menu_favorite'] ?? '{}', true);
            $menu_collapse = $DB->request(
                [
                 'SELECT' => 'menu_open',
                 'FROM'   => 'glpi_users',
                 'WHERE'  => ['id' => $_SESSION["glpiID"]]
                ]
            );
            $menu_collapse = json_decode($menu_collapse->next()['menu_open'] ?? '[]', true);
        } else {
            $menu_favorites = [];
            $menu_collapse = [];
        }

        $icons = [
           'favorite' => 'fa-star',
           'assets' => 'fa-warehouse',
           'helpdesk' => 'fa-hands-helping',
           'management' => 'fa-tasks',
           'tools' => 'fa-tools',
           'plugins' => 'fa-puzzle-piece',
           'admin' => 'fa-users-cog',
           'config' => 'fa-clipboard-list',
           'preference' => 'fa-cog'
        ];
        $favorite =
        ['favorite' => [
           'title' => __('Favorite'),
           'types' => [],
           'default' => 'none',
           'content' => [],
        ]];

        $menu = array_merge($favorite, $menu);
        // Format $menu to pass to Twig
        // print_r($menu);
        foreach ($menu as $part => $data) {
            $menu[$part]['show_menu'] = false;
            // Checks if menu contains a submenu
            if (isset($data['content']) && count($data['content']) || $part == 'favorite') {
                if (!is_null($menu_collapse)) {
                    $menu[$part]['is_open'] = in_array($part, $menu_collapse);
                }
                $menu[$part]['icon'] = $icons[$part];
                $menu[$part]['show_menu'] = true;
                $menu[$part]['class'] = (isset($menu[$sector]) && $menu[$sector]['title'] == $data['title']) ? "active" : "";
                $menu[$part]['link'] = (isset($data['default']) && !empty($data['default'])) ? $CFG_GLPI["root_doc"] . $data['default'] : "#";
                $menu[$part]['show_sub_menu'] = false;

                if (!isset($data['content'][0]) || $data['content'][0] !== true) { //false ?
                    $menu[$part]['show_sub_menu'] = true;
                    // list menu item
                    foreach ($data['content'] as $key => $val) {
                        // some menu arent arrays and should'nt be showed
                        if (!is_array($val)) {
                            continue;
                        }
                        $menu[$part]['content'][$key]['is_favorite'] = isset($menu_favorites[$part]) && in_array($key, $menu_favorites[$part]);
                        $menu[$part]['content'][$key]['part'] = $part;
                        $menu[$part]['content'][$key]['sub_menu_class'] = "";
                        $tmp_active_item  = explode("/", $item);
                        $active_item      = array_pop($tmp_active_item);

                        if (
                            isset($menu[$sector]['content'])
                            && isset($menu[$sector]['content'][$active_item])
                            && isset($val['title'])
                            && ($menu[$sector]['content'][$active_item]['title'] == $val['title'])
                        ) {
                            $menu[$part]['content'][$key]['sub_menu_class']  = "active";
                        }
                        $menu[$part]['content'][$key]['show_sub_menu']  = true;
                        if (
                            isset($val['page'])
                            && isset($val['title'])
                        ) {
                            $menu[$part]['content'][$key]['show_sub_menu']  = true;
                            $menu[$part]['content'][$key]['shortcut_attr'] = "";

                            if (isset($val['shortcut']) && !empty($val['shortcut'])) {
                                if (!isset($already_used_shortcut[$val['shortcut']])) {
                                    $val['shortcut_attr'] = " accesskey='" . $val['shortcut'] . "'";
                                    $already_used_shortcut[$val['shortcut']] = $val['shortcut'];
                                }
                                $menu[$part]['content'][$key]['title'] = $val['title'];
                            }

                            if (!isset($val['icon'])) {
                                $menu[$part]['content'][$key]['icon'] = "";
                            }
                        }
                    }
                }
            } else {
                unset($menu[$part]);
            }
        }

        // Display item
        $mainurl = 'central';
        $link = "";
        if (isset($menu[$sector])) {
            $link = (isset($menu[$sector]['default'])) ? $menu[$sector]['default'] : "/front/central.php";
        }
        $show_page = "";
        if (isset($menu[$sector]['content'][$item])) {
            // Title
            $show_page = isset($menu[$sector]['content'][$item]['page']);
        }

        //actions
        $links = [];
        // Item with Option case
        if (
            !empty($option)
            && isset($menu[$sector]['content'][$item]['options'][$option]['links'])
            && is_array($menu[$sector]['content'][$item]['options'][$option]['links'])
        ) {
            $links = $menu[$sector]['content'][$item]['options'][$option]['links'];
        } elseif (
            isset($menu[$sector]['content'][$item]['links'])
            && is_array($menu[$sector]['content'][$item]['links'])
        ) {
            // Without option case : only item lin
            $links = $menu[$sector]['content'][$item]['links'];
        }
        $twig_vars = [];
        if ($sector == "helpdesk" && Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {
            $opt                              = [];
            $opt['reset']                     = 'reset';
            $opt['criteria'][0]['field']      = 55; // validation status
            $opt['criteria'][0]['searchtype'] = 'equals';
            $opt['criteria'][0]['value']      = TicketValidation::WAITING;
            $opt['criteria'][0]['link']       = 'AND';

            $opt['criteria'][1]['field']      = 59; // validation aprobator
            $opt['criteria'][1]['searchtype'] = 'equals';
            $opt['criteria'][1]['value']      = Session::getLoginUserID();
            $opt['criteria'][1]['link']       = 'AND';

            $twig_vars['url_validate'] = $CFG_GLPI["root_doc"] . "/front/ticket.php?" . Toolbox::append_params($opt, '&amp;');
            $twig_vars['opt'] = $opt;
        }
        $template_path = 'nav/menu.twig';
        $twig_vars += [ "root_doc" => $CFG_GLPI['root_doc'], "menu" => $menu,
        "mainurl" => $mainurl, "show_page" => $show_page,
        "link" => $link, "item" => $item,
        "option" => $option, "sector" => $sector];
        $twig_vars['links'] = $links;

        $twig_vars['menu_small'] = $DB->request(
            [
                    'SELECT' => 'menu_small',
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['id' => $_SESSION["glpiID"]]
                 ]
        )->next()['menu_small'] ?? 'false';
        $twig_vars['menu_small'] = filter_var($twig_vars['menu_small'], FILTER_VALIDATE_BOOLEAN);

        // TODO: add profile selector
        // Profile selector
        // check user id : header used for display messages when session logout
        // if (Session::getLoginUserID()) {
        //    self::showProfileSelecter($CFG_GLPI["root_doc"] . "/front/$mainurl.php");
        // }
        return ["path" => $template_path, "args" => $twig_vars];
    }

    public static function addMenuFavorite($menu, $menu_name, $sub_menu_name): void
    {
        $menu['favorite'][] = $menu[$menu_name]['content'][$sub_menu_name];
    }

    /**
     * Invert the input color (usefull for label bg on top of a background)
     * inpiration: https://github.com/onury/invert-color
     *
     * @since  9.3
     *
     * @param  string  $hexcolor the color, you can pass hex color (prefixed or not by #)
     *                           You can also pass a short css color (ex #FFF)
     * @param  boolean $bw       default true, should we invert the color or return black/white function of the input color
     * @param  boolean $sb       default true, should we soft the black/white to a dark/light grey
     * @return string            the inverted color prefixed by #
     */
    public static function getInvertedColor($hexcolor = "", $bw = true, $sbw = true)
    {
        if (strpos($hexcolor, '#') !== false) {
            $hexcolor = trim($hexcolor, '#');
        }
        // convert 3-digit hex to 6-digits.
        if (strlen($hexcolor) == 3) {
            $hexcolor = $hexcolor[0] + $hexcolor[0]
               + $hexcolor[1] + $hexcolor[1]
               + $hexcolor[2] + $hexcolor[2];
        }
        if (strlen($hexcolor) != 6) {
            throw new Exception('Invalid HEX color.');
        }

        $r = hexdec(substr($hexcolor, 0, 2));
        $g = hexdec(substr($hexcolor, 2, 2));
        $b = hexdec(substr($hexcolor, 4, 2));

        if ($bw) {
            return ($r * 0.299 + $g * 0.587 + $b * 0.114) > 100
               ? ($sbw
                  ? '#303030'
                  : '#000000')
               : ($sbw
                  ? '#DFDFDF'
                  : '#FFFFFF');
        }
        // invert color components
        $r = 255 - $r;
        $g = 255 - $g;
        $b = 255 - $b;

        // pad each with zeros and return
        return "#"
           + str_pad($r, 2, '0', STR_PAD_LEFT)
           + str_pad($g, 2, '0', STR_PAD_LEFT)
           + str_pad($b, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Compile SCSS styleshet
     *
     * @param array $args Arguments. May contain:
     *                      - v: version to append (will default to GLPI_VERSION)
     *                      - debug: if present, will not use Crunched formatter
     *                      - file: filerepresentation  to load
     *                      - reload: force reload and recache
     *                      - nocache: do not use nor update cache
     *
     * @return string
     */
    public static function compileScss($args)
    {
        global $CFG_GLPI, $GLPI_CACHE;

        $ckey = 'css_';
        $ckey .= isset($args['v']) ? $args['v'] : ITSM_SCHEMA_VERSION;

        $scss = new Compiler();
        $scss->setFormatter('ScssPhp\ScssPhp\Formatter\Crunched');
        if (isset($args['debug'])) {
            $ckey .= '_sourcemap';
            $scss->setSourceMap(Compiler::SOURCE_MAP_INLINE);
            $scss->setSourceMapOptions(
                [
                  'sourceMapBasepath' => GLPI_ROOT . '/',
                  'sourceRoot'        => $CFG_GLPI['root_doc'] . '/',
                ]
            );
        }

        $file = isset($args['file']) ? $args['file'] : 'css/styles';

        $ckey .= '_' . $file;

        if (!Toolbox::endsWith($file, '.scss')) {
            // Prevent include of file if ext is not .scss
            $file .= '.scss';
        }

        // Requested file path
        $path = GLPI_ROOT . '/' . $file;

        // Alternate file path (prefixed by a "_", i.e. "_highcontrast.scss").
        $pathargs = explode('/', $file);
        $pathargs[] = '_' . array_pop($pathargs);
        $pathalt = GLPI_ROOT . '/' . implode('/', $pathargs);

        if (!file_exists($path) && !file_exists($pathalt)) {
            Toolbox::logWarning('Requested file ' . $path . ' does not exists.');
            return '';
        }
        if (!file_exists($path)) {
            $path = $pathalt;
        }

        // Prevent import of a file from ouside GLPI dir
        $path = realpath($path);
        if (!Toolbox::startsWith($path, realpath(GLPI_ROOT))) {
            Toolbox::logWarning('Requested file ' . $path . ' is outside GLPI file tree.');
            return '';
        }

        $import = '@import "' . $file . '";';
        $fckey = 'css_raw_file_' . $file;
        $file_hash = self::getScssFileHash($path);

        //check if files has changed
        if ($GLPI_CACHE->has($fckey)) {
            $cached_file_hash = $GLPI_CACHE->get($fckey);

            if ($file_hash != $cached_file_hash) {
                //file has changed
                Toolbox::logDebug("$file has changed, reloading");
                $args['reload'] = true;
                $GLPI_CACHE->set($fckey, $file_hash);
            }
        } else {
            Toolbox::logDebug("$file is new, loading");
            $GLPI_CACHE->set($fckey, $file_hash);
        }

        $scss->addImportPath(GLPI_ROOT);

        if ($GLPI_CACHE->has($ckey) && !isset($args['reload']) && !isset($args['nocache'])) {
            $css = $GLPI_CACHE->get($ckey);
        } else {
            $css = $scss->compile($import);
            if (!isset($args['nocache'])) {
                $GLPI_CACHE->set($ckey, $css);
            }
        }

        return $css;
    }

    /**
     * Returns SCSS file hash.
     * This function evaluates recursivly imports to compute a hash that represent the whole
     * contents of the final SCSS.
     *
     * @param string $filepath
     *
     * @return null|string
     */
    public static function getScssFileHash(string $filepath)
    {

        if (!is_file($filepath) || !is_readable($filepath)) {
            return null;
        }

        $contents = file_get_contents($filepath);
        $hash = md5($contents);

        $matches = [];
        preg_match_all('/@import\s+[\'"]([^\'"]*)[\'"];/', $contents, $matches);

        if (empty($matches)) {
            return $hash;
        }

        $basedir = dirname($filepath);
        foreach ($matches[1] as $import_url) {
            $has_extension = preg_match('/\.s?css$/', $import_url);
            $imported_filepath = $basedir . '/' . $import_url;
            if (!$has_extension && is_file($imported_filepath . '.scss')) {
                $imported_filepath = $imported_filepath . '.scss';
            }

            $hash .= self::getScssFileHash($imported_filepath);
        }

        return $hash;
    }

    /**
     * Get scss compilation path for given file.
     *
     * @return array
     */
    public static function getScssCompilePath($file)
    {
        return implode(
            DIRECTORY_SEPARATOR,
            [
              self::getScssCompileDir(),
              str_replace('/', '_', $file) . '.min.css',
            ]
        );
    }

    /**
     * Get scss compilation directory.
     *
     * @return string
     */
    public static function getScssCompileDir()
    {
        return GLPI_ROOT . '/css_compiled';
    }


    /**
     * Display impersonate banner if feature is currently used.
     *
     * @return void
     *
     * @deprecated 2.0.0
     */
    public static function displayImpersonateBanner()
    {

        if (!Session::isImpersonateActive()) {
            return;
        }

        echo '<div class="banner-impersonate">';
        echo '<form aria-label="Impersonate" name="form" method="post" action="' . User::getFormURL() . '">';
        echo sprintf(__('You are impersonating %s.'), $_SESSION['glpiname']);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo '<button type="submit" aria-label="Impersonate" name="impersonate" class="btn-linkstyled" value="0">';
        echo __s('Stop impersonating');
        echo '</button>';
        echo '</form>';
        echo '</div>';
    }


    /**
     * Get Twig impersonate banner if feature is currently used.
     *
     * @global array $CFG_GLPI
     * @return void
     *
     * @since 2.0.0
     */
    public static function getImpersonateBanner(): array
    {
        global $CFG_GLPI;

        if (!Session::isImpersonateActive()) {
            return [];
        }
        $user_form_url = User::getFormURL();
        $impersonate_name = $_SESSION['glpiname'];
        $csrf_token =  $_SESSION['_glpi_csrf_token'];
        $template_path = 'headers/utils/impersonate_banner.twig';
        $twig_vars = [
           "root_doc" => $CFG_GLPI['root_doc'],
           "user_form_url" => $user_form_url,
           "impersonate_name" => $impersonate_name,
           "csrf_token" => $csrf_token
        ];
        return ["path" => $template_path, "args" => $twig_vars];
    }
}
