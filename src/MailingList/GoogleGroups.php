<?php

declare(strict_types=1);

namespace MSML\MailingList;

use Google\Client as GoogleClient;
use Google\Service\CloudIdentity;
use Google\Service\CloudIdentity\EntityKey;
use Google\Service\CloudIdentity\Group;
use Google\Service\CloudIdentity\Membership;
use Google\Service\CloudIdentity\MembershipRole;
use Google\Service\Directory;
use Google\Service\Directory\Alias;
use Google\Service\Exception as GoogleServiceException;
use MSML\Config;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Google Groups mailing list wrapper class.
 *
 * Groups and members are handled by the Cloud Identity Groups API. Aliases
 * are handled by the Admin SDK Directory API, as Cloud Identity can't
 * manage them.
 *
 * Lists must be named with their mail address 'foo@example.com'.
 */
class GoogleGroups extends AbstractMailingList implements MailingListInterface
{
    protected CloudIdentity $cloudIdentity;
    protected Directory $directory;

    /**
     * Cloud Identity resource name of the group ('groups/{id}').
     *
     * NULL if the group doesn't exist (only on dry-run with auto create).
     */
    protected ?string $groupName = null;

    /**
     * Map of member email to Cloud Identity membership resource name.
     *
     * @var array<string, string>
     */
    protected array $membershipNames = [];

    /**
     * Create list based on list name and addresses.
     *
     * {@inheritDoc}
     */
    public function __construct(string $listName, array $addresses, Config $config, OutputInterface $output)
    {
        parent::__construct($listName, $addresses, $config, $output);

        $client = new GoogleClient();
        $client->setAuthConfig($this->config['config']['google-groups']['service-account-file']);
        $client->addScope([
            CloudIdentity::CLOUD_IDENTITY_GROUPS,
            Directory::ADMIN_DIRECTORY_GROUP,
        ]);

        if (isset($this->config['config']['google-groups']['subject'])) {
            $client->setSubject($this->config['config']['google-groups']['subject']);
        }

        $this->cloudIdentity = new CloudIdentity($client);
        $this->directory = new Directory($client);

        try {
            $this->groupName = $this->lookupGroupName();
        } catch (GoogleServiceException $e) {
            if (
                in_array($e->getCode(), [403, 404]) &&
                    ($this->config['config']['google-groups']['auto-create-groups'] ?? false)
            ) {
                $this->createList();
            } else {
                throw $e;
            }
        }

        if ($this->groupName !== null) {
            $this->syncDescription($this->cloudIdentity->groups->get($this->groupName));
        }

        $this->syncAliases();
    }

    /**
     * Look up the Cloud Identity resource name of the group.
     */
    protected function lookupGroupName(): string
    {
        return $this->cloudIdentity->groups->lookup(['groupKey.id' => $this->listName])->getName();
    }

    /**
     * Create a list.
     */
    protected function createList(): void
    {
        $this->output->writeln('Auto creating group: ' . $this->listName, OutputInterface::VERBOSITY_VERBOSE);

        $customerId = $this->config['config']['google-groups']['customer-id'] ?? null;
        if (empty($customerId)) {
            throw new \Exception('google-groups.customer-id must be set to auto create groups.');
        }

        $group = new Group([
            'parent' => "customers/{$customerId}",
            'groupKey' => new EntityKey(['id' => $this->listName]),
            'displayName' => $this->listName,
            'description' => $this->getGroupDescription(),
            'labels' => ['cloudidentity.googleapis.com/groups.discussion_forum' => ''],
        ]);

        if ($this->config['dry-run']) {
            $this->output->writeln('Would auto create group: ' . print_r($group->toSimpleObject(), true));
            return;
        }

        $this->cloudIdentity->groups->create($group, ['initialGroupConfig' => 'EMPTY']);

        $this->groupName = $this->lookupGroupName();
    }

