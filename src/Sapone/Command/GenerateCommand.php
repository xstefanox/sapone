<?php

namespace Sapone\Command;

use Html2Text\Html2Text;
use Sapone\Wsdl\XmlType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\CS\Fixer;
use Symfony\CS\Config\Config as FixerConfig;
use Symfony\CS\FixerInterface;
use Zend\Code\Generator\AbstractGenerator;
use Zend\Code\Generator\AbstractMemberGenerator;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Generator\ValueGenerator;
use Zend\Code\Reflection\ClassReflection;
use Sapone\Xml\SimpleXMLElement;

class GenerateCommand extends Command
{
    /**
     * The namespace of XMLSchema specification
     *
     * @var string
     */
    const NAMESPACE_XSD = 'http://www.w3.org/2001/XMLSchema';

    /**
     * The namespace of Web Service Definition Language specification
     * @var string
     */
    const NAMESPACE_WSDL = 'http://schemas.xmlsoap.org/wsdl/';

    const NAMESPACE_MESSAGE = 'Message';
    const NAMESPACE_TYPE = 'Type';
    const NAMESPACE_ENUM = 'Enum';

    /**
     * The suffix to append to invalid names.
     *
     * @var string
     */
    const NAME_SUFFIX = '_';

    protected $namespace;
    protected $structuredNamespace;
    protected $importedSchemas = array();
    
