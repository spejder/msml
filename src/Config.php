<?php

namespace MSML;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Yaml\Yaml;

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

        $this->config['config'] = Yaml::parse(file_get_contents($fileLocator->locate('config.yml')));
        $this->config['lists'] = Yaml::parse(file_get_contents($fileLocator->locate('lists.yml')));
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
