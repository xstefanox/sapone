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

    /**
     * The suffix to append to invalid names.
     *
     * @var string
     */
    const NAME_SUFFIX = '_';

    protected $namespace;
    protected $structuredNamespace;
    
    protected function configure()
    {
        $this
            ->setName('generate')
            ->addArgument(
                'input',
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*
         * INPUT VALIDATION
         */
        $wsdlPath = $input->getArgument('input');
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

        /*
         * OUTPUT PREPARATION
         */
        $fs = new Filesystem();
        $fs->mkdir($basePath);

        /*
         * LOAD THE WSDL DOCUMENT
         */
        $wsdl = simplexml_load_file($wsdlPath);
        $wsdl->registerXPathNamespace('s', static::NAMESPACE_XSD);
        $wsdl->registerXPathNamespace('wsdl', static::NAMESPACE_WSDL);
        $wsdlNamespaces = $wsdl->getDocNamespaces();

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
                $fqInputMessageType = $this->getMessagesClassName($inputMessageType);
                $fqOutputMessageType = $this->getMessagesClassName($outputMessageType);

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
                    $fqMessageName = $this->getMessagesClassName($messageName);

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
                                        '//wsdl:types//s:element[@name="%s"]',
                                        $this->parseXmlType((string) $part['element'])->name
                                    )
                                )
                            );

                            // if the element references a type
                            if ($element['type']) {
                                $xmlType = $this->parseXmlType((string) $element['type']);
                            } else {
                                // the element type is defined inline
                                $xmlType = $this->parseXmlType((string) $element['name']);
                            }

                            // if the element uses the current target namespace
                            $tnsXPath = '//wsdl:types//s:element[@name="%s"]/' .
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

        foreach ($wsdl->xpath('//wsdl:types//s:complexType') as $complexType) {
            $complexTypeName = (string) $complexType['name'];

            // if the complex type has been defined inside an element
            if (empty($complexTypeName)) {
                $complexTypeName = (string) current($complexType->xpath('./ancestor::*[@name]/@name'));
            }

            $complexTypeName = $this->validateType($complexTypeName);
            $fqComplexTypeName = $this->getMessagesClassName($complexTypeName);
            $extendedXmlType = $this->parseXmlType((string) current($complexType->xpath('.//s:extension/@base')));
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

            foreach ($complexType->xpath('.//s:element') as $element) {
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

        foreach ($wsdl->xpath('//wsdl:types//s:simpleType') as $simpleType) {
            $simpleTypeName = (string) $simpleType['name'];

            // if the simple type has been defined inside an element
            if (empty($simpleTypeName)) {
                $simpleTypeName = (string) current($simpleType->xpath('./ancestor::*[@name]/@name'));
            }

            $simpleTypeName = $this->validateType($simpleTypeName);
            $fqSimpleTypeName = $this->getTypesClassName($simpleTypeName);
            $extendedXmlType = $this->parseXmlType((string) current($simpleType->xpath('.//s:extension/@base')));
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
            $simpleTypeClass->setNamespaceName($this->getTypesNamespace());

            foreach ($simpleType->xpath('.//s:enumeration') as $enumeration) {
                $enumerationValue = (string) $enumeration['value'];

                // create the property
                $property = new PropertyGenerator($this->validateType($enumerationValue), $enumerationValue);
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
                    $outputPath .= '/' . static::NAMESPACE_TYPE;
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

    protected function getTypesNamespace()
    {
        return $this->getNamespace(static::NAMESPACE_TYPE);
    }

    protected function getMessagesNamespace()
    {
        return $this->getNamespace(static::NAMESPACE_MESSAGE);
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

    protected function getTypesClassName($className)
    {
        $namespace = $this->getTypesNamespace();

        return ($namespace ? $namespace . '\\' : '') . $className;
    }

    protected function getMessagesClassName($className)
    {
        $namespace = $this->getMessagesNamespace();

        return ($namespace ? $namespace . '\\' : '') . $className;
    }

    protected function isValidClassOrMethodName($string)
    {
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
        if (substr($typeName, -2) == "[]") {
            return $typeName;
        }
        if (strtolower(substr($typeName, 0, 7)) == "arrayof") {
            return substr($typeName, 7) . '[]';
        }

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
                $typeName = self::validateNamingConvention($typeName);
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
        // Prepend the string a to names that begin with anything but a-z This is to make a valid name
        if (preg_match('/^[A-Za-z_]/', $name) == false) {
            $name = ucfirst($name) . static::NAME_SUFFIX;
        }

        return preg_replace('/[^a-zA-Z0-9_x7f-xff]*/', '', preg_replace('/^[^a-zA-Z_x7f-xff]*/', '', $name));
    }
}
