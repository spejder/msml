<?php

namespace MSML;

use Symfony\Component\Yaml\Yaml;
use Jsg\Odoo\Odoo;
use Fduch\Netrc\Netrc;
use Zend\Http\Client as HttpClient;

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
    protected $msHost = 'medlem.dds.dk';
    protected $msDatabase = 'ddsprod';
    protected $msUrl;

    /**
     * Construct Medlemsservice API client.
     *
     * Load credentials and call parent with right arguments.
     *
     * @param MSML\Config $config Configuration object
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function __construct(Config $config)
    {
        $this->msUrl = "https://{$this->msHost}/xmlrpc/2";

        // First we try to locate credentials in ~/.netrc
        try {
            $netrc = Netrc::parse();

            if (!empty($netrc[$this->msHost]['login'])) {
                $user = $netrc[$this->msHost]['login'];
            }

            if (!empty($netrc[$this->msHost]['password'])) {
                $password = $netrc[$this->msHost]['password'];
            }
        } catch (\Exception $e) {
            // Nothing.
        }

        // If credentials are present in the config use them instead.
        if (!empty($config['config']['credentials']['user'])) {
            $user = $config['config']['credentials']['user'];
        }

        if (!empty($config['config']['credentials']['password'])) {
            $password = $config['config']['credentials']['password'];
        }

        if (empty($user) || empty($password)) {
            throw new \RuntimeException('Unable to find credentials.');
        }

        parent::__construct(
            $this->msUrl,
            $this->msDatabase,
            (string) $user,
            (string) $password
        );
    }

    /**
     * Get XmlRpc Client
     *
     * Create a HTTP Client and set the timeout before getting the
     * client from our parent (Odoo).
     *
     * {@inheritDoc}
     */
    protected function getClient($path = null)
    {
        $this->httpClient = new HttpClient();
        if (!empty($config['config']['odoo']['client_options'])) {
            $this->httpClient->setOptions($config['config']['odoo']['client_options']);
        }

        return parent::getClient($path);
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
