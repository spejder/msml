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
use Google\Service\Groupssettings;
use Google\Service\Groupssettings\Groups as GroupSettings;
use MSML\Config;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Google Groups mailing list wrapper class.
 *
 * Groups and members are handled by the Cloud Identity Groups API. Aliases
 * are handled by the Admin SDK Directory API, as Cloud Identity can't
 * manage them. Group settings are handled by the Groups Settings API, as
 * Cloud Identity can't manage them either.
 *
 * Lists must be named with their mail address 'foo@example.com'.
 */
class GoogleGroups extends AbstractMailingList implements MailingListInterface
{
    /**
     * The Google client shared by all lists in a run, so they share the
     * OAuth token and the connections.
     */
    protected static ?GoogleClient $client = null;

    /**
     * All groups by lowercased email, loaded once per run. NULL until
     * loaded, and empty if they could not be loaded.
     *
     * @var array<string, array{name: string, description: string, aliases: array<string>}>|null
     */
    protected static ?array $groups = null;

    /**
     * Max number of membership changes in one batch request.
     */
    protected const BATCH_SIZE = 50;

    protected CloudIdentity $cloudIdentity;
    protected Directory $directory;
    protected Groupssettings $groupsSettings;

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

        $client = $this->getClient();

        $this->cloudIdentity = new CloudIdentity($client);
        $this->directory = new Directory($client);
        $this->groupsSettings = new Groupssettings($client);

        $group = $this->getGroups()[strtolower($this->listName)] ?? null;

        if ($group !== null) {
            $this->groupName = $group['name'];
            $this->syncDescription($group['description']);
            $this->syncAliases($group['aliases']);

            return;
        }

