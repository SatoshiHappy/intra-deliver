<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\Role;
use App\Queries\Authz\Api\RoleCandidates;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * ロールの候補の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: コード・そのロケールの表示名が語を含むロール。合い方は共通マスタの
 * 候補と同じ(大文字小文字を区別しない、%・_・\ は文字そのもの)。名前はそのロケールの表示名か null。並び = sort_key・code、件数まで。空の語は空)。
 * 初期データのロール(system_admin・master_admin・workflow_admin・general_user)で確かめる。
 */
final class RoleCandidatesTest extends TransactionalTestCase
{
    public function test_finds_roles_by_the_display_name_of_the_locale_in_the_order_of_the_sort_key(): void
    {
        $this->assertSame(
            [
                ['id' => $this->roleId('system_admin'), 'code' => 'system_admin', 'name' => 'システム管理者'],
                ['id' => $this->roleId('master_admin'), 'code' => 'master_admin', 'name' => '共通マスタ管理者'],
                ['id' => $this->roleId('workflow_admin'), 'code' => 'workflow_admin', 'name' => 'ワークフロー管理者'],
            ],
            $this->candidates()->roles('管理者', 'ja', 20),
        );
        $this->assertSame(['system_admin', 'master_admin'], $this->codes($this->candidates()->roles('管理者', 'ja', 2)));
        $this->assertSame(['workflow_admin'], $this->codes($this->candidates()->roles('Workflow ADMIN', 'en', 20)));
        $this->assertSame([], $this->candidates()->roles('Workflow ADMIN', 'ja', 20));
    }

    public function test_finds_roles_by_the_code_and_the_name_is_null_without_a_display_name_in_the_locale(): void
    {
        $this->assertSame(['system_admin', 'master_admin', 'workflow_admin'], $this->codes($this->candidates()->roles('ADMIN', 'ja', 20)));
        $this->assertSame([['code' => 'general_user', 'name' => null]], array_map(fn (array $role) => ['code' => $role['code'], 'name' => $role['name']], $this->candidates()->roles('general', 'fr', 20)));
    }

    #[TestWith(['%'])]
    #[TestWith(['__'])]
    #[TestWith(['\\'])]
    #[TestWith(['system%admin'])]
    #[TestWith([''])]
    public function test_wildcards_are_plain_characters_and_an_empty_keyword_finds_nothing(string $keyword): void
    {
        $this->assertSame([], $this->candidates()->roles($keyword, 'ja', 20));
    }

    public function test_an_underscore_matches_only_itself(): void
    {
        $this->assertSame(['system_admin'], $this->codes($this->candidates()->roles('m_a', 'ja', 20)));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_a_limit_below_one_is_a_logic_error(int $limit): void
    {
        $this->expectException(LogicException::class);

        $this->candidates()->roles('admin', 'ja', $limit);
    }

    private function candidates(): RoleCandidates
    {
        return app(RoleCandidates::class);
    }

    /**
     * @param  list<array{code: string}>  $rows
     * @return list<string>
     */
    private function codes(array $rows): array
    {
        return array_map(fn (array $row) => $row['code'], $rows);
    }

    private function roleId(string $code): int
    {
        return Role::query()->where('code', $code)->sole()->id;
    }
}
