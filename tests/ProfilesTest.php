<?php

declare(strict_types=1);

namespace MSML\Tests;

use MSML\Profiles;
use PHPUnit\Framework\TestCase;

class ProfilesTest extends TestCase
{
    /**
     * A fake member.profile search_read on IDs.
     *
     * Profile 13 is hidden and is never returned.
     *
     * @param array<mixed> $args
     *
     * @return array<mixed>
     */
    protected function profilesById(array $args): array
    {
        $result = [];
        foreach ($args[0][0][2] as $id) {
            if ($id === 13) {
                continue;
            }

            $result[] = ['id' => $id, 'email' => "p{$id}@example.com", 'relation_all_ids' => []];
        }

        return $result;
    }

    public function testPrefetchUsesOneCallPerChunk(): void
    {
        $odoo = new FakeOdoo(fn (string $method, string $model, array $args): array => $this->profilesById($args));
        $profiles = new Profiles($odoo);

        $profiles->prefetch(range(1, 1200));

        $this->assertCount(3, $odoo->calls);
        $this->assertSame('p1@example.com', $profiles->getById(1)?->getMail());
        $this->assertSame('p1200@example.com', $profiles->getById(1200)?->getMail());
        $this->assertCount(3, $odoo->calls);
    }

    public function testHiddenProfilesAreOnlyLookedUpOnce(): void
    {
        $odoo = new FakeOdoo(fn (string $method, string $model, array $args): array => $this->profilesById($args));
        $profiles = new Profiles($odoo);

        $profiles->prefetch([12, 13]);
        $this->assertNull($profiles->getById(13));
        $profiles->prefetch([12, 13]);
        $this->assertNull($profiles->getById(13));

        $this->assertCount(1, $odoo->calls);
    }

    public function testPrefetchRelativesUsesAFixedNumberOfCalls(): void
    {
        $odoo = new FakeOdoo(function (string $method, string $model, array $args): array {
            if ($model === 'member.profile' && $args[0][0][0] === 'id') {
                $result = [];
                foreach ($args[0][0][2] as $id) {
                    $result[] = [
                        'id' => $id,
                        'email' => "p{$id}@example.com",
                        // Children 1 and 2 have relations, parents (100+) do not.
                        'relation_all_ids' => $id < 100 ? [$id * 10, $id * 10 + 1] : [],
                    ];
                }

                return $result;
            }

            if ($model === 'res.partner.relation.all') {
                $result = [];
                foreach ($args[0] as $id) {
                    $result[] = [
                        'id' => $id,
                        // Relation 11 is to a partner without a profile.
                        'other_partner_id' => [$id === 11 ? 999 : 1000 + $id, 'Parent'],
                        'this_primary_contact' => true,
                        // Relation 21 is not a child of relation.
                        'type_selection_id' => [$id === 21 ? 5 : 9, 'Type'],
                    ];
                }

                return $result;
            }

            // A member.profile search_read on partner IDs.
            $result = [];
            foreach ($args[0][0][2] as $partnerId) {
                if ($partnerId !== 999) {
                    $result[] = ['id' => $partnerId - 1000 + 100, 'partner_id' => [$partnerId, 'Parent']];
                }
            }

            return $result;
        });
        $profiles = new Profiles($odoo);

        $profiles->prefetchRelatives([1, 2]);

        // Children, relations, partners and parents.
        $this->assertCount(4, $odoo->calls);

        $this->assertSame(['p110@example.com'], $profiles->getById(1)?->getRelationMails());
        $this->assertSame(['p120@example.com'], $profiles->getById(2)?->getRelationMails());
        $this->assertCount(4, $odoo->calls);
    }

    public function testProfileWithoutRelativesIsOnlyExpandedOnce(): void
    {
        $odoo = new FakeOdoo(fn (string $method, string $model, array $args): array => $this->profilesById($args));
        $profiles = new Profiles($odoo);

        $profile = $profiles->getById(1);
        $this->assertNotNull($profile);

        $this->assertSame([], $profile->getRelationMails());
        $this->assertSame([], $profile->getRelationMails());

        // Only the profile itself. There are no relations to read.
        $this->assertCount(1, $odoo->calls);
    }
}
