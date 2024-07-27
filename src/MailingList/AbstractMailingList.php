<?php

namespace MSML\MailingList;

use Symfony\Component\Console\Output\OutputInterface;
use MSML\Config;

/**
 * An abstract mailing list class.
 *
 * Implements common stuff for mailing lists to build upon.
 */
abstract class AbstractMailingList implements MailingListInterface
{
    protected string $listName;

    /**
     * @var array<string>
     */
    protected array $addresses = [];
    protected Config $config;
    protected OutputInterface $output;

    /**
     * @var array<mixed>
     */
    protected array $currentSubscribers = [];

    /**
     * Create list based on list name and addresses.
     *
     * {@inheritDoc}
     */
    public function __construct(string $listName, array $addresses, Config $config, OutputInterface $output)
    {
        $this->listName = $listName;
        $this->addresses = array_map('strtolower', $addresses);
        $this->config = $config;
        $this->output = $output;

        // Add extra addresses from config.
        if (
            !empty($this->config['config']['extras'][$listName]) &&
            is_array($this->config['config']['extras'][$listName])
        ) {
            $this->addresses = array_merge(
                $this->addresses,
                array_map('strtolower', $this->config['config']['extras'][$listName])
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function save(): void
    {
        $this->currentSubscribers();

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->output->writeln('Current Subscribers: ' . var_export($this->currentSubscribers, true));
        }

        $unsubscribers = array_diff($this->currentSubscribers, $this->addresses);
        $subscribers = array_diff($this->addresses, $this->currentSubscribers);

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln('Unsubscribing: ' . var_export($unsubscribers, true));
        }
        $this->unsubscribe($unsubscribers);

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln('Subscribing: ' . var_export($subscribers, true));
        }
        $this->subscribe($subscribers);
    }

    /**
     * {@inheritDoc}
     */
    abstract protected function currentSubscribers(): void;

    /**
     * @param array<mixed> $unsubscribers
     */
    abstract protected function unsubscribe(array $unsubscribers): void;

    /**
     * @param array<mixed> $subscribers
     */
    abstract protected function subscribe(array $subscribers): void;
}
