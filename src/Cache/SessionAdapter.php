<?php

declare(strict_types=1);

namespace itsmng\Cache;

use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\Adapter\KeyListIterator;

/** Cache data shares the existing PHP session lifecycle, never its authentication keys. */
final class SessionAdapter extends AbstractAdapter implements ClearByPrefixInterface, FlushableInterface, IterableInterface
{
    private const SESSION_KEY = 'itsmng_cache';

    public function setOptions(iterable|AdapterOptions $options): self
    {
        return parent::setOptions($options instanceof SessionOptions ? $options : new SessionOptions($options));
    }

    public function getOptions(): SessionOptions
    {
        if (!$this->options) {
            $this->setOptions(new SessionOptions());
        }
        return $this->options;
    }

    private function namespaceValues(): array
    {
        $container = $this->getOptions()->getSessionContainer();
        $namespace = $this->getOptions()->getNamespace();
        return $container !== null ? ($container[$namespace] ?? []) : ($_SESSION[self::SESSION_KEY][$namespace] ?? []);
    }

    private function replaceNamespace(array $values): void
    {
        $container = $this->getOptions()->getSessionContainer();
        $namespace = $this->getOptions()->getNamespace();
        if ($container !== null) {
            if ($values === []) {
                unset($container[$namespace]);
            } else {
                $container[$namespace] = $values;
            }
        } elseif ($values === []) {
            unset($_SESSION[self::SESSION_KEY][$namespace]);
        } else {
            $_SESSION[self::SESSION_KEY][$namespace] = $values;
        }
    }

    public function getIterator(): KeyListIterator
    {
        return new KeyListIterator($this, array_keys($this->namespaceValues()));
    }

    public function flush(): bool
    {
        $container = $this->getOptions()->getSessionContainer();
        if ($container !== null) {
            $container->exchangeArray([]);
        } else {
            unset($_SESSION[self::SESSION_KEY]);
        }
        return true;
    }

    public function clearByPrefix(string $prefix): bool
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('No prefix given');
        }
        $values = $this->namespaceValues();
        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($values[$key]);
            }
        }
        $this->replaceNamespace($values);
        return true;
    }

    protected function internalGetItem(string $key, ?bool &$success = null, mixed &$casToken = null): mixed
    {
        $values = $this->namespaceValues();
        $success = array_key_exists($key, $values);
        if (!$success) {
            return null;
        }
        return $casToken = $values[$key];
    }

    protected function internalSetItem(string $key, mixed $value): bool
    {
        $values = $this->namespaceValues();
        $values[$key] = $value;
        $this->replaceNamespace($values);
        return true;
    }

    protected function internalSetItems(array $values): array
    {
        $this->replaceNamespace(array_replace($this->namespaceValues(), $values));
        return [];
    }

    protected function internalRemoveItem(string $key): bool
    {
        $values = $this->namespaceValues();
        if (!array_key_exists($key, $values)) {
            return false;
        }
        unset($values[$key]);
        $this->replaceNamespace($values);
        return true;
    }

    protected function internalGetCapabilities(): Capabilities
    {
        return new Capabilities(
            maxKeyLength: 0,
            ttlSupported: false,
            namespaceIsPrefix: false,
            supportedDataTypes: ['NULL' => true, 'boolean' => true, 'integer' => true, 'double' => true, 'string' => true, 'array' => 'array', 'object' => 'object', 'resource' => false],
        );
    }
}
