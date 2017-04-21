<?php

namespace MSML;

use Jsg\Odoo\Odoo;

/**
 * Profile collection class.
 */
class Profiles
{
    protected $odooClient;
    protected $collection;

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
    public function getById(int $profileId)
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
    protected function requestProfile(int $profileId)
    {
        $profiles = $this->odooClient->search('member.profile', [['id', '=', $profileId]]);

        if (empty($profileId)) {
            $this->collection[$profileId] = null;

            return;
        }

        $this->collection[$profileId] = new Profile($this->odooClient, $this, reset($profiles));
    }
}
