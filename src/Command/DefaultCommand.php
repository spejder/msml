<?php

namespace MSML\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;

/**
 * The default command.
 */
class DefaultCommand extends Command implements CompletionAwareInterface
{
    protected $container;
    protected $config;

    /**
     * {@inheritDoc}
     */
    public function __construct()
    {
        parent::__construct();

        $fileLocator = new FileLocator(['.', './config', __DIR__ . '/../../config']);

        $this->container = new ContainerBuilder();
        $loader = new YamlFileLoader($this->container, $fileLocator);
        $loader->load('services.yml');

        $this->config = $this->container->get('config');
    }

    /**
     * {@inheritDoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName == 'list') {
            return array_keys($this->config['lists']['lists'] ?? $this->config['lists']);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('sync')
            ->setDescription('Sync mailing lists with Medlemsservice.')
            ->setHelp('This command allows you to synchronize configured mailing lists with Medlemsservice.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do dry run (dont change anything)',
                null
            )
            ->addArgument(
                'list',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Lists to sync. Defaults to all.'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $style = new OutputFormatterStyle(null, null, array('bold', 'underscore'));
        $output->getFormatter()->setStyle('list', $style);

        $lists = $this->config['lists']['lists'] ?? $this->config['lists'];

        $enheder = $this->container->get('enheder');
        $profiles = $this->container->get('profiles');

        $selectedLists = $input->getArgument('list');

        // Normalize data structure to always be an array.
        if (!is_array($selectedLists)) {
            $selectedLists = [ $selectedLists ];
        }

        $this->config['dry-run'] = $input->getOption('dry-run');

        if (empty($lists)) {
            throw new \RuntimeException('No lists configured.');
        }

        // If some lists added on command line limit sync to those
        // lists.
        if (!empty($selectedLists)) {
            $lists = array_filter($lists, function ($key) use ($selectedLists) {
                return in_array($key, $selectedLists);
            }, ARRAY_FILTER_USE_KEY);
        }

        foreach ($lists as $listName => $list) {
            $addresses = [];

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln("<list>${list['description']}</list>");
            }

            foreach ($list['select'] as $conf) {
                if (!empty($conf['org'])) {
                    $enhed = $enheder->getByOrganizationCode($conf['org']);
                } else {
                    $enhed = $enheder->getById($conf['id']);
                }

                $callable = [$enhed, 'get' . ucfirst($conf['type'])];

                if (!is_callable($callable)) {
                    continue;
                }

                $profileIds = call_user_func($callable);

                // If type is members we need to filter out leaders.
                if ('members' == $conf['type']) {
                    $leaders = $enhed->getLeaders();
                    $profileIds = array_diff($profileIds, $leaders);
                }

                $mails = array_map(function ($profileId) use ($profiles, $conf) {
                    $profile = $profiles->getById($profileId);
                    $mail = $profile->getMail();

                    if (empty($conf['relatives'])) {
                        return array_filter([$mail]);
                    }

                    $relmails = $profile->getRelationMails();

                    return array_filter(array_merge($relmails, [$mail]));
                }, $profileIds);

                if (!empty($mails)) {
                    $mails = call_user_func_array('array_merge', array_values($mails));
                }

                $addresses = array_unique(array_merge($mails, $addresses));
            }

            $list = $this->container->get('mailinglist.factory')->create($listName, $addresses, $this->config, $output);
            $list->save();
        }
    }
}
