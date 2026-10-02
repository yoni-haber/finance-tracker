<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlannedBill;
use Tests\TestCase;

final class PlannedBillTest extends TestCase
{
    public function test_estimates_have_two_decimal_places_even_before_saving(): void
    {
        foreach ([600 => '600.00', '12.5' => '12.50', '0.01' => '0.01'] as $amount => $formatted) {
            $bill = new PlannedBill(['estimated_amount' => $amount]);
            $this->assertSame($formatted, $bill->estimated_amount);
        }
    }
}
