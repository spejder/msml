<?php

declare(strict_types=1);

namespace MSML;

use Email\Parse;
use Spejder\Odoo\Odoo;

/**
 * Profile.
 */
class Profile
{
    protected Odoo $odooClient;
    protected Profiles $profiles;
    protected int $profileId;

    protected ?string $mail;

    /**
     * @var array<int>
     */
    protected array $relationPartnerIds;

    /**
     * @var array<int>
     */
    protected array $relationProfileIds;

    // Hard coded medlemssystem value.
    protected const TYPE_CHILD_OF = 9;

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

        $this->mail = null;
        $this->relationPartnerIds = [];
        $this->relationProfileIds = [];
    }

    /**
     * Get mail of profile.
     */
    public function getMail(): ?string
    {
        if (empty($this->mail)) {
            $this->extractProfile();
        }

        return $this->mail;
    }

    /**
     * Get a list of relations emails.
     *
     * @return string[]
     */
    public function getRelationMails(): array
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
    protected function extractProfile(): void
    {
        $fields = ['email', 'relation_all_ids'];
        $profiles = $this->odooClient->read('member.profile', [$this->profileId], $fields);
        $profile = reset($profiles);

        $parsedMail = Parse::getInstance()->parse($profile['email']);
        $this->mail = $parsedMail['success'] ? reset($parsedMail['email_addresses'])['simple_address'] : null;
        $this->relationPartnerIds = $profile['relation_all_ids'];
    }

    /**
     * Expand Relation Partner IDs into their Profile IDs.
     */
    protected function expandRelations(): void
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
                $profiles = $this->odooClient->search('member.profile', $criteria);

                $profile = reset($profiles);

                if (!is_int($profile)) {
                    continue;
                }

                $this->relationProfileIds[] = $profile;
            }
        }
    }
}
