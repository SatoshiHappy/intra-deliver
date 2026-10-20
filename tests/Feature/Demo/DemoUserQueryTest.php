<?php

namespace Tests\Feature\Demo;

use App\Models\Master\CompanyPostTerm;
use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Queries\Demo\DemoUser;
use App\Queries\Demo\DemoUserQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 切替の候補の読み取り(docs の 設計/デモユーザー切替/デモの取り付け.md 6.5): 候補の所属の表示の役職の並び(DB の ORDER BY と同じ順)と、
 * 出荷する config/demo.php の users の形(差し替えずに実物を読む)。
 */
final class DemoUserQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    public function test_the_posts_of_the_affiliation_are_in_the_order_of_the_database_for_all_digit_codes(): void
    {
        DepartmentSet::query()->update(['is_default' => false]);
        $set = DepartmentSet::factory()->create(['is_default' => true]);
        $department = $this->department($set, isRoot: true);
        DepartmentTermTranslation::factory()->create(['department_term_id' => $department->departmentTerms()->sole()->id, 'locale_id' => $this->ja()->id, 'name' => '総務部']);
        $user = $this->user();
        $membership = $this->join($user, $department, isMain: true);

        foreach (['2' => '二', '10' => '十'] as $code => $name) {
            $post = $this->companyPost($set, 10);
            $post->update(['code' => (string) $code, 'sort_key' => 1]);
            CompanyPostTermTranslation::factory()->create([
                'company_post_term_id' => CompanyPostTerm::query()->where('company_post_id', $post->id)->sole()->id,
                'locale_id' => $this->ja()->id,
                'name' => $name,
            ]);
            $this->assignPost($membership, $post);
        }

        $affiliations = app(DemoUserQuery::class)->affiliationsOn([new DemoUser($user->id, $user->code, null, '役どころ', '試せること')], self::day(self::DATE), 'ja');

        $this->assertSame([$user->id => '総務部 十 二'], $affiliations, '同じ sort_key の数字だけのコードは DB の文字列の順(10 → 2)');
    }

    /**
     * 候補を組む読み取りは role・can を必ず文字列で読む(欠けると例外 = デモがオンの全ページが 500 になる)ので、出荷する設定の全員の値を止める。
     */
    public function test_the_shipped_config_gives_every_candidate_a_role_and_a_can(): void
    {
        $shipped = require config_path('demo.php');
        $users = $shipped['users'];

        $this->assertNotSame([], $users);

        foreach ($users as $code => $values) {
            $this->assertIsArray($values, "demo.users.{$code}");

            foreach (['role', 'can'] as $key) {
                $this->assertIsString($values[$key] ?? null, "demo.users.{$code}.{$key}");
                $this->assertNotSame('', $values[$key], "demo.users.{$code}.{$key}");
            }
        }
    }

    private function ja(): Locale
    {
        return Locale::query()->where('code', 'ja')->sole();
    }
}
