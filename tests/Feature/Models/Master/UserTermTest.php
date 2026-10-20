<?php

namespace Tests\Feature\Models\Master;

use App\Enums\Master\Sex;
use App\Models\Master\UserTerm;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーの期間の行の性別(本家のインポート形式の値 0・1・2・9 と NULL だけ。この作業で決めた検査制約)。
 */
final class UserTermTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{Sex}>
     */
    public static function sexes(): array
    {
        $cases = [];

        foreach (Sex::cases() as $sex) {
            $cases[$sex->name] = [$sex];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesOutsideTheList(): array
    {
        return ['一覧に無い数字' => ['3'], '英字' => ['M']];
    }

    #[DataProvider('sexes')]
    public function test_reads_back_each_sex_as_the_enum(Sex $sex): void
    {
        $term = UserTerm::factory()->create(['sex' => $sex]);

        $this->assertSame($sex, $term->refresh()->sex);
    }

    public function test_accepts_no_sex(): void
    {
        $term = UserTerm::factory()->create(['sex' => null]);

        $this->assertNull($term->refresh()->sex);
    }

    #[DataProvider('valuesOutsideTheList')]
    public function test_rejects_a_sex_outside_the_list(string $value): void
    {
        $row = [...UserTerm::factory()->makeOne()->getAttributes(), 'sex' => $value];

        $this->assertRejectedBy('user_terms_sex_check', fn () => DB::table('user_terms')->insert($row));
    }
}
