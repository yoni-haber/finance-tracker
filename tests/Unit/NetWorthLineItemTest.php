<?php

namespace Tests\Unit;

use App\Models\NetWorthLineItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetWorthLineItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_net_worth_line_item_amount_casts_to_decimal(): void
    {
        /** @var NetWorthLineItem $lineItem */
        $lineItem = NetWorthLineItem::factory()->create(['amount' => '123.456']);

        $this->assertSame(123.46, (float) $lineItem->amount);
    }

    public function test_net_worth_line_item_belongs_to_a_user(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthLineItem $lineItem */
        $lineItem = NetWorthLineItem::factory()->for($user)->create();

        $this->assertInstanceOf(User::class, $lineItem->user);
        $this->assertTrue($lineItem->user->is($user));
    }

    public function test_net_worth_line_item_belongs_to_a_net_worth_entry(): void
    {
        /** @var NetWorthLineItem $lineItem */
        $lineItem = NetWorthLineItem::factory()->create();

        $this->assertEquals($lineItem->net_worth_entry_id, $lineItem->netWorthEntry->id);
    }
}
