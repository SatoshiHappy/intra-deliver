<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\CompanyPost;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 役職のアンカー(ランクは本家の NUMERIC(5,0) の範囲 = 5 桁まで。この作業で決めた検査制約)。
 */
final class CompanyPostTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{int}>
     */
    public static function ranksBeyond5Digits(): array
    {
        return ['10^5' => [100_000], '-10^5' => [-100_000]];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function ranksWithin5Digits(): array
    {
        return ['10^5 - 1' => [99_999], '-(10^5 - 1)' => [-99_999]];
    }

    #[DataProvider('ranksBeyond5Digits')]
    public function test_rejects_a_rank_beyond_5_digits(int $rank): void
    {
        $this->assertRejectedBy('company_posts_rank_check', fn () => CompanyPost::factory()->create(['rank' => $rank]));
    }

    #[DataProvider('ranksWithin5Digits')]
    public function test_accepts_a_rank_within_5_digits(int $rank): void
    {
        $post = CompanyPost::factory()->create(['rank' => $rank]);

        $this->assertSame($rank, $post->refresh()->rank);
    }
}
