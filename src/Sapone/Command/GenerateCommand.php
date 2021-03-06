<?php

namespace Sapone\Command;

use Sapone\Config;
use Sapone\Event;
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

        $generator->getEventDispatcher()->addListener(Event::ENUM_CREATE, function (Event $event) use ($output) {
            $output->writeln('<info> * </info>Enum class created: ' . $event->getClassName());
        });

        $generator->getEventDispatcher()->addListener(Event::DTO_CREATE, function (Event $event) use ($output) {
            $output->writeln('<info> * </info>Data Transfer Object class created: ' . $event->getClassName());
        });

        $generator->getEventDispatcher()->addListener(Event::SERVICE_CREATE, function (Event $event) use ($output) {
            $output->writeln('<info> * </info>Service class created: ' . $event->getClassName());
        });

        $generator->getEventDispatcher()->addListener(Event::CLASSMAP_CREATE, function (Event $event) use ($output) {
            $output->writeln('<info> * </info>Classmap class created: ' . $event->getClassName());
        });

        $generator->generate();
    }
}
