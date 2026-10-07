<?php

declare(strict_types=1);

namespace itsmng\Cache;

use ArrayObject;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Stdlib\ArrayObject as LaminasArrayObject;

final class SessionOptions extends AdapterOptions
{
    private ArrayObject|LaminasArrayObject|null $sessionContainer = null;

    public function setSessionContainer(ArrayObject|LaminasArrayObject|null $container = null): self
    {
        if ($this->sessionContainer !== $container) {
            $this->triggerOptionEvent('session_container', $container);
            $this->sessionContainer = $container;
        }
        return $this;
    }

    public function getSessionContainer(): ArrayObject|LaminasArrayObject|null
    {
        return $this->sessionContainer;
    }
}
