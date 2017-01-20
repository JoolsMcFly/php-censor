<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCensor\Plugin;

use PHPCensor\Builder;
use PHPCensor\Helper\Lang;
use PHPCensor\Model\Build;
use PHPCensor\Model\BuildError;
use PHPCensor\Plugin\Util\TestResultParsers\Codeception as Parser;
use PHPCensor\Plugin;
use Symfony\Component\Yaml\Parser as YamlParser;
use PHPCensor\ZeroConfigPluginInterface;
use Psr\Log\LogLevel;

/**
 * Codeception Plugin - Enables full acceptance, unit, and functional testing.
 * 
 * @author       Don Gilbert <don@dongilbert.net>
 * @author       Igor Timoshenko <contact@igortimoshenko.com>
 * @author       Adam Cooper <adam@networkpie.co.uk>
 * @package      PHPCI
 * @subpackage   Plugins
 */
class Codeception extends Plugin implements ZeroConfigPluginInterface
{
    /** @var string */
    protected $args = '';

    /**
     * @var string $ymlConfigFile The path of a yml config for Codeception
     */
    protected $ymlConfigFile;

    /**
     * @var string $path The path to the codeception tests folder.
     */
    protected $path;

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'codeception';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        $this->path = 'tests' . DIRECTORY_SEPARATOR . '_output' . DIRECTORY_SEPARATOR;

        if (empty($options['config'])) {
            $this->ymlConfigFile = self::findConfigFile($this->builder->buildPath);
        } else {
            $this->ymlConfigFile = $options['config'];
        }
        if (isset($options['args'])) {
            $this->args = (string) $options['args'];
        }
    }

    /**
     * @param $stage
     * @param Builder $builder
     * @param Build $build
     * @return bool
     */
    public static function canExecute($stage, Builder $builder, Build $build)
    {
        return $stage == 'test' && !is_null(self::findConfigFile($builder->buildPath));
    }

    /**
     * Try and find the codeception YML config file.
     * @param $buildPath
     * @return null|string
     */
    public static function findConfigFile($buildPath)
    {
        if (file_exists($buildPath . 'codeception.yml')) {
            return 'codeception.yml';
        }

        if (file_exists($buildPath . 'codeception.dist.yml')) {
            return 'codeception.dist.yml';
        }

        return null;
    }

    /**
     * Runs Codeception tests
     */
    public function execute()
    {
        if (empty($this->ymlConfigFile)) {
            throw new \Exception("No configuration file found");
        }

        // Run any config files first. This can be either a single value or an array.
        return $this->runConfigFile($this->ymlConfigFile);
    }

    /**
     * Run tests from a Codeception config file.
     * @param $configPath
     * @return bool|mixed
     * @throws \Exception
     */
    protected function runConfigFile($configPath)
    {
        $codeception = $this->builder->findBinary('codecept');
        $cmd         = $codeception . ' run -c "%s" --json ' . $this->args;
        $configPath  = $this->builder->buildPath . $configPath;
        $success     = $this->builder->executeCommand($cmd, $this->builder->buildPath, $configPath);

        $parser = new YamlParser();
        $yaml   = file_get_contents($configPath);
        $config = (array)$parser->parse($yaml);

        if ($config && isset($config['paths']['log'])) {
            $this->path = $config['paths']['log'] . DIRECTORY_SEPARATOR;
        }
        
        $jsonFile = $this->builder->buildPath . $this->path . 'report.json';

        $this->processResults($jsonFile);

        return $success;
    }

    /**
     * Saves the test results
     *
     * @param string $jsonFile
     *
     * @throws \Exception If the failed to parse the JSON file
     */
    protected function processResults($jsonFile)
    {
        if (file_exists($jsonFile)) {
            $parser = new Plugin\Util\PhpUnitResult($jsonFile, $this->build->getBuildPath());

            $this->build->storeMeta('codeception-data', $parser->parse()->getResults());
            $this->build->storeMeta('codeception-errors', $parser->getFailures());

            foreach ($parser->getErrors() as $error) {
                $severity = $error['severity'] == $parser::SEVERITY_ERROR ? BuildError::SEVERITY_CRITICAL : BuildError::SEVERITY_HIGH;
                $this->build->reportError(
                    $this->builder, 'codeception', $error['message'], $severity, $error['file'], $error['line']
                );
            }
            @unlink($jsonFile);
        } else {
            throw new \Exception('JSON output file does not exist: ' . $jsonFile);
        }
    }
}
