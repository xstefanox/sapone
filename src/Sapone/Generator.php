<?php

namespace Sapone;

use Goetas\XML\XSDReader\Schema\Type\ComplexType;
use Goetas\XML\XSDReader\Schema\Type\SimpleType;
use Goetas\XML\XSDReader\SchemaReader;
use League\Url\Url;
use Sapone\Factory\ClassFactory;
use Sapone\Util\SimpleXMLElement;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\CS\Fixer;
use Symfony\CS\Config\Config as FixerConfig;
use Symfony\CS\FixerInterface;

/**
 * SOAP client PHP code generator
 */
class Generator
{
    /**
     * The namespace of Web Service Definition Language specification
     *
     * @var string
     */
    const WSDL_NS = 'http://schemas.xmlsoap.org/wsdl/';

    /**
     * The generator configuration instance
     *
     * @var \Sapone\Config
     */
    protected $config;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @param \Sapone\Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @return \Symfony\Component\EventDispatcher\EventDispatcher
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * Execute the code generation
     */
    public function generate()
    {
        /*
         * PROXY CONFIGURATION
         */
        $proxy = current(array_filter(array(
            getenv('HTTP_PROXY'),
            getenv('http_proxy'),
        ), 'strlen'));

        if ($proxy) {
            $parsedWsdlPath = Url::createFromUrl($this->config->getWsdlDocumentPath());

            // if not fetching the wsdl file from filesystem and a proxy has been set
            if ($parsedWsdlPath->getScheme()->get() !== 'file') {
                $proxy = Url::createFromUrl($proxy);

                libxml_set_streams_context(
                    stream_context_get_default(
                        array(
                            $proxy->getScheme()->get() => array(
                                'proxy' => 'tcp://' . $proxy->getAuthority() . $proxy->getRelativeUrl(),
                                'request_fulluri' => true,
                            )
                        )
                    )
                );
            }
        }

        unset($proxy);

        /*
         * LOAD THE WSDL DOCUMENT
         */

        $wsdlDocument = SimpleXMLElement::loadFile($this->config->getWsdlDocumentPath());
        $wsdlDocument->registerXPathNamespace('wsdl', static::WSDL_NS);
        $schemaReader = new SchemaReader();

        /* @var \Goetas\XML\XSDReader\Schema\Schema[] $schemas */
        $schemas = array();

        /* @var \Goetas\XML\XSDReader\Schema\Type\Type[] $types */
        $types = array();

        /*
         * LOAD THE XML SCHEMAS
         */

        // read the schemas included in the wsdl document
        foreach ($wsdlDocument->xpath('/wsdl:definitions/wsdl:types/xsd:schema') as $schemaNode) {
            $schemas[] = $schemaReader->readNode(dom_import_simplexml($schemaNode));
        }

        // exclude the schemas having the following namespaces
        $unusedSchemaNamespaces = array(
            SchemaReader::XML_NS,
            SchemaReader::XSD_NS,
        );

        // recursively read all the schema chain
        $processedSchemas = array();

        while (!empty($schemas)) {
            /* @var \Goetas\XML\XSDReader\Schema\Schema $currentSchema */
            $currentSchema = array_shift($schemas);

            if (
                !in_array($currentSchema, $processedSchemas)
                and
                !in_array($currentSchema->getTargetNamespace(), $unusedSchemaNamespaces)
            ) {
                $processedSchemas[] = $currentSchema;
                $schemas = array_merge($schemas, $currentSchema->getSchemas());
            }
        }

        $schemas = $processedSchemas;

        // cleanup
        unset($currentSchema);
        unset($processedSchemas);
        unset($unusedSchemaNamespaces);
        unset($schemaNode);
        unset($schemaReader);

        /*
         * LOAD THE DEFINED TYPES
         */

        // get the complete list of defined types
        foreach ($schemas as $schema) {
            $types = array_merge($types, $schema->getTypes());
        }

        /*
         * LOAD THE SERVICES
         */

        $services = $wsdlDocument->xpath('/wsdl:definitions/wsdl:portType');

        /*
         * CODE GENERATION
         */

        $classFactory = new ClassFactory($this->config, $schemas, $types);

        foreach ($types as $type) {
            if ($type instanceof SimpleType) {
                // build the inheritance chain of the current SimpleType

                /* @var \Goetas\XML\XSDReader\Schema\Type\SimpleType[] $inheritanceChain */
                $inheritanceChain = array(
                    $type->getRestriction(),
                );

                // loop through the type inheritance chain untill the base type
                while (end($inheritanceChain) !== null) {
                    $inheritanceChain[] = end($inheritanceChain)->getBase()->getParent();
                }

                // remove the null value
                array_pop($inheritanceChain);

                // remove the 'anySimpleType'
                array_pop($inheritanceChain);

                // now the last element of the chain is the base simple type

                // enums are built only of string enumerations
                if (
                    end($inheritanceChain)->getBase()->getName() === 'string'
                    and
                    array_key_exists('enumeration', $type->getRestriction()->getChecks())
                ) {
                    $className = $classFactory->createEnum($type);
                    $this->eventDispatcher->dispatch(Event::ENUM_CREATE, new Event($className));
                }
            } elseif ($type instanceof ComplexType) {
                $className = $classFactory->createDTO($type);
                $this->eventDispatcher->dispatch(Event::DTO_CREATE, new Event($className));
            }
        }

        foreach ($services as $service) {
            $className = $classFactory->createService($service);
            $this->eventDispatcher->dispatch(Event::SERVICE_CREATE, new Event($className));
        }

        $className = $classFactory->createClassmap();
        $this->eventDispatcher->dispatch(Event::CLASSMAP_CREATE, new Event($className));

        /*
         * GENERATED CODE FIX
         */

        // create the coding standards fixer
        $fixer = new Fixer();
        $config = new FixerConfig();
        $config->setDir($this->config->getOutputPath());

        // register all the existing fixers
        $fixer->registerBuiltInFixers();
        $config->fixers(array_filter($fixer->getFixers(), function (FixerInterface $fixer) {
            return $fixer->getLevel() === FixerInterface::PSR2_LEVEL;
        }));

        // fix the generated code
        $fixer->fix($config);
    }
}
