<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordCommentAdministration;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * コメントの管理の権限(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 4 節): 管理のルートの execute を許されるのは初期データでは
 * システム管理者(u0004)だけ。判定は Authz の公開の口 RouteAuthorization(ルートの認可と同じ判定。一致は RouteAuthorizationTest)を通す。
 * 初期データのユーザーで確かめる。
 */
final class RecordCommentAdministrationTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_only_the_system_administrator_may_delete_others_comments_and_the_guest_may_not(): void
    {
        $administration = app(RecordCommentAdministration::class);
        $date = $this->contextFor()->date;

        $this->assertTrue($administration->allows(new Requester($this->seeded('u0004'), null), $date));
        $this->assertFalse($administration->allows(new Requester($this->seeded('u0001'), null), $date));
        $this->assertFalse($administration->allows(new Requester($this->seeded('u0007'), null), $date));
        $this->assertFalse($administration->allows(new Requester(null, null), $date));
    }

    private function seeded(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
