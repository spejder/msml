<?php

declare(strict_types=1);

namespace MSML;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Yaml\Parser;

/**
 * Config.
 *
  * @implements \ArrayAccess<string, mixed>
 */
class Config implements \ArrayAccess
{
    /**
     * The config.
     *
     * @var array<string|int, mixed>
     */
    protected array $config;

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

            $configFileContent = file_get_contents($configFile);

            if (!is_string($configFileContent)) {
                throw new \RuntimeException('Could not locate or read config.yml');
            }

            $this->config['config'] = $yamlParser->parse($configFileContent);

            $overrideConfigFile = $fileLocator->locate('config.override.yml');

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

            $listsFileContent = file_get_contents($listsFile);

            if (!is_string($listsFileContent)) {
                throw new \RuntimeException('Could not locate or read list.yml');
            }

            $this->config['lists'] = $yamlParser->parse($listsFileContent);

            $overrideListsFile = $fileLocator->locate('lists.override.yml');

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
