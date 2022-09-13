<?php

namespace MSML;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Yaml\Parser;

/**
 * Config.
 */
class Config implements \ArrayAccess
{
    protected $config;

    /**
     * Construct by loading config from YAML file.
     *
     * YAML file 'config.yml' must exist in either current dir or a
     * subdir config.
     */
    public function __construct()
    {
        $fileLocator = new FileLocator(['.', './config']);
        $yamlParser = new Parser();

        try {
            $configFile = $fileLocator->locate('config.yml');

            if (is_array($configFile)) {
                $configFile = reset($configFile);
            }

            $configFileContent = file_get_contents($configFile);

            if (!is_string($configFileContent)) {
                throw new \RuntimeException('Could not locate or read config.yml');
            }

            $this->config['config'] = $yamlParser->parse($configFileContent);

            $overrideConfigFile = $fileLocator->locate('config.override.yml');

            if (is_array($overrideConfigFile)) {
                $overrideConfigFile = reset($overrideConfigFile);
            }

            $overrideConfigFileContent = file_get_contents($overrideConfigFile);

            if (is_string($overrideConfigFileContent)) {
                $this->config['config'] = array_merge(
                    $this->config['config'],
                    $yamlParser->parse($overrideConfigFileContent)
                );
            }
        } catch (\InvalidArgumentException $e) {
            // Intentionally left blank. We silently ignore missing override file.
        }

        try {
            $listsFile = $fileLocator->locate('lists.yml');

            if (is_array($listsFile)) {
                $listsFile = reset($listsFile);
            }

            $listsFileContent = file_get_contents($listsFile);

            if (!is_string($listsFileContent)) {
                throw new \RuntimeException('Could not locate or read list.yml');
            }

            $this->config['lists'] = $yamlParser->parse($listsFileContent);

            $overrideListsFile = $fileLocator->locate('lists.override.yml');

            if (is_array($overrideListsFile)) {
                $overrideListsFile = reset($overrideListsFile);
            }

            $overrideListsFileContent = file_get_contents($overrideListsFile);

            if (is_string($overrideListsFileContent)) {
                $this->config['lists'] = array_merge(
                    $this->config['lists'],
                    $yamlParser->parse($overrideListsFile)
                );
            }
        } catch (\InvalidArgumentException $e) {
            // Intentionally left blank. We silently ignore missing override file.
        }
    }

    /**
     * {@inheritDoc}
     */
    public function offsetExists($offset): bool
    {
        return isset($this->config[$offset]);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetGet($offset): mixed
    {
        return isset($this->config[$offset]) ? $this->config[$offset] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->config[] = $value;

            return;
        }

        $this->config[$offset] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function offsetUnset($offset): void
    {
        unset($this->config[$offset]);
    }
}
