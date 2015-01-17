<?php

namespace Sapone;

use Symfony\Component\EventDispatcher\Event as BaseEvent;

class Event extends BaseEvent
{
    const ENUM_CREATE = 'enum.create';
    const DTO_CREATE = 'dto.create';
    const SERVICE_CREATE = 'service.create';
    const CLASSMAP_CREATE = 'classmap.create';

    protected $className;

    public function __construct($className)
    {
        $this->className = $className;
    }

    /**
     * @return mixed
     */
    public function getClassName()
    {
        return $this->className;
    }

    /**
     * @param mixed $className
     */
    public function setClassName($className)
    {
        $this->className = $className;
    }
}
