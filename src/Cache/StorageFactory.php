<?php

declare(strict_types=1);

namespace itsmng\Cache;

use Laminas\Cache\ConfigProvider;
use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Service\StorageAdapterFactoryInterface;
use Laminas\Cache\Storage\AdapterPluginManager;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\ServiceManager\ServiceManager;

/** Application cache configuration, composed from each installed adapter's official provider. */
final class StorageFactory
{
    public static function create(array $configuration): StorageInterface
    {
        $services = new ServiceManager((new ConfigProvider())()['dependencies']);
        foreach ([
            new \Laminas\Cache\Storage\Adapter\Apcu\ConfigProvider(),
            new \Laminas\Cache\Storage\Adapter\Filesystem\ConfigProvider(),
            new \Laminas\Cache\Storage\Adapter\Memory\ConfigProvider(),
            new \Laminas\Cache\Storage\Adapter\Redis\ConfigProvider(),
        ] as $provider) {
            $services->configure($provider()['dependencies']);
        }
        $adapters = $services->get(AdapterPluginManager::class);
        $adapters->configure([
            'factories' => [SessionAdapter::class => static fn ($container, $name, ?array $options = null) => new SessionAdapter($options)],
            'aliases' => [
                'session' => SessionAdapter::class,
                'Session' => SessionAdapter::class,
                'Laminas\\Cache\\Storage\\Adapter\\Session' => SessionAdapter::class,
            ],
        ]);

        if (is_array($configuration['adapter'] ?? null)) {
            $adapter = $configuration['adapter'];
            $configuration['adapter'] = $adapter['name'] ?? null;
            $configuration['options'] = array_merge($adapter['options'] ?? [], $configuration['options'] ?? []);
        }
        $plugins = $configuration['plugins'] ?? [];
        if (!is_array($plugins)) {
            throw new InvalidArgumentException('Plugins needs to be an array');
        }
        $normalized = [];
        foreach ($plugins as $key => $plugin) {
            if (is_string($key)) {
                if (!is_array($plugin)) {
                    throw new InvalidArgumentException("'plugins.{$key}' needs to be an array");
                }
                $normalized[] = ['name' => $key, 'options' => $plugin];
            } elseif (is_string($plugin)) {
                $normalized[] = ['name' => $plugin];
            } elseif (is_array($plugin)) {
                if (isset($plugin['priority'])) {
                    if (!is_numeric($plugin['priority'])) {
                        throw new InvalidArgumentException('Plugin priority must be numeric');
                    }
                    $plugin['priority'] = (int) $plugin['priority'];
                }
                $normalized[] = $plugin;
            } else {
                throw new InvalidArgumentException('Plugin must be a name or configuration array');
            }
        }
        $configuration['plugins'] = $normalized;
        $factory = $services->get(StorageAdapterFactoryInterface::class);
        $factory->assertValidConfigurationStructure($configuration);
        return $factory->createFromArrayConfiguration($configuration);
    }
}
