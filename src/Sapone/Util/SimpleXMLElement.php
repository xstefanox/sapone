<?php

namespace Sapone\Util;

use Sapone\Command\GenerateCommand;

/**
 * A SimpleXMLElement that automatically loads the XMLSchema and WSDL namespaces for XPath queries
 */
class SimpleXMLElement extends \SimpleXMLElement
{
    /**
     * @param string $path The path to the XML document
     * @return SimpleXMLElement
     */
    public static function loadFile($path)
    {
        /* @var \Sapone\Util\SimpleXMLElement $xml */
        $xml = simplexml_load_file($path, 'Sapone\Util\SimpleXMLElement');
        $xml->registerBaseXPathNamespaces();
        
        return $xml;
    }

    public static function parseQualifiedXmlType($name)
    {
        preg_match('/^((?<prefix>\w+):)?(?<name>.*$)/', $name, $matches);

        $parsedTypeName = array(
            'prefix' => (array_key_exists('prefix', $matches) and !empty($matches['prefix'])) ? $matches['prefix'] : '',
            'name' => (array_key_exists('name', $matches) and !empty($matches['name'])) ? $matches['name'] : null,
        );

        return $parsedTypeName;
    }

    /**
     * Register the commonly used XMLSchema namespaces and prefixes used in XPath queries
     */
    protected function registerBaseXPathNamespaces()
    {
        $this->registerXPathNamespace('xsd', GenerateCommand::NAMESPACE_XSD);
        $this->registerXPathNamespace('wsdl', GenerateCommand::NAMESPACE_WSDL);
    }

    /**
     * @inheritdoc
     */
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

    public function getNamespace()
    {
        $parsedTypeName = static::parseQualifiedXmlType($this->getName());

        $namespaces = $this->getNamespaces();

        return $namespaces[$parsedTypeName['prefix']];
    }
}
