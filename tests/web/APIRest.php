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
use Itsmng\Tests\Web\Deprecated\Computer_SoftwareLicense;
use Itsmng\Tests\Web\Deprecated\Computer_SoftwareVersion;
use Itsmng\Tests\Web\Deprecated\TicketFollowup;
use GuzzleHttp;
use GuzzleHttp\Exception\ClientException;
use ITILFollowup;
use itsmng\Database\Entity as OrmEntity;
use itsmng\Database\Orm;

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

        // Check that no errors occured on the test server
        $this->string(file_get_contents($logfile))->isEmpty();
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
                $repository = new \itsmng\Database\Repository\NetworkNameRepository($read);
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
            $owned->date_creation = new \DateTime('2026-10-05 12:34:56');
            $fixture->persist($owned);
            $fixture->flush();
            $read = Orm::create($DB);
            try {
                $repository = new \itsmng\Database\Repository\UserRepository($read);
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
                $source = new \Computer();
                $this->boolean($source->getFromDB($fixture['add']['items_id']))->isTrue();
                $entity = $source->fields['entities_id'];
                foreach (['source', 'target'] as $role) {
                    $computer = new \Computer();
                    $id = $computer->add(['name' => "$name-$role", 'entities_id' => $entity]);
                    if (!is_int($id) || $id <= 0) {
                        throw new \RuntimeException('Cannot create the owned deprecated API computer');
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
                    $source_version = new \SoftwareVersion();
                    $this->boolean($source_version->getFromDB($fixture['add']['softwareversions_id']))->isTrue();
                    $version = new \SoftwareVersion();
                    $id = $version->add([
                        'name' => $name,
                        'softwares_id' => $source_version->fields['softwares_id'],
                        'entities_id' => $source_version->fields['entities_id'],
                    ]);
                    if (!is_int($id) || $id <= 0) {
                        throw new \RuntimeException('Cannot create the owned deprecated API software version');
                    }
                    $parents[] = $version;
                    $fixture['add']['softwareversions_id'] = $id;
                    $fixture['create']['softwareversions_id'] = $id;
                    $fixture['inserted']['softwareversions_id'] = $id;
                    $fixture['updated']['softwareversions_id'] = $id;
                } else {
                    $source_license = new \SoftwareLicense();
                    $this->boolean($source_license->getFromDB($fixture['add']['softwarelicenses_id']))->isTrue();
                    $license = new \SoftwareLicense();
                    $id = $license->add([
                        'name' => $name,
                        'softwares_id' => $source_license->fields['softwares_id'],
                        'entities_id' => $source_license->fields['entities_id'],
                        'is_recursive' => $source_license->fields['is_recursive'],
                        'number' => $source_license->fields['number'],
                    ]);
                    if (!is_int($id) || $id <= 0) {
                        throw new \RuntimeException('Cannot create the owned deprecated API software license');
                    }
                    $parents[] = $license;
                    $fixture['add']['softwarelicenses_id'] = $id;
                    $fixture['create']['softwarelicenses_id'] = $id;
                    $fixture['inserted']['softwarelicenses_id'] = $id;
                    $fixture['updated']['softwarelicenses_id'] = $id;
                }
            }
            $test($fixture, $item);
        } catch (\Throwable $error) {
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
            } catch (\Throwable $error) {
                $cleanup_error = $error;
            }
            foreach (array_reverse($parents) as $parent) {
                try {
                    $this->boolean($parent->delete(['id' => $parent->getID()], true))->isTrue();
                } catch (\Throwable $error) {
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