    /**
     * {@inheritDoc}
     */
    protected function currentSubscribers(): void
    {
        if ($this->groupName === null) {
            return;
        }

        $pageToken = null;

        do {
            $params = ['pageSize' => 1000];
            if (is_string($pageToken)) {
                $params['pageToken'] = $pageToken;
            }

            $data = $this->cloudIdentity->groups_memberships->listGroupsMemberships($this->groupName, $params);

            foreach ($data->getMemberships() ?? [] as $membership) {
                $email = strtolower($membership->getPreferredMemberKey()->getId());

                $this->currentSubscribers[] = $email;
                $this->membershipNames[$email] = $membership->getName();
            }

            $pageToken = $data->getNextPageToken();
        } while (!empty($pageToken));
    }

    /**
     * {@inheritDoc}
     */
    protected function unsubscribe(array $unsubscribers): void
    {
        foreach ($unsubscribers as $unsubscriber) {
            if (!$this->config['dry-run']) {
                $this->cloudIdentity->groups_memberships->delete($this->membershipNames[$unsubscriber]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function subscribe(array $subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $membership = new Membership([
                'preferredMemberKey' => new EntityKey(['id' => $subscriber]),
                'roles' => [new MembershipRole(['name' => 'MEMBER'])],
            ]);

            if (!$this->config['dry-run']) {
                try {
                    $this->cloudIdentity->groups_memberships->create($this->groupName, $membership);
                } catch (GoogleServiceException $e) {
                    if ($e->getCode() === 404) {
                        $this->output->writeln(
                            'Error adding subscriber: ' . $e->getMessage(),
                            OutputInterface::VERBOSITY_QUIET,
                        );
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Sync aliases.
     */
    protected function syncAliases(): void
    {
        $currentAliases = [];

        if ($this->groupName !== null) {
            $data = $this->directory->groups_aliases->listGroupsAliases($this->listName);

            foreach ($data->getAliases() ?? [] as $currentAlias) {
                $currentAliases[] = $currentAlias['alias'];
            }
        }

        $aliases = $this->config['lists']['lists'][$this->listName]['aliases'] ?? [];

        $remove = array_diff($currentAliases, $aliases);
        $add = array_diff($aliases, $currentAliases);

        if ($this->config['dry-run']) {
            foreach ($remove as $alias) {
                $this->output->writeln('Would remove alias: ' . $alias);
            }

            foreach ($add as $alias) {
                $this->output->writeln('Would add alias: ' . $alias);
            }

            return;
        }

        // Remove old aliases
        foreach ($remove as $alias) {
            $this->output->writeln('Removing alias: ' . $alias, OutputInterface::VERBOSITY_VERBOSE);

            $this->directory->groups_aliases->delete($this->listName, $alias);
        }

        // Add new aliases.
        foreach ($add as $alias) {
            $this->output->writeln('Adding alias: ' . $alias, OutputInterface::VERBOSITY_VERBOSE);

            $this->directory->groups_aliases->insert($this->listName, new Alias([
                'primaryEmail' => $this->listName,
                'alias' => $alias,
            ]));
        }
    }

    /**
     * Sync description.
     */
    protected function syncDescription(Group $group): void
    {
        $description = $this->getGroupDescription();

        // A group without a description returns NULL, despite the docblock.
        if ((string) $group->getDescription() === $description) {
            return;
        }

        $this->output->writeln('Updating description: ' . $description, OutputInterface::VERBOSITY_VERBOSE);

        if ($this->config['dry-run']) {
            $this->output->writeln('Would update description: ' . $description);
            return;
        }

        $this->cloudIdentity->groups->patch(
            $this->groupName,
            new Group(['description' => $description]),
            ['updateMask' => 'description'],
        );
    }

    protected function getGroupDescription(): string
    {
        $description = $this->config['lists']['lists'][$this->listName]['description'] ?? '';

        if (!empty($this->config['config']['google-groups']['description-prefix'])) {
            $description = $this->config['config']['google-groups']['description-prefix'] . ' ' . $description;
        }

        return $description;
    }
}
