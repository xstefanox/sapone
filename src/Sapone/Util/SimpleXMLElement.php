<?php

namespace Sapone\Util;

use Sapone\Command\GenerateCommand;

/**
 * A SimpleXMLElement that automatically loads the XMLSchema and WSDL namespaces for XPath queries
 */
class SimpleXMLElement extends \SimpleXMLElement
{
    public static function loadFile($path)
    {
        /* @var \Sapone\Util\SimpleXMLElement $xml */
        $xml = simplexml_load_file($path, 'Sapone\Util\SimpleXMLElement');
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

        // in case of a successful query, ensure that the loaded elements register the needed namespaces
        if (is_array($elements)) {

            /* @var \Sapone\Util\SimpleXMLElement[] $elements */

            foreach ($elements as $element) {
                $element->registerBaseXPathNamespaces();
            }
        }

        return $elements;
    }
}
