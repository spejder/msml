<?php

declare(strict_types=1);

namespace MSML;

use Spejder\Odoo\Odoo;

/**
 * A class holding a collection of Enhed's.
 */
class Enheder
{
    protected Odoo $odooClient;

    /**
     * Enheder by lookup field and value. NULL if Odoo did not find it.
     *
     * @var array<string, array<int|string, Enhed|null>>
     */
    protected array $collection;

    /**
     * The same Enhed can be looked up by different fields. Keep one
     * object per Odoo ID so they share the cached lookups.
     *
     * @var array<int, Enhed>
     */
    protected array $byOdooId;

    /**
     * Construct using an Odoo Client.
     *
     * @param \Spejder\Odoo\Odoo $odooClient The Odoo Client to use for lookups.
     */
    public function __construct(Odoo $odooClient)
    {
        $this->odooClient = $odooClient;
        $this->collection = [];
        $this->byOdooId = [];
    }

    /**
     * Get an Enhed by it's ID (i.e. 8960).
     *
     * @param int $enhedId The Enhed ID to lookup by (i.e. 8960).
     *
     * @return Enhed|null
     */
    public function getById(int $enhedId): ?Enhed
    {
        return $this->requestEnhed($enhedId, 'id');
    }

    /**
     * Get an Enhed by it's organization code (i.e. "2227-5").
     *
     * @param string $organizationCode The Enhed organization code to lookup by (i.e. "2227-5").
     *
     * @return Enhed|null
     */
    public function getByOrganizationCode(string $organizationCode): ?Enhed
    {
        return $this->requestEnhed($organizationCode, 'organization_code');
    }

    /**
     * Lookup Enheder and their leaders in Odoo with as few calls as
     * possible.
     *
     * @param array<int> $ids The Enhed IDs to lookup (i.e. 8960).
     * @param array<string> $organizationCodes The organization codes to lookup (i.e. "2227-5").
     */
    public function prefetch(array $ids, array $organizationCodes): void
    {
        $lookups = [
            'id' => $ids,
            'organization_code' => $organizationCodes,
        ];
        $fields = ['organization_code', 'leader_ids'];
        $leaderIds = [];

        foreach ($lookups as $field => $values) {
            $values = array_values(array_filter(
                array_unique($values),
                fn (int|string $value): bool => !array_key_exists($value, $this->collection[$field] ?? [])
            ));

            if (empty($values)) {
                continue;
            }

            // Values that Odoo does not find stay NULL so we do not
            // search for them again.
            foreach ($values as $value) {
                $this->collection[$field][$value] = null;
            }

            $criteria = [[$field, 'in', $values]];

            foreach ($this->odooClient->searchRead('member.organization', $criteria, $fields) as $organization) {
                $value = $organization[$field];

                // Keep the first like a search for the single value
                // would.
                if (isset($this->collection[$field][$value])) {
                    continue;
                }

                $odooId = $organization['id'];
                $this->byOdooId[$odooId] ??= new Enhed($this->odooClient, $odooId, $value, $organization['leader_ids']);
                $this->collection[$field][$value] = $this->byOdooId[$odooId];
                $leaderIds[$odooId] = $organization['leader_ids'];
            }
        }

        $this->prefetchLeaders($leaderIds);
    }

    /**
     * Lookup the leaders of Enheder in one call.
     *
     * @param array<int, array<int>> $leaderIds The leader_ids by Odoo ID of the Enhed.
     */
    protected function prefetchLeaders(array $leaderIds): void
    {
        $odooIdsByFunction = [];
        foreach ($leaderIds as $odooId => $functionIds) {
            foreach ($functionIds as $functionId) {
                $odooIdsByFunction[$functionId][] = $odooId;
            }
        }

        $leaders = array_fill_keys(array_keys($leaderIds), []);

        if (!empty($odooIdsByFunction)) {
            // Search instead of read so Odoo filters the functions like
            // Enhed::requestLeaders() does.
            $criteria = [['id', 'in', array_keys($odooIdsByFunction)]];

            foreach ($this->odooClient->searchRead('member.function', $criteria, ['profile_id']) as $function) {
                foreach ($odooIdsByFunction[$function['id']] ?? [] as $odooId) {
                    $leaders[$odooId][] = $function['profile_id'][0];
                }
            }
        }

        foreach ($leaders as $odooId => $profileIds) {
            $this->byOdooId[$odooId]->setLeaders($profileIds);
        }
    }

    /**
     * Lookup an Enhed by it's ID (i.e. "2227-5") from Odoo.
     *
     * @param string|int $value The Enhed value to lookup by (i.e. "2227-5").
     * @param string $field The Enhed field to lookup in (i.e. "id" or "organisation_code").
     *
     * @return Enhed|null
     */
    protected function requestEnhed(string|int $value, string $field): ?Enhed
    {
        if (array_key_exists($value, $this->collection[$field] ?? [])) {
            return $this->collection[$field][$value];
        }

        $criteria = [[$field, '=', $value]];

        $organizationCode = $this->odooClient->search('member.organization', $criteria);

        if (empty($organizationCode)) {
            $this->collection[$field][$value] = null;

            return null;
        }

        $odooId = reset($organizationCode);
        $this->byOdooId[$odooId] ??= new Enhed($this->odooClient, $odooId, $value);
        $this->collection[$field][$value] = $this->byOdooId[$odooId];

        return $this->collection[$field][$value];
    }
}