        // The group was not in the list of groups. Look it up on its own.
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
            // A group without a description returns NULL, despite the docblock.
            $this->syncDescription((string) $this->cloudIdentity->groups->get($this->groupName)->getDescription());
        }

        $this->syncAliases($this->lookupAliases());
    }

    /**
     * Get all groups with their Cloud Identity name, description and aliases.
     *
     * The groups are loaded with the Directory API on first use, so each
     * list does not need its own calls to look them up.
     *
     * @return array<string, array{name: string, description: string, aliases: array<string>}>
     */
    protected function getGroups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $customerId = $this->config['config']['google-groups']['customer-id'] ?? null;
        $groups = [];
        $pageToken = null;

        try {
            do {
                $params = [
                    'customer' => empty($customerId) ? 'my_customer' : $customerId,
                    'maxResults' => 200,
                ];
                if (is_string($pageToken)) {
                    $params['pageToken'] = $pageToken;
                }

                $data = $this->directory->groups->listGroups($params);

                foreach ($data->getGroups() ?? [] as $group) {
                    // The Cloud Identity group ID is the Directory group ID.
                    $groups[strtolower($group->getEmail())] = [
                        'name' => 'groups/' . $group->getId(),
                        'description' => (string) $group->getDescription(),
                        'aliases' => $group->getAliases() ?? [],
                    ];
                }

                $pageToken = $data->getNextPageToken();
            } while (!empty($pageToken));
        } catch (GoogleServiceException $e) {
            $this->output->writeln(
                'Unable to list groups. Looking up each group on its own: ' . $e->getMessage(),
                OutputInterface::VERBOSITY_VERBOSE,
            );
            $groups = [];
        }

        return self::$groups = $groups;
    }

    /**
     * Get the Google client. It is created once and shared by all lists.
     */
    protected function getClient(): GoogleClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $client = new GoogleClient();
        $client->setAuthConfig($this->config['config']['google-groups']['service-account-file']);
        $client->addScope([
            CloudIdentity::CLOUD_IDENTITY_GROUPS,
            Directory::ADMIN_DIRECTORY_GROUP,
        ]);

        // Only auto create uses the Groups Settings API. Asking for the scope
        // otherwise would break domain-wide delegation setups without it.
        if ($this->config['config']['google-groups']['auto-create-groups'] ?? false) {
            $client->addScope(Groupssettings::APPS_GROUPS_SETTINGS);
        }

        if (isset($this->config['config']['google-groups']['subject'])) {
            $client->setSubject($this->config['config']['google-groups']['subject']);
        }

        return self::$client = $client;
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
            $this->output->writeln('Would allow external members in group: ' . $this->listName);
            return;
        }

        $this->cloudIdentity->groups->create($group, ['initialGroupConfig' => 'EMPTY']);

        $this->groupName = $this->lookupGroupName();

        // New groups don't allow external members, and most of our members
        // are external. The Groups Settings API wants a string, not a bool.
        $this->groupsSettings->groups->patch(
            $this->listName,
            new GroupSettings(['allowExternalMembers' => 'true']),
        );
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
        if ($this->config['dry-run']) {
            return;
        }

        $this->sendInBatches(
            $unsubscribers,
            fn (string $unsubscriber): mixed => $this->cloudIdentity->groups_memberships->delete(
                $this->membershipNames[$unsubscriber]
            ),
            function (string $unsubscriber, GoogleServiceException $e): void {
                throw $e;
            },
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function subscribe(array $subscribers): void
    {
        if ($this->config['dry-run']) {
            return;
        }

        $this->sendInBatches(
            $subscribers,
            function (string $subscriber): mixed {
                $membership = new Membership([
                    'preferredMemberKey' => new EntityKey(['id' => $subscriber]),
                    'roles' => [new MembershipRole(['name' => 'MEMBER'])],
                ]);

                return $this->cloudIdentity->groups_memberships->create($this->groupName, $membership);
            },
            function (string $subscriber, GoogleServiceException $e): void {
                // Cloud Identity gives 403 for some addresses that can't be
                // added. The error names the group, so name the address.
                if (!in_array($e->getCode(), [403, 404])) {
                    throw $e;
                }

                $error = "Error adding subscriber {$subscriber} to {$this->listName}: " . $e->getMessage();
                $this->errors[] = $error;
                $this->output->writeln($error, OutputInterface::VERBOSITY_QUIET);
            },
        );
    }

    /**
     * Send one membership request per address in batch requests.
     *
     * @param array<string> $addresses The addresses to send a request for.
     * @param callable(string): mixed $request Makes the request for an address. In batch mode the
     *   client returns the request instead of sending it.
     * @param callable(string, GoogleServiceException): void $onError Handles a failed request.
     */
    protected function sendInBatches(array $addresses, callable $request, callable $onError): void
    {
        $client = $this->getClient();

        foreach (array_chunk(array_values($addresses), self::BATCH_SIZE) as $chunk) {
            $client->setUseBatch(true);

            try {
                $batch = $this->cloudIdentity->createBatch();

                // Batch::add() makes a random key for keys that are
                // loosely false, i.e. "0", so prefix the index.
                foreach ($chunk as $i => $address) {
                    $batch->add($request($address), "m{$i}");
                }

                $results = $batch->execute() ?? [];
            } finally {
                $client->setUseBatch(false);
            }

            foreach ($chunk as $i => $address) {
                if (!array_key_exists("response-m{$i}", $results)) {
                    throw new \RuntimeException("No response in batch request for {$address}.");
                }

                if ($results["response-m{$i}"] instanceof GoogleServiceException) {
                    $onError($address, $results["response-m{$i}"]);
                }
            }
        }
    }

    /**
     * Look up the current aliases of the group.
     *
     * @return array<string>
     */
    protected function lookupAliases(): array
    {
        $currentAliases = [];

        if ($this->groupName !== null) {
            $data = $this->directory->groups_aliases->listGroupsAliases($this->listName);

            foreach ($data->getAliases() ?? [] as $currentAlias) {
                $currentAliases[] = $currentAlias['alias'];
            }
        }

        return $currentAliases;
    }

    /**
     * Sync aliases.
     *
     * @param array<string> $currentAliases The current aliases of the group.
     */
    protected function syncAliases(array $currentAliases): void
    {
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
     *
     * @param string $currentDescription The current description of the group.
     */
    protected function syncDescription(string $currentDescription): void
    {
        $description = $this->getGroupDescription();

        if ($currentDescription === $description) {
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
