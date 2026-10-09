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

namespace tests\units\Glpi\Cache;

use ArrayAccess;
use ArrayIterator;
use ArrayObject;
use DateInterval;
use Glpi\Cache\SimpleCache as SimpleCacheModel;
use IteratorAggregate;
use itsmng\Cache\SessionAdapter;
use itsmng\Cache\StorageFactory;
use org\bovigo\vfs\vfsStream;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Psr16Cache;
use Traversable;

/* Test for inc/cache/simplecache.class.php */

class SimpleCache extends \GLPITestCase
{
    public function testNativeSessionCachePreservesAuthenticationAndNamespaces(): void
    {
        $previousSession = $_SESSION;
        try {
            $_SESSION['glpiID'] = 194;
            $first = StorageFactory::create(['adapter' => 'session', 'options' => ['namespace' => 'first']]);
            $second = StorageFactory::create(['adapter' => 'Session', 'options' => ['namespace' => 'second']]);
            $this->boolean($first->setMultiple(['42' => 'numeric-key']))->isTrue();
            $this->string($first->get('42'))->isIdenticalTo('numeric-key');
            $this->boolean($first->delete('42'))->isTrue();
            $this->boolean($first->delete('absent'))->isTrue();
            $this->boolean($first->setMultiple(['empty' => null, 'nested' => ['value' => 7]]))->isTrue();
            $this->boolean($first->has('empty'))->isTrue();
            $this->variable($first->get('empty', 'missing'))->isNull();
            $this->array($first->getMultiple(['empty', 'nested', 'absent'], 'missing'))
                ->isIdenticalTo(['empty' => null, 'nested' => ['value' => 7], 'absent' => 'missing']);
            $this->boolean($second->has('empty'))->isFalse();
            $this->boolean($second->set('kept', 9))->isTrue();
            foreach ([30, new DateInterval('PT30S')] as $ttl) {
                $this->boolean($first->set('explicit-ttl', 'value', $ttl))->isTrue();
                $this->boolean($first->has('explicit-ttl'))->isTrue();
                $this->boolean($first->setMultiple(['explicit-bulk' => 'value'], $ttl))->isTrue();
                $this->boolean($first->has('explicit-bulk'))->isTrue();
            }
            $past = new DateInterval('PT1S');
            $past->invert = 1;
            foreach ([0, -1, new DateInterval('PT0S'), $past] as $ttl) {
                $first->set('expired', 'old');
                $this->boolean($first->set('expired', 'new', $ttl))->isTrue();
                $this->boolean($first->has('expired'))->isFalse();
                $first->set('expired-bulk', 'old');
                $this->boolean($first->setMultiple(['expired-bulk' => 'new'], $ttl))->isTrue();
                $this->boolean($first->has('expired-bulk'))->isFalse();
            }
            $this->boolean($first->setMultiple([], 30))->isTrue();
            $expiring = StorageFactory::create(['adapter' => 'session', 'options' => ['namespace' => 'expiry', 'ttl' => 1]]);
            $this->boolean($expiring->set('default', 'short'))->isTrue();
            $this->boolean($expiring->set('integer', null, 1))->isTrue();
            $this->boolean($expiring->set('interval', 'short', new DateInterval('PT1S')))->isTrue();
            $this->boolean($expiring->setMultiple(['bulk' => 'short']))->isTrue();
            $this->boolean($first->set('unlimited', 'held'))->isTrue();
            $this->boolean($expiring->has('integer'))->isTrue();
            $this->variable($expiring->get('integer', 'missing'))->isNull();
            sleep(1);
            $this->boolean($expiring->has('default'))->isFalse();
            $this->string($expiring->get('integer', 'missing'))->isIdenticalTo('missing');
            $this->array($expiring->getMultiple(['interval', 'bulk'], 'missing'))
                ->isIdenticalTo(['interval' => 'missing', 'bulk' => 'missing']);
            $this->string($first->get('unlimited'))->isIdenticalTo('held');
            // Existing unversioned data is deliberately cold, not misread as expiry envelopes.
            $_SESSION['itsmng_cache']['first']['legacy'] = ['value' => 'old', 'expires' => null];
            $this->boolean($first->has('legacy'))->isFalse();

            $this->boolean($first->set('session-lifetime', 'value'))->isTrue();
            $this->string((new SessionAdapter('first'))->get('session-lifetime'))->isIdenticalTo('value');
            $keys = static function () {
                yield 0 => 'empty';
                yield 0 => 'nested';
            };
            $this->array($first->getMultiple($keys()))->isIdenticalTo(['empty' => null, 'nested' => ['value' => 7]]);
            $this->boolean($first->deleteMultiple($keys()))->isTrue();
            $this->boolean($first->has('empty'))->isFalse();
            foreach (['', 'bad:key', 'bad/key', 'bad\\key', '{}', '()', '@', 42] as $invalid) {
                $this->exception(fn () => $first->get($invalid))->isInstanceOf(CacheInvalidArgumentException::class);
                $this->exception(fn () => $first->set($invalid, 1))->isInstanceOf(CacheInvalidArgumentException::class);
                $this->exception(fn () => $first->has($invalid))->isInstanceOf(CacheInvalidArgumentException::class);
                $this->exception(fn () => $first->delete($invalid))->isInstanceOf(CacheInvalidArgumentException::class);
            }
            $this->boolean($first->clear())->isTrue();
            $this->boolean($second->has('kept'))->isTrue();
            $this->integer($second->get('kept'))->isIdenticalTo(9);
            $this->integer($_SESSION['glpiID'])->isIdenticalTo(194);

            // A reader follows the current session, rather than retaining an old array.
            $this->boolean($first->set('old-session', 'private'))->isTrue();
            $_SESSION = ['glpiID' => 195];
            $this->boolean($first->has('old-session'))->isFalse();
            $this->boolean($first->set('new-session', 1))->isTrue();
            $this->integer($_SESSION['glpiID'])->isIdenticalTo(195);

            // Native ArrayObject and any ArrayAccess+Traversable container work without
            // a Laminas dependency or exchangeArray method requirement.
            $containers = [new ArrayObject(['other' => ['retained' => 3]]), new class () implements ArrayAccess, IteratorAggregate {
                private array $values = ['other' => ['retained' => 3]];
                public function offsetExists(mixed $offset): bool
                {
                    return array_key_exists($offset, $this->values);
                }
                public function offsetGet(mixed $offset): mixed
                {
                    return $this->values[$offset] ?? null;
                }
                public function offsetSet(mixed $offset, mixed $value): void
                {
                    $this->values[$offset] = $value;
                }
                public function offsetUnset(mixed $offset): void
                {
                    unset($this->values[$offset]);
                }
                public function getIterator(): Traversable
                {
                    return new ArrayIterator($this->values);
                }
            }];
            foreach ($containers as $container) {
                $external = new SessionAdapter('external', $container);
                $this->boolean($external->set('raw', ['nested' => true]))->isTrue();
                $this->array($external->get('raw'))->isIdenticalTo(['nested' => true]);
                $this->integer($container['other']['retained'])->isIdenticalTo(3);
                $this->boolean($external->clear())->isTrue();
                $this->array(iterator_to_array($container))->isIdenticalTo(['other' => ['retained' => 3]]);
                $this->boolean($external->has('raw'))->isFalse();
                $this->integer($first->get('new-session'))->isIdenticalTo(1);
                $this->integer($_SESSION['glpiID'])->isIdenticalTo(195);
            }
        } finally {
            $_SESSION = $previousSession;
        }
    }

