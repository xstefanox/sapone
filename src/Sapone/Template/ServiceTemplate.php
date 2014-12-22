<?php

namespace Sapone\Template;

class ServiceTemplate extends \SoapClient
{
    /**
     * @see SoapClient::__construct
     *
     * @param string $wsdl
     * @param array $options
     */
    public function __construct($wsdl, array $options = array())
    {
        $options["classmap"] = empty($options["classmap"]) ? new Classmap() : $options["classmap"];

        parent::__construct($wsdl, $options);
    }
}
