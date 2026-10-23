<?php

namespace Tests\Unit\Queries\Workflow;

use App\Queries\Workflow\PrincipalContext;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 代理元の要求ごとの文脈(代理元が別の人なら選んだ組織を外す = docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。
 * 訪問者の ID は同じ要求の値なので、作り直しで落とさない(読み方 = 設計/メニュー/判定の入力.md 5 節)。
 */
final class PrincipalContextTest extends TestCase
{
    private const VISITOR_ID = '1d2c3b4a-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

    public function test_another_principal_keeps_the_visitor_id_and_drops_the_selected_department(): void
    {
        $context = new AccessContext(7, '192.0.2.1', 20, CarbonImmutable::parse('2026-10-23'), 'ja', self::VISITOR_ID);

        $principal = PrincipalContext::of($context, 8);

        $this->assertSame(8, $principal->userId);
        $this->assertNull($principal->selectedDepartmentId);
        $this->assertSame(self::VISITOR_ID, $principal->visitorId);
    }

    public function test_the_same_principal_returns_the_context_as_is(): void
    {
        $context = new AccessContext(7, null, 20, CarbonImmutable::parse('2026-10-23'), 'ja', self::VISITOR_ID);

        $this->assertSame($context, PrincipalContext::of($context, 7));
    }
}
