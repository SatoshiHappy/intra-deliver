<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\LedgerMatch;

final class LedgerMatchTest extends TestCase
{
    public function test_subset_reports_only_entries_missing_from_the_allowed_set(): void
    {
        $difference = LedgerMatch::subset(['Models', 'Jobs', 'Http', 'Jobs'], ['Http', 'Models', 'Core']);

        $this->assertSame(['Jobs'], $difference->extra);
        $this->assertSame([], $difference->missing);
        $this->assertFalse($difference->isEmpty());
    }

    public function test_subset_accepts_unused_allowed_entries(): void
    {
        $difference = LedgerMatch::subset(['Http'], ['Http', 'Core']);

        $this->assertTrue($difference->isEmpty());
    }

    public function test_equal_reports_both_unregistered_and_padded_entries(): void
    {
        $difference = LedgerMatch::equal(['c', 'a', 'b'], ['d', 'b', 'c']);

        $this->assertSame(['a'], $difference->extra);
        $this->assertSame(['d'], $difference->missing);
        $this->assertSame('余分 = [a] / 不足 = [d]', $difference->describe());
    }

    public function test_equal_ignores_order_and_duplicates(): void
    {
        $difference = LedgerMatch::equal(['b', 'a', 'a'], ['a', 'b']);

        $this->assertTrue($difference->isEmpty());
    }
}