    public function testStorageFactoryPreservesPluginFormsAndPriority(): void
    {
        // Existing documented serializer forms become native Symfony marshalling;
        // application code no longer depends on mutable adapter plugin events.
        foreach ([['serializer'], ['serializer' => ['serializer' => 'phpserialize']], [['name' => 'serializer', 'options' => ['serializer' => 'phpserialize'], 'priority' => '20']]] as $plugins) {
            $pool = StorageFactory::create([
                'adapter' => ['name' => 'Memory', 'options' => ['namespace' => 'nested']],
                'options' => ['namespace' => 'override'],
                'plugins' => $plugins,
            ]);
            $this->object($pool)->isInstanceOf(ArrayAdapter::class);
            $cache = new Psr16Cache($pool);
            $this->boolean($cache->set('value', ['nested' => 4]))->isTrue();
            $this->array($cache->get('value'))->isIdenticalTo(['nested' => 4]);
            $this->boolean($cache->set('expired', 'value', 0))->isTrue();
            $this->boolean($cache->has('expired'))->isFalse();
        }
        foreach ([
            ['adapter' => 'memory', 'plugins' => ['unknown']],
            ['adapter' => 7], ['adapter' => 'memory', 'options' => 'invalid'],
            ['adapter' => 'memory', 'options' => ['namespace' => []]],
            ['adapter' => 'filesystem', 'options' => ['cache_dir' => []]],
            ['adapter' => 'session', 'options' => ['session_container' => []]],
            ['adapter' => 'redis', 'options' => ['server' => ['host' => []]]],
            ['adapter' => 'redis', 'options' => ['server' => []]],
            ['adapter' => 'redis', 'options' => ['server' => ['port' => 6380]]],
        ] as $invalid) {
            $this->exception(static fn () => StorageFactory::create($invalid))->isInstanceOf(InvalidArgumentException::class);
        }
        vfsStream::setup('cache-backends');
        $options = ['cache_dir' => vfsStream::url('cache-backends'), 'namespace' => 'first', 'ttl' => 600];
        $first = new Psr16Cache(StorageFactory::create(['adapter' => 'filesystem', 'options' => $options]));
        $same = new Psr16Cache(StorageFactory::create(['adapter' => 'filesystem', 'options' => $options]));
        $other = new Psr16Cache(StorageFactory::create(['adapter' => 'filesystem', 'options' => array_replace($options, ['namespace' => 'second'])]));
        $this->boolean($first->set('shared', ['value' => 7]))->isTrue();
        $this->array($same->get('shared'))->isIdenticalTo(['value' => 7]);
        $this->boolean($other->has('shared'))->isFalse();
        $other->set('retained', 9);
        $this->boolean($first->clear())->isTrue();
        $this->boolean($same->has('shared'))->isFalse();
        $this->integer($other->get('retained'))->isIdenticalTo(9);
        $overridden = new Psr16Cache(StorageFactory::create([
            'adapter' => ['name' => 'Filesystem', 'options' => $options],
            'options' => ['namespace' => 'second'],
        ]));
        $this->integer($overridden->get('retained'))->isIdenticalTo(9);
    }

