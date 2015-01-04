<?php

namespace Sapone\Builder;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\SchemaReader;
use Zend\Code\Generator\AbstractGenerator;
use Zend\Code\Generator\AbstractMemberGenerator;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Generator\ValueGenerator;

class ClassBuilder extends AbstractBuilder
{
    protected function finalize(ClassGenerator $class, Type $type)
    {
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
            $param = new ParameterGenerator($elementName, $element->getType());
            $paramTag = new ParamTag($elementName, $docElementType);

            // set the parameter nullability
            if ($element->isNil()) {
                $param->setDefaultValue(new ValueGenerator(null, ValueGenerator::TYPE_NULL));
            }

            /*
             * PROPERTY CREATION
             */

            $doc = new DocBlockGenerator();
            $doc->setShortDescription($element->getDoc());
            $doc->setTag(new GenericTag('var', $docElementType));

            $property = new PropertyGenerator();
            $property->setDocBlock($doc);
            $property->setName(filter_var($elementName, FILTER_CALLBACK, array('options' => array($this, 'sanitizeVariableName'))));
            $property->setVisibility(
                $this->config->isAccessors()
                    ? AbstractMemberGenerator::VISIBILITY_PROTECTED
                    : AbstractMemberGenerator::VISIBILITY_PUBLIC
            );

            $class->addPropertyFromGenerator($property);

            /*
             * IMPORTS
             */

            if ($element->getType()->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS and $this->namespaceInflector->inflectNamespace($element->getType()) !== $class->getNamespaceName()) {
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
    }
}
