<?php

namespace MSML\MailingList;

use Symfony\Component\Console\Output\OutputInterface;
use MSML\Config;

/**
 * An interface specifying a mailing list.
 */
interface MailingListInterface
{
    /**
     * Create list based on list name and addresses.
     *
     * @param string          $listName  The list name / identifier
     * @param array           $addresses Addresses
     * @param Config          $config    Configuration object
     * @param OutputInterface $output    For output
     */
    public function __construct(string $listName, array $addresses, Config $config, OutputInterface $output);

    /**
     * Save / sync the addresses to the mailing list.
     */
    public function save();
}
