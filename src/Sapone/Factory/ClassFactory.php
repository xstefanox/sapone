<?php

namespace Sapone\Factory;

use Goetas\XML\XSDReader\Schema\Type\ComplexType;
use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\SchemaReader;
use Html2Text\Html2Text;
use Sapone\Config;
use Sapone\Util\NamespaceInflector;
use Sapone\Util\SimpleXMLElement;
use Symfony\Component\Filesystem\Filesystem;
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

/**
 * Factory for the classes generated from the configured WSDL document
 */
class ClassFactory implements ClassFactoryInterface
{
    /**
     * The suffix to append to invalid names.
     *
     * @var string
     */
    const INVALID_NAME_SUFFIX = '_';

    /**
     * @var \Sapone\Config
     */
    protected $config;

    /**
     * @var \Sapone\Util\NamespaceInflector
     */
    protected $namespaceInflector;

    /**
     * @var \Goetas\XML\XSDReader\Schema\Schema[]
     */
    protected $schemas;

    /**
     * @var \Goetas\XML\XSDReader\Schema\Type\Type[]
     */
    protected $types;

    /**
     * @var string[]
     */
    protected $classmap;

    /**
     * @param \Sapone\Config $config
     * @param \Goetas\XML\XSDReader\Schema\Schema[] $schemas
     * @param \Goetas\XML\XSDReader\Schema\Type\Type[] $types
     */
    public function __construct(Config $config, array $schemas, array $types)
    {
        $this->config = $config;
        $this->schemas = $schemas;
        $this->types = $types;
        $this->namespaceInflector = new NamespaceInflector($config);
        $this->classmap = array();
    }

    /**
     * Create a skeleton class from the given XML type
     *
     * @param Type $type
     * @return \Zend\Code\Generator\ClassGenerator
     */
    protected function createClassFromType(Type $type)
    {
        $targetNamespace = $type->getSchema()->getTargetNamespace();

        $namespace = $this->namespaceInflector->inflectNamespace($type);

        // create the class
        $class = new ClassGenerator();
        $class->setName($type->getName());

        if ($namespace) {
            $class->setNamespaceName($namespace);
        }

        // set the class documentation
        $docDescription = new Html2Text($type->getDoc());
        $doc = new DocBlockGenerator($docDescription->getText());
        $doc->setTag(new GenericTag('xmlns', $targetNamespace));
        $class->setDocBlock($doc);

        return $class;
    }

    /**
     * Serialize to disk the given class code
     *
     * @param \Zend\Code\Generator\ClassGenerator $class
     */
    protected function serializeClass(ClassGenerator $class)
    {
        // serialize the class
        $fs = new Filesystem();
        $file = new FileGenerator(array('class' => $class));
        $outputPath = array($this->config->getOutputPath());

        // if the psr0 autoloader has been selected, transform the class namespace into a filesystem path
        if ($this->config->getAutoloader() === Config::AUTOLOADER_PSR0) {
            $outputPath[] = str_ireplace('\\', DIRECTORY_SEPARATOR, $class->getNamespaceName());
        }

        // append the file name
        $outputPath[] = $class->getName() . '.php';

        // finalize the output path
        $outputPath = implode(DIRECTORY_SEPARATOR, $outputPath);

        $fs->mkdir(dirname($outputPath));
        file_put_contents($outputPath, $file->generate());
    }

