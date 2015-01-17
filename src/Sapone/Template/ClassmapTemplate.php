<?php

namespace Sapone\Template;

class ClassmapTemplate extends \ArrayObject
{
    /**
     * Build the classmap from the classmap.ini file
     */
    public function __construct()
    {
        foreach (parse_ini_file(__DIR__ . '/classmap.ini') as $wsdlType => $phpType) {
            $this[$wsdlType] = $phpType;
        }
    }
}
