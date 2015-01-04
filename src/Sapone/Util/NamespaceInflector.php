<?php

namespace Sapone\Util;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\SchemaReader;
use League\Url\Url;
use Sapone\Config;

class NamespaceInflector
{
    /**
     * @var \Sapone\Config
     */
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Determine the namespace of a type from the XMLSchema
     *
     * @param Type $type
     * @return string
     */
    public function inflectNamespace(Type $type)
    {

        if ($type->getSchema()->getTargetNamespace() == SchemaReader::XSD_NS) {

            // XMLSchema primitive types do not have a namespace
            $namespace = null;

        } else {

            $namespace = array();

            // prepend the base namespace
            if ($this->config->getNamespace()) {
                $namespace[] = $this->config->getNamespace();
            }

            if ($this->config->isAxisNamespaces()) {

                // append the XMLSchema namespace, formatted in Apache Axis style
                $url = Url::createFromUrl($type->getSchema()->getTargetNamespace());

                // the namespace is an url
                $namespace = array_merge($namespace, array_reverse(explode('.', $url->getHost()->get())));

                if (!empty($url->getPath()->get())) {
                    $namespace = array_merge($namespace, explode('/', $url->getPath()->get()));
                }
            }

            $namespace = implode('\\', $namespace);

        }

        return $namespace;
    }

    public function inflectQualifiedName(Type $type)
    {
        $namespace = $this->inflectNamespace($type);
        return ($namespace ? $namespace . '\\' : '') . $type->getName();
    }

    public function inflectDocBlockQualifiedName(Type $type)
    {
        return ($type->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS ? '\\' : '') . $this->inflectQualifiedName($type);
    }
}