    public function testIterableBulkOperationsKeepFootprints(): void
    {
        vfsStream::setup('glpi', null, ['cache' => []]);
        $storage = new Psr16Cache(StorageFactory::create(['adapter' => 'memory']));
        $cache = new SimpleCacheModel($storage, vfsStream::url('glpi/cache'));
        $values = static function () {
            yield 'one' => 1;
            yield 'two' => 2;
        };
        $keys = static function () {
            yield 0 => 'one';
            yield 0 => 'two';
        };
        $this->boolean($cache->setMultiple($values()))->isTrue();
        $this->array($cache->getMultiple($keys()))->isIdenticalTo(['one' => 1, 'two' => 2]);
        $this->array($cache->getAllKnownCacheKeys())->isIdenticalTo(['one', 'two']);
        $this->boolean($cache->deleteMultiple($keys()))->isTrue();
        $this->array($cache->getMultiple($keys(), 'missing'))->isIdenticalTo(['one' => 'missing', 'two' => 'missing']);
    }

    public function testFootprintWritesPreserveBytesAndKnownKeys(): void
    {
        vfsStream::setup('glpi', null, ['cache' => []]);
        $namespace = 'footprint-bytes-' . uniqid();
        $directory = vfsStream::url('glpi/cache');
        $file = $directory . '/' . $namespace . '.json';
        file_put_contents($file, '{"retained_null":null}');
        $storage = new Psr16Cache(new ArrayAdapter());
        $cache = new SimpleCacheModel($storage, $directory, true, $namespace);
        $this->boolean($cache->setMultiple(['live' => 'value', 'zero' => 0, 'null' => null]))->isTrue();
        // Known SHA-1 footprints for serialized "value", 0 and null. Existing
        // null entries and deleted keys are retained by the footprint writer.
        $expected = [
            'retained_null' => null,
            'live' => '6ab0da787d99179b10c9317242ced665d759a579',
            'zero' => 'c01643a543ddd8516e3cf55eb39c7eb9246aac21',
            'null' => 'a03e9ce134099d2bd410bdc53e8abb7d3f95c397',
        ];
        $this->string(file_get_contents($file))->isIdenticalTo(json_encode($expected, JSON_PRETTY_PRINT));
        $this->array($cache->getAllKnownCacheKeys())->isIdenticalTo(array_keys($expected));
        $this->string($cache->get('live'))->isIdenticalTo('value');
        $this->integer($cache->get('zero'))->isIdenticalTo(0);
        $this->variable($cache->get('null'))->isNull();
        $this->string($cache->get('null', 'missing'))->isIdenticalTo('missing');
        $this->boolean($cache->delete('live'))->isTrue();
        $expected['live'] = $expected['null'];
        $this->string(file_get_contents($file))->isIdenticalTo(json_encode($expected, JSON_PRETTY_PRINT));
        $this->boolean($cache->has('live'))->isFalse();
        $this->boolean($cache->deleteMultiple(['zero', 'null']))->isTrue();
        $expected['zero'] = $expected['null'];
        $this->string(file_get_contents($file))->isIdenticalTo(json_encode($expected, JSON_PRETTY_PRINT));
        $this->array($cache->getAllKnownCacheKeys())->isIdenticalTo(array_keys($expected));
        $this->boolean($cache->has('zero'))->isFalse();
        $this->boolean($cache->has('null'))->isFalse();
        $this->boolean($cache->clear())->isTrue();
        $this->string(file_get_contents($file))->isIdenticalTo('[]');
        $this->array($cache->getAllKnownCacheKeys())->isEmpty();
    }