    /**
     * {@inheritdoc}
     */
    public function createEnum(Type $type)
    {
        $class = $this->createClassFromType($type);

        $parentType = $type->getParent()->getBase();

        // set the parent class
        if ($parentType->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS) {
            // this enum extends another, it will indirectly extend \SplEnum
            $class->setExtendedClass($parentType->getName());
        } elseif ($this->config->isSplEnums()) {
            // this enum has no parent, so make it extend \SplEnum
            $class->setExtendedClass('\SplEnum');
        }

        // create the class constants
        $checks = $type->getRestriction()->getChecks();

        foreach ($checks['enumeration'] as $enum) {
            $property = new PropertyGenerator();
            $property->setName(
                filter_var(
                    $enum['value'],
                    FILTER_CALLBACK,
                    array('options' => array($this, 'sanitizeVariableName'))
                )
            );
            $property->setName(
                filter_var(
                    $property->getName(),
                    FILTER_CALLBACK,
                    array('options' => array($this, 'sanitizeConstantName'))
                )
            );
            $property->setConst(true);
            $property->setDefaultValue($enum['value']);

            if ($enum['doc']) {
                $docDescription = new Html2Text($enum['doc']);
                $property->setDocBlock(new DocBlockGenerator($docDescription->getText()));
            }

            $class->addPropertyFromGenerator($property);
        }

        $this->serializeClass($class);

        // register the class in the classmap
        $this->classmap[$class->getName()] = $class->getNamespaceName() . '\\' . $class->getName();

        return $class->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function createDTO(Type $type)
    {
        $class = $this->createClassFromType($type);

        $parentType = $type->getParent();

        // set the parent class
        if ($parentType) {
            $parentType = $parentType->getBase();

            if ($parentType->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS) {
                $class->setExtendedClass($parentType->getName());
            }
        }

        // check if the type is abstract
        $class->setAbstract($type->isAbstract());

        // create the constructor
        $constructor = new MethodGenerator('__construct');
        $constructorDoc = new DocBlockGenerator();
        $constructorBody = '';

        $constructor->setDocBlock($constructorDoc);
        $class->addMethodFromGenerator($constructor);

        /* @var \Goetas\XML\XSDReader\Schema\Type\ComplexType $type */
        foreach ($type->getElements() as $element) {
            /* @var \Goetas\XML\XSDReader\Schema\Element\Element $element */

            $docElementType = $this->namespaceInflector->inflectDocBlockQualifiedName($element->getType());
            $elementName = $element->getName();

            // create a param and a param tag used in constructor and setter docs
            $param = new ParameterGenerator(
                $elementName,
                $this->namespaceInflector->inflectQualifiedName($element->getType())
            );
            $paramTag = new ParamTag($elementName, $docElementType);

            // set the parameter nullability
            if ($element->isNil() or $this->config->isNullConstructorArguments()) {
                $param->setDefaultValue(new ValueGenerator(null, ValueGenerator::TYPE_NULL));
            }

            /*
             * PROPERTY CREATION
             */

            $docDescription = new Html2Text($type->getDoc());

            $doc = new DocBlockGenerator();
            $doc->setShortDescription($docDescription->getText());
            $doc->setTag(new GenericTag('var', $docElementType));

            $property = new PropertyGenerator();
            $property->setDocBlock($doc);
            $property->setName(
                filter_var($elementName, FILTER_CALLBACK, array('options' => array($this, 'sanitizeVariableName')))
            );
            $property->setVisibility(
                $this->config->isAccessors()
                ? AbstractMemberGenerator::VISIBILITY_PROTECTED
                : AbstractMemberGenerator::VISIBILITY_PUBLIC
            );

            $class->addPropertyFromGenerator($property);

            /*
             * IMPORTS
             */

            if (
                $element->getType()->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS
                and
                $this->namespaceInflector->inflectNamespace($element->getType()) !== $class->getNamespaceName()
            ) {
                $class->addUse($this->namespaceInflector->inflectQualifiedName($element->getType()));
            }

            /*
             * CONSTRUCTOR PARAM CREATION
             */

            $constructorDoc->setTag($paramTag);
            $constructorBody .= "\$this->{$elementName} = \${$elementName};" . AbstractGenerator::LINE_FEED;
            $constructor->setParameter($param);

            /*
             * ACCESSORS CREATION
             */

            if ($this->config->isAccessors()) {
                // create the setter
                $setterDoc = new DocBlockGenerator();
                $setterDoc->setTag($paramTag);

                $setter = new MethodGenerator('set' . ucfirst($elementName));
                $setter->setParameter($param);
                $setter->setDocBlock($setterDoc);
                $setter->setBody("\$this->{$elementName} = \${$elementName};");
                $class->addMethodFromGenerator($setter);

                // create the getter
                $getterDoc = new DocBlockGenerator();
                $getterDoc->setTag(new ReturnTag($docElementType));
                $getter = new MethodGenerator('get' . ucfirst($elementName));
                $getter->setDocBlock($getterDoc);
                $getter->setBody("return \$this->{$elementName};");
                $class->addMethodFromGenerator($getter);
            }
        }

        // finalize the constructor body
        $constructor->setBody($constructorBody);

        $this->serializeClass($class);

        // register the class in the classmap
        $this->classmap[$class->getName()] = $class->getNamespaceName() . '\\' . $class->getName();

        return $class->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function createService(SimpleXMLElement $service)
    {
        // read the service name
        $serviceName = (string) $service['name'];
        $serviceClassName = $serviceName;
        $serviceClassName = filter_var(
            $serviceClassName,
            FILTER_CALLBACK,
            array('options' => array($this, 'sanitizeVariableName'))
        );
        $serviceClassName = filter_var(
            $serviceClassName,
            FILTER_CALLBACK,
            array('options' => array($this, 'sanitizeConstantName'))
        );

        /*
         * CLASS CREATION
         */

        // create the class
        $class = ClassGenerator::fromReflection(new ClassReflection('\Sapone\Template\ServiceTemplate'));
        $class->setName($serviceClassName);
        $class->setNamespaceName($this->namespaceInflector->inflectNamespace($service));

        // set the correct inheritance
        if ($this->config->isBesimpleClient()) {
            $class->setExtendedClass('BeSimpleSoapClient');
            $class->addUse('BeSimple\SoapClient\SoapClient');
        } else {
            $class->setExtendedClass('\SoapClient');
        }

        // read the service documentation
        $serviceDoc = new DocBlockGenerator();
        $serviceDoc->setTag(new GenericTag('xmlns', '@todo'));
        $documentation = new Html2Text((string) current($service->xpath('./wsdl:documentation')));
        if ($documentation->getText()) {
            $serviceDoc->setShortDescription($documentation->getText());
        }
        $class->setDocBlock($serviceDoc);

        /*
         * METHODS CREATION
         */

        foreach ($service->xpath('.//wsdl:operation') as $operation) {
            $operationName = (string) $operation['name'];
            $operationName = filter_var(
                $operationName,
                FILTER_CALLBACK,
                array('options' => array($this, 'sanitizeVariableName'))
            );
            $operationName = filter_var(
                $operationName,
                FILTER_CALLBACK,
                array('options' => array($this, 'sanitizeConstantName'))
            );

            $inputMessageType = $this->getTypeFromQualifiedString(
                (string) current($operation->xpath('.//wsdl:input/@message')),
                $operation
            );
            $outputMessageType = $this->getTypeFromQualifiedString(
                (string) current($operation->xpath('.//wsdl:output/@message')),
                $operation
            );

            // read the service documentation
            $messageDoc = new DocBlockGenerator();
            $documentation = new Html2Text((string) current($operation->xpath('./wsdl:documentation')));
            $param = new ParameterGenerator('parameters', $inputMessageType);
            $messageDoc->setTag(
                new ParamTag(
                    $param->getName(),
                    $this->namespaceInflector->inflectDocBlockQualifiedName($inputMessageType)
                )
            );
            $messageDoc->setTag(
                new ReturnTag($this->namespaceInflector->inflectDocBlockQualifiedName($outputMessageType))
            );
            if ($documentation->getText()) {
                $messageDoc->setShortDescription($documentation->getText());
            }

            // create the method
            $method = new MethodGenerator($operationName);
            $method->setDocBlock($messageDoc);
            $method->setParameter($param);
            $method->setBody("return \$this->__soapCall('{$operation['name']}', array(\$parameters));");
            $class->addMethodFromGenerator($method);
        }

        $this->serializeClass($class);

        // register the class in the classmap
        $this->classmap[$class->getName()] = $class->getNamespaceName() . '\\' . $class->getName();

        return $class->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function createClassmap()
    {
        /*
         * INI FILE GENERATION
         */

        $outputPath = array($this->config->getOutputPath());

        // if the psr0 autoloader has been selected, transform the class namespace into a filesystem path
        if ($this->config->getAutoloader() === Config::AUTOLOADER_PSR0) {
            $outputPath[] = str_ireplace('\\', DIRECTORY_SEPARATOR, $this->config->getNamespace());
        }

        // append the file name
        $outputPath[] = 'classmap.ini';

        // finalize the output path
        $outputPath = implode(DIRECTORY_SEPARATOR, $outputPath);

        // remove the file if exists
        $fs = new Filesystem();
        $fs->remove($outputPath);

        foreach ($this->classmap as $wsdlType => $phpType) {
            file_put_contents($outputPath, "{$wsdlType} = {$phpType}" . AbstractGenerator::LINE_FEED, FILE_APPEND);
        }

        /*
         * CLASS GENERATION
         */

        // create the class
        $class = ClassGenerator::fromReflection(new ClassReflection('\Sapone\Template\ClassmapTemplate'));
        $class->setName('Classmap');
        $class->setExtendedClass('\ArrayObject');
        $class->setNamespaceName($this->config->getNamespace());

        $doc = new DocBlockGenerator();
        $doc->setTag(new GenericTag('@service', $this->config->getWsdlDocumentPath()));
        $class->setDocBlock($doc);

        $this->serializeClass($class);

        return $class->getName();
    }

    /**
     * @param string $qualifiedTypeName
     * @param \Sapone\Util\SimpleXMLElement $context
     * @return \Goetas\XML\XSDReader\Schema\Type\Type
     */
    public function getTypeFromQualifiedString($qualifiedTypeName, SimpleXMLElement $context)
    {
        $parsedTypeName = SimpleXMLElement::parseQualifiedXmlType($qualifiedTypeName);

        $namespaces = $context->getDocNamespaces();

        $schema = null;

        foreach ($this->schemas as $schema) {
            if ($schema->getTargetNamespace() === $namespaces[$parsedTypeName['prefix']]) {
                break;
            }
        }

        return new ComplexType($schema, $parsedTypeName['name']);
    }

    /**
     * Sanitize the given string to make it usable as a PHP variable
     *
     * @param $string
     * @return string
     */
    protected function sanitizeVariableName($string)
    {
        return preg_replace('/\s/', '_', $string);
    }

    /**
     * Sanitize the given string to make it usable as a PHP constant
     *
     * @param $string
     * @return string
     */
    protected function sanitizeConstantName($string)
    {
        if ($this->isReservedWord($string)) {
            $string .= static::INVALID_NAME_SUFFIX;
        }

        return $string;
    }

    /**
     * Check if the given string is a PHP reserved word
     *
     * @param $string
     * @return bool
     */
    protected function isReservedWord($string)
    {
        $tokens = token_get_all("<?php {$string} ?>");
        return $tokens[1][0] !== T_STRING;
    }
}
