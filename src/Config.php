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

        $this->config['config'] = $yamlParser->parse(file_get_contents($fileLocator->locate('config.yml')));

        try {
            $overrideConfig = $fileLocator->locate('config.override.yml');
            $this->config['config'] = array_merge(
                $this->config['config'],
                $yamlParser->parse(file_get_contents($overrideConfig))
            );
        } catch (\InvalidArgumentException $e) {
            // Intentionally left blank. We silently ignore missing override file.
        }

        $this->config['lists'] = $yamlParser->parse(file_get_contents($fileLocator->locate('lists.yml')));

        try {
            $overrideLists = $fileLocator->locate('lists.override.yml');
            $this->config['lists'] = array_merge(
                $this->config['lists'],
                $yamlParser->parse(file_get_contents($overrideLists))
            );
        } catch (\InvalidArgumentException $e) {
            // Intentionally left blank. We silently ignore missing override file.
        }
    }

    /**
     * {@inheritDoc}
     */
    public function offsetExists($offset)
    {
        return isset($this->config[$offset]);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetGet($offset)
    {
        return isset($this->config[$offset]) ? $this->config[$offset] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function offsetSet($offset, $value)
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
    public function offsetUnset($offset)
    {
        unset($this->config[$offset]);
    }
}