    public function testRepeatedFootprintReadsObserveExternalChanges(): void
    {
        vfsStream::setup('glpi', null, ['cache' => []]);
        $namespace = 'fresh-footprint-' . uniqid();
        $directory = vfsStream::url('glpi/cache');
        $file = $directory . '/' . $namespace . '.json';
        $storage = new Psr16Cache(new ArrayAdapter());
        $cache = new SimpleCacheModel($storage, $directory, true, $namespace);
        $other = new SimpleCacheModel($storage, $directory, true, $namespace);
        $cache->set('one', 'first');
        $this->string($cache->get('one'))->isIdenticalTo('first');
        $this->string($cache->get('one'))->isIdenticalTo('first');
        $original = file_get_contents($file);
        $modified = json_decode($original, true);
        $modified['one'] = sha1(serialize('other'));
        $changed = json_encode($modified, JSON_PRETTY_PRINT);
        $this->integer(strlen($changed))->isIdenticalTo(strlen($original));
        $mtime = filemtime($file);
        file_put_contents($file, $changed);
        touch($file, $mtime);
        $this->integer(filemtime($file))->isIdenticalTo($mtime);
        $this->variable($cache->get('one'))->isNull();
        $this->boolean($cache->has('one'))->isFalse();
        file_put_contents($file, $original);
        $this->string($cache->get('one'))->isIdenticalTo('first');

        // A setter changes a returned footprint array; it must not mutate the remembered decoding.
        $cache->set('two', 'second');
        file_put_contents($file, $original);
        $this->array($cache->getAllKnownCacheKeys())->isIdenticalTo(['one']);
        $this->variable($cache->get('two'))->isNull();

        // Another wrapper and a direct backend write remain visible to an already warmed reader.
        $other->clear();
        $this->variable($cache->get('one'))->isNull();
        $other->set('one', ['nested' => ['value' => 'new']]);
        $returned = $cache->get('one');
        $returned['nested']['value'] = 'local mutation';
        $this->array($cache->get('one'))->isIdenticalTo(['nested' => ['value' => 'new']]);
        $storage->set(sha1('one'), 'changed without matching footprint');
        $this->variable($cache->get('one'))->isNull();
        $other->set('one', 'restored');
        $this->string($cache->get('one'))->isIdenticalTo('restored');

        $this->when(function () use ($cache, $file): void {
            file_put_contents($file, 'invalid json');
            $this->variable($cache->get('one'))->isNull();
        })->error()->withType(E_USER_WARNING)
            ->withMessage('Cache footprint file "' . $file . '" contents was invalid, it has been cleaned.')->exists();
        $this->string(file_get_contents($file))->isIdenticalTo('[]');
        $other->set('one', 'after corruption');
        $this->string($cache->get('one'))->isIdenticalTo('after corruption');
    }

