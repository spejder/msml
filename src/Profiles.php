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
     * Get a profile by member ID.
     *
     * @param int $memberId The member ID to lookup by.
     *
     * @return Profile
     */
    public function getById(int $memberId)
    {
        // Preferably use a cached profile. Otherwise look in Odoo.
        if (empty($this->collection[$memberId])) {
            $this->requestProfile($memberId);
        }

        return $this->collection[$memberId];
    }

    /**
     * Lookup a profile by it's member ID from Odoo.
     *
     * @param int $memberId The member ID to lookup by.
     */
    protected function requestProfile(int $memberId)
    {
        $profiles = $this->odooClient->search('member.member', [['id', '=', $memberId]]);

        if (empty($memberId)) {
            $this->collection[$memberId] = null;

            return;
        }

        $this->collection[$memberId] = new Profile($this->odooClient, $this, reset($profiles));
    }
}
