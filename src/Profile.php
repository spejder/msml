<?php

declare(strict_types=1);

namespace MSML;

use Email\Parse;

/**
 * Profile.
 *
 * Profiles are loaded by \MSML\Profiles which does all the lookups in
 * Odoo.
 */
class Profile
{
    protected Profiles $profiles;
    protected int $profileId;

    protected ?string $mail;

    /**
     * @var array<int>
     */
    protected array $relationPartnerIds;

    /**
     * Profile IDs of the relatives. NULL until they are looked up.
     *
     * @var array<int>|null
     */
    protected ?array $relationProfileIds = null;

    /**
     * Construct from a member.profile record from Odoo.
     *
     * @param Profiles $profiles The profiles storage.
     * @param array<string, mixed> $data The fields "id", "email", and "relation_all_ids".
     */
    public function __construct(Profiles $profiles, array $data)
    {
        $this->profiles = $profiles;
        $this->profileId = $data['id'];

        $mail = is_string($data['email']) ? $data['email'] : '';
        $parsedMail = Parse::getInstance()->parse($mail);
        $this->mail = $parsedMail['success'] ? reset($parsedMail['email_addresses'])['simple_address'] : null;
        $this->relationPartnerIds = $data['relation_all_ids'];
    }

    /**
     * Get the profile ID.
     */
    public function getId(): int
    {
        return $this->profileId;
    }

    /**
     * Get mail of profile.
     */
    public function getMail(): ?string
    {
        return $this->mail;
    }

    /**
     * Get the IDs of the relations (res.partner.relation.all).
     *
     * @return array<int>
     */
    public function getRelationPartnerIds(): array
    {
        return $this->relationPartnerIds;
    }

    /**
     * Whether the relatives are looked up already.
     */
    public function hasRelationProfileIds(): bool
    {
        return $this->relationProfileIds !== null;
    }

    /**
     * Set the profile IDs of the relatives.
     *
     * @param array<int> $profileIds
     */
    public function setRelationProfileIds(array $profileIds): void
    {
        $this->relationProfileIds = $profileIds;
    }

    /**
     * Get a list of relations emails.
     *
     * @return string[]
     */
    public function getRelationMails(): array
    {
        if ($this->relationProfileIds === null) {
            $this->profiles->prefetchRelatives([$this->profileId]);
        }

        $result = [];
        foreach ($this->relationProfileIds ?? [] as $profileId) {
            $result[] = $this->profiles->getById($profileId)?->getMail();
        }

        return array_filter($result);
    }
}
