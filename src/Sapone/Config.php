<?php

namespace Sapone;


class Config
{
    const AUTOLOADER_PSR0 = 'psr0';
    const AUTOLOADER_PSR4 = 'psr4';

    /**
     * @var string
     */
    protected $autoloader;

    /**
     * @var string
     */
    protected $wsdlDocumentPath;

    /**
     * @var string
     */
    protected $outputPath;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var bool
     */
    protected $splEnums;

    /**
     * @var bool
     */
    protected $axisNamespaces;

    /**
     * @var bool
     */
    protected $accessors;

    /**
     * @var bool
     */
    protected $besimpleClient;

    /**
     * @var bool
     */
    protected $nullConstructorArguments;

    public function __construct()
    {
        // set default values
        $this->autoloader = static::AUTOLOADER_PSR4;
        $this->splEnums = false;
        $this->axisNamespaces = false;
        $this->accessors = false;
    }

    /**
     * @return string
     */
    public function getOutputPath()
    {
        return $this->outputPath;
    }

    /**
     * @param string $outputPath
     */
    public function setOutputPath($outputPath)
    {
        $this->outputPath = $outputPath;
    }

    /**
     * @return string
     */
    public function getWsdlDocumentPath()
    {
        return $this->wsdlDocumentPath;
    }

    /**
     * @param string $wsdlDocumentPath
     */
    public function setWsdlDocumentPath($wsdlDocumentPath)
    {
        $this->wsdlDocumentPath = $wsdlDocumentPath;
    }

    /**
     * @return string
     */
    public function getAutoloader()
    {
        return $this->autoloader;
    }

    /**
     * @param string $autoloader
     */
    public function setAutoloader($autoloader)
    {
        if (!in_array(strtolower($autoloader), array(static::AUTOLOADER_PSR0, static::AUTOLOADER_PSR4))) {
            throw new \InvalidArgumentException("Invalid autoloader: '{$autoloader}'");
        }

        $this->autoloader = $autoloader;
    }

    /**
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @param string $namespace
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * @return boolean
     */
    public function isSplEnums()
    {
        return $this->splEnums;
    }

    /**
     * @param boolean $splEnums
     */
    public function setSplEnums($splEnums)
    {
        $this->splEnums = $splEnums;
    }

    /**
     * @return boolean
     */
    public function isAxisNamespaces()
    {
        return $this->axisNamespaces;
    }

    /**
     * @param boolean $axisNamespaces
     */
    public function setAxisNamespaces($axisNamespaces)
    {
        $this->axisNamespaces = $axisNamespaces;
    }

    /**
     * @return boolean
     */
    public function isAccessors()
    {
        return $this->accessors;
    }

    /**
     * @param boolean $accessors
     */
    public function setAccessors($accessors)
    {
        $this->accessors = $accessors;
    }

    /**
     * @return boolean
     */
    public function isNullConstructorArguments()
    {
        return $this->nullConstructorArguments;
    }

    /**
     * @param boolean $nullConstructorArguments
     */
    public function setNullConstructorArguments($nullConstructorArguments)
    {
        $this->nullConstructorArguments = $nullConstructorArguments;
    }

    /**
     * @return boolean
     */
    public function isBesimpleClient()
    {
        return $this->besimpleClient;
    }

    /**
     * @param boolean $besimpleClient
     */
    public function setBesimpleClient($besimpleClient)
    {
        $this->besimpleClient = $besimpleClient;
    }
}
