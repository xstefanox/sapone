<?php

namespace Sapone\Builder;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\SchemaReader;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\PropertyGenerator;

class EnumBuilder extends AbstractBuilder
{
    protected function finalize(ClassGenerator $class, Type $type)
    {
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
            $property->setName(filter_var($enum['value'], FILTER_CALLBACK, array('options' => array($this, 'sanitizeVariableName'))), $enum['value']);
            $property->setName(filter_var($property->getName(), FILTER_CALLBACK, array('options' => array($this, 'sanitizeConstantName'))), $property->getName());
            $property->setConst(true);
            $property->setDefaultValue($enum['value']);

            if ($enum['doc']) {
                $property->setDocBlock(new DocBlockGenerator($enum['doc']));
            }

            $class->addPropertyFromGenerator($property);
        }
    }
}
