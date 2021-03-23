<?php

namespace MSML;

use Symfony\Component\Yaml\Yaml;
use Spejder\Odoo\Odoo;
use Fduch\Netrc\Netrc;
use Laminas\XmlRpc\Client as XmlRpcClient;
use Laminas\Http\Client as HttpClient;

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
    protected $msDatabase = 'dds';
    protected $msUrl;
    protected $config;

    /**
     * Construct Medlemsservice API client.
     *
     * Load credentials and call parent with right arguments.
     *
     * @param \MSML\Config $config Configuration object
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
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
    protected function getClient(?string $path = null): XmlRpcClient
    {
        $this->httpClient = new HttpClient();
        if (!empty($this->config['config']['odoo']['client_options'])) {
            $this->httpClient->setOptions($this->config['config']['odoo']['client_options']);
        }

        return parent::getClient($path);
    }
}
