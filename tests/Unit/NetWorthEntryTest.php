<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\NetWorthEntry;
use App\Models\NetWorthLineItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NetWorthEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_net_worth_entry_belongs_to_a_user(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create();

        $this->assertInstanceOf(User::class, $entry->user);
        $this->assertTrue($entry->user->is($user));
    }

    public function test_net_worth_entry_has_many_line_items(): void
    {
        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->create();

        NetWorthLineItem::factory()->for($entry)->createMany([
            ['type' => 'asset', 'category' => 'Savings', 'amount' => 1000],
            ['type' => 'liability', 'category' => 'Credit Card', 'amount' => 200],
        ]);

        $this->assertCount(2, $entry->lineItems);
        $this->assertInstanceOf(NetWorthLineItem::class, $entry->lineItems->first());
    }

    public function test_net_worth_entry_casts_amounts_to_decimal(): void
    {
        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->create([
            'assets' => '1500.567',
            'liabilities' => '500.234',
            'net_worth' => '1000.333',
        ]);

        $this->assertEqualsWithDelta(1500.57, (float) $entry->assets, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(500.23, (float) $entry->liabilities, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1000.33, (float) $entry->net_worth, PHP_FLOAT_EPSILON);
    }
}
