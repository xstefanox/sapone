<?php

namespace Sapone\Xml;

use Sapone\Command\GenerateCommand;

class SimpleXMLElement extends \SimpleXMLElement
{
    public static function loadFile($path)
    {
        $xml = simplexml_load_file($path, 'Sapone\Xml\SimpleXMLElement');
        $xml->registerBaseXPathNamespaces();
        
        return $xml;
    }
    
    protected function registerBaseXPathNamespaces()
    {
        $this->registerXPathNamespace('xsd', GenerateCommand::NAMESPACE_XSD);
        $this->registerXPathNamespace('wsdl', GenerateCommand::NAMESPACE_WSDL);
    }
    
    public function xpath($path)
    {
        $elements = parent::xpath($path);

        if (is_array($elements)) {
            foreach ($elements as $element) {
                $element->registerBaseXPathNamespaces();
            }
        }

        return $elements;
    }
    
    public function getSimpleTypes()
    {
        return $this->xpath('//wsdl:types//xsd:simpleType|//xsd:schema//xsd:simpleType');
    }
}
