<?php

declare(strict_types=1);

namespace MSML\Tests;

use MSML\Enheder;
use PHPUnit\Framework\TestCase;

class EnhederTest extends TestCase
{
    /**
     * A fake Odoo with three units.
     *
     * Unit 1 ("2227-1") has leader functions 10 and 11, unit 2 ("2227-2")
     * has function 12, and unit 3 ("2227") has none.
     */
    protected function fakeOdoo(): FakeOdoo
    {
        $units = [
            ['id' => 1, 'organization_code' => '2227-1', 'leader_ids' => [10, 11]],
            ['id' => 2, 'organization_code' => '2227-2', 'leader_ids' => [12]],
            ['id' => 3, 'organization_code' => '2227', 'leader_ids' => []],
        ];
        $functions = [
            // Odoo order, which is not the order of the IDs.
            ['id' => 11, 'profile_id' => [101, 'B'], 'function_type_id' => [234, 'GL']],
            ['id' => 12, 'profile_id' => [102, 'C'], 'function_type_id' => [233, 'BF']],
            ['id' => 10, 'profile_id' => [100, 'A'], 'function_type_id' => [285, 'GK']],
        ];

        return new FakeOdoo(function (string $method, string $model, array $args) use ($units, $functions): array {
            $records = $model === 'member.organization' ? $units : $functions;

            if ($method === 'read') {
                $ids = $args[0];
                return array_values(array_filter($records, fn (array $record): bool => in_array($record['id'], $ids)));
            }

            [$field, $operator, $value] = $args[0][0];
            $values = $operator === 'in' ? $value : [$value];
            $records = array_filter($records, fn (array $record): bool => in_array($record[$field], $values, true));

            return $method === 'search' ? array_values(array_column($records, 'id')) : array_values($records);
        });
    }

    public function testPrefetchUsesThreeCalls(): void
    {
        $odoo = $this->fakeOdoo();
        $enheder = new Enheder($odoo);

        $enheder->prefetch([2, 3], ['2227-1', '2227']);

        $this->assertCount(3, $odoo->calls);

        $this->assertSame([101, 100], $enheder->getByOrganizationCode('2227-1')?->getLeaders());
        $this->assertSame([102], $enheder->getById(2)?->getLeaders());
        $this->assertSame([], $enheder->getByOrganizationCode('2227')?->getLeaders());
        $this->assertSame($enheder->getById(3), $enheder->getByOrganizationCode('2227'));

        $this->assertCount(3, $odoo->calls);
    }

    public function testLeaderIdsAreReadOnce(): void
    {
        $odoo = $this->fakeOdoo();
        $enheder = new Enheder($odoo);

        $enhed = $enheder->getById(1);
        $this->assertNotNull($enhed);

        $this->assertSame([101, 100], $enhed->getLeaders());
        $this->assertSame([101], $enhed->getGruppeleder());
        $this->assertSame([100], $enhed->getGruppekasserer());

        $this->assertSame(1, $odoo->count('read', 'member.organization'));
    }

    public function testMissingUnitsAreOnlyLookedUpOnce(): void
    {
        $odoo = $this->fakeOdoo();
        $enheder = new Enheder($odoo);

        $enheder->prefetch([], ['9999-1']);
        $this->assertNull($enheder->getByOrganizationCode('9999-1'));
        $this->assertNull($enheder->getById(99));
        $this->assertNull($enheder->getById(99));

        $this->assertCount(2, $odoo->calls);
    }
}
