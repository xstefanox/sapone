<?php

namespace Sapone\Builder;

use Goetas\XML\XSDReader\Schema\Type\Type;

interface BuilderInterface
{
    public function buildClass(Type $type);
}
