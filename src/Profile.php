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
    protected $memberId;

    protected $name;
    protected $mail;
    protected $relationPartnerIds;
    protected $relationProfileIds;

    // Hard coded medlemssystem value.
    const TYPE_CHILD_OF = 11;

    /**
     * Construct but lazy load most stuff.
     *
     * @param Odoo     $odooClient The Odoo Client to use for later lookups.
     * @param Profiles $profiles   The profiles storage.
     * @param int      $memberId  The organization code.
     */
    public function __construct(Odoo $odooClient, Profiles $profiles, int $memberId)
    {
        $this->odooClient = $odooClient;
        $this->profiles = $profiles;
        $this->memberId = $memberId;

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
        foreach ($this->relationProfileIds as $memberId) {
            $result[] = $this->profiles->getById($memberId)->getMail();
        }

        return array_filter($result);
    }

    /**
     * Extract profile from Odoo.
     */
    protected function extractProfile()
    {
        $fields = ['name', 'email', 'member_number', 'relation_all_ids'];
        $member = $this->odooClient->read('member.member', $this->memberId, $fields);

        $this->name = trim($member['name']);
        $this->mail = trim($member['email']);
        $this->relationPartnerIds = $member['relation_all_ids'];
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
                $member = $this->odooClient->search('member.member', $criteria);
                $this->relationProfileIds[] = reset($member);
            }
        }
    }
}
