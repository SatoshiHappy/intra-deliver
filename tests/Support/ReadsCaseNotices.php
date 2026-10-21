<?php

namespace Tests\Support;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Workflow\ConfirmCase;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Queries\Workflow\WorkflowNoticeTargets;

/**
 * 案件の操作の通知と確認を読む手助け(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.4 節)。
 * 初期データは業務処理を通すので通知の行を持つ。テストの中でできた行は、操作の前の最後の行の id({@see self::lastNoticeId()})より後で読む。
 *
 * @phpstan-type NoticeLine array{string, string, string|null, string|null, array<string, mixed>}
 */
trait ReadsCaseNotices
{
    use RunsWorkflowOperations;

    protected function lastNoticeId(): int
    {
        return (int) Notice::query()->max('id');
    }

    /**
     * $lastId より後の通知の行([種類, 宛先のコード, 起こした人のコード, 対象(種類:id), params(キーの順)])。種類・宛先・ステップ名の順に並べる
     * (同じ回の宛先の書く順に依らない)。
     *
     * @return list<NoticeLine>
     */
    protected function noticesSince(int $lastId): array
    {
        $codes = User::query()->pluck('code', 'id')->all();
        $lines = Notice::query()->where('id', '>', $lastId)->get()->map(fn (Notice $notice) => [
            $notice->type,
            (string) $codes[$notice->user_id],
            $notice->actor_user_id === null ? null : (string) $codes[$notice->actor_user_id],
            $notice->target_type === null ? null : "{$notice->target_type}:{$notice->target_id}",
            self::sortedParams($notice->params),
        ])->all();

        return self::sortedLines(array_values($lines));
    }

    /**
     * 期待の通知の行(params に案件の申請番号と申請名を足す)。
     *
     * @param  array<string, mixed>  $params
     * @return NoticeLine
     */
    protected function caseNotice(
        WorkflowNoticeType $type,
        string $to,
        string $actor,
        WorkflowCase $case,
        array $params = [],
    ): array {
        $case = WorkflowCase::query()->findOrFail($case->id);
        $base = ['case_number' => $case->case_number, 'case_name' => $case->name];
        $target = WorkflowNoticeTargets::CASE_TARGET_TYPE.":{$case->id}";

        return [$type->value, $to, $actor, $target, self::sortedParams([...$base, ...$params])];
    }

    /**
     * @param  list<NoticeLine>  $lines
     * @return list<NoticeLine>
     */
    protected static function sortedLines(array $lines): array
    {
        $key = fn (array $line) => [$line[0], $line[1], $line[4]['step_name'] ?? ''];
        usort($lines, fn (array $a, array $b) => $key($a) <=> $key($b));

        return $lines;
    }

    /**
     * 確認する人の対象者(用途 selection。ユーザーで指定)。
     */
    protected function confirmGroup(string ...$userCodes): int
    {
        $conditions = array_map(fn (string $code) => $this->userCondition($code), array_values($userCodes));

        return app(CreateSelectionSubjectGroup::class)->execute($conditions, ['ja' => '確認する人']);
    }

    /**
     * @return list<int> 確認したノードの id
     */
    protected function confirmAs(WorkflowCase $case, string $userCode, ?string $date = null): array
    {
        return app(ConfirmCase::class)->execute($this->contextOf($this->seededUser($userCode), date: $date), $case->id);
    }

    /**
     * @return list<string> ノードの確認対象者のコード(昇順)
     */
    protected function confirmTargetCodes(int $nodeId): array
    {
        $userIds = WorkflowCaseConfirmTarget::query()->where('workflow_case_node_id', $nodeId)->pluck('user_id')->all();
        $codes = array_values(User::query()->whereIn('id', $userIds)->orderBy('code')->pluck('code')->all());

        return array_map('strval', $codes);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function sortedParams(array $params): array
    {
        ksort($params);

        return $params;
    }
}
