<?php

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
    protected $listFolder;
    protected $commandPrefix = '';

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
            $folders[] = '/var/spool/mlmmj/'.$domain.'/'.$address;
            $folders[] = '/var/spool/mlmmj/'.$address.'@'.$domain;
        }

        $folders[] = '/var/spool/mlmmj/'.$address;

        foreach ($folders as $folder) {
            $command = $this->commandPrefix.'test -d '.$folder.' -a -r '.$folder.'';

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: '.$command);
            }

            system($command, $returnValue);

            if ($returnValue === 0) {
                $this->listFolder = $folder;

                return;
            }
        }

        throw new \RuntimeException('List '.$listName.' not found.');
    }

    protected function currentSubscribers()
    {
        $command = $this->commandPrefix.'/usr/bin/mlmmj-list -L '.$this->listFolder;

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->output->writeln('Executing commmand: '.$command);
        }

        $subscribers = `$command`;

        if (empty($subscribers)) {
            $this->currentSubscribers = [];

            return;
        }

        $this->currentSubscribers = explode("\n", trim($subscribers));
    }

    protected function unsubscribe(array $unsubscribers)
    {
        foreach ($unsubscribers as $unsubscribe) {
            $command = $this->commandPrefix.'/usr/bin/mlmmj-unsub -L '.$this->listFolder.' -a '.$unsubscribe;

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: '.$command);
            }

            //            `$command`;
        }
    }

    protected function subscribe(array $subscribers)
    {
        foreach ($subscribers as $subscribe) {
            $command = $this->commandPrefix.'/usr/bin/mlmmj-sub -L '.$this->listFolder.' -a '.$subscribe;

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln('Executing commmand: '.$command);
            }

            //            `$command`;
        }
    }
}
