<?php

declare(strict_types=1);

namespace MSML\Command;

use MSML\Config;
use MSML\Enhed;
use MSML\Enheder;
use MSML\MailingList\MailingListFactory;
use MSML\Profile;
use MSML\Profiles;
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
class DefaultCommand extends Command
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
        if (!$config instanceof Config) {
            throw new \RuntimeException('No config service');
        }

        $this->config = $config;


        $listFactory = $this->container->get('mailinglist.factory');
        if (!$listFactory instanceof MailingListFactory) {
            throw new \RuntimeException('No mailing list factory');
        }

        $this->listFactory = $listFactory;
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
                'Lists to sync. Defaults to all.',
                null,
                fn (): array => array_map(
                    'strval',
                    array_keys($this->config['lists']['lists'] ?? $this->config['lists'])
                )
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

        $summaryFile = null;
        $summaryFileName = $input->getOption('summary-file');
        if (is_string($summaryFileName) && $summaryFileName !== '') {
            $summaryFile = fopen($summaryFileName, 'w') ?: null;
        }

        // Always set summary-file so lists can rely on it being present.
        $this->config['summary-file'] = $summaryFile;

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

        $start = microtime(true);

        // Lookup all the Enheder in as few calls as possible.
        $ids = [];
        $organizationCodes = [];
        foreach ($lists as $list) {
            foreach ($list['select'] as $conf) {
                if (!empty($conf['org'])) {
                    $organizationCodes[] = $conf['org'];
                } else {
                    $ids[] = $conf['id'];
                }
            }
        }

        $enheder->prefetch($ids, $organizationCodes);

        // Find the profiles each list selects.
        $selections = [];
        foreach ($lists as $listName => $list) {
            $selections[$listName] = $this->selectProfiles($list['select'], $enheder);
        }

        // Load all the selected profiles from Medlemsservice in as few
        // calls as possible before we start saving lists.
        $profileIds = [];
        $relativesProfileIds = [];
        foreach (array_merge([], ...array_values($selections)) as $selection) {
            array_push($profileIds, ...$selection['profileIds']);

            if ($selection['relatives']) {
                array_push($relativesProfileIds, ...$selection['profileIds']);
            }
        }

        $profiles->prefetch($profileIds);
        $profiles->prefetchRelatives($relativesProfileIds);

        $output->writeln(
            sprintf('Medlemsservice lookups took %.1f seconds.', microtime(true) - $start),
            OutputInterface::VERBOSITY_VERBOSE
        );
        $start = microtime(true);

        foreach ($lists as $listName => $list) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln("<list>{$list['description']}</list>");
            }

            if (is_resource($summaryFile)) {
                fwrite($summaryFile, "### {$listName} &ndash; {$list['description']}\n");
            }

            $addresses = $this->collectAddresses($selections[$listName], $profiles);

            $list = $this->listFactory->create($listName, $addresses, $this->config, $output);
            $list->save();

            if (is_resource($summaryFile)) {
                fwrite($summaryFile, "---\n");
            }
        }

        $output->writeln(
            sprintf('Mailing list sync took %.1f seconds.', microtime(true) - $start),
            OutputInterface::VERBOSITY_VERBOSE
        );

        if (is_resource($summaryFile)) {
            fclose($summaryFile);
        }

        return 0;
    }

    /**
     * Find the profiles selected by the select part of a list.
     *
     * @param array<array<string, mixed>> $select The select part of a list.
     * @param Enheder $enheder The Enheder to lookup in.
     *
     * @return array<array{profileIds: array<int>, relatives: bool}>
     */
    protected function selectProfiles(array $select, Enheder $enheder): array
    {
        $selections = [];

        foreach ($select as $conf) {
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

            $selections[] = [
                'profileIds' => array_values($profileIds),
                'relatives' => !empty($conf['relatives']),
            ];
        }

        return $selections;
    }

    /**
     * Collect the mail addresses of the selected profiles.
     *
     * @param array<array{profileIds: array<int>, relatives: bool}> $selections The selections of a list.
     * @param Profiles $profiles The profiles to lookup in.
     *
     * @return array<string>
     */
    protected function collectAddresses(array $selections, Profiles $profiles): array
    {
        $addresses = [];

        foreach ($selections as $selection) {
            $mails = array_map(function ($profileId) use ($profiles, $selection) {
                $profile = $profiles->getById($profileId);

                if (!$profile instanceof Profile) {
                    return [];
                }

                $mail = $profile->getMail();

                if (!$selection['relatives']) {
                    return array_filter([$mail]);
                }

                $relmails = $profile->getRelationMails();

                return array_filter(array_merge($relmails, [$mail]));
            }, $selection['profileIds']);

            if (!empty($mails)) {
                $mails = call_user_func_array('array_merge', array_values($mails));
            }

            $addresses = array_unique(array_merge($mails, $addresses));
        }

        return $addresses;
    }
}
