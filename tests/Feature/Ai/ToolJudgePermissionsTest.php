<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\ToolJudge;
use App\Actions\Ai\ToolCalls\ToolJudgement;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\AuthorizationReuse;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 判定の関数の操作の権限(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B2・B12・B9。権限の列を展開する): 同じジョブの中で、外の使い回す
 * まとまりで判定を求める者の事実を覚えた後に、途中でロールか所属を外した依頼者は、性質が要る操作を今持たないので断る(読む = 閲覧、作る = 登録、
 * 直す = 閲覧と更新。直すは閲覧だけ・更新だけ・どちらも無しに外す)。外さなければ読むは許す。
 */
final class ToolJudgePermissionsTest extends TransactionalTestCase
{
    use ArrangesToolJudgements;

    private const MODULE = 'm_split';

    /**
     * 外し方の名前 → [道具, 外し方]。
     *
     * @return array<string, array{string, string}>
     */
    public static function revocations(): array
    {
        return [
            '読む: ロールを外す' => ['ai_read', 'role'],
            '読む: 所属を外す' => ['ai_read', 'department'],
            '作る: ロールを外す' => ['ai_create', 'role'],
            '直す: 更新を外して閲覧だけ' => ['ai_update', 'update'],
            '直す: 閲覧を外して更新だけ' => ['ai_update', 'view'],
            '直す: どちらも外す' => ['ai_update', 'both'],
        ];
    }

    #[DataProvider('revocations')]
    public function test_operations_revoked_during_the_job_are_denied(string $tool, string $revocation): void
    {
        $revoke = $this->arrangePermissions($tool, $revocation);

        $judgement = $this->judgeAfter($tool, $revoke);

        $expected = [AiToolCallDecision::Deny, AiToolDenialReason::NoPermission];
        $this->assertSame($expected, [$judgement->decision, $judgement->reason]);
        $detail = '業務モジュール '.self::MODULE.': '.ToolJudge::NO_PERMISSION;
        $this->assertSame(['denial_detail' => $detail], $judgement->values);
    }

    public function test_a_read_without_revocation_is_allowed(): void
    {
        $this->arrangePermissions('ai_read', 'role');

        $this->assertSame(AiToolCallDecision::Allow, $this->judgeAfter('ai_read', fn () => null)->decision);
    }

    /** 外のまとまりで事実を覚え、外してから判定する(1 段目では渡せる範囲に入っていた) */
    private function judgeAfter(string $tool, Closure $revoke): ToolJudgement
    {
        return app(AuthorizationReuse::class)->within(function () use ($tool, $revoke): ToolJudgement {
            $this->assertSame([self::MODULE], $this->granted->find($tool)?->scope->codes());
            $this->judgeOutside(self::MODULE);
            $revoke();

            return $this->judgeCall($tool, self::MODULE);
        });
    }

    /**
     * 外し方ごとの許し方で業務モジュールを作って 1 段目を済ませ、外す関数を返す(ロール = 依頼者のロール、所属 = 主所属の組織、
     * 閲覧・更新 = 操作ごとの 2 つ目のロール)。
     *
     * @return Closure(): void
     */
    private function arrangePermissions(string $tool, string $revocation): Closure
    {
        $this->arrangeRequester();
        $this->recordModule(self::MODULE, []);
        $deleted = ['is_deleted' => true];

        if ($revocation === 'role') {
            $action = $tool === 'ai_create' ? RecordModuleAction::Create : RecordModuleAction::Read;
            $this->permit(self::MODULE, [$action]);
            $this->arrangeTools([self::MODULE]);

            return fn () => $this->revokeRole();
        }

        if ($revocation === 'department') {
            $membership = $this->departmentGrant(self::MODULE, RecordModuleAction::Read);
            $this->arrangeTools([self::MODULE]);

            return function () use ($membership, $deleted): void {
                $membership->update($deleted);
            };
        }

        $view = $this->secondRole(self::MODULE, RecordModuleAction::Read);
        $update = $this->secondRole(self::MODULE, RecordModuleAction::Update);
        $this->arrangeTools([self::MODULE]);
        $revoked = match ($revocation) {
            'update' => [$update],
            'view' => [$view],
            default => [$view, $update],
        };

        return function () use ($revoked, $deleted): void {
            foreach ($revoked as $grant) {
                $grant->update($deleted);
            }
        };
    }
}
