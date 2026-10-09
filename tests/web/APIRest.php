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

namespace tests\units\Glpi\Api;

use APIBaseClass;
use Auth;
use Computer;
use DateTime;
use DOMDocument;
use DOMXPath;
use GuzzleHttp;
use GuzzleHttp\Exception\ClientException;
use Html;
use Infocom;
use ITILFollowup;
use itsmng\Database\Entity as OrmEntity;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NetworkNameRepository;
use itsmng\Database\Repository\UserRepository;
use Itsmng\Tests\Web\Deprecated\Computer_SoftwareLicense;
use Itsmng\Tests\Web\Deprecated\Computer_SoftwareVersion;
use Itsmng\Tests\Web\Deprecated\TicketFollowup;
use ProfileRight;
use Psr\Http\Message\ResponseInterface;
use Reservation;
use ReservationItem;
use RuntimeException;
use Software;
use SoftwareLicense;
use SoftwareVersion;
use Throwable;

/* Test for inc/api/api.class.php */

/**
 * @engine isolate
 */
class APIRest extends APIBaseClass
{
    protected function getLogFilePath(): string
    {
        return GLPI_LOG_DIR . "/php-errors.log";
    }

    public function beforeTestMethod($method)
    {
        global $CFG_GLPI;

        $logfile = $this->getLogFilePath();

        // Empty log file
        $file_updated = file_put_contents($logfile, "");
        $this->variable($file_updated)->isNotIdenticalTo(false);

        $this->http_client = new GuzzleHttp\Client();
        $this->base_uri    = trim((string) $CFG_GLPI['url_base_api'], "/")."/";

        parent::beforeTestMethod($method);
    }

    public function afterTestMethod($method)
    {
        $logfile = $this->getLogFilePath();

        try {
            parent::afterTestMethod($method);
        } finally {
            // Keep the existing error assertion, including cleanup requests.
            $this->string(file_get_contents($logfile))->isEmpty();
        }
    }

    /**
     * Check errors that are expected to happen on the API server side and thus
     * can't be caught directly from the unit tests
     *
     * @param array $expected_errors
     *
     * @return void
     */
    protected function checkServerSideError(array $expected_errors): void
    {
        $logfile = $this->getLogFilePath();
        $errors = file_get_contents($logfile);

        // Race conditions can happen with log flushing to disk, so some polling is required.
        if ($expected_errors !== []) {
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $missing_errors = array_filter(
                    $expected_errors,
                    static fn (string $error): bool => !str_contains($errors, $error)
                );
                if ($missing_errors === []) {
                    break;
                }

                usleep(100000);
                $errors = file_get_contents($logfile);
            }
        }

        foreach ($expected_errors as $error) {
            $this->string($errors)->contains($error);
        }

