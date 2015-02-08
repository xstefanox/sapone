<?php

namespace Sapone\Test;

use Sapone\Config;
use Sapone\Generator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Test case for the code generator
 */
class GeneratorTest extends \PHPUnit_Framework_TestCase
{
    public function testCodeGeneration()
    {
        $outputPath = __DIR__ . '/generated';

        $config = new Config();

        // wsdl document
        $config->setWsdlDocumentPath(__DIR__ . '/resources/service.wsdl');

        // output path
        $config->setOutputPath($outputPath);

        // parameters
        $config->setNamespace('Sapone\Test\GeneratedCode');
        $config->setAutoloader(Config::AUTOLOADER_PSR0);
        $config->setSplEnums(true);
        $config->setAccessors(true);

        // generate the code
        $generator = new Generator($config);
        $generator->generate();

        // assert that the generated code is syntactically correct
        foreach (Finder::create()->in($outputPath)->name('*.php')->files() as $file) {
            $process = new Process(sprintf('php -l %s', escapeshellarg($file)));
            $process->run();

            $this->assertTrue($process->isSuccessful());
        }
    }
}
