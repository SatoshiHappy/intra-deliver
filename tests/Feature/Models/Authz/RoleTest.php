<?php

namespace Tests\Feature\Models\Authz;

use App\Models\Authz\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ロール名(本家の制限「半角英字、数字、アンダースコア、ハイフン、アットマーク、ドット、プラス、または、エクスクラメーション」。この作業で決めた検査制約)。
 */
final class RoleTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function namesOutsideTheCharacters(): array
    {
        return [
            '空文字' => [''],
            '空白' => ['sales admin'],
            '全角の英字' => ['ＳＡＬＥＳ'],
            'ラテン文字の記号つきの英字' => ['café'],
            '日本語' => ['営業管理者'],
            '許されない記号' => ['sales#admin'],
        ];
    }

    #[DataProvider('namesOutsideTheCharacters')]
    public function test_rejects_a_name_with_characters_outside_the_allowed_set(string $name): void
    {
        $this->assertRejectedBy('roles_name_check', fn () => Role::factory()->create(['name' => $name]));
    }

    public function test_accepts_a_name_using_every_allowed_kind_of_character(): void
    {
        $role = Role::factory()->create(['name' => 'Sales_admin-01@example.jp+x!']);

        $this->assertModelExists($role);
    }
}
