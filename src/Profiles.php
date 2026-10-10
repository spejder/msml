<?php

declare(strict_types=1);

namespace MSML;

use Spejder\Odoo\Odoo;

/**
 * Profile collection class.
 *
 * Looks up profiles in Odoo in batches to keep the number of calls
 * down.
 */
class Profiles
{
    protected Odoo $odooClient;

    /**
     * Profiles by ID. NULL if Odoo did not return the profile.
     *
     * @var array<int, \MSML\Profile|null>
     */
    protected array $collection;

    // Hard coded medlemssystem value.
    protected const TYPE_CHILD_OF = 9;

    // Max number of IDs to send to Odoo in one call.
    protected const CHUNK_SIZE = 500;

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
     * @return Profile|null
     */
    public function getById(int $profileId): ?Profile
    {
        // Preferably use a cached profile. Otherwise look in Odoo.
        if (!array_key_exists($profileId, $this->collection)) {
            $this->prefetch([$profileId]);
        }

        return $this->collection[$profileId] ?? null;
    }

    /**
     * Lookup the profiles that are not cached yet in Odoo.
     *
     * @param array<int|null> $profileIds The profile IDs to lookup.
     */
    public function prefetch(array $profileIds): void
    {
        $missing = array_filter(
            array_unique($profileIds),
            fn (?int $profileId): bool => !empty($profileId) && !array_key_exists($profileId, $this->collection)
        );

        foreach (array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
            // Profiles that Odoo does not return stay NULL so we do
            // not ask for them again.
            $this->collection += array_fill_keys($chunk, null);

            $criteria = [['id', 'in', $chunk]];
            $fields = ['email', 'relation_all_ids'];

            foreach ($this->odooClient->searchRead('member.profile', $criteria, $fields) as $data) {
                $this->collection[$data['id']] = new Profile($this, $data);
            }
        }
    }

    /**
     * Lookup the relatives (parents) of the profiles in Odoo.
     *
     * @param array<int|null> $profileIds The profile IDs to lookup relatives for.
     */
    public function prefetchRelatives(array $profileIds): void
    {
        $this->prefetch($profileIds);

        // The profiles that need their relatives looked up, and the
        // profiles each relation belongs to.
        $pending = [];
        $profileIdsByRelation = [];
        foreach (array_unique($profileIds) as $profileId) {
            $profile = $this->collection[$profileId] ?? null;

            if ($profile === null || $profile->hasRelationProfileIds()) {
                continue;
            }

            $pending[$profileId] = $profile;

            foreach ($profile->getRelationPartnerIds() as $relationId) {
                $profileIdsByRelation[$relationId][] = $profileId;
            }
        }

        // The partner IDs of the relatives of each profile.
        $partnerIds = [];
        $fields = ['id', 'other_partner_id', 'this_primary_contact', 'type_selection_id'];
        foreach (array_chunk(array_keys($profileIdsByRelation), self::CHUNK_SIZE) as $chunk) {
            foreach ($this->odooClient->read('res.partner.relation.all', $chunk, $fields) as $relation) {
                if (!$relation['this_primary_contact'] || self::TYPE_CHILD_OF != $relation['type_selection_id'][0]) {
                    continue;
                }

                foreach ($profileIdsByRelation[$relation['id']] ?? [] as $profileId) {
                    $partnerIds[$profileId][] = $relation['other_partner_id'][0];
                }
            }
        }

        // The profile ID of each partner.
        $profileIdByPartner = [];
        $uniquePartnerIds = array_values(array_unique(array_merge([], ...array_values($partnerIds))));
        foreach (array_chunk($uniquePartnerIds, self::CHUNK_SIZE) as $chunk) {
            $criteria = [['partner_id', 'in', $chunk]];

            foreach ($this->odooClient->searchRead('member.profile', $criteria, ['partner_id']) as $data) {
                // Keep the first profile of a partner like a search
                // for the single partner would.
                $profileIdByPartner[$data['partner_id'][0]] ??= $data['id'];
            }
        }

        $relativeIds = [];
        foreach ($pending as $profileId => $profile) {
            $relationProfileIds = [];
            foreach ($partnerIds[$profileId] ?? [] as $partnerId) {
                if (isset($profileIdByPartner[$partnerId])) {
                    $relationProfileIds[] = $profileIdByPartner[$partnerId];
                }
            }

            $profile->setRelationProfileIds($relationProfileIds);
            array_push($relativeIds, ...$relationProfileIds);
        }

        $this->prefetch($relativeIds);
    }
}