        // Clear error file
        file_put_contents($logfile, "");
    }

    protected function doHttpRequest($verb = "get", $relative_uri = "", $params = [])
    {
        if (!empty($relative_uri)) {
            $params['headers']['Content-Type'] = "application/json";
        }
        if (isset($params['multipart'])) {
            // Guzzle lib will automatically push the correct Content-type
            unset($params['headers']['Content-Type']);
        }
        $verb = strtolower((string) $verb);
        if (in_array($verb, ['get', 'post', 'delete', 'put', 'options', 'patch'])) {
            try {
                return $this->http_client->{$verb}(
                    $this->base_uri.$relative_uri,
                    $params
                );
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }

    protected function query(
        $resource = "",
        $params = [],
        $expected_codes = [200],
        $expected_symbol = '',
        bool $no_decode = false
    ) {
        if (!is_array($expected_codes)) {
            $expected_codes = [$expected_codes];
        }

        $verb         = isset($params['verb'])
                          ? $params['verb']
                          : 'GET';

        $resource_path  = parse_url((string) $resource, PHP_URL_PATH);
        $resource_query = parse_url((string) $resource, PHP_URL_QUERY);

        $relative_uri = (!in_array($resource_path, ['getItem', 'getItems', 'createItems',
                                               'updateItems', 'deleteItems'])
                           ? $resource_path.'/'
                           : '').
                        (isset($params['parent_itemtype'])
                           ? $params['parent_itemtype'].'/'
                           : '').
                        (isset($params['parent_id'])
                           ? $params['parent_id'].'/'
                           : '').
                        (isset($params['itemtype'])
                           ? $params['itemtype'].'/'
                           : '').
                        (isset($params['id'])
                           ? $params['id']
                           : '').
                        (!empty($resource_query)
                           ? '?' . $resource_query
                           : '');

        $expected_errors = $params['server_errors'] ?? [];

        unset(
            $params['itemtype'],
            $params['id'],
            $params['parent_itemtype'],
            $params['parent_id'],
            $params['verb'],
            $params['server_errors']
        );

        // launch query
        try {
            $res = $this->doHttpRequest($verb, $relative_uri, $params);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if (!in_array($response->getStatusCode(), $expected_codes)) {
                //throw exceptions not expected
                throw $e;
            }
            $this->array($expected_codes)->contains($response->getStatusCode());
            $body = json_decode($e->getResponse()->getBody());
            $this->array($body)
               ->hasKey('0')
               ->string[0]->isIdenticalTo($expected_symbol);
            return $body;
        }

        // retrieve data
        $body = $res->getBody();

        if ($no_decode) {
            $data = $body;
        } else {
            $data = json_decode((string) $body, true);
            if (is_array($data)) {
                $data['headers'] = $res->getHeaders();
            }
        }

        // common tests
        $this->variable($res)->isNotNull();
        $this->array($expected_codes)->contains($res->getStatusCode());
        $this->checkServerSideError($expected_errors);
        return $data;
    }

    /** @tags api */
    public function testFinancialReportUsesCommittedLicenseQuantities(): void
    {
        $marker = 'financial-http-' . bin2hex(random_bytes(12));
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $primary = null;
        $cleanup = [];
        try {
            $this->query('changeActiveEntities', ['verb' => 'POST',
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['entities_id' => $entity, 'is_recursive' => false]]);
            $browser = $this->reservationHttpLogin(TU_USER, TU_PASS);
            $response = $browser->get('front/central.php', ['query' => [
                'active_entity' => $entity, 'is_recursive' => 0,
            ]]);
            $this->integer($response->getStatusCode())->isIdenticalTo(200);
            $report = function () use ($browser): string {
                $path = 'front/report.infocom.conso.php';
                $response = $browser->get($path);
                $this->integer($response->getStatusCode())->isIdenticalTo(200);
                $document = new DOMDocument();
                @$document->loadHTML((string)$response->getBody());
                $xpath = new DOMXPath($document);
                $forms = $xpath->query('//form[.//input[@name="date1"] and .//input[@name="date2"]]');
                $this->integer($forms->length)->isIdenticalTo(1);
                $data = [];
                foreach ($xpath->query('.//input[@type="hidden"]', $forms->item(0)) as $input) {
                    $data[$input->getAttribute('name')] = $input->getAttribute('value');
                }
                $this->string($data['_glpi_csrf_token'])->isNotEmpty();
                $data['date1'] = '2090-01-01';
                $data['date2'] = '2090-01-31';
                $response = $browser->post($path, ['form_params' => $data,
                    'headers' => ['Referer' => (string)$browser->getConfig('base_uri') . $path]]);
                $this->integer($response->getStatusCode())->isIdenticalTo(200);
                $document = new DOMDocument();
                @$document->loadHTML((string)$response->getBody());
                $xpath = new DOMXPath($document);
                $headings = $xpath->query('//h3');
                $prefix = explode('%1$s', __('Total: Value=%1$s - Account net value=%2$s'))[0];
                $totals = [];
                foreach ($headings as $heading) {
                    if (str_starts_with(trim($heading->textContent), $prefix)) {
                        $totals[] = trim($heading->textContent);
                    }
                }
                $this->array($totals)->hasSize(1);
                return $totals[0];
            };
            $totalPrefix = static fn (float $value): string => explode('%2$s', str_replace(
                '%1$s',
                Html::formatNumber($value),
                __('Total: Value=%1$s - Account net value=%2$s')
            ))[0];
            // Admit the existing entity/date window before adding any committed fixtures.
            $this->string($report())->startWith($totalPrefix(0));
            $software = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'Software',
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['input' => ['name' => $marker, 'entities_id' => $entity]]], 201)['id'];
            foreach ([
                ['global', 3, '12.5000', '2090-01-01', null],
                ['individual', 5, '7.2500', '2090-01-02', null],
                ['global', -1, '4.1250', null, '2090-01-03'],
                ['global', 0, '2.5000', '2090-01-04', null],
                ['global', 10, '99.0000', '2089-12-31', '2090-02-01'],
            ] as $index => [$serial, $number, $value, $buy, $use]) {
                $license = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'SoftwareLicense',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                        'name' => $marker . '-' . $index, 'softwares_id' => $software, 'entities_id' => $entity,
                        'serial' => $serial, 'number' => $number,
                    ]]], 201)['id'];
                $financial = new Infocom();
                $existing = $financial->getFromDBforDevice('SoftwareLicense', $license);
                $input = ['itemtype' => 'SoftwareLicense', 'items_id' => $license,
                    'value' => $value, 'buy_date' => $buy, 'use_date' => $use,
                    'sink_type' => 1, 'sink_time' => 3, 'sink_coeff' => 2.0];
                $params = ['verb' => $existing ? 'PUT' : 'POST', 'itemtype' => 'Infocom',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => $input]];
                if ($existing) {
                    $params['id'] = $financial->getID();
                    $params['json']['input']['id'] = $financial->getID();
                }
                $this->query($existing ? 'updateItems' : 'createItems', $params, $existing ? 200 : 201);
            }
            // 12.5 * 3 + 7.25 + 4.125 + 2.5; the fifth license is outside both date bounds.
            $this->string($report())->startWith($totalPrefix(51.375));
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            // Recover exact owned descendants even if an HTTP response failed before returning its ID.
            try {
                foreach ((new Software())->find(['name' => $marker]) as $software) {
                    foreach ((new SoftwareLicense())->find(['softwares_id' => $software['id']]) as $license) {
                        foreach ((new Infocom())->find(['itemtype' => 'SoftwareLicense', 'items_id' => $license['id']]) as $financial) {
                            try {
                                $this->reservationHttpDelete('Infocom', (int)$financial['id']);
                            } catch (Throwable $error) {
                                $cleanup[] = $error;
                            }
                        }
                        try {
                            $this->reservationHttpDelete('SoftwareLicense', (int)$license['id']);
                        } catch (Throwable $error) {
                            $cleanup[] = $error;
                        }
                    }
                    try {
                        $this->reservationHttpDelete('Software', (int)$software['id']);
                    } catch (Throwable $error) {
                        $cleanup[] = $error;
                    }
                }
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        foreach ($cleanup as $error) {
            $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error);
        }
        if ($primary !== null) {
            throw $primary;
        }
    }

    /** @tags api */
    public function testReservationCreateItemsReturnsCompletedBookings(): void
    {
        $this->withReservationHttpItems(function (array $items): void {
            $user = (int)getItemByTypeName('User', TU_USER, true);
            $input = ['reservationitems_id' => $items[0], 'users_id' => $user,
                'begin' => '2031-04-01 09:00:00', 'end' => '2031-04-01 10:00:00', 'comment' => 'REST completed booking'];
            $post = function (array $value) {
                return $this->doHttpRequest('POST', 'Reservation/', [
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => $value], 'allow_redirects' => false, 'http_errors' => false,
                ]);
            };
            $response = $post($input);
            $this->integer($response->getStatusCode())->isIdenticalTo(201);
            $this->string($response->getHeaderLine('Location'))->notContains('reservation.php');
            $created = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->array($created)->hasKeys(['id', 'message']);
            $this->integer($created['id'])->isGreaterThan(0);
            $read = new Reservation();
            $this->boolean($read->getFromDB($created['id']))->isTrue();
            foreach ($input as $field => $value) {
                $this->variable($read->fields[$field])->isIdenticalTo($value);
            }
            $bulk = [];
            foreach ($items as $id) {
                $bulk[] = array_replace($input, ['reservationitems_id' => $id,
                    'begin' => '2031-04-02 09:00:00', 'end' => '2031-04-02 10:00:00']);
            }
            $response = $post($bulk);
            $this->integer($response->getStatusCode())->isIdenticalTo(201);
            $created = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->array($created)->hasSize(2);
            foreach ($created as $index => $row) {
                $this->integer($row['id'])->isGreaterThan(0);
                $this->boolean($read->getFromDB($row['id']))->isTrue();
                $this->integer($read->fields['reservationitems_id'])->isIdenticalTo($items[$index]);
                $this->string($read->fields['begin'])->isIdenticalTo($bulk[$index]['begin']);
            }
            $before = $this->reservationHttpRows($items);
            $response = $post($input); // Existing booking conflicts; no navigation or extra row.
            $this->integer($response->getStatusCode())->isIdenticalTo(400);
            $failure = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->string($failure[0])->isIdenticalTo('ERROR_GLPI_ADD');
            $this->string($failure[1])->contains(__('The required item is already reserved for this timeframe'));
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
            $response = $post(array_replace($input, ['end' => '2031-04-01 08:00:00']));
            $this->integer($response->getStatusCode())->isIdenticalTo(400);
            $failure = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->string($failure[0])->isIdenticalTo('ERROR_GLPI_ADD');
            $this->string($failure[1])->contains(__('Error in entering dates. The starting date is later than the ending date'));
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
            $response = $this->doHttpRequest('POST', 'Reservation/', [
                'json' => ['input' => $bulk], 'allow_redirects' => false, 'http_errors' => false,
            ]);
            $this->integer($response->getStatusCode())->isIdenticalTo(400);
            $failure = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->string($failure[0])->isIdenticalTo('ERROR_SESSION_TOKEN_MISSING');
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
        });
    }

    /** @tags api */
    public function testReservationCoreFormCompletesSingleAndPeriodicItems(): void
    {
        global $DB;
        $this->withReservationHttpItems(function (array $items) use ($DB): void {
            $browser = $this->reservationHttpLogin(TU_USER, TU_PASS);
            $user = (int)getItemByTypeName('User', TU_USER, true);
            $calendar = $browser->get('front/reservation.php?' . http_build_query([
                'reservationitems_id' => $items[0], 'mois_courant' => 5, 'annee_courante' => 2031,
            ]));
            $document = new DOMDocument();
            @$document->loadHTML((string)$calendar->getBody());
            $newForm = null;
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $href = $anchor->getAttribute('href');
                parse_str((string)parse_url($href, PHP_URL_QUERY), $parameters);
                if (str_ends_with((string)parse_url($href, PHP_URL_PATH), '/front/reservation.form.php')
                    && ($parameters['begin'] ?? '') === '2031-05-01 12:00:00') {
                    $newForm = $parameters;
                    break;
                }
            }
            $this->array($newForm)->isIdenticalTo(['id' => '', 'item' => [(string)$items[0] => (string)$items[0]],
                'begin' => '2031-05-01 12:00:00']);
            $single = $this->reservationHttpSubmit($browser, [$items[0]], $user, '2031-05-01', 'single');
            $target = $this->reservationHttpRedirect($single);
            $this->string(parse_url($target, PHP_URL_PATH))->endWith('/front/reservation.php');
            parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
            $this->array($query)->isIdenticalTo(['reservationitems_id' => (string)$items[0],
                'mois_courant' => '5', 'annee_courante' => '2031', 'reservation_added' => '1']);
            $rows = $this->reservationHttpRows($items);
            $this->array($rows)->hasSize(1);
            $this->string($rows[0]['comment'])->isIdenticalTo('single');
            $periodic = $this->reservationHttpSubmit(
                $browser,
                $items,
                $user,
                '2031-05-10',
                'periodic',
                ['type' => 'day', 'end' => '2031-05-12']
            );
            $target = $this->reservationHttpRedirect($periodic);
            $this->string(parse_url($target, PHP_URL_PATH))->endWith('/front/reservation.php');
            parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
            $this->array($query)->isIdenticalTo(['reservation_added' => '1']);
            $rows = $this->reservationHttpRows($items);
            $this->array($rows)->hasSize(7);
            $actual = [];
            foreach ($rows as $row) {
                $actual[] = [(int)$row['reservationitems_id'], $row['begin'], $row['end'], $row['comment']];
                // The controller logs only after add() returns. All seven must complete.
                $events = iterator_to_array($DB->request(['FROM' => 'glpi_events', 'WHERE' => [
                    'items_id' => $row['id'], 'type' => 'reservation', 'service' => 'inventory', 'level' => 4,
                ]]));
                $this->array($events)->hasSize(1);
            }
            $expected = [[$items[0], '2031-05-01 09:00:00', '2031-05-01 10:00:00', 'single']];
            foreach ($items as $id) {
                foreach (['10', '11', '12'] as $day) {
                    $expected[] = [$id, '2031-05-' . $day . ' 09:00:00', '2031-05-' . $day . ' 10:00:00', 'periodic'];
                }
            }
            sort($actual);
            sort($expected);
            $this->array($actual)->isIdenticalTo($expected);
            $before = $rows;
            try {
                $response = $this->doHttpRequest('PUT', 'ReservationItem/' . $items[1], [
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => ['id' => $items[1], 'is_active' => false]], 'http_errors' => false,
                ]);
                $this->integer($response->getStatusCode())->isIdenticalTo(200);
                $response = $browser->get('front/reservation.form.php?' . http_build_query([
                    'id' => '', 'item' => array_combine($items, $items), 'begin' => '2031-05-20 09:00:00',
                ]));
                $this->string(html_entity_decode((string)$response->getBody(), ENT_QUOTES | ENT_HTML5))
                    ->contains("You don't have permission to perform this action.")->notContains('name="resa[begin]"');
                $response = $this->doHttpRequest('POST', 'Reservation/', [
                    'headers' => ['Session-Token' => $this->session_token], 'http_errors' => false,
                    'allow_redirects' => false, 'json' => ['input' => ['reservationitems_id' => $items[1],
                        'users_id' => $user, 'begin' => '2031-05-20 09:00:00', 'end' => '2031-05-20 10:00:00']],
                ]);
                $this->integer($response->getStatusCode())->isIdenticalTo(400);
                $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
            } finally {
                $response = $this->doHttpRequest('PUT', 'ReservationItem/' . $items[1], [
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => ['id' => $items[1], 'is_active' => true]], 'http_errors' => false,
                ]);
                $this->integer($response->getStatusCode())->isIdenticalTo(200);
            }
            $missing = (int)$DB->getDoctrineConnection()->fetchOne('SELECT MAX(id) FROM glpi_reservationitems') + 1;
            $response = $browser->get('front/reservation.form.php?' . http_build_query([
                'id' => '', 'item' => [$items[0] => $items[0], $missing => $missing], 'begin' => '2031-05-20 09:00:00',
            ]));
            $this->string(html_entity_decode((string)$response->getBody(), ENT_QUOTES | ENT_HTML5))
                ->contains("You don't have permission to perform this action.")->notContains('name="resa[begin]"');
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
            $refused = $this->reservationHttpSubmit($browser, [$items[0]], $user, '2031-05-20', 'invalid csrf', [], true);
            $this->string((string)$refused->getBody())->notContains('reservation_added=1');
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
            $response = $this->reservationHttpSubmit($browser, [$items[0]], $user, '2031-05-01', 'conflict');
            $this->integer($response->getStatusCode())->isIdenticalTo(200);
            $this->string((string)$response->getBody())->contains('already reserved')->notContains('reservation_added=1');
            $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
        });
    }

    /** @tags api */
    public function testReservationHelpdeskRedirectAndMissingCreateRight(): void
    {
        global $DB;
        $this->withReservationHttpItems(function (array $items) use ($DB): void {
            $marker = 'reservation-http-' . bin2hex(random_bytes(12));
            $profile = $user = $outsideEntity = $outsideComputer = null;
            $primary = null;
            $cleanup = [];
            try {
                $profile = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'Profile',
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => ['name' => $marker, 'interface' => 'helpdesk']]], 201)['id'];
                $this->integer($profile)->isGreaterThan(0);
                ProfileRight::updateProfileRights($profile, ['reservation' => ReservationItem::RESERVEANITEM]);
                $item = new ReservationItem();
                $this->boolean($item->getFromDB($items[0]))->isTrue();
                $password = 'Reservation-' . bin2hex(random_bytes(12)) . '-9aA!';
                $user = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'User',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                        'name' => $marker, 'password' => $password, 'password2' => $password,
                        '_profiles_id' => $profile, '_entities_id' => $item->getEntityID(),
                        'entities_id' => $item->getEntityID(), '_is_recursive' => 0, 'authtype' => Auth::DB_GLPI,
                    ]]], 201)['id'];
                $this->integer($user)->isGreaterThan(0);
                $browser = $this->reservationHttpLogin($marker, $password);
                $outsideEntity = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'Entity',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                        'name' => $marker, 'entities_id' => $item->getEntityID(),
                    ]]], 201)['id'];
                $this->integer($outsideEntity)->isGreaterThan(0);
                // This entity did not exist when the administrator API session was opened.
                $this->query('changeActiveEntities', ['verb' => 'POST',
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['entities_id' => $item->getEntityID(), 'is_recursive' => true]]);
                $active = $this->query('getActiveEntities', [
                    'headers' => ['Session-Token' => $this->session_token]]);
                $this->array($active['active_entity']['active_entities'])->contains(['id' => $outsideEntity]);
                $outsideComputer = (int)$this->createComputer()->getID();
                $this->query('updateItems', ['verb' => 'PUT', 'itemtype' => 'Computer', 'id' => $outsideComputer,
                    'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => ['id' => $outsideComputer, 'entities_id' => $outsideEntity]]]);
                $outsideItem = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'ReservationItem',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                        'itemtype' => 'Computer', 'items_id' => $outsideComputer, 'entities_id' => $outsideEntity, 'is_active' => true,
                    ]]], 201)['id'];
                $this->integer($outsideItem)->isGreaterThan(0);
                $response = $browser->get('front/reservation.form.php?' . http_build_query([
                    'id' => '', 'item' => [$items[0] => $items[0], $outsideItem => $outsideItem], 'begin' => '2031-06-01 09:00:00',
                ]));
                $this->string(html_entity_decode((string)$response->getBody(), ENT_QUOTES | ENT_HTML5))
                    ->contains("You don't have permission to perform this action.")->notContains('name="resa[begin]"');
                $this->array($this->reservationHttpRows([$outsideItem]))->isEmpty();
                $response = $this->reservationHttpSubmit($browser, [$items[0]], $user, '2031-06-01', 'helpdesk');
                $target = $this->reservationHttpRedirect($response);
                $this->string(parse_url($target, PHP_URL_PATH))->endWith('/plugins/formcreator/front/reservation.php');
                parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
                $this->array($query)->isIdenticalTo(['reservationitems_id' => (string)$items[0],
                    'mois_courant' => '6', 'annee_courante' => '2031', 'reservation_added' => '1']);
                // Assert the preserved destination only: the external Formcreator controller is not installed here.
                $before = $this->reservationHttpRows($items);
                $this->array($before)->hasSize(1);
                $this->integer((int)$before[0]['users_id'])->isIdenticalTo($user);
                $session = $this->doHttpRequest('GET', 'initSession/', ['auth' => [$marker, $password],
                    'query' => ['get_full_session' => true]]);
                $this->integer($session->getStatusCode())->isIdenticalTo(200);
                $sessionData = json_decode((string)$session->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $this->string($sessionData['session']['glpiactiveprofile']['interface'])->isIdenticalTo('helpdesk');
                $this->array($sessionData['session']['glpiactiveprofile'])->notHasKey('computer');
                $this->array($sessionData['session']['glpiactiveentities'])->notContains($outsideEntity);
                $this->integer((int)$sessionData['session']['glpiactiveprofile']['reservation'])->isIdenticalTo(ReservationItem::RESERVEANITEM);
                $token = $sessionData['session_token'];
                try {
                    $foreign = ['reservationitems_id' => $items[0],
                        'users_id' => (int)getItemByTypeName('User', TU_USER, true),
                        'begin' => '2031-06-04 09:00:00', 'end' => '2031-06-04 10:00:00'];
                    $response = $this->doHttpRequest('POST', 'Reservation/', ['http_errors' => false,
                        'allow_redirects' => false, 'headers' => ['Session-Token' => $token],
                        'json' => ['input' => $foreign]]);
                    $this->integer($response->getStatusCode())->isIdenticalTo(400);
                    $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
                    foreach ([$items[0] => 201, $outsideItem => 400] as $endpoint => $status) {
                        $response = $this->doHttpRequest('POST', 'Reservation/', ['http_errors' => false,
                            'allow_redirects' => false, 'headers' => ['Session-Token' => $token], 'json' => ['input' => [
                                'reservationitems_id' => $endpoint, 'users_id' => $user,
                                'begin' => '2031-06-03 09:00:00', 'end' => '2031-06-03 10:00:00',
                            ]]]);
                        $this->integer($response->getStatusCode())->isIdenticalTo($status);
                        if ($status === 201) {
                            $created = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                            $this->integer($created['id'])->isGreaterThan(0);
                        }
                    }
                    $this->array($this->reservationHttpRows([$outsideItem]))->isEmpty();
                    $before = $this->reservationHttpRows($items);
                    $this->array($before)->hasSize(2);
                } finally {
                    $this->doHttpRequest('GET', 'killSession/', ['headers' => ['Session-Token' => $token]]);
                }
                // An administrator can choose the helpdesk borrower for the
                // same free interval that the borrower's foreign-owner request denied.
                $response = $this->doHttpRequest('POST', 'Reservation/', ['http_errors' => false,
                    'allow_redirects' => false, 'headers' => ['Session-Token' => $this->session_token],
                    'json' => ['input' => array_replace($foreign, ['users_id' => $user])]]);
                $this->integer($response->getStatusCode())->isIdenticalTo(201);
                $created = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $this->integer($created['id'])->isGreaterThan(0);
                $booking = new Reservation();
                $this->boolean($booking->getFromDB($created['id']))->isTrue();
                $this->integer($booking->fields['users_id'])->isIdenticalTo($user);
                $before = $this->reservationHttpRows($items);
                $this->array($before)->hasSize(3);
                ProfileRight::updateProfileRights($profile, ['reservation' => 0]);
                $browser = $this->reservationHttpLogin($marker, $password); // Fresh actual session reads the revoked right.
                $response = $browser->get('front/reservation.form.php?' . http_build_query([
                    'id' => '', 'item' => [$items[0] => $items[0]], 'begin' => '2031-06-02 09:00:00',
                ]));
                $this->string(html_entity_decode((string)$response->getBody(), ENT_QUOTES | ENT_HTML5))->contains("You don't have permission to perform this action.");
                $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
                $session = $this->doHttpRequest('GET', 'initSession/', ['auth' => [$marker, $password]]);
                $this->integer($session->getStatusCode())->isIdenticalTo(200);
                $token = json_decode((string)$session->getBody(), true, 512, JSON_THROW_ON_ERROR)['session_token'];
                try {
                    $response = $this->doHttpRequest('POST', 'Reservation/', ['http_errors' => false,
                        'allow_redirects' => false, 'headers' => ['Session-Token' => $token], 'json' => ['input' => [
                            'reservationitems_id' => $items[0], 'users_id' => $user,
                            'begin' => '2031-06-02 09:00:00', 'end' => '2031-06-02 10:00:00',
                        ]]]);
                    $this->integer($response->getStatusCode())->isIdenticalTo(400);
                    $this->array($this->reservationHttpRows($items))->isIdenticalTo($before);
                } finally {
                    $this->doHttpRequest('GET', 'killSession/', ['headers' => ['Session-Token' => $token]]);
                }
            } catch (Throwable $error) {
                $primary = $error;
            } finally {
                $cleanup = $this->cleanupReservationHttpFixtures($marker, $items, $outsideComputer);
            }
            foreach ($cleanup as $error) {
                $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error);
            }
            if ($primary !== null) {
                throw $primary;
            }
        });
    }

    /** @tags api */
    public function testReservationCleanupRestoresOwnedFixturesAfterScopeFailure(): void
    {
        $baseline = $this->reservationHttpFixtureIdentitySets();
        $marker = 'reservation-http-' . bin2hex(random_bytes(12));
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $computer = null;
        $items = [];
        $failure = new RuntimeException('Owned reservation operation failed after changing API scope.');
        $primary = null;
        try {
            $child = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'Entity',
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['input' => ['name' => $marker, 'entities_id' => $entity]]], 201)['id'];
            $this->integer($child)->isGreaterThan(0);
            $this->query('changeActiveEntities', ['verb' => 'POST',
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['entities_id' => $entity, 'is_recursive' => true]]);
            $computer = (int)$this->createComputer()->getID();
            $this->query('updateItems', ['verb' => 'PUT', 'itemtype' => 'Computer', 'id' => $computer,
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['input' => ['id' => $computer, 'entities_id' => $child]]]);
            $item = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'ReservationItem',
                'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                    'itemtype' => 'Computer', 'items_id' => $computer, 'entities_id' => $child, 'is_active' => true,
                ]]], 201)['id'];
            $this->integer($item)->isGreaterThan(0);
            $items[] = $item;
            $booking = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'Reservation',
                'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                    'reservationitems_id' => $item, 'users_id' => (int)getItemByTypeName('User', TU_USER, true),
                    'begin' => '2031-07-01 09:00:00', 'end' => '2031-07-01 10:00:00',
                ]]], 201)['id'];
            $this->integer($booking)->isGreaterThan(0);
            $this->query('changeActiveEntities', ['verb' => 'POST',
                'headers' => ['Session-Token' => $this->session_token],
                'json' => ['entities_id' => $entity, 'is_recursive' => false]]);
            $active = $this->query('getActiveEntities', [
                'headers' => ['Session-Token' => $this->session_token]]);
            $this->array($active['active_entity']['active_entities'])->notContains(['id' => $child]);
            // The same owner finally must work when an operation stops with its child out of scope.
            throw $failure;
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            foreach ($this->cleanupReservationHttpFixtures($marker, $items, $computer) as $error) {
                $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error);
            }
        }
        // A cleanup error stays visible instead of replacing or swallowing the original failure.
        if ($primary !== $failure && $primary !== null) {
            throw $primary;
        }
        $this->object($primary)->isIdenticalTo($failure);
        $this->array($this->reservationHttpFixtureIdentitySets())->isIdenticalTo($baseline);
    }

    private function reservationHttpFixtureIdentitySets(): array
    {
        global $DB;
        $ids = [];
        foreach (['Entity', 'Computer', 'User', 'Profile', 'ReservationItem', 'Reservation'] as $type) {
            $ids[$type] = [];
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $type::getTable(), 'ORDER' => 'id ASC']) as $row) {
                $ids[$type][] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /** Attempt every exact owned deletion, preserving each failure for the caller. */
    private function cleanupReservationHttpFixtures(string $marker, array $items, ?int $outsideComputer): array
    {
        global $DB;
        $cleanup = [];
        try {
            // The child can be committed before the operation refreshes its original API session.
            // Select the existing parent grant again; never change the helpdesk user's grants.
            $this->query('changeActiveEntities', ['verb' => 'POST',
                'headers' => ['Session-Token' => $this->session_token], 'json' => [
                    'entities_id' => (int)getItemByTypeName('Entity', '_test_root_entity', true),
                    'is_recursive' => true,
                ]]);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        try {
            $this->string(file_get_contents($this->getLogFilePath()))->isEmpty();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        // Bookings must be removed before the owned user; scope uses only the owned endpoints.
        $ownedBookings = [];
        try {
            $ownedBookings = $this->reservationHttpRows($items);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        foreach ($ownedBookings as $row) {
            try {
                $this->reservationHttpDelete('Reservation', (int)$row['id']);
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        try {
            $this->boolean($DB->delete('glpi_events', ['type' => 'system', 'service' => 'login',
                'message' => ['LIKE', '%' . $marker . '%']]))->isTrue();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        if ($outsideComputer !== null) {
            try {
                foreach ($DB->request(['FROM' => 'glpi_reservationitems',
                    'WHERE' => ['itemtype' => 'Computer', 'items_id' => $outsideComputer]]) as $row) {
                    $ownedBookings = [];
                    try {
                        $ownedBookings = $this->reservationHttpRows([(int)$row['id']]);
                    } catch (Throwable $error) {
                        $cleanup[] = $error;
                    }
                    foreach ($ownedBookings as $booking) {
                        try {
                            $this->reservationHttpDelete('Reservation', (int)$booking['id']);
                        } catch (Throwable $error) {
                            $cleanup[] = $error;
                        }
                    }
                    try {
                        $this->reservationHttpDelete('ReservationItem', (int)$row['id']);
                    } catch (Throwable $error) {
                        $cleanup[] = $error;
                    }
                }
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
            try {
                $this->reservationHttpDelete('Computer', $outsideComputer);
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        // Unique marker also recovers IDs if an HTTP assertion failed before assignment.
        foreach (['User', 'Profile', 'Entity'] as $type) {
            try {
                $model = new $type();
                foreach ($model->find(['name' => $marker]) as $row) {
                    $this->reservationHttpDelete($type, (int)$row['id']);
                }
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        return $cleanup;
    }

    private function reservationHttpRows(array $items): array
    {
        global $DB;
        return array_values(iterator_to_array($DB->request(['FROM' => 'glpi_reservations',
            'WHERE' => ['reservationitems_id' => $items], 'ORDER' => 'id ASC'])));
    }

    private function withReservationHttpItems(callable $operation): void
    {
        global $DB;
        $computers = [];
        $primary = null;
        $cleanup = [];
        try {
            $items = [];
            for ($i = 0; $i < 2; ++$i) {
                $computer = $this->createComputer(); // Base owner tracks the exact unique Computer even on failure.
                $computers[] = (int)$computer->getID();
                $data = $this->query('createItems', ['verb' => 'POST', 'itemtype' => 'ReservationItem',
                    'headers' => ['Session-Token' => $this->session_token], 'json' => ['input' => [
                        'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                        'entities_id' => $computer->fields['entities_id'], 'is_active' => true,
                    ]]], 201);
                $this->integer($data['id'])->isGreaterThan(0);
                $items[] = $data['id'];
            }
            $operation($items);
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            try {
                $this->string(file_get_contents($this->getLogFilePath()))->isEmpty();
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
            // Endpoint ownership also recovers rows whose failed HTTP response hid their ID.
            foreach ($computers as $id) {
                try {
                    foreach ($DB->request(['FROM' => 'glpi_reservationitems',
                        'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id]]) as $row) {
                        $ownedBookings = [];
                        try {
                            $ownedBookings = $this->reservationHttpRows([(int)$row['id']]);
                        } catch (Throwable $error) {
                            $cleanup[] = $error;
                        }
                        foreach ($ownedBookings as $booking) {
                            try {
                                $this->reservationHttpDelete('Reservation', (int)$booking['id']);
                            } catch (Throwable $error) {
                                $cleanup[] = $error;
                            }
                        }
                        $this->reservationHttpDelete('ReservationItem', (int)$row['id']);
                    }
                } catch (Throwable $error) {
                    $cleanup[] = $error;
                }
            }
        }
        foreach ($cleanup as $error) {
            $primary = $primary === null ? $error : new MutationCleanupFailure($primary, $error);
        }
        if ($primary !== null) {
            throw $primary;
        }
    }

    private function reservationHttpDelete(string $type, int $id): void
    {
        global $DB;
        $response = $this->doHttpRequest('DELETE', $type . '/' . $id, [
            'headers' => ['Session-Token' => $this->session_token], 'query' => ['force_purge' => true],
            'http_errors' => false, 'allow_redirects' => false,
        ]);
        $this->integer($response->getStatusCode())->isIdenticalTo(200);
        $this->boolean((new $type())->getFromDB($id))->isFalse();
        if ($type === 'Reservation') {
            // Event rows describe only this owned booking, including its public purge event.
            $this->boolean($DB->delete('glpi_events', ['type' => 'reservation', 'items_id' => $id]))->isTrue();
        }
    }

    private function reservationHttpLogin(string $name, string $password): GuzzleHttp\Client
    {
        $root = preg_replace('~/apirest\.php/?$~', '/', $this->base_uri);
        $browser = new GuzzleHttp\Client(['base_uri' => $root, 'cookies' => new GuzzleHttp\Cookie\CookieJar(),
            'allow_redirects' => false, 'http_errors' => false]);
        $response = $browser->get('index.php');
        $this->integer($response->getStatusCode())->isIdenticalTo(200);
        $document = new DOMDocument();
        @$document->loadHTML((string)$response->getBody());
        $data = [];
        foreach ($document->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('type') === 'hidden') {
                $data[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            if ($input->getAttribute('id') === 'login_name') {
                $data[$input->getAttribute('name')] = $name;
            }
            if ($input->getAttribute('type') === 'password') {
                $data[$input->getAttribute('name')] = $password;
            }
        }
        $this->string($data['_glpi_csrf_token'])->isNotEmpty();
        $response = $browser->post('front/login.php', ['form_params' => $data, 'headers' => ['Referer' => $root . 'index.php']]);
        $this->array([302, 303])->contains($response->getStatusCode());
        $this->string($response->getHeaderLine('Location'))->contains('front/');
        return $browser;
    }

    private function reservationHttpSubmit(
        GuzzleHttp\Client $browser,
        array $items,
        int $user,
        string $day,
        string $comment,
        array $periodicity = [],
        bool $invalidCsrf = false
    ): ResponseInterface {
        $query = ['id' => '', 'item' => array_combine($items, $items), 'begin' => $day . ' 09:00:00'];
        $path = 'front/reservation.form.php?' . http_build_query($query);
        $response = $browser->get($path);
        $this->integer($response->getStatusCode())->isIdenticalTo(200);
        $document = new DOMDocument();
        @$document->loadHTML((string)$response->getBody());
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[.//input[@name="items[' . $items[0] . ']"]]');
        $this->integer($forms->length)->isIdenticalTo(1);
        $data = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $forms->item(0)) as $input) {
            $data[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->string($data['_glpi_csrf_token'])->isNotEmpty();
        if ($invalidCsrf) {
            $data['_glpi_csrf_token'] = 'invalid-' . $data['_glpi_csrf_token'];
        }
        $data += ['add' => '1', 'users_id' => $user, 'comment' => $comment,
            'resa[begin]' => $day . ' 09:00:00', 'resa[end]' => $day . ' 10:00:00'];
        foreach ($periodicity as $key => $value) {
            $data['periodicity[' . $key . ']'] = $value;
        }
        return $browser->post('front/reservation.form.php', ['form_params' => $data,
            'headers' => ['Referer' => (string)$browser->getConfig('base_uri') . $path]]);
    }

    private function reservationHttpRedirect(ResponseInterface $response): string
    {
        $this->array([200, 302, 303])->contains($response->getStatusCode());
        $target = $response->getHeaderLine('Location');
        if ($target === '') {
            // Html::header may already have emitted the page: normal Html::redirect uses JS then exits.
            preg_match_all("~window\\.location='([^']+)';~", (string)$response->getBody(), $matches);
            // Html::redirect emits a Konqueror-only cache token first, then its ordinary target.
            $target = $matches[1] === [] ? '' : end($matches[1]);
        }
        $this->string($target)->contains('reservation_added=1');
        return html_entity_decode($target, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @tags api
     * @covers API::getItems
     */
    public function testDropdownCollectionPagination()
    {
        global $DB;

        $expected = [];
        foreach ($DB->request(['FROM' => 'glpi_specialstatuses', 'ORDER' => 'id ASC']) as $row) {
            $expected[] = ['id' => (int)$row['id']];
        }
        $this->integer(count($expected))->isGreaterThanOrEqualTo(4);

        $params = [
            'itemtype' => 'SpecialStatus',
            'headers' => ['Session-Token' => $this->session_token],
            'query' => [
                'sort' => 'id',
                'only_id' => true,
                'get_hateoas' => false,
                'range' => '0-9999',
            ],
        ];
        $all = $this->query('getItems', $params);
        $headers = $all['headers'];
        unset($all['headers']);
        $this->array($all)->isIdenticalTo($expected);
        $this->string($headers['Content-Range'][0])->isIdenticalTo(
            '0-' . (count($expected) - 1) . '/' . count($expected)
        );

        foreach (['ASC' => $expected, 'DESC' => array_reverse($expected)] as $order => $ordered) {
            $params['query']['order'] = $order;
            $params['query']['range'] = '2-3';
            $page = $this->query('getItems', $params, 206);
            $headers = $page['headers'];
            unset($page['headers']);
            $this->array($page)->isIdenticalTo(array_slice($ordered, 2, 2));
            $this->string($headers['Content-Range'][0])->isIdenticalTo('2-3/' . count($expected));
        }
    }

    /**
     * @tags api
     * @covers API::getItem
     */
    public function testNetworkPortAddressExpansion()
    {
        global $DB;

        $computer = $this->createComputer();
        $em = Orm::create($DB);
        $entity = $em->getReference(OrmEntity\Entity::class, (int)$computer->getEntityID());
        $ports = $subtypes = $names = $addresses = $networks = $links = [];
        try {
            for ($number = 1; $number <= 2; ++$number) {
                $port = new OrmEntity\NetworkPort();
                $port->entities = $entity;
                $port->itemtype = 'Computer';
                $port->items_id = (int)$computer->getID();
                $port->instantiation_type = 'NetworkPortEthernet';
                $port->logical_number = $number;
                $port->name = 'Expansion port ' . $number;
                $em->persist($ports[] = $port);
                $subtype = new OrmEntity\NetworkPortEthernet();
                $subtype->networkports_id = $port;
                $em->persist($subtypes[] = $subtype);
            }
            $em->flush();
            foreach ([$ports[0], $ports[0], $ports[1]] as $index => $port) {
                $name = new OrmEntity\NetworkName();
                $name->entities = $entity;
                $name->itemtype = 'NetworkPort';
                $name->items_id = $port->id;
                $name->name = ['Selected "name"', 'Later name', 'Empty address collection'][$index];
                $em->persist($names[] = $name);
            }
            $em->flush();
            foreach (['10.42.1.9', '198.51.100.9'] as $ip) {
                $address = new OrmEntity\IPAddress();
                $address->entities = $entity;
                $address->itemtype = 'NetworkName';
                $address->items_id = $names[0]->id;
                $address->name = $ip;
                $address->version = 4;
                $address->binary_2 = 65535;
                $address->binary_3 = (int)ip2long($ip);
                $em->persist($addresses[] = $address);
            }
            foreach (['255.255.0.0' => '10.42.0.0', '255.255.255.0' => '10.42.1.0'] as $mask => $address) {
                $network = new OrmEntity\IPNetwork();
                $network->entities = $entity;
                $network->name = 'Overlapping network ' . $mask;
                $network->completename = $network->name;
                $network->address = $address;
                $network->netmask = $mask;
                $network->gateway = '10.42.1.1';
                $network->comment = 'Expanded "membership"';
                $network->version = 4;
                $network->address_2 = $network->gateway_2 = 65535;
                $network->address_3 = (int)ip2long($address);
                $network->netmask_2 = 4294967295;
                $network->netmask_3 = (int)ip2long($mask);
                $network->gateway_3 = (int)ip2long($network->gateway);
                $em->persist($networks[] = $network);
            }
            $em->flush();
            foreach ($networks as $network) {
                $link = new OrmEntity\IPAddressIPNetwork();
                $link->ipaddresses = $addresses[0];
                $link->ipnetworks = $network;
                $em->persist($links[] = $link);
            }
            $em->flush();

            $read = Orm::create($DB);
            try {
                $repository = new NetworkNameRepository($read);
                $this->array($repository->apiDetailsForPorts([]))->isEmpty();
                $details = $repository->apiDetailsForPorts([$ports[0]->id, $ports[1]->id]);
                $this->integer($read->getUnitOfWork()->size())->isIdenticalTo(0);
                $this->array($details[$ports[0]->id]['IPAddress'])->hasSize(2);

                $data = $this->query('getItem', [
                    'itemtype' => 'Computer',
                    'id' => $computer->getID(),
                    'headers' => ['Session-Token' => $this->session_token],
                    'query' => ['with_networkports' => true],
                ]);
                $public = [];
                foreach ($data['_networkports']['NetworkPortEthernet'] as $port) {
                    $public[(int)$port['netport_id']] = $port;
                }
                $this->integer((int)$public[$ports[0]->id]['logical_number'])->isIdenticalTo(1);
                $this->array($public[$ports[0]->id])->hasKey('speed')->hasKey('mac');
                $name = $public[$ports[0]->id]['NetworkName'];
                $this->integer($name['id'])->isIdenticalTo($names[0]->id);
                $this->string($name['name'])->isIdenticalTo('Selected "name"');
                $this->array($name['IPAddress'])->hasSize(2);
                $this->array(array_column($name['IPAddress'], 'id'))->isIdenticalTo(
                    [(string)$addresses[0]->id, (string)$addresses[1]->id]
                );
                $this->array($name['IPAddress'][0]['IPNetwork'])->hasSize(2);
                foreach ($networks as $index => $network) {
                    $this->array($name['IPAddress'][0]['IPNetwork'][$index])->isIdenticalTo([
                        'id' => $network->id,
                        'completename' => $network->completename,
                        'name' => $network->name,
                        'address' => $network->address,
                        'netmask' => $network->netmask,
                        'gateway' => $network->gateway,
                        'ipnetworks_id' => null,
                        'comment' => $network->comment,
                    ]);
                }
                $this->array($name['IPAddress'][1]['IPNetwork'])->isEmpty();
                $this->array($public[$ports[1]->id]['NetworkName']['IPAddress'])->isEmpty();
                $this->variable($name['fqdns_id'])->isNull();
                $this->array($name['FQDN'])->isIdenticalTo(['id' => null, 'name' => null, 'fqdn' => null]);

                $names[0]->name = 'Current committed name';
                $networks[0]->gateway = '10.42.1.2';
                $networks[0]->gateway_3 = (int)ip2long($networks[0]->gateway);
                $em->flush();
                $current = $repository->apiDetailsForPorts([$ports[0]->id]);
                $this->string($current[$ports[0]->id]['name'])->isIdenticalTo($names[0]->name);
                $this->string($current[$ports[0]->id]['IPAddress'][0]['IPNetwork'][0]['gateway'])->isIdenticalTo($networks[0]->gateway);
                $this->integer($read->getUnitOfWork()->size())->isIdenticalTo(0);
            } finally {
                $read->clear();
            }
        } finally {
            // Respect real owning FK order, including scalar compatibility parent identities.
            foreach ([$links, $addresses, $names, $subtypes, $ports, $networks] as $owned) {
                foreach ($owned as $row) {
                    $em->remove($row);
                }
                $em->flush();
            }
            $em->clear();
            $this->query('deleteItems', [
                'itemtype' => 'Computer',
                'id' => $computer->getID(),
                'verb' => 'DELETE',
                'headers' => ['Session-Token' => $this->session_token],
                'query' => ['force_purge' => true],
            ]);
        }
    }

    /**
     * @tags api
     * @covers API::getItems
     */
    public function testUserCollectionDeletionSelectors()
    {
        global $DB;

        $name = '_api_deleted_selector_' . bin2hex(random_bytes(6));
        $headers = ['Session-Token' => $this->session_token];
        $created = $this->query('createItems', [
            'itemtype' => 'User',
            'verb' => 'POST',
            'headers' => $headers,
            'json' => ['input' => [
                'name' => $name,
                'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
                '_entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
                '_profiles_id' => 4,
                '_is_recursive' => 1,
            ]],
        ], 201);
        $this->integer((int)$created['id'])->isGreaterThan(0);
        $id = (int)$created['id'];
        $fixture = Orm::create($DB);
        $owned = null;
        $grants = [];
        try {
            // Real mapped JSON/native date values must not be compared by SQL DISTINCT.
            $root = $fixture->getReference(OrmEntity\Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $owned = new OrmEntity\User();
            $owned->name = $name . '_grant_visibility';
            $owned->entities = $root;
            $owned->access_custom_shortcuts = ['quoted' => 'native "JSON" value'];
            $owned->date_creation = new DateTime('2026-10-05 12:34:56');
            $fixture->persist($owned);
            $fixture->flush();
            $read = Orm::create($DB);
            try {
                $repository = new UserRepository($read);
                $options = ['is_deleted' => false, 'searchText' => ['id' => '^' . $owned->id . '$'],
                    'sort' => 'id', 'order' => 'ASC', 'start' => 0, 'list_limit' => 1];
                $all = $repository->apiPage($options, null);
                $this->integer($all['total'])->isIdenticalTo(1);
                $this->array($all['rows'])->hasSize(1);
                $this->string($all['rows'][0]['access_custom_shortcuts'])->isIdenticalTo(json_encode($owned->access_custom_shortcuts));
                $this->string($all['rows'][0]['date_creation'])->isIdenticalTo('2026-10-05 12:34:56');
                $this->integer($all['rows'][0]['is_deleted'])->isIdenticalTo(0);
                foreach (['native' => 1, 'not present' => 0, 'NULL' => 0] as $pattern => $total) {
                    $jsonFilter = $options;
                    $jsonFilter['searchText']['access_custom_shortcuts'] = $pattern;
                    $this->integer($repository->apiPage($jsonFilter, null)['total'])->isIdenticalTo($total);
                }
                foreach (['^0$', '^', '%0%', 'true'] as $booleanPattern) {
                    $filtered = $options;
                    $filtered['searchText']['is_deleted'] = $booleanPattern;
                    $this->integer($repository->apiPage($filtered, null)['total'])
                        ->isIdenticalTo($booleanPattern === 'true' ? 0 : 1);
                }
                $scope = ['entities' => [$root->id], 'ancestors' => [0]];
                $this->integer($repository->apiPage($options, $scope)['total'])->isIdenticalTo(0);
                foreach ([2, 4] as $profile) {
                    $grant = new OrmEntity\ProfileUser();
                    $grant->users = $owned;
                    $grant->profiles = $fixture->getReference(OrmEntity\Profile::class, $profile);
                    $grant->entities = $root;
                    $grant->is_recursive = false;
                    $fixture->persist($grants[] = $grant);
                }
                $fixture->flush();
                $page = $repository->apiPage($options, $scope);
                $this->integer($page['total'])->isIdenticalTo(1);
                $this->array(array_column($page['rows'], 'id'))->isIdenticalTo([$owned->id]);
                $parent = ['table' => 'glpi_entities', 'id' => $root->id,
                    'foreignKey' => 'entities_id', 'userForeignKey' => 'users_id', 'kind' => 'User'];
                $this->integer($repository->apiPage($options, $scope, $parent)['total'])->isIdenticalTo(1);
                $parent['id'] = 0;
                $this->integer($repository->apiPage($options, $scope, $parent)['total'])->isIdenticalTo(0);
                $parent = ['table' => 'glpi_profiles_users', 'id' => $grants[0]->id,
                    'foreignKey' => 'profiles_users_id', 'userForeignKey' => 'users_id', 'kind' => 'User'];
                $this->integer($repository->apiPage($options, $scope, $parent)['total'])->isIdenticalTo(1);
                $otherGrant = $fixture->getRepository(OrmEntity\ProfileUser::class)
                    ->findOneBy(['users' => $fixture->getReference(OrmEntity\User::class, $id)]);
                $this->object($otherGrant)->isInstanceOf(OrmEntity\ProfileUser::class);
                $parent['id'] = $otherGrant->id;
                $this->integer($repository->apiPage($options, $scope, $parent)['total'])->isIdenticalTo(0);
                $this->integer($repository->apiPage($options, ['entities' => [], 'ancestors' => [$root->id]])['total'])->isIdenticalTo(0);
                $this->array($repository->apiPage($options, ['entities' => [], 'ancestors' => [$root->id]])['rows'])->isEmpty();
                $options['start'] = 1;
                $this->integer($repository->apiPage($options, $scope)['total'])->isIdenticalTo(1);
                $this->array($repository->apiPage($options, $scope)['rows'])->isEmpty();
                $options['start'] = 0;

                // A recursive ancestor grant is allowed only within a nonempty active scope.
                foreach ($grants as $grant) {
                    $grant->entities = $fixture->getReference(OrmEntity\Entity::class, 0);
                }
                $fixture->flush();
                $this->integer($repository->apiPage($options, $scope)['total'])->isIdenticalTo(0);
                $grants[0]->is_recursive = true;
                $fixture->flush();
                $this->integer($repository->apiPage($options, $scope)['total'])->isIdenticalTo(1);
                $this->integer($repository->apiPage($options, ['entities' => [0], 'ancestors' => []])['total'])->isIdenticalTo(1);
                $this->integer($repository->apiPage($options, ['entities' => [$root->id], 'ancestors' => []])['total'])->isIdenticalTo(0);
                $this->integer($repository->apiPage($options, ['entities' => [], 'ancestors' => [0]])['total'])->isIdenticalTo(0);

                $owned->name = 'Current "committed" name ' . $name;
                $owned->access_custom_shortcuts = null;
                $fixture->flush();
                $options['sort'] = 'name';
                $options['order'] = 'DESC';
                $fresh = $repository->apiPage($options, $scope);
                $this->string($fresh['rows'][0]['name'])->isIdenticalTo($owned->name);
                $this->variable($fresh['rows'][0]['access_custom_shortcuts'])->isNull();
                $jsonFilter['searchText']['access_custom_shortcuts'] = 'NULL';
                $this->integer($repository->apiPage($jsonFilter, $scope)['total'])->isIdenticalTo(1);
                $jsonFilter['searchText']['access_custom_shortcuts'] = 'native';
                $this->integer($repository->apiPage($jsonFilter, $scope)['total'])->isIdenticalTo(0);
                $this->array($read->getUnitOfWork()->getIdentityMap()[OrmEntity\User::class] ?? [])->isEmpty();

                $public = $this->query('getItems', [
                    'itemtype' => 'User', 'headers' => $headers,
                    'query' => ['searchText' => ['id' => '^' . $owned->id . '$'],
                        'range' => '0-0', 'only_id' => true, 'get_hateoas' => false],
                ]);
                $this->string($public['headers']['Content-Range'][0])->isIdenticalTo('0-0/1');
                unset($public['headers']);
                $this->array($public)->isIdenticalTo([['id' => $owned->id]]);
            } finally {
                $read->clear();
            }
            $params = [
                'itemtype' => 'User',
                'headers' => $headers,
                'query' => [
                    'searchText' => ['id' => '^' . $id . '$'],
                    'only_id' => true,
                    'get_hateoas' => false,
                    'is_deleted' => 0,
                ],
            ];
            $active = $this->query('getItems', $params);
            unset($active['headers']);
            $this->array($active)->isIdenticalTo([['id' => $id]]);

            $this->query('deleteItems', [
                'itemtype' => 'User',
                'id' => $id,
                'verb' => 'DELETE',
                'headers' => $headers,
            ]);
            $active = $this->query('getItems', $params);
            unset($active['headers']);
            $this->array($active)->isEmpty();

            $params['query']['is_deleted'] = 1;
            $deleted = $this->query('getItems', $params);
            unset($deleted['headers']);
            $this->array($deleted)->isIdenticalTo([['id' => $id]]);

            $params['query']['is_deleted'] = 2;
            $this->query('getItems', $params, 400, 'ERROR');
        } finally {
            foreach ($grants as $grant) {
                $fixture->remove($grant);
            }
            $fixture->flush();
            if ($owned !== null && $owned->id !== null) {
                $fixture->remove($owned);
                $fixture->flush();
            }
            $fixture->clear();
            $this->query('deleteItems', [
                'itemtype' => 'User',
                'id' => $id,
                'verb' => 'DELETE',
                'headers' => $headers,
                'query' => ['force_purge' => true],
            ]);
        }
    }

    /**
     * @tags api
     * @covers API::getItems
     */
    public function testDropdownCollectionQueryFailure()
    {
        // A nonexistent filter field makes the physical SELECT fail on both providers.
        // The API must report an error rather than a successful empty collection.
        $response = $this->doHttpRequest('GET', 'SpecialStatus/', [
            'http_errors' => false,
            'headers' => ['Session-Token' => $this->session_token],
            'query' => [
                'get_hateoas' => false,
                'searchText' => ['_missing_api_collection_field' => 'diagnostic'],
            ],
        ]);
        $this->integer($response->getStatusCode())->isIdenticalTo(500);
        $body = json_decode((string)$response->getBody(), true);
        $this->array($body)->hasSize(2);
        $this->string($body[0])->isIdenticalTo('ERROR_SQL');
        $this->string($body[1])->isNotEmpty()
            ->notContains('_missing_api_collection_field')
            ->notContains('SELECT ')
            ->notContains('SQLSTATE');
    }

    /**
     * @tags   api
     * @covers API::cors
    **/
    public function testCORS()
    {
        $res = $this->doHttpRequest(
            'OPTIONS',
            '',
            ['headers' => [
                                               'Origin' => "http://localhost",
                                               'Access-Control-Request-Method'  => 'GET',
                                               'Access-Control-Request-Headers' => 'X-Requested-With'
                                           ]]
        );

        $this->variable($res)->isNotNull();
        $this->variable($res->getStatusCode())->isEqualTo(200);
        $headers = $res->getHeaders();
        $this->array($headers)
           ->hasKey('Access-Control-Allow-Methods')
           ->hasKey('Access-Control-Allow-Headers');

        $this->string($headers['Access-Control-Allow-Methods'][0])
           ->contains('GET')
           ->contains('PUT')
           ->contains('POST')
           ->contains('DELETE')
           ->contains('OPTIONS');

        $this->string($headers['Access-Control-Allow-Headers'][0])
           ->contains('origin')
           ->contains('content-type')
           ->contains('accept')
           ->contains('session-token')
           ->contains('authorization');
    }

    /**
     * @tags   api
     * @covers API::inlineDocumentation
    **/
    public function testInlineDocumentation()
    {
        $res = $this->doHttpRequest('GET');
        $this->variable($res)->isNotNull();
        $this->variable($res->getStatusCode())->isEqualTo(200);
        $headers = $res->getHeaders();
        $this->array($headers)->hasKey('Content-Type');
        $this->string($headers['Content-Type'][0])->isIdenticalTo('text/html; charset=UTF-8');
    }

    /**
     * @tags   api
     * @covers API::initSession
    **/
    public function initSessionCredentials()
    {
        $res = $this->doHttpRequest('GET', 'initSession/', ['auth' => [TU_USER, TU_PASS]]);

        $this->variable($res)->isNotNull();
        $this->variable($res->getStatusCode())->isEqualTo(200);
        $this->array($res->getHeader('content-type'))->contains('application/json; charset=UTF-8');

        $body = $res->getBody();
        $data = json_decode((string) $body, true);
        $this->variable($data)->isNotFalse();
        $this->array($data)->hasKey('session_token');
        $this->session_token = $data['session_token'];
    }

    /**
     * @tags   api
     * @covers API::initSession
    **/
    public function testInitSessionUserToken()
    {
        // retrieve personnal token of TU_USER user
        $user = new \User();
        $uid = getItemByTypeName('User', TU_USER, true);
        $this->boolean((bool)$user->getFromDB($uid))->isTrue();
        $token = isset($user->fields['api_token']) ? $user->fields['api_token'] : "";
        if (empty($token)) {
            $token = $user->getAuthToken('api_token');
        }

        $res = $this->doHttpRequest(
            'GET',
            'initSession?get_full_session=true',
            ['headers' => [
                                               'Authorization' => "user_token $token"
                                           ]]
        );

        $this->variable($res)->isNotNull();
        $this->variable($res->getStatusCode())->isEqualTo(200);

        $body = $res->getBody();
        $data = json_decode((string) $body, true);
        $this->variable($data)->isNotFalse();
        $this->array($data)->hasKey('session_token');
        $this->array($data)->hasKey('session');
        $this->integer((int) $data['session']['glpiID'])->isEqualTo($uid);
    }

    /**
     * @tags    api
     */
    public function testBadEndpoint()
    {
        parent::badEndpoint(400, 'ERROR_RESOURCE_NOT_FOUND_NOR_COMMONDBTM');

        $data = $this->query(
            'getItems',
            ['itemtype'        => 'badEndpoint',
                              'parent_id'       => 0,
                              'parent_itemtype' => 'Entity',
                              'headers'         => [
                              'Session-Token' => $this->session_token]],
            400,
            'ERROR_RESOURCE_NOT_FOUND_NOR_COMMONDBTM'
        );
    }

    /**
      * @tags    api
      */
    public function testUpdateItemWithIdInQueryString()
    {
        $computer = $this->createComputer();
        $computers_id = $computer->getID();

        $data = $this->query(
            'updateItems',
            ['itemtype' => 'Computer',
                              'id'       => $computers_id,
                              'verb'     => 'PUT',
                              'headers'  => [
                                 'Session-Token' => $this->session_token],
                              'json'     => [
                                 'input' => [
                                    'serial' => "abcdefg"]]]
        );

        $this->variable($data)->isNotFalse();

        $this->array($data)->hasKey('headers');
        unset($data['headers']);

        $computer = array_shift($data);
        $this->array($computer)
           ->hasKey($computers_id)
           ->hasKey('message');
        $this->boolean((bool)$computer[$computers_id])->isTrue();

        $computer = new \Computer();
        $this->boolean((bool)$computer->getFromDB($computers_id))->isTrue();
        $this->string($computer->fields['serial'])->isIdenticalTo('abcdefg');
    }


    /**
     * @tags    api
     */
    public function testUploadDocument()
    {
        // we will try to upload the README.md file
        $document_name = "My document uploaded by api";
        $filename      = "README.md";
        $filecontent   = file_get_contents($filename);

        $data = $this->query(
            'createItems',
            ['verb'      => 'POST',
                              'itemtype'  => 'Document',
                              'headers'   => [
                                'Session-Token' => $this->session_token
                              ],
                              'multipart' => [
                                // the document part
                                [
                                   'name'     => 'uploadManifest',
                                   'contents' => json_encode([
                                      'input' => [
                                         'name'       => $document_name,
                                         '_filename'  => [$filename],
                                      ]
                                   ])
                                ],
                                // the FILE part
                                [
                                   'name'     => 'filename[]',
                                   'contents' => $filecontent,
                                   'filename' => $filename
                                ]
                              ]],
            201
        );

        $this->array($data)
           ->hasKey('id')
           ->hasKey('message');
        $documents_id = $data['id'];
        $this->boolean(is_numeric($documents_id))->isTrue();
        $this->integer((int)$documents_id)->isGreaterThan(0);

        $document        = new \Document();
        $this->boolean((bool)$document->getFromDB($documents_id));

        $this->array($document->fields)->hasKeys(['name', 'filename', 'filepath']);
        if (is_string($document->fields['name']) && $document->fields['name'] !== '') {
            $this->string($document->fields['name'])->isIdenticalTo($document_name);
        }
        if (is_string($document->fields['filename']) && $document->fields['filename'] !== '') {
            $this->string($document->fields['filename'])->isIdenticalTo($filename);
        }

        $this->variable($document->fields['filepath'])->isNotFalse();
    }

    /**
     * @tags    api
     * @covers  API::updateItems
     */
    public function testUpdateItem()
    {
        //parent::testUpdateItem($session_token, $computers_id);

        //try to update an item without input
        $data = $this->query(
            'updateItems',
            ['itemtype' => 'Computer',
                    'verb'     => 'PUT',
                    'headers'  => ['Session-Token' => $this->session_token],
                    'json'     => []],
            400,
            'ERROR_JSON_PAYLOAD_INVALID'
        );
    }

    /**
     * @tags    api
     * @covers  API::getItems
     */
    public function testGetItemsCommonDBChild()
    {
        // test the case have DBChild not have entities_id
        $ticketTemplate = new \TicketTemplate();
        $ticketTMF = new \TicketTemplateMandatoryField();

        $tt_id = $ticketTemplate->add([
           'entities_id' => getItemByTypeName('Entity', '_test_child_1', true),
           'name'        => 'test'
        ]);
        $this->boolean((bool)$tt_id)->isTrue();

        $ttmf_id = $ticketTMF->add([
           'tickettemplates_id' => $tt_id,
           'num'                => 7
        ]);
        $this->boolean((bool)$ttmf_id)->isTrue();

        $data = $this->query(
            'getItems',
            ['query'     => [
                                 'searchText' => ['tickettemplates_id' => "^".$tt_id."$"]],
                              'itemtype'   => 'TicketTemplateMandatoryField',
                              'headers'    => ['Session-Token' => $this->session_token]],
            200
        );
        if (isset($data['headers'])) {
            unset($data['headers']);
        }
        $this->integer(count($data))->isEqualTo(1);
    }

    /**
     * @tags   api
     * @covers API::userPicture
     */
    public function testUserPicture()
    {
        $pic = "test_picture.png";
        $params = ['headers' => ['Session-Token' => $this->session_token]];
        $id = getItemByTypeName('User', 'itsm', true);
        $user = new \User();

        /**
         * Case 1: normal execution
         */

        // Copy pic to tmp folder so it can be set to a user
        copy("tests/$pic", GLPI_TMP_DIR . "/$pic");

        // Load GLPI user
        $this->boolean($user->getFromDB($id))->isTrue();

        // Set a pic URL
        $success = $user->update([
           'id'      => $id,
           '_picture' => [$pic],
        ]);
        $this->boolean($success)->isTrue();
        $this->boolean($user->getFromDB($id))->isTrue();

        // Get updated pic url
        $pic = $user->fields['picture'];
        if (!is_string($pic) || $pic === '') {
            $response = $this->query("User/$id/Picture", $params, 204);
            $this->variable($response)->isNull();
        } else {
            $this->string($pic)->isNotEmpty();

            // Check pic was moved correctly into _picture folder
            $this->boolean(file_exists(GLPI_PICTURE_DIR . "/$pic"))->isTrue();
            $file_content = file_get_contents(GLPI_PICTURE_DIR . "/$pic");
            $this->string($file_content)->isNotEmpty();

            // Request
            $response = $this->query("User/$id/Picture", $params, 200, '', true);
            $this->string($response->__toString())->isEqualTo($file_content);
        }

        /**
         * Case 2: user doens't exist
         */

        // Request
        $response = $this->query("User/99999999/Picture", $params, 400, "ERROR");
        $this->array($response)->hasSize(2);
        $this->string($response[1])->contains("Bad request: user with id '99999999' not found");

        /**
         * Case 3: user with no pictures
         */

        // Remove pic URL
        $success = $user->update([
           'id'             => $id,
           '_blank_picture' => true,
        ]);
        $this->boolean($success)->isTrue();

        // Request
        $response = $this->query("User/$id/Picture", $params, 204);
        $this->variable($response)->isNull();
    }

    protected function deprecatedProvider()
    {
        return [
           ['provider' => TicketFollowup::class],
           ['provider' => Computer_SoftwareVersion::class],
           ['provider' => Computer_SoftwareLicense::class],
        ];
    }

    /** Run a CRUD assertion with parents owned by this invocation and unconditional cleanup. */
    private function withDeprecatedFixture(string $provider, callable $test): void
    {
        $fixture = [
            'add' => $provider::getCurrentAddInput(),
            'create' => $provider::getDeprecatedAddInput(),
            'update' => $provider::getDeprecatedUpdateInput(),
            'inserted' => $provider::getExpectedAfterInsert(),
            'updated' => $provider::getExpectedAfterUpdate(),
        ];
        $itemtype = $provider::getCurrentType();
        $item = new $itemtype();
        $parents = [];
        $computers = [];
        $failure = null;
        $name = 'deprecated-api-' . bin2hex(random_bytes(12));

        try {
            if ($provider === TicketFollowup::class) {
                $fixture['add']['content'] .= " [$name]";
                $fixture['create']['content'] .= " [$name]";
                $fixture['inserted']['content'] = $fixture['create']['content'];
                $fixture['updated']['content'] = $fixture['add']['content'];
            }
            if ($provider === Computer_SoftwareVersion::class || $provider === Computer_SoftwareLicense::class) {
                $source = new Computer();
                $this->boolean($source->getFromDB($fixture['add']['items_id']))->isTrue();
                $entity = $source->fields['entities_id'];
                foreach (['source', 'target'] as $role) {
                    $computer = new Computer();
                    $id = $computer->add(['name' => "$name-$role", 'entities_id' => $entity]);
                    if (!is_int($id) || $id <= 0) {
                        throw new RuntimeException('Cannot create the owned deprecated API computer');
                    }
                    $parents[] = $computer;
                    $computers[] = $id;
                }
                $fixture['add']['items_id'] = $computers[0];
                $fixture['create']['computers_id'] = $computers[0];
                $fixture['update']['computers_id'] = $computers[1];
                $fixture['inserted']['items_id'] = $computers[0];
                $fixture['updated']['items_id'] = $computers[1];

                if ($provider === Computer_SoftwareVersion::class) {
                    $source_version = new SoftwareVersion();
                    $this->boolean($source_version->getFromDB($fixture['add']['softwareversions_id']))->isTrue();
                    $version = new SoftwareVersion();
                    $id = $version->add([
                        'name' => $name,
                        'softwares_id' => $source_version->fields['softwares_id'],
                        'entities_id' => $source_version->fields['entities_id'],
                    ]);
                    if (!is_int($id) || $id <= 0) {
                        throw new RuntimeException('Cannot create the owned deprecated API software version');
                    }
                    $parents[] = $version;
                    $fixture['add']['softwareversions_id'] = $id;
                    $fixture['create']['softwareversions_id'] = $id;
                    $fixture['inserted']['softwareversions_id'] = $id;
                    $fixture['updated']['softwareversions_id'] = $id;
                } else {
                    $source_license = new SoftwareLicense();
                    $this->boolean($source_license->getFromDB($fixture['add']['softwarelicenses_id']))->isTrue();
                    $license = new SoftwareLicense();
                    $id = $license->add([
                        'name' => $name,
                        'softwares_id' => $source_license->fields['softwares_id'],
                        'entities_id' => $source_license->fields['entities_id'],
                        'is_recursive' => $source_license->fields['is_recursive'],
                        'number' => $source_license->fields['number'],
                    ]);
                    if (!is_int($id) || $id <= 0) {
                        throw new RuntimeException('Cannot create the owned deprecated API software license');
                    }
                    $parents[] = $license;
                    $fixture['add']['softwarelicenses_id'] = $id;
                    $fixture['create']['softwarelicenses_id'] = $id;
                    $fixture['inserted']['softwarelicenses_id'] = $id;
                    $fixture['updated']['softwarelicenses_id'] = $id;
                }
            }
            $test($fixture, $item);
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            $cleanup_error = null;
            try {
                // A failed HTTP assertion can hide a newly inserted relation's ID. Both
                // endpoints are owned here, so this lookup cannot select shared rows.
                if ($computers !== []) {
                    $rows = $item->find(['itemtype' => 'Computer', 'items_id' => $computers]);
                } elseif ($item->getID() > 0) {
                    $rows = $item->find(['id' => $item->getID()]);
                } elseif ($provider === TicketFollowup::class) {
                    // Recover only this invocation's followup if POST inserted it before
                    // query() threw; the shared Ticket itself is never a cleanup target.
                    $rows = $item->find([
                        'itemtype' => 'Ticket', 'items_id' => $fixture['add']['items_id'],
                        'content' => [$fixture['add']['content'], $fixture['create']['content']],
                    ]);
                } else {
                    $rows = [];
                }
                foreach ($rows as $row) {
                    $this->boolean($item->delete(['id' => $row['id']], true))->isTrue();
                }
            } catch (Throwable $error) {
                $cleanup_error = $error;
            }
            foreach (array_reverse($parents) as $parent) {
                try {
                    $this->boolean($parent->delete(['id' => $parent->getID()], true))->isTrue();
                } catch (Throwable $error) {
                    $cleanup_error ??= $error;
                }
            }
            if ($cleanup_error !== null && $failure === null) {
                throw $cleanup_error;
            }
        }
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedGetItem(string $provider)
    {
        $this->withDeprecatedFixture($provider, function (array $fixture, $item) use ($provider): void {
            // Get params from provider
            $deprecated_itemtype = $provider::getDeprecatedType();
            $deprecated_fields   = $provider::getDeprecatedFields();
            $add_input           = $fixture['add'];

            $headers = ['Session-Token' => $this->session_token];

            // Insert data for tests
            $item_id = $item->add($add_input);
            $this->integer($item_id);

            // Call API
            $data = $this->query("$deprecated_itemtype/$item_id", [
               'headers' => $headers,
            ], 200);
            $this->array($data)
               ->hasSize(count($deprecated_fields) + 1) // + 1 for headers
               ->hasKeys($deprecated_fields);
        });
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedGetItems(string $provider)
    {
        $this->withDeprecatedFixture($provider, function (array $fixture, $item) use ($provider): void {
            // Get params from provider
            $deprecated_itemtype = $provider::getDeprecatedType();
            $deprecated_fields   = $provider::getDeprecatedFields();
            $add_input           = $fixture['add'];

            $headers = ['Session-Token' => $this->session_token];

            // Insert data for tests (we need at least one item)
            $item_id = $item->add($add_input);
            $this->integer($item_id);

            // Call API
            $data = $this->query("$deprecated_itemtype", [
               'headers' => $headers,
            ], [200, 206]);
            $this->array($data);
            unset($data["headers"]);

            foreach ($data as $row) {
                $this->array($row)
                   ->hasSize(count($deprecated_fields))
                   ->hasKeys($deprecated_fields);
            }
        });
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedCreateItems(string $provider)
    {
        $this->withDeprecatedFixture($provider, function (array $fixture, $item) use ($provider): void {
            // Get params from provider
            $deprecated_itemtype   = $provider::getDeprecatedType();
            $input                 = $fixture['create'];
            $expected_after_insert = $fixture['inserted'];

            $headers = ['Session-Token' => $this->session_token];

            // Call API
            $data = $this->query("$deprecated_itemtype", [
               'headers' => $headers,
               'verb'    => "POST",
               'json'    => ['input' => $input]
            ], 201);

            $this->integer($data['id']);
            $this->boolean($item->getFromDB($data['id']))->isTrue();

            foreach ($expected_after_insert as $field => $value) {
                $this->variable($item->fields[$field])->isEqualTo($value);
            }
        });
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedUpdateItems(string $provider)
    {
        $this->withDeprecatedFixture($provider, function (array $fixture, $item) use ($provider): void {
            // Get params from provider
            $deprecated_itemtype   = $provider::getDeprecatedType();
            $add_input             = $fixture['add'];
            $update_input          = $fixture['update'];
            $expected_after_update = $fixture['updated'];

            $headers = ['Session-Token' => $this->session_token];

            // Insert data for tests
            $item_id = $item->add($add_input);
            $this->integer($item_id);

            // Call API
            $this->query("$deprecated_itemtype/$item_id", [
               'headers' => $headers,
               'verb'    => "PUT",
               'json'    => ['input' => $update_input]
            ], 200);

            // Check expected values
            $this->boolean($item->getFromDB($item_id))->isTrue();

            foreach ($expected_after_update as $field => $value) {
                $this->variable($item->fields[$field])->isEqualTo($value);
            }
        });
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedDeleteItems(string $provider)
    {
        $this->withDeprecatedFixture($provider, function (array $fixture, $item) use ($provider): void {
            // Get params from provider
            $deprecated_itemtype   = $provider::getDeprecatedType();
            $add_input             = $fixture['add'];

            $headers = ['Session-Token' => $this->session_token];

            // Insert data for tests
            $item_id = $item->add($add_input);
            $this->integer($item_id);

            // Call API
            $this->query("$deprecated_itemtype/$item_id?force_purge=1", [
               'headers' => $headers,
               'verb'    => "DELETE",
            ], 200, "", true);

            $this->boolean($item->getFromDB($item_id))->isFalse();
        });
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedListSearchOptions(string $provider)
    {
        // Get params from provider
        $deprecated_itemtype   = $provider::getDeprecatedType();

        $headers = ['Session-Token' => $this->session_token];

        $data = $this->query("listSearchOptions/$deprecated_itemtype/", [
           'headers' => $headers,
        ]);

        $expected = file_get_contents(
            __DIR__ . "/../deprecated-searchoptions/$deprecated_itemtype.json"
        );
        $this->string($expected)->isNotEmpty();

        unset($data['headers']);
        $json_data = json_encode($data, JSON_PRETTY_PRINT);
        $this->string($json_data)->isEqualTo($expected);
    }

    /**
     * @dataProvider deprecatedProvider
     */
    public function testDeprecatedSearch(string $provider)
    {
        // Get params from provider
        $deprecated_itemtype       = $provider::getDeprecatedType();
        $deprecated_itemtype_query = $provider::getDeprecatedSearchQuery();
        $itemtype                  = $provider::getCurrentType();
        $itemtype_query            = $provider::getCurrentSearchQuery();

        $headers = ['Session-Token' => $this->session_token];

        $deprecated_data = $this->query(
            "search/$deprecated_itemtype?$deprecated_itemtype_query",
            ['headers' => $headers],
            [200, 206]
        );

        $data = $this->query(
            "search/$itemtype?$itemtype_query",
            ['headers' => $headers],
            [200, 206]
        );
        $this->string($deprecated_data['rawdata']['sql']['search'])
           ->isEqualTo($data['rawdata']['sql']['search']);
    }
}
