<?php

namespace Sapone\Builder;

use Goetas\XML\XSDReader\Schema\Type\Type;
use League\Url\Url;
use Sapone\Config;
use Sapone\Util\NamespaceInflector;
use Symfony\Component\Filesystem\Filesystem;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;

abstract class AbstractBuilder implements BuilderInterface
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

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->namespaceInflector = new NamespaceInflector($config);
    }

    public function buildClass(Type $type)
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
        $classDocs = new DocBlockGenerator($type->getDoc());
        $classDocs->setTag(new GenericTag('xmlns', $targetNamespace));
        $class->setDocBlock($classDocs);

        $this->finalize($class, $type);

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

    protected abstract function finalize(ClassGenerator $class, Type $type);

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
