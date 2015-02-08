<?php

namespace Sapone\Util;

use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\SchemaReader;
use League\Url\Url;
use Sapone\Config;

/**
 * Helper class used to translate a XML namespace into a PHP namespace
 */
class NamespaceInflector
{
    /**
     * @var \Sapone\Config
     */
    protected $config;

    /**
     * @param \Sapone\Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Determine the namespace of a type from the XMLSchema
     *
     * @param \Goetas\XML\XSDReader\Schema\Type\Type|\Sapone\Util\SimpleXMLElement $type
     * @return string
     */
    public function inflectNamespace($type)
    {
        if ($type instanceof Type) {
            if ($type->getSchema()->getTargetNamespace() === SchemaReader::XSD_NS) {
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
        } elseif ($type instanceof SimpleXMLElement) {
            if ($type->getNamespace() === SchemaReader::XSD_NS) {
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
                    $url = Url::createFromUrl($type->getNamespace());

                    // the namespace is an url
                    $namespace = array_merge($namespace, array_reverse(explode('.', $url->getHost()->get())));

                    if (!empty($url->getPath()->get())) {
                        $namespace = array_merge($namespace, explode('/', $url->getPath()->get()));
                    }
                }

                $namespace = implode('\\', $namespace);
            }

            return $namespace;
        } else {
            throw new \InvalidArgumentException(
                'Expected an instance of Goetas\XML\XSDReader\Schema\Type\Type or Sapone\Util\SimpleXMLElement'
            );
        }
    }

    /**
     * Determine the name of a type from the XMLSchema
     * @param Type $type
     * @return string
     */
    public function inflectName(Type $type)
    {
        $name = $type->getName();

        if ($type->getSchema()->getTargetNamespace() === SchemaReader::XSD_NS) {
            // convert to the corresponding PHP type
            $name = $this->convertXsdTypeToPhpType($name);
        }

        return $name;
    }

    /**
     * Determine the fully qualified name of a type from the XMLSchema
     *
     * @param \Goetas\XML\XSDReader\Schema\Type\Type|\Sapone\Util\SimpleXMLElement $type
     * @return string
     */
    public function inflectQualifiedName(Type $type)
    {
        $name = $this->extractArrayType($type->getName());

        // if the given type is a XSD primitive type
        if ($type->getSchema()->getTargetNamespace() === SchemaReader::XSD_NS) {
            // convert to the corresponding PHP type
            $qualifiedName = $this->convertXsdTypeToPhpType($name);
        } else {
            // preprend the namespace
            $namespace = $this->inflectNamespace($type);
            $qualifiedName = ($namespace ? $namespace . '\\' : '') . $name;
        }

        return $qualifiedName;
    }

    /**
     * Determine the fully qualified name of a type from the XMLSchema for a DocBlock comment, prepending the type with
     * a '\'
     *
     * @param \Goetas\XML\XSDReader\Schema\Type\Type|\Sapone\Util\SimpleXMLElement $type
     * @return string
     */
    public function inflectDocBlockQualifiedName(Type $type)
    {
        $result = $type->getSchema()->getTargetNamespace() !== SchemaReader::XSD_NS ? '\\' : '';
        $result .= $this->inflectQualifiedName($type);

        return $result;
    }


    protected function extractArrayType($typeName)
    {
        $pregResult = preg_match('/^(ArrayOf(?<ArrayOf>\w+)|(?<braces>\w+)\[\])$/i', $typeName, $matches);

        if ($pregResult === false) {
            throw new \Exception(preg_last_error());
        }

        // if the given type is an array
        if ($pregResult) {
            $typeName = $matches['ArrayOf'] ? $matches['ArrayOf'] : $matches['braces'];
        }

        return $typeName;
    }

    /**
     * Convert the given XSD primitive type into a corresponding PHP primitive type
     *
     * @param string $typeName
     * @return string
     * @throws \Exception
     */
    protected function convertXsdTypeToPhpType($typeName)
    {
        switch (strtolower($this->extractArrayType($typeName))) {
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
                $phpType = 'int';
                break;
            case "float":
            case "double":
            case "decimal":
                $phpType = 'float';
                break;
            case "<anyxml>":
            case "string":
            case "token":
            case "normalizedstring":
            case "hexbinary":
                $phpType = 'string';
                break;
            case "datetime":
                $phpType =  '\DateTime';
                break;
            case 'anytype':
                $phpType = 'mixed';
                break;
            default:
                $phpType = $typeName;
                break;
        }

        return $phpType;
    }
}
