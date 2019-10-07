<?php

namespace MSML;

use Jsg\Odoo\Odoo;

/**
 * Profile.
 */
class Profile
{
    protected $odooClient;
    protected $profiles;
    protected $profileId;

    protected $name;
    protected $mail;
    protected $relationPartnerIds;
    protected $relationProfileIds;

    // Hard coded medlemssystem value.
    protected const TYPE_CHILD_OF = 11;

    /**
     * Construct but lazy load most stuff.
     *
     * @param Odoo     $odooClient The Odoo Client to use for later lookups.
     * @param Profiles $profiles   The profiles storage.
     * @param int      $profileId  The organization code.
     */
    public function __construct(Odoo $odooClient, Profiles $profiles, int $profileId)
    {
        $this->odooClient = $odooClient;
        $this->profiles = $profiles;
        $this->profileId = $profileId;

        $this->name = null;
        $this->mail = null;
        $this->relationPartnerIds = [];
        $this->relationProfileIds = [];
    }

    /**
     * Get mail of profile.
     *
     * @return string
     */
    public function getMail()
    {
        if (empty($this->mail)) {
            $this->extractProfile();
        }

        return $this->mail;
    }

    /**
     * Get a list of relations emails.
     *
     * @return array
     */
    public function getRelationMails()
    {
        $this->expandRelations();
        $result = [];
        foreach ($this->relationProfileIds as $profileId) {
            $result[] = $this->profiles->getById($profileId)->getMail();
        }

        return array_filter($result);
    }

    /**
     * Extract profile from Odoo.
     */
    protected function extractProfile()
    {
        $fields = ['name', 'email', 'member_number', 'relation_all_ids'];
        $profile = $this->odooClient->read('member.profile', $this->profileId, $fields);

        $this->name = trim($profile['name']);
        $this->mail = trim($profile['email']);
        $this->relationPartnerIds = $profile['relation_all_ids'];
    }

    /**
     * Expand Relation Partner IDs into their Profile IDs.
     */
    protected function expandRelations()
    {
        // Bail out early if already expanded.
        if (!empty($this->relationProfileIds)) {
            return;
        }

        // If the profile data has not been extracted from Odoo yet
        // do it now.
        if (empty($this->relationPartnerIds)) {
            $this->extractProfile();
        }

        $fields = ['id', 'other_partner_id', 'this_primary_contact', 'type_selection_id'];
        $relations = $this->odooClient->read('res.partner.relation.all', $this->relationPartnerIds, $fields);

        // @todo Since we do a search below there is maybe no need to
        // repeat it in a foreach loop. Refactor?
        foreach ($relations as $relation) {
            if (($relation['this_primary_contact']) && (self::TYPE_CHILD_OF == $relation['type_selection_id'][0])) {
                $criteria = [
                    ['partner_id', '=', $relation['other_partner_id'][0]],
                ];
                $profile = $this->odooClient->search('member.profile', $criteria);
                $this->relationProfileIds[] = reset($profile);
            }
        }
    }
}
