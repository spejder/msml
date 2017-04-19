<?php

namespace MSML;

use Symfony\Component\Yaml\Yaml;
use Jsg\Odoo\Odoo;

/**
 * Medlemsservice.
 *
 * A simple extension of the Odoo class.
 *
 * Constructor takes care of constructing parent Odoo with credentials
 * from settingsfile and using right URL and database.
 */
class Medlemsservice extends Odoo
{
    protected $msUrl = 'https://medlem.dds.dk/xmlrpc/2';
    protected $msDatabase = 'ddsprod';
    protected $msCredentials;

    /**
     * Construct Medlemsservice API client.
     *
     * Load credentials and call parent with right arguments.
     *
     * @param MSML\Config $config Configuration object
     */
    public function __construct(Config $config)
    {
        parent::__construct(
            $this->msUrl,
            $this->msDatabase,
            (string) $config['config']['credentials']['user'],
            (string) $config['config']['credentials']['password']
        );
    }

    /**
     * Search_read model(s)
     *
     * @param string  $model  Model
     * @param array   $data   Array of criteria
     * @param array   $fields Index array of fields to fetch, an empty array fetches all fields
     * @param integer $offset Offset
     * @param integer $limit  Max results
     *
     * @return array An array of models
     *
     * @see https://github.com/jacobsteringa/OdooClient/pull/10
     */
    public function searchRead($model, $data = array(), $fields = array(), $offset = 0, $limit = 100)
    {
        $params = $this->buildParams(array(
            $model,
            'search_read',
            $data,
            $fields,
            $offset,
            $limit,
        ));

        $response = $this->getClient('object')->call('execute', $params);

        return $response;
    }
}
