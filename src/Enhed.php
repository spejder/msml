<?php

declare(strict_types=1);

namespace MSML;

use Spejder\Odoo\Odoo;

/**
 * Enhed.
 */
class Enhed
{
    protected Odoo $odooClient;
    protected int $organizationCode;
    protected int|string|null $enhedId;

    /**
     * IDs of the leader functions (member.function). NULL until they
     * are looked up.
     *
     * @var array<int>|null
     */
    protected ?array $leaderIds;

    /**
     * @var array<int>|null
     */
    protected ?array $leaders = null;
    /**
     * @var array<int>|null
     */
    protected ?array $leaderlist = null;
    /**
     * @var array<int>|null
     */
    protected ?array $members = null;
    /**
     * @var array<int>|null
     */
    protected ?array $waitingListMembers = null;
    /**
     * @var array<int>|null
     */
    protected ?array $board = null;

    /**
     * @var array<int>
     */
    protected array $gruppeleder;
    /**
     * @var array<int>
     */
    protected array $bestyrelsesformand;
    /**
     * @var array<int>
     */
    protected array $gruppekasserer;

    protected bool $gruppeFunktionerLoaded = false;

    /**
     * @var array<int>|null
     */
    protected ?array $webmaster = null;


    protected const BESTYRELSESFORMAND = 233;
    protected const GRUPPELEDER = 234;
    protected const GRUPPEKASSERER = 285;

    protected const GRUPPEREVISOR = 305;
    protected const GRUPPEREVISORSUPPLEANT = 248;

    protected const WEBANSVARLIG = 275;


    /**
     * Construct but lazy load most stuff.
     *
     * @param Odoo   $odooClient       The Odoo Client to use for later lookups.
     * @param int    $organizationCode The organization code.
     * @param int|string|null $enhedId          The Enhed ID (i.e. "2227-5").
     * @param array<int>|null $leaderIds        The leader_ids if already known.
     */
    public function __construct(
        Odoo $odooClient,
        int $organizationCode,
        int|string|null $enhedId = null,
        ?array $leaderIds = null
    ) {
        $this->odooClient = $odooClient;
        $this->organizationCode = $organizationCode;
        $this->enhedId = $enhedId;
        $this->leaderIds = $leaderIds;

        $this->gruppeleder = [];
        $this->bestyrelsesformand = [];
        $this->gruppekasserer = [];
    }

    /**
     * Get leaders.
     *
     * @return array<int>
     */
    public function getLeaders(): array
    {
        // Preferably use an cached version.
        return $this->leaders ??= $this->requestLeaders();
    }

    /**
     * Set the leaders when they are looked up elsewhere.
     *
     * @param array<int> $profileIds The profile IDs of the leaders.
     */
    public function setLeaders(array $profileIds): void
    {
        $this->leaders = $profileIds;
    }

    /**
     * Get leaderlist.
     *
     * @return array<int>
     */
    public function getLeaderlist(): array
    {
        // Preferably use an cached version.
        return $this->leaderlist ??= $this->requestLeaderlist();
    }

    /**
     * Get board.
     *
     * @return array<int>
     */
    public function getBoard(): array
    {
        // Preferably use an cached version.
        return $this->board ??= $this->requestBoard();
    }

    /**
     * Get webmaster.
     *
     * @return array<int>
     */
    public function getWebmaster(): array
    {
        // Preferably use an cached version.
        return $this->webmaster ??= $this->requestWebmaster();
    }

    /**
     * Get Members.
     *
     * @return array<int>
     */
    public function getMembers(): array
    {
        // Preferably use an cached version.
        return $this->members ??= $this->requestMembers();
    }

    /**
     * Get Waiting List Members.
     *
     * @return array<int>
     */
    public function getWaitingListMembers(): array
    {
        // Preferably use an cached version.
        return $this->waitingListMembers ??= $this->requestWaitingListMembers();
    }

    /**
     * Get Bestyrelsesformænd.
     *
     * @return array<int>
     */
    public function getBestyrelsesformand(): array
    {
        // Preferably use an cached version.
        if (!$this->gruppeFunktionerLoaded) {
            $this->requestGruppeFunktioner();
        }

        return $this->bestyrelsesformand;
    }

    /**
     * Get Gruppeledere.
     *
     * @return array<int>
     */
    public function getGruppeleder(): array
    {
        // Preferably use an cached version.
        if (!$this->gruppeFunktionerLoaded) {
            $this->requestGruppeFunktioner();
        }

        return $this->gruppeleder;
    }

    /**
     * Get Gruppekasserere.
     *
     * @return array<int>
     */
    public function getGruppekasserer(): array
    {
        // Preferably use an cached version.
        if (!$this->gruppeFunktionerLoaded) {
            $this->requestGruppeFunktioner();
        }

        return $this->gruppekasserer;
    }

