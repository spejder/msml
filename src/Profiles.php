<?php

declare(strict_types=1);

namespace MSML;

use Spejder\Odoo\Odoo;

/**
 * Profile collection class.
 */
class Profiles
{
    protected Odoo $odooClient;

    /**
     * @var array<int, \MSML\Profile>
     */
    protected array $collection;

    /**
     * Construct using an Odoo Client.
     *
     * @param Odoo $odooClient The Odoo Client to use for lookups.
     */
    public function __construct(Odoo $odooClient)
    {
        $this->odooClient = $odooClient;
        $this->collection = [];
    }

    /**
     * Get a profile by ID.
     *
     * @param int $profileId The profile ID to lookup by.
     *
     * @return Profile
     */
    public function getById(int $profileId): Profile
    {
        // Preferably use a cached profile. Otherwise look in Odoo.
        if (empty($this->collection[$profileId])) {
            $this->requestProfile($profileId);
        }

        return $this->collection[$profileId];
    }

    /**
     * Lookup a profile by it's ID from Odoo.
     *
     * @param int $profileId The profile ID to lookup by.
     */
    protected function requestProfile(int $profileId): void
    {
        if (empty($profileId)) {
            return;
        }

        $profiles = $this->odooClient->search('member.profile', [['id', '=', $profileId]]);

        $profile = reset($profiles);

        if (!is_int($profile)) {
            return;
        }

        $this->collection[$profileId] = new Profile($this->odooClient, $this, $profile);
    }
}
