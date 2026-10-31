<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Queries\Master\Api\MasterNames;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザー・組織・役職の表示名の公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1: id → {code, name}。存在しない id は入れない。
 * d・ロケールの名前が無ければ name が null、code はアンカーの値)。初期データ(u0014 は 2025-10-01 に退職、第二営業部は 2025-04-01 から)で確かめる。
 */
final class MasterNamesTest extends TransactionalTestCase
{
    public function test_returns_the_code_and_the_name_in_the_locale_on_the_date_in_the_given_order_without_duplicates(): void
    {
        [$first, $retired] = [$this->userId('u0001'), $this->userId('u0014')];

        $this->assertSame(
            [$retired => ['code' => 'u0014', 'name' => '木村 綾'], $first => ['code' => 'u0001', 'name' => '佐々木 陽太']],
            $this->names()->users([$retired, $first, $retired], $this->day('2025-09-30'), 'ja'),
        );
        $this->assertSame([$first => ['code' => 'u0001', 'name' => 'Yota Sasaki']], $this->names()->users([$first], $this->day('2025-09-30'), 'en'));
        $this->assertSame(
            [$this->departmentId('general-affairs') => ['code' => 'general-affairs', 'name' => '総務部']],
            $this->names()->departments([$this->departmentId('general-affairs')], $this->day('2025-09-30'), 'ja'),
        );
    }

    public function test_leaves_out_ids_that_do_not_exist(): void
    {
        $missingUser = (int) User::query()->max('id') + 1;
        $missingDepartment = (int) Department::query()->max('id') + 1;
        $first = $this->userId('u0001');

        $this->assertSame([$first], array_keys($this->names()->users([$missingUser, $first], $this->day('2026-01-01'), 'ja')));
        $this->assertSame([], $this->names()->departments([$missingDepartment], $this->day('2026-01-01'), 'ja'));
        $this->assertSame([], $this->names()->users([], $this->day('2026-01-01'), 'ja'));
    }

    public function test_the_name_is_null_and_the_code_stays_without_a_term_on_the_date_or_a_name_in_the_locale(): void
    {
        $retired = $this->userId('u0014');
        $sales2 = $this->departmentId('sales-2');

        $this->assertSame([$retired => ['code' => 'u0014', 'name' => null]], $this->names()->users([$retired], $this->day('2025-10-01'), 'ja'));
        $this->assertSame([$sales2 => ['code' => 'sales-2', 'name' => null]], $this->names()->departments([$sales2], $this->day('2025-03-31'), 'ja'));
        $this->assertSame([$sales2 => ['code' => 'sales-2', 'name' => null]], $this->names()->departments([$sales2], $this->day('2026-01-01'), 'fr'));
    }

    public function test_returns_the_code_and_the_name_of_company_posts_and_leaves_out_missing_ids(): void
    {
        [$manager, $chief] = [$this->companyPostId('manager'), $this->companyPostId('section-chief')];
        $missing = (int) CompanyPost::query()->max('id') + 1;

        $this->assertSame(
            [$chief => ['code' => 'section-chief', 'name' => '課長'], $manager => ['code' => 'manager', 'name' => '部長']],
            $this->names()->companyPosts([$chief, $missing, $manager, $chief], $this->day('2026-10-10'), 'ja'),
        );
        $this->assertSame(
            [$manager => ['code' => 'manager', 'name' => null]],
            $this->names()->companyPosts([$manager], $this->day('2026-10-10'), 'fr'),
        );
    }

    private function companyPostId(string $code): int
    {
        return CompanyPost::query()->where('code', $code)->sole()->id;
    }

    private function names(): MasterNames
    {
        return app(MasterNames::class);
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }
}
