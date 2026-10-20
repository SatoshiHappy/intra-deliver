<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\UserTermTranslation;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーのロケール別の行(docs の 設計/共通マスタ/組織とユーザー.md 7 節: 氏名は空にしない)。
 */
final class UserTermTranslationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy('user_term_translations_name_check', fn () => UserTermTranslation::factory()->create(['name' => '']));
    }
}
