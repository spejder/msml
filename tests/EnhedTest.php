<?php

declare(strict_types=1);

namespace MSML\Tests;

use MSML\Enhed;
use PHPUnit\Framework\TestCase;

class EnhedTest extends TestCase
{
    public function testUnitWithoutLeadersIsOnlyLookedUpOnce(): void
    {
        $odoo = new FakeOdoo(fn (): array => [['id' => 1, 'leader_ids' => []]]);
        $enhed = new Enhed($odoo, 1);

        $this->assertSame([], $enhed->getLeaders());
        $this->assertSame([], $enhed->getLeaders());
        $this->assertCount(1, $odoo->calls);
    }

    public function testLeadersUseTwoCalls(): void
    {
        $odoo = new FakeOdoo(function (string $method, string $model, array $args): array {
            if ($model === 'member.organization') {
                return [['id' => 1, 'leader_ids' => [10, 11]]];
            }

            $this->assertSame('search_read', $method);
            $this->assertSame([['id', 'in', [10, 11]]], $args[0]);

            return [
                ['id' => 10, 'profile_id' => [100, 'A']],
                ['id' => 11, 'profile_id' => [101, 'B']],
            ];
        });
        $enhed = new Enhed($odoo, 1);

        $this->assertSame([100, 101], $enhed->getLeaders());
        $this->assertCount(2, $odoo->calls);
    }

    public function testEmptyMembersAreOnlyLookedUpOnce(): void
    {
        $odoo = new FakeOdoo(fn (): array => []);
        $enhed = new Enhed($odoo, 1);

        $this->assertSame([], $enhed->getMembers());
        $this->assertSame([], $enhed->getMembers());
        $this->assertCount(1, $odoo->calls);
    }

    public function testGruppeFunktionerAreOnlyLookedUpOnce(): void
    {
        $odoo = new FakeOdoo(function (string $method, string $model): array {
            if ($model === 'member.organization') {
                return [['id' => 1, 'leader_ids' => [10, 11]]];
            }

            return [
                // Gruppeleder.
                ['id' => 10, 'function_type_id' => [234, 'GL'], 'profile_id' => [100, 'A']],
                // Bestyrelsesformand.
                ['id' => 11, 'function_type_id' => [233, 'BF'], 'profile_id' => [101, 'B']],
            ];
        });
        $enhed = new Enhed($odoo, 1);

        $this->assertSame([100], $enhed->getGruppeleder());
        $this->assertSame([101], $enhed->getBestyrelsesformand());
        // No kasserer, and that must not trigger a new lookup.
        $this->assertSame([], $enhed->getGruppekasserer());
        $this->assertSame([100], $enhed->getGruppeleder());
        $this->assertCount(2, $odoo->calls);
    }
}