    protected function configure()
    {
        $this
            ->setName('generate')
            ->addArgument(
                'wsdl-path',
                InputArgument::REQUIRED,
                'The path to the wsdl'
            )
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'The path to the generated code'
            )
            ->addArgument(
                'namespace',
                InputArgument::REQUIRED,
                'The namespace of the generated code'
            )
            ->addOption(
                'accessors',
                null,
                InputOption::VALUE_NONE,
                'Enable the generation of setters/getters'
            )
            ->addOption(
                'constructor-null',
                null,
                InputOption::VALUE_NONE,
                'Default every constructor parameter to null'
            )
            ->addOption(
                'spl-enums',
                null,
                InputOption::VALUE_NONE,
                'Make the enum classes extend SPL enums'
            )
            ->addOption(
                'structured-namespace',
                null,
                InputOption::VALUE_NONE,
                'Put messages and types in a sub-namespace'
            )
            ->addOption(
                'namespace-style',
                null,
                InputOption::VALUE_REQUIRED,
                'The style of the namespace [psr0|psr4]',
                'psr4'
            )
            ->addOption(
                'logging',
                null,
                InputOption::VALUE_NONE,
                'Add support for a PSR-3 logger'
            )
            ->addOption(
                'proxy',
                null,
                InputOption::VALUE_REQUIRED,
                'The URL of the proxy used to connect to the wsdl file'
            );
    }

    /**
     * Recursively load the XML Schemas included in the given document, adding them to the imported schemas registry.
     * 
     * @param type $document
     */
    protected function loadSchemas($document)
    {
        // find each import node
        foreach ($document->xpath('//xsd:import') as $importedSchema) {
            
            // read the schema namespace and location
            $namespace = (string) $importedSchema['namespace'];
            $location = (string) $importedSchema['schemaLocation'];
            
            // if the current schema has not been loaded yet
            if (!array_key_exists($namespace, $this->importedSchemas)) {
                
                // load the schema
                $this->importedSchemas[$namespace] = SimpleXMLElement::loadFile($location);
                
                // load its imported schemas
                $this->loadSchemas($this->importedSchemas[$namespace]);
            }
        }
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*
         * INPUT VALIDATION
         */
        $wsdlPath = $input->getArgument('wsdl-path');
        $basePath = $input->getArgument('output');

        $this->namespace = $input->getArgument('namespace');
        $this->structuredNamespace = $input->getOption('structured-namespace');
        $namespaceStyle = $input->getOption('namespace-style');
        if (!in_array(strtolower($namespaceStyle), array('psr0', 'psr4'))) {
            throw new \InvalidArgumentException("Invalid namespace style: '{$namespaceStyle}'");
        }

        $constructorNull = $input->getOption('constructor-null');
        $accessors = $input->getOption('accessors');
        $splEnums = $input->getOption('spl-enums');
        $logging = $input->getOption('logging');
        $proxy = $input->getOption('proxy');

        if ($proxy) {
            if (filter_var($proxy, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException("Proxy must be a valid URL");
            }
        }

        $parsedWsdlPath = parse_url($wsdlPath);

        // if not fetching the wsdl file from filesystem and a proxy has been set
        if (array_key_exists('scheme', $parsedWsdlPath) and $parsedWsdlPath['scheme'] !== 'file' and $proxy) {

            $parsedProxy = parse_url($proxy);
            $proxyScheme = $parsedProxy['scheme'];
            $parsedProxy['scheme'] = 'tcp';

            // @todo: replace URL cretaion with league/url
            $proxy = $parsedProxy['scheme'] . '://' . $parsedProxy['host'] . ':' . $parsedProxy['port'];

            libxml_set_streams_context(
                stream_context_get_default(
                    array(
                        $proxyScheme => array(
                            'proxy' => $proxy,
                            'request_fulluri' => true,
                        )
                    )
                )
            );
        }

        /*
         * OUTPUT PREPARATION
         */
        $fs = new Filesystem();
        $fs->mkdir($basePath);

        /*
         * LOAD THE WSDL DOCUMENT
         */
        $wsdl = SimpleXMLElement::loadFile($wsdlPath, 'Sapone\Util\SimpleXMLElement');
        $wsdlNamespaces = $wsdl->getDocNamespaces();
        
        $this->loadSchemas($wsdl);
        
        /*
         * GENERATE THE CLASSMAPPING CLASS
         */

        $classmapClassName = 'Classmap';
        $classmapClass = ClassGenerator::fromReflection(new ClassReflection('\Sapone\Template\ClassmapTemplate'));
        $classmapClass->setName($classmapClassName);
        $classmapClass->setNamespaceName($this->namespace);
        $classmapClass->setImplementedInterfaces(array('\ArrayAccess'));
        $classmapConstructorBody = '';
        
        /*
         * GENERATE THE SERVICES CLASSES
         */
        foreach ($wsdl->xpath('//wsdl:portType') as $port) {
            $serviceName = (string) $port['name'];
            $serviceClassName = "{$serviceName}Client";

            // create the class
            $serviceClass = ClassGenerator::fromReflection(new ClassReflection('\Sapone\Template\ServiceTemplate'));
            $serviceClass->setName($serviceClassName);
            $serviceClass->setExtendedClass('\SoapClient');
            $serviceClass->setNamespaceName($this->namespace);

            $documentation = new Html2Text((string) current($port->xpath('./wsdl:documentation')));
            if ($documentation->getText()) {
                $serviceClass->setDocBlock(new DocBlockGenerator($documentation->getText()));
            }

            if ($logging) {
                $serviceClass->getMethod('__construct');
            }

            // create the service methods
            foreach ($port->xpath('.//wsdl:operation') as $operation) {
                $operationName = $this->validateType((string) $operation['name']);

                $inputXmlType = $this->parseXmlType((string) current($operation->xpath('.//wsdl:input/@message')));
                $outputXmlType = $this->parseXmlType((string) current($operation->xpath('.//wsdl:output/@message')));
                $inputMessageType = $this->validateType($inputXmlType->name);
                $outputMessageType = $this->validateType($outputXmlType->name);
                $documentation = new Html2Text((string) current($operation->xpath('.//wsdl:documentation')));

                // read the name and type of the messages
                $fqInputMessageType = $this->getMessageClassName($inputMessageType);
                $fqOutputMessageType = $this->getMessageClassName($outputMessageType);

                if ($this->structuredNamespace) {
                    $serviceClass->addUse($fqInputMessageType);
                }

                // create the comment
                $doc = new DocBlockGenerator();
                $doc->setTag(new ParamTag('parameters', '\\' . $fqInputMessageType));
                $doc->setTag(new ReturnTag('\\' . $fqOutputMessageType));
                $doc->setShortDescription($documentation->getText());

                // create the parameter
                $param = new ParameterGenerator('parameters', $inputMessageType);

                // create the method
                $method = new MethodGenerator($operationName);
                $method->setDocBlock($doc);
                $method->setParameter($param);
                $method->setBody("return \$this->__soapCall('{$operation['name']}', array(\$parameters));");
                $serviceClass->addMethodFromGenerator($method);

                /*
                 * GENERATE THE MESSAGES CLASSES
                 */
                foreach ($wsdl->xpath('//wsdl:message') as $message) {
                    $messageName = $this->validateType((string) $message['name']);
                    $messageNameNamespace = $this->getMessagesNamespace();
                    $fqMessageName = $this->getMessageClassName($messageName);

                    // create the class
                    $messageClass = new ClassGenerator();
                    $messageClass->setName($messageName);
                    $messageClass->setNamespaceName($messageNameNamespace);

                    $documentation = new Html2Text((string) current($message->xpath('./wsdl:documentation')));
                    if ($documentation->getText()) {
                        $messageClass->setDocBlock(new DocBlockGenerator($documentation->getText()));
                    }

                    foreach ($message->xpath('.//wsdl:part') as $part) {
                        
                        $partName = (string) $part['name'];

                        if ($part['type']) {
                            // for document-style messages
                            $xmlType = $this->parseXmlType((string) $part['type']);
                        } else {
                            // for rpc-style messages
                            $element = current(
                                $wsdl->xpath(
                                    sprintf(
                                        '//wsdl:types//xsd:element[@name="%s"]',
                                        $this->parseXmlType((string) $part['element'])->name
                                    )
                                )
                            );

                            // if not found, try to search in the imported XSD documents
                            if ($element === false) {
                                
                                $importedWsdl = $this->importedSchemas[$wsdlNamespaces[$this->parseXmlType((string) $part['element'])->namespacePrefix]];

                                $element = current(
                                    $importedWsdl->xpath(
                                        sprintf(
                                            '//xsd:element[@name="%s"]',
                                            $this->parseXmlType((string) $part['element'])->name
                                        )
                                    )
                                );
                            }

                            // if the element references a type
                            if ($element['type']) {
                                $xmlType = $this->parseXmlType((string) $element['type']);
                            } else {
                                // the element type is defined inline
                                $xmlType = $this->parseXmlType((string) $element['name']);
                            }

                            // if the element uses the current target namespace
                            $tnsXPath = '//wsdl:types//xsd:element[@name="%s"]/' .
                                        'ancestor::*[@targetNamespace]/@targetNamespace';
                            if ($xmlType->namespacePrefix === null) {
                                $xmlType->namespacePrefix = array_search(
                                    (string) current(
                                        $wsdl->xpath(
                                            sprintf(
                                                $tnsXPath,
                                                $this->parseXmlType((string) $part['element'])->name
                                            )
                                        )
                                    ),
                                    $wsdlNamespaces
                                );
                            }
                        }

                        $partType = $this->validateType($xmlType->name);
                        $typeIsPrimitive = $wsdlNamespaces[$xmlType->namespacePrefix] === static::NAMESPACE_XSD;
                        $fqPartType = ($typeIsPrimitive ? '' : $this->getTypesNamespace() . '\\') . $partType;
                        $fqDocBlockPartType = ($typeIsPrimitive ? '' : '\\') . $fqPartType;

                        // create the comment
                        $documentation = new Html2Text((string) current($part->xpath('./wsdl:documentation')));
                        $doc = new DocBlockGenerator($documentation->getText());
                        $doc->setTag(new GenericTag('var', $fqDocBlockPartType));

                        // create the property
                        $property = new PropertyGenerator($partName);
                        $property->setDocBlock($doc);
                        $property->setVisibility(AbstractMemberGenerator::VISIBILITY_PUBLIC);
                        $messageClass->addPropertyFromGenerator($property);
                    }

                    // add the class to the classmap
                    $classmapConstructorBody .= $this->generateClassmapEntry($messageName, $fqMessageName);

                    // serialize the class
                    $file = new FileGenerator(array('class' => $messageClass));
                    $outputPath = "{$basePath}";

                    if ($namespaceStyle === 'psr0') {
                        $outputPath .= "/{$this->namespace}";

                        if ($this->structuredNamespace) {
                            $outputPath .= '/' . static::NAMESPACE_MESSAGE;
                        }

                        $fs->mkdir($outputPath);
                    }

                    file_put_contents("{$outputPath}/{$messageName}.php", $file->generate());
                }
            }

            // there is no need to add the service class to the classmap

            // serialize the class
            $file = new FileGenerator(array('class' => $serviceClass));
            $outputPath = "{$basePath}";

            if ($namespaceStyle === 'psr0') {
                $outputPath .= "/{$this->namespace}";

                if ($this->structuredNamespace) {
                    $outputPath .= '/';
                }

                $fs->mkdir($outputPath);
            }

            file_put_contents("{$outputPath}/{$serviceClassName}.php", $file->generate());
        }

        /*
         * GENERATE THE TYPE CLASSES
         */

        foreach ($wsdl->xpath('//wsdl:types//xsd:complexType') as $complexType) {
            $complexTypeName = (string) $complexType['name'];
echo $complexTypeName . PHP_EOL;
            // if the complex type has been defined inside an element
            if (empty($complexTypeName)) {
                $complexTypeName = (string) current($complexType->xpath('./ancestor::*[@name]/@name'));
            }

            $complexTypeName = $this->validateType($complexTypeName);
            $fqComplexTypeName = $this->getMessageClassName($complexTypeName);
            $extendedXmlType = $this->parseXmlType((string) current($complexType->xpath('.//xsd:extension/@base')));
            $extendedTypeName = $this->validateType($extendedXmlType->name);

            // create the class
            $complexTypeClass = new ClassGenerator();
            $complexTypeClass->setName($complexTypeName);
            $complexTypeClass->setAbstract((boolean) $complexType['abstract']);
            if ($extendedXmlType->name) {
                $complexTypeClass->setExtendedClass($extendedTypeName);
            }
            $complexTypeClass->setNamespaceName($this->getTypesNamespace());

            // create the constructor
            $constructor = new MethodGenerator('__construct');
            $constructorDocBlock = new DocBlockGenerator();
            $constructorBody = '';
            $complexTypeClass->addMethodFromGenerator($constructor);

            foreach ($complexType->xpath('.//xsd:element') as $element) {
                $elementName = (string) $element['name'];

                $xmlType = $this->parseXmlType((string) $element['type']);
                $elementType = $this->validateType($xmlType->name);
                $typeIsPrimitive = $wsdlNamespaces[$xmlType->namespacePrefix] === static::NAMESPACE_XSD;
                $fqElementType = ($typeIsPrimitive ? '' : $this->getTypesNamespace() . '\\') . $elementType;
                $fqDocBlockElementType = ($typeIsPrimitive ? '' : '\\') . $fqElementType;
                $elementIsNullable = false;
                $documentation = new Html2Text((string) current($element->xpath('.//wsdl:documentation')));

                // create the comment
                $doc = new DocBlockGenerator();
                $doc->setTag(new GenericTag('var', $fqDocBlockElementType));
                $doc->setShortDescription($documentation->getText());

                // create the property
                $property = new PropertyGenerator($elementName);
                $property->setDocBlock($doc);
                $property->setVisibility(
                    $accessors
                    ? AbstractMemberGenerator::VISIBILITY_PROTECTED
                    : AbstractMemberGenerator::VISIBILITY_PUBLIC
                );
                $complexTypeClass->addPropertyFromGenerator($property);

                $paramTag = new ParamTag($elementName, $fqDocBlockElementType);
                $param = new ParameterGenerator($elementName, $elementType);

                // set the element nullability
                if ($elementIsNullable or $constructorNull) {
                    $param->setDefaultValue(new ValueGenerator(null, ValueGenerator::TYPE_NULL));
                }

                // add the parameter to the constructor
                $constructorDocBlock->setTag($paramTag);

                // add the property assignment to the constructor body
                $constructorBody .= "\$this->{$elementName} = \${$elementName};" . AbstractGenerator::LINE_FEED;

                $constructor->setParameter($param);

                // if the property accessors must be generated
                if ($accessors) {
                    // create the setter
                    $accessorDocBlock = new DocBlockGenerator();
                    $accessorDocBlock->setTag($paramTag);
                    $setter = new MethodGenerator('set' . ucfirst($elementName));
                    $setter->setParameter($param);
                    $setter->setDocBlock($accessorDocBlock);
                    $setter->setBody("\$this->{$elementName} = \${$elementName};");
                    $complexTypeClass->addMethodFromGenerator($setter);

                    // create the getter
                    $accessorDocBlock = new DocBlockGenerator();
                    $accessorDocBlock->setTag(new ReturnTag($fqDocBlockElementType));
                    $getter = new MethodGenerator('get' . ucfirst($elementName));
                    $getter->setDocBlock($accessorDocBlock);
                    $getter->setBody("return \$this->{$elementName};");
                    $complexTypeClass->addMethodFromGenerator($getter);
                }
            }

            $constructor->setDocBlock($constructorDocBlock);
            $constructor->setBody($constructorBody);

            // add the class to the classmap
            $classmapConstructorBody .= $this->generateClassmapEntry($complexTypeName, $fqComplexTypeName);

            // serialize the class
            $file = new FileGenerator(array('class' => $complexTypeClass));
            $outputPath = "{$basePath}";

            if ($namespaceStyle === 'psr0') {
                $outputPath .= "/{$this->namespace}";

                if ($this->structuredNamespace) {
                    $outputPath .= '/';
                }

                if ($this->structuredNamespace) {
                    $outputPath .= '/' . static::NAMESPACE_TYPE;
                }

                $fs->mkdir($outputPath);
            }

            file_put_contents("{$outputPath}/{$complexTypeName}.php", $file->generate());
        }
        
        /*
         * GENERATE THE ENUM CLASSES
         */
        
        $simpleTypeNodes = $wsdl->getSimpleTypes();
        
        foreach (array_map(function($wsdl) {
                return $wsdl->getSimpleTypes();
            }, $this->importedSchemas) as $types) {
            $simpleTypeNodes = array_merge($simpleTypeNodes, $types);
        }
        
        foreach ($simpleTypeNodes as $simpleType) {
            $simpleTypeName = (string) $simpleType['name'];

            // if the simple type has been defined inside an element
            if (empty($simpleTypeName)) {
                $simpleTypeName = (string) current($simpleType->xpath('./ancestor::*[@name]/@name'));
            }

            $simpleTypeName = $this->validateType($simpleTypeName);
            $fqSimpleTypeName = $this->getEnumClassName($simpleTypeName);
            $extendedXmlType = $this->parseXmlType((string) current($simpleType->xpath('.//xsd:extension/@base')));
            $extendedTypeName = $this->validateType($extendedXmlType->name);

            // create the class
            $simpleTypeClass = new ClassGenerator();
            $simpleTypeClass->setName($simpleTypeName);
            if ($extendedXmlType->name) {
                // this type extends another, it will indirectly extend \SplEnum
                $simpleTypeClass->setExtendedClass($extendedTypeName);
            } elseif ($splEnums) {
                // this class has no parent in the service, so make it extend \SplEnum
                $simpleTypeClass->setExtendedClass('\SplEnum');
            }
            $simpleTypeClass->setNamespaceName($this->getEnumsNamespace());

            foreach ($simpleType->xpath('.//xsd:enumeration') as $enumeration) {
                $enumerationValue = (string) $enumeration['value'];

                // create the property
                $property = new PropertyGenerator($enumerationValue, $enumerationValue);
                $property->setConst(true);
                $simpleTypeClass->addPropertyFromGenerator($property);
            }

            // add the class to the classmap
            $classmapConstructorBody .= $this->generateClassmapEntry($simpleTypeName, $fqSimpleTypeName);

            // serialize the class
            $file = new FileGenerator(array('class' => $simpleTypeClass));
            $outputPath = "{$basePath}";

            if ($namespaceStyle === 'psr0') {
                $outputPath .= "/{$this->namespace}";

                if ($this->structuredNamespace) {
                    $outputPath .= '/';
                }

                if ($this->structuredNamespace) {
                    $outputPath .= '/' . static::NAMESPACE_ENUM;
                }

                $fs->mkdir($outputPath);
            }

            file_put_contents("{$outputPath}/{$simpleTypeName}.php", $file->generate());
        }

        // set the constructor body of the classmap class
        $classmapClass->getMethod('__construct')->setBody($classmapConstructorBody);

        // serialize the classmapping class
        $file = new FileGenerator(array('class' => $classmapClass));
        $outputPath = "{$basePath}";

        if ($namespaceStyle === 'psr0') {
            $outputPath .= "/{$this->namespace}";

            if ($this->structuredNamespace) {
                $outputPath .= '/';
            }

            $fs->mkdir($outputPath);
        }

        file_put_contents("{$outputPath}/{$classmapClassName}.php", $file->generate());

        /*
         * GENERATED CODE FIX
         */
/*
        // create the coding standards fixer
        $fixer = new Fixer();
        $config = new FixerConfig();
        $config->setDir($outputPath);

        // register all the existing fixers
        $fixer->registerBuiltInFixers();
        $config->fixers(array_filter($fixer->getFixers(), function (FixerInterface $fixer) {
            return $fixer->getLevel() === FixerInterface::PSR2_LEVEL;
        }));

        // fix the generated code
        $fixer->fix($config);
 */
    }

    protected function parseXmlType($type)
    {
        preg_match('/^((?<prefix>\w+):)?(?<name>.*$)/', $type, $matches);

        return new XmlType(
            (array_key_exists('prefix', $matches) and !empty($matches['prefix'])) ? $matches['prefix'] : null,
            (array_key_exists('name', $matches) and !empty($matches['name'])) ? $matches['name'] : null
        );
    }

    protected function isToken($string)
    {
        $tokens = token_get_all("<?php {$string} ?>");
        return $tokens[1][0] !== T_STRING;
    }

    protected function generateClassmapEntry($soapType, $phpType)
    {
        return sprintf("\$this['%s'] = '%s';", $soapType, $phpType) . AbstractGenerator::LINE_FEED;
    }

    protected function getNamespace($typeNamespace)
    {
        $fqns = '';

        if ($this->namespace) {
            $fqns .= $this->namespace;

            if ($this->structuredNamespace) {
                $fqns .= '\\' . $typeNamespace;
            }
        }

        return $fqns;
    }

    protected function getTypesNamespace()
    {
        return $this->getNamespace(static::NAMESPACE_TYPE);
    }

    protected function getEnumsNamespace()
    {
        return $this->getNamespace(static::NAMESPACE_ENUM);
    }
    
    protected function getMessagesNamespace()
    {
        return $this->getNamespace(static::NAMESPACE_MESSAGE);
    }

    protected function getTypeClassName($className)
    {
        $namespace = $this->getTypesNamespace();

        return ($namespace ? $namespace . '\\' : '') . $className;
    }

    protected function getEnumClassName($className)
    {
        $namespace = $this->getEnumsNamespace();

        return ($namespace ? $namespace . '\\' : '') . $className;
    }
    
    protected function getMessageClassName($className)
    {
        $namespace = $this->getMessagesNamespace();

        return ($namespace ? $namespace . '\\' : '') . $className;
    }

    /**
     * Validates a wsdl type against known PHP primitive types, or otherwise
     * validates the namespace of the type to PHP naming conventions
     *
     * @param string $typeName the type to test
     * @return string the validated version of the submitted type
     */
    public function validateType($typeName)
    {
        // if the given type is an array
        if (substr($typeName, -2) == "[]") {
            return $typeName;
        }
        if (strtolower(substr($typeName, 0, 7)) == "arrayof") {
            return substr($typeName, 7) . '[]';
        }

        // convert the XSD type to the corresponding PHP type
        switch (strtolower($typeName)) {
            case "int":
            case "integer":
            case "long":
            case "byte":
            case "short":
            case "negativeinteger":
            case "nonnegativeinteger":
            case "nonpositiveinteger":
            case "positiveinteger":
            case "unsignedbyte":
            case "unsignedint":
            case "unsignedlong":
            case "unsignedshort":
                return 'int';
                break;
            case "float":
            case "double":
            case "decimal":
                return 'float';
                break;
            case "<anyxml>":
            case "string":
            case "token":
            case "normalizedstring":
            case "hexbinary":
                return 'string';
                break;
            case "datetime":
                return  '\DateTime';
                break;
            case 'anytype':
                return 'mixed';
                break;
            default:
                if ($this->isToken($typeName)) {
                    $typeName .= static::NAME_SUFFIX;
                }
                break;
        }

        if ($this->isToken($typeName)) {
            $typeName .= static::NAME_SUFFIX;
        }

        return $typeName;
    }

    /**
     * Validates a name against standard PHP naming conventions
     *
     * @param string $name the name to validate
     * @return string the validated version of the submitted name
     */
    private static function validateNamingConvention($name)
    {
        // Prepend the string a to name that begins with anything but a-z This is to make a valid name
        if (preg_match('/^[A-Za-z_]/', $name) == false) {
            $name = ucfirst($name) . static::NAME_SUFFIX;
        }

        return preg_replace('/[^a-zA-Z0-9_x7f-xff]*/', '', preg_replace('/^[^a-zA-Z_x7f-xff]*/', '', $name));
    }
}
