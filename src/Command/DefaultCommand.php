<?php

declare(strict_types=1);

namespace MSML\Command;

use MSML\Config;
use MSML\Enhed;
use MSML\Enheder;
use MSML\MailingList\MailingListFactory;
use MSML\Profiles;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The default command.
 */
class DefaultCommand extends Command implements CompletionAwareInterface
{
    protected ContainerBuilder $container;
    protected Config $config;
    protected MailingListFactory $listFactory;

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

        $config = $this->container->get('config');
        if (is_null($config)) {
            throw new \RuntimeException('No config service');
        }

        /** @var \MSML\Config $config */
        $this->config = $config;


        $listFactory = $this->container->get('mailinglist.factory');
        if (is_null($listFactory)) {
            throw new \RuntimeException('No mailing list factory');
        }

        /** @var \MSML\MailingList\MailingListFactory $listFactory */
        $this->listFactory = $listFactory;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<mixed>
     */
    public function completeOptionValues($optionName, CompletionContext $context): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     *
     * @return array<mixed>
     */
    public function completeArgumentValues($argumentName, CompletionContext $context): array
    {
        if ($argumentName != 'list') {
            return [];
        }

        return array_keys($this->config['lists']['lists'] ?? $this->config['lists']);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('sync')
            ->setDescription('Sync mailing lists with Medlemsservice.')
            ->setHelp('This command allows you to synchronize configured mailing lists with Medlemsservice.')
            ->addOption(
                'summary-file',
                null,
                InputOption::VALUE_OPTIONAL,
                'Write summary to SUMMARY-FILE',
                null
            )
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new OutputFormatterStyle(null, null, array('bold', 'underscore'));
        $output->getFormatter()->setStyle('list', $style);

        $lists = $this->config['lists']['lists'] ?? $this->config['lists'];

        $enheder = $this->container->get('enheder');

        if (!$enheder instanceof Enheder) {
            throw new \RuntimeException('No "enheder".');
        }

        $profiles = $this->container->get('profiles');
        if (!$profiles instanceof Profiles) {
            throw new \RuntimeException('No profiles.');
        }

        $selectedLists = $input->getArgument('list');

        // Normalize data structure to always be an array.
        if (!is_array($selectedLists)) {
            $selectedLists = [ $selectedLists ];
        }

        $this->config['dry-run'] = $input->getOption('dry-run');

        $summaryFile = fopen($input->getOption('summary-file'), 'w');
        if (is_resource($summaryFile)) {
            $this->config['summary-file'] = $summaryFile;
        }

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
                $output->writeln("<list>{$list['description']}</list>");
            }

            if (is_resource($summaryFile)) {
                fwrite($summaryFile, "### {$listName} &ndash; {$list['description']}\n");
            }

            foreach ($list['select'] as $conf) {
                if (!empty($conf['org'])) {
                    $enhed = $enheder->getByOrganizationCode($conf['org']);
                } else {
                    $enhed = $enheder->getById($conf['id']);
                }

                if (!$enhed instanceof Enhed) {
                    continue;
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

            $list = $this->listFactory->create($listName, $addresses, $this->config, $output);
            $list->save();

            if (is_resource($summaryFile)) {
                fwrite($summaryFile, "---\n");
            }
        }

        if (is_resource($summaryFile)) {
            fclose($summaryFile);
        }

        return 0;
    }
}