    /**
     * Test case: cache dir is empty and writable, footprint file should be created and used.
     */
    public function testCacheWithEmptyWritableCacheDir()
    {
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [],
         ]
        );

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $this->newTestedInstance(
            new Psr16Cache(new ArrayAdapter()),
            $cache_dir,
            true,
            $cache_namespace
        );

        // File has been initialized
        $this->string(file_get_contents($footprint_file))->isEqualTo('[]');

        $this->testOperationsOnCache($footprint_file);
    }

    /**
     * Test case: footprint file exists, is writable and empty, it should be initialized and used.
     */
    public function testCacheWithEmptyFootprintFile()
    {
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [
                 $cache_namespace . '.json' => ''
              ],
         ]
        );

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $this->newTestedInstance(
            new Psr16Cache(new ArrayAdapter()),
            $cache_dir,
            true,
            $cache_namespace
        );

        // File has initialized
        $this->string(file_get_contents($footprint_file))->isEqualTo('[]');

        $this->testOperationsOnCache($footprint_file);
    }

    /**
     * Test case: footprint file exists and is writable, it should be used.
     */
    public function testCacheWithExistingFootprintFile()
    {
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        $existing_footprint = '{"existing_key":"752c14ea195c460bac3c3b7896975ee9fd15eeb7"}';

        vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [
                 $cache_namespace . '.json' => $existing_footprint
              ],
         ]
        );

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $this->newTestedInstance(
            new Psr16Cache(new ArrayAdapter()),
            $cache_dir,
            true,
            $cache_namespace
        );

        // File has not been erased
        $this->string(file_get_contents($footprint_file))->isEqualTo($existing_footprint);

        $this->testOperationsOnCache($footprint_file);
    }

    /**
     * Test case: footprint file is corrupted, it should be regenerated.
     */
    public function testCacheWithCorruptedFootprintFile()
    {
        $self = $this;
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [
                 $cache_namespace . '.json' => 'invalid json'
              ],
         ]
        );

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $this->when(
            function () use ($self, $cache_dir, $cache_namespace) {
                $self->newTestedInstance(
                    new Psr16Cache(new ArrayAdapter()),
                    $cache_dir,
                    true,
                    $cache_namespace
                );
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage('Cache footprint file "' . $footprint_file . '" contents was invalid, it has been cleaned.')
              ->exists();

        // File has been regenerated
        $this->string(file_get_contents($footprint_file))->isEqualTo('[]');

        $this->testOperationsOnCache($footprint_file);
    }

    /**
     * Test case: cache dir is not writable, footprint file cannot be used.
     */
    public function testCacheWithoutFootprintFile()
    {
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        $root_directory = vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [],
         ]
        );

        // Simulate existing cache with footprint.
        $this->newTestedInstance(
            new Psr16Cache(new ArrayAdapter()),
            $cache_dir,
            true,
            $cache_namespace
        );

        $this->boolean($this->testedInstance->set('footprinted', 'some value'))->isTrue();
        $this->boolean($this->testedInstance->has('footprinted'))->isTrue();

        $root_directory->getChild('cache/' . $cache_namespace . '.json')->chmod(0500); // Make file not writable

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $self = $this;
        $this->when(
            function () use ($self, $cache_dir, $cache_namespace) {
                $self->newTestedInstance(
                    new Psr16Cache(new ArrayAdapter()),
                    $cache_dir,
                    true,
                    $cache_namespace
                );
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage('Cannot write "' . $footprint_file . '" cache footprint file. Cache performance can be lowered.')
              ->exists();

        // Previously set value is not valid anymore as footprint file is not usable and cannot be trusted.
        $this->boolean($this->testedInstance->has('footprinted'))->isFalse();

        $this->testOperationsOnCache(null);
    }

    /**
     * Test case: cache dir is not writable, footprint file cannot be used.
     */
    public function testCacheWithUnreadableFootprintFile()
    {
        $cache_dir = vfsStream::url('glpi/cache');
        $cache_namespace = uniqid(true);

        $root_directory = vfsStream::setup(
            'glpi',
            null,
            [
              'cache' => [
                 $cache_namespace . '.json' => '[]',
              ],
         ]
        );
        // Make file writable but not readable
        $root_directory->getChild('cache/' . $cache_namespace . '.json')->chmod(0200);

        $footprint_file = vfsStream::url('glpi/cache/' . $cache_namespace . '.json');

        $self = $this;
        $this->when(
            function () use ($self, $cache_dir, $cache_namespace) {
                $self->newTestedInstance(
                    new Psr16Cache(new ArrayAdapter()),
                    $cache_dir,
                    true,
                    $cache_namespace
                );
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage('Cannot read "' . $footprint_file . '" cache footprint file. Cache performance can be lowered.')
              ->exists();

        $this->testOperationsOnCache(null);
    }

    /**
     * Test all possible cache operations.
     *
     * @param string|null $footprint_file
     */
    private function testOperationsOnCache($footprint_file)
    {
        // Different scalar types to test.
        $values = [
           'null'         => null,
           'string'       => 'some value',
           'true'         => true,
           'false'        => false,
           'negative int' => -10,
           'positive int' => 15,
           'zero'         => 0,
           'float'        => 15.358,
           'simple array' => ['a', 'b', 'c'],
           'assoc array'  => ['some' => 'value', 'from' => 'assoc', 'array' => null]
        ];

        // Test single set/get/has/delete
        foreach ($values as $key => $value) {
            // Not yet existing
            $this->boolean($this->testedInstance->has($key))->isFalse();

            // Can be set if not existing
            $this->boolean($this->testedInstance->set($key, $value))->isTrue();

            // Is existing after being set
            $this->boolean($this->testedInstance->has($key))->isTrue();

            // Cached value is equal to value that was set
            $this->variable($this->testedInstance->get($key))->isEqualTo($value);

            // Overwriting an existing value works
            $rand = mt_rand();
            $this->boolean($this->testedInstance->set($key, $rand))->isTrue();
            $this->variable($this->testedInstance->get($key))->isEqualTo($rand);

            // Can delete a value
            $this->boolean($this->testedInstance->delete($key))->isTrue();
        }

        // Test multiple set/get
        $this->testedInstance->setMultiple($values);
        foreach ($values as $key => $value) {
            // Cached value exists and is equal to value that was set
            $this->boolean($this->testedInstance->has($key))->isTrue();
            $this->variable($this->testedInstance->get($key))->isEqualTo($value);
        }

        // Test only on partial result to be sure that "*Multiple" methods acts only on targetted elements
        $some_keys = array_rand($values, 4);
        $some_values = array_intersect_key($values, array_fill_keys($some_keys, null));

        $this->array($this->testedInstance->getMultiple($some_keys))->isEqualTo($some_values);

        $this->testedInstance->deleteMultiple($some_keys);
        foreach ($some_keys as $key) {
            // Cached value should not exists as it has been deleted
            $this->boolean($this->testedInstance->has($key))->isFalse();
        }

        // Test global clear
        $this->testedInstance->clear();
        foreach (array_keys($values) as $key) {
            // Cached value should not exists as it has been deleted
            $this->boolean($this->testedInstance->has($key))->isFalse();
        }

        // Test that footprint changes made cache stale
        if (null !== $footprint_file) {
            $this->boolean($this->testedInstance->set('another_key', 'another value'))->isTrue();
            $this->boolean($this->testedInstance->has('another_key'))->isTrue();
            file_put_contents($footprint_file, '{"another_key":"752c14ea195c460bac3c3b7896975ee9fd15eeb7"}');
            $this->boolean($this->testedInstance->has('another_key'))->isFalse();
        }
    }
}
