<?php

namespace Sapone\Wsdl;

class XmlType
{
    public $namespacePrefix = null;
    public $name = null;

    public function __construct($namespacePrefix, $name)
    {
        $this->namespacePrefix = $namespacePrefix;
        $this->name = $name;
    }

    public function getPhpType()
    {
        return '';
    }
}