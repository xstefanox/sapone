<?php

namespace Sapone\Builder;

use Sapone\Config;
use Sapone\Util\NamespaceInflector;
use Sapone\Util\SimpleXMLElement;

class ServiceBuilder
{

    /**
     * @var \Sapone\Config
     */
    protected $config;

    /**
     * @var \Sapone\Util\NamespaceInflector
     */
    protected $namespaceInflector;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->namespaceInflector = new NamespaceInflector($config);
    }

    public function buildClass(SimpleXMLElement $portType)
    {

    }
}