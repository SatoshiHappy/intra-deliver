<?php

namespace Tests\Unit\Actions\Workflow;

use App\Actions\Workflow\ReferenceFirstOrder;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * ノードを書く順(実装へ送られた手段 1): 参照される側を先に、参照の無いノードは渡した順のまま。循環・無い先は LogicException。
 */
final class ReferenceFirstOrderTest extends TestCase
{
    public function test_referenced_nodes_come_first_and_the_rest_keep_their_order(): void
    {
        $order = (new ReferenceFirstOrder)->order([
            'apply' => [], 'chief' => [], 'budget' => ['budget_end'], 'manager' => [], 'budget_end' => [], 'council' => ['chief'], 'owner' => ['chief'],
        ]);

        $this->assertSame(['apply', 'chief', 'budget_end', 'budget', 'manager', 'council', 'owner'], $order);
    }

    public function test_a_cycle_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);
        (new ReferenceFirstOrder)->order(['a' => ['b'], 'b' => ['a']]);
    }

    public function test_a_missing_reference_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);
        (new ReferenceFirstOrder)->order([1 => [2]]);
    }
}