    /**
     * Get the IDs of the leader functions on the organization.
     *
     * @return array<int>
     */
    protected function getLeaderIds(): array
    {
        if ($this->leaderIds === null) {
            $fields = ['leader_ids'];
            $organizations = $this->odooClient->read('member.organization', [0 => $this->organizationCode], $fields);
            $this->leaderIds = $organizations[0]['leader_ids'] ?? [];
        }

        return $this->leaderIds;
    }

    /**
     * Lookup leaders in Odoo.
     *
     * @return array<int>
     */
    protected function requestLeaders(): array
    {
        $leaderIds = $this->getLeaderIds();

        if (empty($leaderIds)) {
            return [];
        }

        // Lookup profile IDs from Leaders IDs. Search instead of read
        // so Odoo filters the functions like it always has.
        $criteria = [
            ['id', 'in', $leaderIds],
        ];
        $fields = ['profile_id'];
        $leaders = $this->odooClient->searchRead('member.function', $criteria, $fields);

        // Only keep the profile ID.
        return array_map(function ($leader) {
            return $leader['profile_id'][0];
        }, $leaders);
    }

    /**
     * Lookup leaderlist in Odoo.
     *
     * @return array<int>
     */
    protected function requestLeaderlist(): array
    {
        $criteria = [
            '|',
            ['organization_id', 'child_of', $this->organizationCode],
            ['organization_id', '=', $this->organizationCode],
            ['function_type_id.leader_function', '=', true],
        ];
        $fields = ['profile_id'];
        $profiles = $this->odooClient->searchRead('member.function', $criteria, $fields);

        // Only keep the profile ID.
        return array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup board in Odoo.
     *
     * @return array<int>
     */
    protected function requestBoard(): array
    {
        $criteria = [
            ['organization_id', 'child_of', $this->organizationCode],
            ['function_type_id.board_function', '=', true],
            ['function_type_id', '!=', self::GRUPPEREVISOR],
            ['function_type_id', '!=', self::GRUPPEREVISORSUPPLEANT],
        ];
        $fields = ['profile_id'];
        $profiles = $this->odooClient->searchRead('member.function', $criteria, $fields);

        // Only keep the profile ID.
        return array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup webmasters in Odoo.
     *
     * @return array<int>
     */
    protected function requestWebmaster(): array
    {
        $criteria = [
            ['organization_id', 'child_of', $this->organizationCode],
            ['function_type_id', '=', self::WEBANSVARLIG],
        ];
        $fields = ['profile_id'];
        $profiles = $this->odooClient->searchRead('member.function', $criteria, $fields);

        // Only keep the profile ID.
        return array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup members in Odoo.
     *
     * @return array<int>
     */
    protected function requestMembers(): array
    {
        // @todo filtrer ledere fra -- nedenstående virker ikke
        // delvist ikke pga ledere oftest _er_ medlemer (udover
        // ledere) jvf medlemssystemet
        $criteria = [
            ['member_id.function_ids.organization_id', 'child_of', $this->organizationCode],
            ['state', '=', 'active'],
            ['member_id.function_ids.leader_function', '!=', true],
        ];
        return $this->odooClient->search('member.profile', $criteria);
    }

    /**
     * Lookup waiting list members in Odoo.
     *
     * @return array<int>
     */
    protected function requestWaitingListMembers(): array
    {
        $criteria = [
            ['preliminary_organization_id', '=', $this->organizationCode],
            ['state', '=', 'waiting'],
        ];
        return $this->odooClient->search('member.profile', $criteria);
    }

    /**
     * Lookup gruppeledere, bestyrelsesformænd, and kasserere.
     */
    protected function requestGruppeFunktioner(): void
    {
        $this->gruppeFunktionerLoaded = true;
        $this->gruppeleder = [];
        $this->bestyrelsesformand = [];
        $this->gruppekasserer = [];

        $leaderIds = $this->getLeaderIds();

        if (empty($leaderIds)) {
            return;
        }

        $fields = ['function_type_id', 'profile_id'];
        $functions = $this->odooClient->read('member.function', $leaderIds, $fields);

        foreach ($functions as $function) {
            $profileId = $function['profile_id'][0];

            switch ($function['function_type_id'][0]) {
                case self::BESTYRELSESFORMAND:
                    $this->bestyrelsesformand[] = $profileId;
                    break;

                case self::GRUPPELEDER:
                    $this->gruppeleder[] = $profileId;
                    break;

                case self::GRUPPEKASSERER:
                    $this->gruppekasserer[] = $profileId;
                    break;
            }
        }
    }
}
