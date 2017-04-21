<?php

namespace MSML;

/**
 * A class holding a collection of Enhed's.
 */
class Enheder
{
    protected $odooClient;
    protected $collection;

    /**
     * Construct using an Odoo Client.
     *
     * @param Odoo $odooClient The Odoo Client to use for lookups.
     */
    public function __construct(\Jsg\Odoo\Odoo $odooClient)
    {
        $this->odooClient = $odooClient;
        $this->collection = [];
    }

    /**
     * Get an Enhed by it's ID (i.e. "2227-5").
     *
     * @param string $enhedId The Enhed ID to lookup by (i.e. "2227-5").
     *
     * @return Enhed
     */
    public function getById(string $enhedId)
    {
        // Preferably use an cached version.
        if (empty($this->collection[$enhedId])) {
            $this->requestEnhed($enhedId);
        }

        return $this->collection[$enhedId];
    }

    /**
     * Lookup an Enhed by it's ID (i.e. "2227-5") from Odoo.
     *
     * @param string $enhedId The Enhed ID to lookup by (i.e. "2227-5").
     */
    protected function requestEnhed(string $enhedId)
    {
        $criteria = [['organization_code', '=', $enhedId]];

        $organizationCode = $this->odooClient->search('member.organization', $criteria);

        if (empty($organizationCode)) {
            $this->collection[$enhedId] = null;

            return;
        }

        $this->collection[$enhedId] = new Enhed($this->odooClient, reset($organizationCode), $enhedId);
    }
}
