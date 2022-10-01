<?php

namespace MSML;

use Spejder\Odoo\Odoo;

/**
 * Enhed.
 */
class Enhed
{
    protected $odooClient;
    protected $organizationCode;
    protected $enhedId;

    protected $leaders;
    protected $leaderlist;
    protected $members;
    protected $waitingListMembers;
    protected $board;

    protected $gruppeleder;
    protected $bestyrelsesformand;
    protected $gruppekasserer;

    protected $webmaster;


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
     * @param string $enhedId          The Enhed ID (i.e. "2227-5").
     */
    public function __construct(Odoo $odooClient, int $organizationCode, string $enhedId = null)
    {
        $this->odooClient = $odooClient;
        $this->organizationCode = $organizationCode;
        $this->enhedId = $enhedId;

        $this->leaders = [];
        $this->leaderlist = [];
        $this->members = [];
        $this->board = [];

        $this->gruppeleder = [];
        $this->bestyrelsesformand = [];
        $this->gruppekasserer = [];
    }

    /**
     * Get leaders.
     *
     * @return array
     */
    public function getLeaders(): array
    {
        // Preferably use an cached version.
        if (empty($this->leaders)) {
            $this->requestLeaders();
        }

        return $this->leaders;
    }

    /**
     * Get leaderlist.
     *
     * @return array
     */
    public function getLeaderlist(): array
    {
        // Preferably use an cached version.
        if (empty($this->leaderlist)) {
            $this->requestLeaderlist();
        }

        return $this->leaderlist;
    }

    /**
     * Get board.
     *
     * @return array
     */
    public function getBoard(): array
    {
        // Preferably use an cached version.
        if (empty($this->board)) {
            $this->requestBoard();
        }

        return $this->board;
    }

    /**
     * Get webmaster.
     *
     * @return array
     */
    public function getWebmaster(): array
    {
        // Preferably use an cached version.
        if (empty($this->webmaster)) {
            $this->requestWebmaster();
        }

        return $this->webmaster;
    }

    /**
     * Get Members.
     *
     * @return array
     */
    public function getMembers(): array
    {
        // Preferably use an cached version.
        if (empty($this->members)) {
            $this->requestMembers();
        }

        return $this->members;
    }

    /**
     * Get Waiting List Members.
     *
     * @return array
     */
    public function getWaitingListMembers(): array
    {
        // Preferably use an cached version.
        if (empty($this->waitingListMembers)) {
            $this->requestWaitingListMembers();
        }

        return $this->waitingListMembers;
    }

    /**
     * Get Bestyrelsesformænd.
     *
     * @return array
     */
    public function getBestyrelsesformand(): array
    {
        // Preferably use an cached version.
        if (empty($this->bestyrelsesformand)) {
            $this->requestGruppeFunktioner();
        }

        return $this->bestyrelsesformand;
    }

    /**
     * Get Gruppeledere.
     *
     * @return array
     */
    public function getGruppeleder(): array
    {
        // Preferably use an cached version.
        if (empty($this->gruppeleder)) {
            $this->requestGruppeFunktioner();
        }

        return $this->gruppeleder;
    }

    /**
     * Get Gruppekasserere.
     *
     * @return array
     */
    public function getGruppekasserer(): array
    {
        // Preferably use an cached version.
        if (empty($this->gruppekasserer)) {
            $this->requestGruppeFunktioner();
        }

        return $this->gruppekasserer;
    }

    /**
     * Lookup leaders in Odoo.
     */
    protected function requestLeaders()
    {
        // Lookup leaders IDs on organization.
        $fields = ['leader_ids'];
        $leaderIds = $this->odooClient->read('member.organization', [0 => $this->organizationCode], $fields);

        // Lookup profile IDs from Leaders IDs.
        $criteria = [
            ['id', '=', $leaderIds[0]['leader_ids']],
        ];
        $functionIds = $this->odooClient->search('member.function', $criteria);

        $fields = ['profile_id'];
        $leaders = $this->odooClient->read('member.function', $functionIds, $fields);

        // Only keep the profile ID.
        $this->leaders = array_map(function ($leader) {
            return $leader['profile_id'][0];
        }, $leaders);
    }

    /**
     * Lookup leaderlist in Odoo.
     */
    protected function requestLeaderlist()
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
        $this->leaderlist = array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup board in Odoo.
     */
    protected function requestBoard()
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
        $this->board = array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup webmasters in Odoo.
     */
    protected function requestWebmaster()
    {
        $criteria = [
            ['organization_id', 'child_of', $this->organizationCode],
            ['function_type_id', '=', self::WEBANSVARLIG],
        ];
        $fields = ['profile_id'];
        $profiles = $this->odooClient->searchRead('member.function', $criteria, $fields);

        // Only keep the profile ID.
        $this->webmaster = array_map(function ($profile) {
            return $profile['profile_id'][0];
        }, $profiles);
    }

    /**
     * Lookup members in Odoo.
     */
    protected function requestMembers()
    {
        // @todo filtrer ledere fra -- nedenstående virker ikke
        // delvist ikke pga ledere oftest _er_ medlemer (udover
        // ledere) jvf medlemssystemet
        $criteria = [
            ['member_id.function_ids.organization_id', 'child_of', $this->organizationCode],
            ['state', '=', 'active'],
            ['member_id.function_ids.leader_function', '!=', true],
        ];
        $this->members = $this->odooClient->search('member.profile', $criteria);
    }

    /**
     * Lookup waiting list members in Odoo.
     */
    protected function requestWaitingListMembers()
    {
        $criteria = [
            ['preliminary_organization_id', '=', $this->organizationCode],
            ['state', '=', 'waiting'],
        ];
        $this->waitingListMembers = $this->odooClient->search('member.profile', $criteria);
    }

    /**
     * Lookup gruppeledere, bestyrelsesformænd, and kasserere.
     */
    protected function requestGruppeFunktioner()
    {
        $fields = ['leader_ids'];
        $leaderIds = $this->odooClient->read('member.organization', [0 => $this->organizationCode], $fields);

        $fields = ['function_type_id', 'member_id'];
        $functions = $this->odooClient->read('member.function', $leaderIds[0]['leader_ids'], $fields);

        foreach ($functions as $function) {
            $criteria = [
                ['member_id', '=', $function['member_id'][0]],
            ];
            $fields = ['id'];
            $profileId = $this->odooClient->searchRead('member.profile', $criteria, $fields);

            switch ($function['function_type_id'][0]) {
                case self::BESTYRELSESFORMAND:
                    $this->bestyrelsesformand[] = $profileId[0]['id'];
                    break;

                case self::GRUPPELEDER:
                    $this->gruppeleder[] = $profileId[0]['id'];
                    break;

                case self::GRUPPEKASSERER:
                    $this->gruppekasserer[] = $profileId[0]['id'];
                    break;
            }
        }
    }
}
