<?php

namespace Sapone\Factory;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Sapone\Util\SimpleXMLElement;

/**
 * Interface for the generated code factory
 */
interface ClassFactoryInterface
{
    /**
     * Create an enum class from the given XML type
     *
     * @param \Goetas\XML\XSDReader\Schema\Type\Type $type
     */
    public function createEnum(Type $type);

    /**
     * Create a Data Transfer Object class from the given XML type
     *
     * @param \Goetas\XML\XSDReader\Schema\Type\Type $type
     */
    public function createDTO(Type $type);

    /**
     * Create a Service class from the given XML element
     *
     * @param \Sapone\Util\SimpleXMLElement $service
     */
    public function createService(SimpleXMLElement $service);

    /**
     * Create the classmap class for the generated classes
     */
    public function createClassmap();
}
