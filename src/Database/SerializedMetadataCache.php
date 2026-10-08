<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Throwable;
use UnexpectedValueException;

/** PSR-6 bridge that never leaves live metadata in an application cache backend. */
final class SerializedMetadataCache extends Psr16Adapter
{
    private readonly DefaultMarshaller $marshaller;

    public function __construct(CacheInterface $pool, string $namespace)
    {
        parent::__construct($pool, $namespace);
        $this->marshaller = new DefaultMarshaller(false);
    }

    protected function doFetch(array $ids): iterable
    {
        foreach (parent::doFetch($ids) as $id => $bytes) {
            if (!is_string($bytes)) {
                continue;
            }
            set_error_handler(static function (int $severity, string $message): never {
                throw new UnexpectedValueException($message);
            });
            try {
                $value = $this->marshaller->unmarshall($bytes);
            } catch (Throwable) {
                continue; // A damaged optional cache is a miss, never a mapping failure.
            } finally {
                restore_error_handler();
            }
            yield $id => $value;
        }
    }

    protected function doSave(array $values, int $lifetime): bool
    {
        $bytes = $this->marshaller->marshall($values, $failed);
        return parent::doSave($bytes, $lifetime) && !$failed;
    }
}
