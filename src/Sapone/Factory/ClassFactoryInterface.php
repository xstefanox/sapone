<?php

namespace Sapone\Factory;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Sapone\Util\SimpleXMLElement;

interface ClassFactoryInterface
{
    public function createEnum(Type $type);

    public function createDTO(Type $type);

    public function createService(SimpleXMLElement $wsdl);

    public function createClassmap();
}
