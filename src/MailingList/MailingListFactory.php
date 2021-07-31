<?php

namespace MSML\MailingList;

use Symfony\Component\Console\Output\OutputInterface;
use MSML\Config;

/**
 * A factory for creating mailinglist classes.
 */
class MailingListFactory
{
    protected string $class = 'MLMMJ';

    /**
     * Construct the factory.
     *
     * @param \MSML\Config $config
     */
    public function __construct(Config $config)
    {
        if (!empty($config['config']['mailinglist'])) {
            $this->class = $config['config']['mailinglist'];
        };

        if (!class_exists($this->class)) {
            if (!class_exists('MSML\\MailingList\\' . $this->class)) {
                throw new \InvalidArgumentException('Unknown mailing list class: ' . $this->class);
            }

            $this->class = 'MSML\\MailingList\\' . $this->class;
        }

        $class = class_implements($this->class);
        if (!$class || !in_array('MSML\MailingList\MailingListInterface', $class)) {
            throw new \InvalidArgumentException(
                'Mailing list class, ' . $this->class . ', does not implement MSML\MailingList\MailingListInterface.'
            );
        }
    }

    /**
     * Create a mailing list class.
     *
     * @param string          $listName  The list name / identifier
     * @param array<string>   $addresses Addresses
     * @param Config          $config    Configuration object
     * @param OutputInterface $output    For output
     *
     * @return MailingListInterface
     */
    public function create(
        string $listName,
        array $addresses,
        Config $config,
        OutputInterface $output
    ): MailingListInterface {
        return new $this->class($listName, $addresses, $config, $output);
    }
}
