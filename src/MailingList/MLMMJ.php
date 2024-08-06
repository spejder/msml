<?php

declare(strict_types=1);

namespace MSML\MailingList;

use Symfony\Component\Console\Output\OutputInterface;
use MSML\Config;

/**
 * A MLMMJ mailing list wrapper class.
 *
 * A list named 'foo@example.com' will be search for in the following
 * locations and order:
 *
 *  1. /var/spool/mlmmj/example.com/foo
 *  2. /var/spool/mlmmj/foo@example.com
 *  3. /var/spool/mlmmj/foo
 *
 * Where as list simply named 'foo' will only be search for in the
 * location '/var/spool/mlmmj/foo'.
 */
class MLMMJ extends AbstractMailingList implements MailingListInterface
{
    protected string $listFolder;
    protected string $commandPrefix = '';

    /**
     * Create list based on list name and addresses.
     *
     * {@inheritDoc}
     */
    public function __construct(string $listName, array $addresses, Config $config, OutputInterface $output)
    {
        parent::__construct($listName, $addresses, $config, $output);

        if (!empty($config['config']['mlmmj']['command_prefix'])) {
            $this->commandPrefix = $config['config']['mlmmj']['command_prefix'];
        }

        list($address, $domain) = array_merge(explode('@', $listName, 2), array(false));

        $folders = [];

        if (!empty($domain)) {
            $folders[] = '/var/spool/mlmmj/' . $domain . '/' . $address;
            $folders[] = '/var/spool/mlmmj/' . $address . '@' . $domain;
        }

        $folders[] = '/var/spool/mlmmj/' . $address;

        foreach ($folders as $folder) {
            $command = $this->commandPrefix . 'test -d ' . $folder . ' -a -r ' . $folder . '';

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: ' . $command);
            }

            system($command, $returnValue);

            if ($returnValue === 0) {
                $this->listFolder = $folder;

                return;
            }
        }

        throw new \RuntimeException('List ' . $listName . ' not found.');
    }

    /**
     * {@inheritDoc}
     */
    protected function currentSubscribers(): void
    {
        $command = $this->mlmmjCommand('list');

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->output->writeln('Executing commmand: ' . $command);
        }

        $subscribers = `$command`;

        if (empty($subscribers)) {
            $this->currentSubscribers = [];
            return;
        }

        $this->currentSubscribers = explode("\n", trim($subscribers));
    }

    /**
     * {@inheritDoc}
     */
    protected function unsubscribe(array $unsubscribers): void
    {
        foreach ($unsubscribers as $unsubscribe) {
            $command = $this->mlmmjCommand('unsub', ['-a', $unsubscribe]);

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: ' . $command);
            }

            if (!$this->config['dry-run']) {
                `$command`;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function subscribe(array $subscribers): void
    {
        foreach ($subscribers as $subscribe) {
            $command = $this->mlmmjCommand('sub', ['-a', $subscribe]);

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: ' . $command);
            }

            if (!$this->config['dry-run']) {
                `$command`;
            }
        }
    }

    /**
     * Build a MLMMJ command to execute.
     *
     * @param string $command The MLMMJ commmand, i.e. 'list' for /usr/bin/mlmmj-list
     * @param string[] $extraArguments Extra arguments to pass to the commmand
     *
     * @return string The command
     */
    protected function mlmmjCommand(string $command, array $extraArguments = []): string
    {
        $extraArguments = implode(' ', $extraArguments);

        return "{$this->commandPrefix}/usr/bin/mlmmj-{$command} -L {$this->listFolder} {$extraArguments}";
    }
}
