<?php

declare(strict_types=1);

namespace MSML\MailingList;

use Google\Client as GoogleClient;
use Google\Service\Directory;
use GuzzleHttp\ClientInterface as GuzzleClient;
use MSML\Config;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Google Groups mailing list wrapper class.
 *
 * Lists must be named with their mail address 'foo@example.com'.
 */
class GoogleGroups extends AbstractMailingList implements MailingListInterface
{
    protected GuzzleClient $client;

    /**
     * Create list based on list name and addresses.
     *
     * {@inheritDoc}
     */
    public function __construct(string $listName, array $addresses, Config $config, OutputInterface $output)
    {
        parent::__construct($listName, $addresses, $config, $output);

        putenv("GOOGLE_APPLICATION_CREDENTIALS={$this->config['config']['google-groups']['service-account-file']}");

        $client = new GoogleClient();
        $client->useApplicationDefaultCredentials();
        $client->addScope(Directory::ADMIN_DIRECTORY_GROUP);

        if (isset($this->config['config']['google-groups']['subject'])) {
            $client->setSubject($this->config['config']['google-groups']['subject']);
        }

        $this->client = $client->authorize();


        try {
            $data = $this->request(
                'GET',
                "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}",
            );

            $this->syncDescription($data);
        } catch (\Exception $e) {
            if (
                in_array($e->getCode(), [403, 404]) &&
                    ($this->config['config']['google-groups']['auto-create-groups'] ?? false)
            ) {
                $this->createList();
            } else {
                throw $e;
            }
        }

        $this->syncAliases();
    }

    /**
     * Make request, parse response and throw exception on error.
     *
     * @param array<mixed> $options
     */
    protected function request(string $method, string $uri, array $options = []): mixed
    {
        $response = $this->client->request($method, $uri, $options);

        $data = json_decode($response->getBody()->getContents());
        if (isset($data->error)) {
            throw new \Exception($data->error->message ?? 'Unknown error', $data->error->code ?? 0);
        }

        return $data;
    }

    /**
     * Create a list.
     */
    protected function createList(): void
    {
        $this->output->writeln('Auto creating group: ' . $this->listName, OutputInterface::VERBOSITY_VERBOSE);

        $body = [
            'email' => $this->listName,
            'name' => $this->listName,
            'description' => $this->getGroupDescription(),
            'kind' => 'admin#directory#group',
        ];

        if ($this->config['dry-run']) {
            $this->output->writeln('Would auto create group: ' . print_r($body, true));
        }

        $this->request(
            'POST',
            'https://admin.googleapis.com/admin/directory/v1/groups',
            [
                'json' => $body,
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function currentSubscribers(?string $pageToken = null): void
    {
        $endpoint = "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/members";
        if (is_string($pageToken)) {
            $endpoint .= "?pageToken={$pageToken}";
        }

        $data = $this->request(
            'GET',
            $endpoint,
        );

        if (empty($data->members)) {
            return;
        }

        foreach ($data->members as $member) {
            $this->currentSubscribers[] = strtolower($member->email);
        }

        if (!empty($data->nextPageToken)) {
            $this->currentSubscribers($data->nextPageToken);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function unsubscribe(array $unsubscribers): void
    {
        foreach ($unsubscribers as $unsubscriber) {
            if (!$this->config['dry-run']) {
                $this->request(
                    'DELETE',
                    "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/members/{$unsubscriber}",
                );
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function subscribe(array $subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $member = [
                'email' => $subscriber,
                'kind' => 'admin#directory#member',
            ];

            if (!$this->config['dry-run']) {
                try {
                    $this->request(
                        'POST',
                        "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/members",
                        [
                            'json' => $member,
                        ],
                    );
                } catch (\Exception $e) {
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
        if ($this->config['dry-run']) {
            $this->output->writeln('Skiping alias sync on dry-run.');
        }

        // Get aliases.
        $data = $this->request(
            'GET',
            "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/aliases",
        );

        $currentAliases = [];
        foreach ($data->aliases ?? [] as $currentAlias) {
            $currentAliases[] = $currentAlias->alias;
        }

        $aliases =  $this->config['lists']['lists'][$this->listName]['aliases'] ?? [];

        // Remove old aliases
        $remove = array_diff($currentAliases, $aliases);

        foreach ($remove as $alias) {
            $this->output->writeln('Removing alias: ' . $alias, OutputInterface::VERBOSITY_VERBOSE);

            $this->request(
                'DELETE',
                "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/aliases/{$alias}",
            );
        }

        // Add new aliases.
        $add = array_diff($aliases, $currentAliases);

        foreach ($add as $alias) {
            $this->output->writeln('Adding alias: ' . $alias, OutputInterface::VERBOSITY_VERBOSE);

            $body = [
                'primaryEmail' => $this->listName,
                'alias' => $alias,
                'kind' => 'admin#directory#alias',
            ];

            $this->request(
                'POST',
                "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}/aliases",
                [
                    'json' => $body,
                ],
            );
        }
    }

    /**
     * Sync description.
     */
    protected function syncDescription(\stdClass $data): void
    {
        $description = $this->getGroupDescription();

        if ($data->description === $description) {
            return;
        }

        $this->output->writeln('Updating description: ' . $description, OutputInterface::VERBOSITY_VERBOSE);

        $body = [
            'description' => $description,
        ];

        if ($this->config['dry-run']) {
            $this->output->writeln('Would update description: ' . print_r($body, true));
        }

        $this->request(
            'PATCH',
            "https://admin.googleapis.com/admin/directory/v1/groups/{$this->listName}",
            [
                'json' => $body,
            ],
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
