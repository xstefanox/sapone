<?php

namespace Sapone\Command;

use Sapone\Config;
use Sapone\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command class for the generator command line interface
 */
class GenerateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('generate')
            ->addArgument(
                'wsdl-path',
                InputArgument::REQUIRED,
                'The path to the wsdl'
            )
            ->addArgument(
                'output-path',
                InputArgument::REQUIRED,
                'The path to the generated code'
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'The namespace of the generated code'
            )
            ->addOption(
                'axis-namespaces',
                null,
                InputOption::VALUE_NONE,
                'Put the generated classes into Apache Axis style namespaces, derived from the XMLSchema namespaces'
            )
            ->addOption(
                'autoloader',
                null,
                InputOption::VALUE_REQUIRED,
                'The style of generated autoloader [psr0|psr4]',
                'psr4'
            )
            ->addOption(
                'spl-enums',
                null,
                InputOption::VALUE_NONE,
                'Make the enum classes extend SPL enums'
            )
            ->addOption(
                'accessors',
                null,
                InputOption::VALUE_NONE,
                'Enable the generation of setters/getters'
            )
            ->addOption(
                'null-constructor-arguments',
                null,
                InputOption::VALUE_NONE,
                'Default every constructor argument to null'
            )
            ->addOption(
                'besimple-client',
                null,
                InputOption::VALUE_NONE,
                'Extend BeSimpleSoapClient'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = new Config();

        $config->setWsdlDocumentPath($input->getArgument('wsdl-path'));
        $config->setOutputPath($input->getArgument('output-path'));

        if ($input->getOption('namespace')) {
            $config->setNamespace($input->getOption('namespace'));
        }

        if ($input->getOption('axis-namespaces')) {
            $config->setAxisNamespaces($input->getOption('axis-namespaces'));
        }

        if ($input->getOption('autoloader')) {
            $config->setAutoloader($input->getOption('autoloader'));
        }

        if ($input->getOption('spl-enums')) {
            $config->setSplEnums(true);
        }

        if ($input->getOption('accessors')) {
            $config->setAccessors(true);
        }

        if ($input->getOption('null-constructor-arguments')) {
            $config->setNullConstructorArguments(true);
        }

        $generator = new Generator($config);
        $generator->generate();
    }

    protected function convertXsdTypeToPhpType($typeName)
    {
        $isArray = false;
        $pregResult = preg_match("/^(ArrayOf(?<t1>\w+)|(?<t2>\w+)\[\])$/i", $typeName, $matches);

        if ($pregResult === false) {
            throw new \Exception(preg_last_error());
        }

        // if the given type is an array
        if ($pregResult) {
            $isArray = true;
            $typeName = $matches['t1'] ? $matches['t1'] : $matches['t2'];
        }

        switch (strtolower($typeName)) {
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

        return $phpType . '[]';
    }
}
