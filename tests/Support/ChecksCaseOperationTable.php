<?php

namespace Tests\Support;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 状態 × 操作の表のテスト(docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 7 節)の仕組み。状況を本物の業務処理で 1 回作り、役割 9 × 操作 11 の組ごとに
 * savepoint の中で操作して巻き戻す。期待は表のデータ(状況ごとの「閲覧できる人」と、操作.md 3.2 の「状態 × 操作の表」の役割と結果)と、操作.md 3.1 の 2 の判定の順
 * (閲覧できない → 404、申請の系統で申請できない → 403、表に無い → 422 case)から決める(実装から作らない)。各組で、操作の前の行・詳細の can の該当の旗と
 * 業務処理の成否を突き合わせる(旗の無い組 = 案件が無い・閲覧できない は外す。一時保存は行の can だけ、一時保存でない案件は行と詳細の両方)。
 *
 * 人は記号で書く: A = 申請者(u0001)、P = 申請できる人(u0004)、N = 申請できない人(u0005)、B = 承認 1(u0002)、C = 承認 2(u0003)、R = 参照者(経理部の u0010)、
 * U = 関わらない人(u0006)、G = ゲスト。P はシステム管理者なので、一時保存でない案件は管理の述語で閲覧できる(visible に入れる)。フロー = 申請(A・P)→ 承認 1(B)→ 承認 2(C)、参照者 = 経理部。記号 → 人は {@see self::tablePeople()}(ルートの見本 R1〜R3 の表は
 * {@see ChecksRouteOperationTable} が替える)。
 *
 * 操作の入力(inputs)を渡すと、ノードを取る操作(承認・承認終了・否認・差戻し・保留・保留解除・振替)はどの役割でも同じ注目のノード、差戻し先・引戻すノード・指定・
 * 振替先(既定 = 関わらない人 U)も全員に同じ値を使い、連絡する人(contact)を渡せば連絡を付けられる操作に付けて成功の組で連絡の通知が 1 つできることを確かめ
 * (設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節の「連絡」の行。失敗の組は通知を含めて何も変わらない)、
 * 詳細の can は注目のノードの nodeActions で比べる(設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節)。渡さないときは案件の操作の形(その人の現在のノード・申請のノード・
 * その人の引戻せるノード)。
 * 成功の結果を {@see self::ONLY_INVARIANTS} にした組は、8 節の結果を確かめず 2.7 の不変の条件({@see RunsRoutePlans::assertRouteInvariants()})
 * だけを確かめる(R1〜R3 = 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節。
 * 結果が書かれていない組・キーの書き違えは、不変の条件だけに落とさず失敗にする)。
 * principals(役割 → 代理元の記号)の役割は、その代理元として操作する(画面の can は処理者 = 自分だけなので比べない)。成功の役割は複数を並べられる
 * (結果が同じ組 = 保留者側)。rejections(役割 → 操作 → 期待)は 422 case 以外の拒否(422:principal_user_id など)。
 * 案件の管理者({@see self::tableManagers()}。案件のフローの operate を持つ人)の成功は manager(操作 → 結果)に書く。案件操作
 * ({@see self::MANAGER_OPERATIONS})は、閲覧できて管理者でない人には 403(設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節)。
 * 結果の row は最新の履歴の行の [種別, 処理者, 実行者(null = 処理者本人), 履歴の人を並べた記号]。
 *
 * @phpstan-type Result array{nodes: list<string>, state: string, histories: int, tabs: array{draft: string, pending: string, processed: string, referenced: string}, row?: array{string, string, string|null, string}}
 * @phpstan-type Inputs array{
 *     node: int, sendBackTo: int, pullBack: int, assignments: array<int, list<int>>, transferTo?: string,
 *     contact?: string, retargetTo?: string, reassign?: int, reassignTo?: string,
 * }
 * @phpstan-type Spec array{
 *     roles: array<string, string|null>, visible: string,
 *     success: array<string, array{string|list<string>, Result|self::ONLY_INVARIANTS}>,
 *     inputs?: Inputs, principals?: array<string, string>, rejections?: array<string, array<string, string>>,
 *     manager?: array<string, Result|self::ONLY_INVARIANTS>,
 * }
 */
trait ChecksCaseOperationTable
{
    use CatchesValidationErrors;
    use ChecksCaseOperationCans;
    use DescribesTableCases;
    use PerformsTableOperations;
    use RunsRoutePlans;
    use RunsWorkflowOperations;

    /** 成功の結果の印: 8 節の結果を確かめず、不変の条件だけを確かめる組(R1〜R3) */
    protected const ONLY_INVARIANTS = 'only_invariants';

    /** 案件の削除の結果(案件の行が無く、どの一覧にも出ない) */
    protected const DELETED = [
        'nodes' => [], 'state' => 'deleted', 'histories' => 0,
        'tabs' => ['draft' => '', 'pending' => '', 'processed' => '', 'referenced' => ''],
    ];

    /** 人の記号 → 初期データのユーザーのコード(G = ゲスト) */
    private const PEOPLE = ['A' => 'u0001', 'P' => 'u0004', 'N' => 'u0005', 'B' => 'u0002', 'C' => 'u0003', 'R' => 'u0010', 'U' => 'u0006', 'G' => null];

    /** どの状況にもいる役割(状況が null で「該当なし」にできる) */
    private const FIXED_ROLES = ['canApplyPerson' => 'P', 'cannotApplyUser' => 'N', 'applicant' => 'A', 'referrer' => 'R', 'unrelated' => 'U', 'guest' => 'G'];

    /** 申請できる人(申請のノードの指定に合い create を持つ) */
    private const CAN_APPLY = ['A', 'P'];

    /** 4 タブの述語 */
    private const TABS = ['draft', 'pending', 'processed', 'referenced'];

    /** 案件操作(案件の管理者だけの操作。operate を許されない閲覧できる人は 403) */
    private const MANAGER_OPERATIONS = [
        'retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish', 'reassign', 'delete_case',
    ];

    protected function createTableFlow(): void
    {
        $this->createFlow(applicants: [$this->userCondition('u0001'), $this->userCondition('u0004')]);
    }

    /** @var array<string, User|null> 人の記号 → ユーザー(1 回引いて覚える) */
    private array $people = [];

    /**
     * 案件の管理者の記号(初期データのシステム管理者 u0004 = テストのフローの operate を許される)。
     */
    protected function tableManagers(): string
    {
        return 'P';
    }

    /**
     * 人の記号 → 初期データのユーザーのコード(null = ゲスト)。
     *
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return self::PEOPLE;
    }

    protected function person(string $letter): ?User
    {
        if (! array_key_exists($letter, $this->people)) {
            $code = $this->tablePeople()[$letter];
            $this->people[$letter] = $code === null ? null : $this->seededUser($code);
        }

        return $this->people[$letter];
    }

    /**
     * 状況の全組を確かめ、確かめた組の数を返す(該当なしの組は数えない)。各組で、操作の前の画面の can の該当の旗が業務処理の成否と一致することも見る
     * (設計/ワークフロー/認可と一覧.md 3.2 = 押すと 403・422 になるボタンを出さず、押せば通るボタンを消さない)。
     *
     * @param  Spec  $spec
     */
    protected function assertOperationTable(?WorkflowCase $case, array $spec): int
    {
        $roles = [...self::FIXED_ROLES, ...$spec['roles']];
        $cans = $this->cansBefore($case, $spec['visible'], $spec['inputs']['node'] ?? null);
        $checked = 0;

        foreach (WorkflowOperation::cases() as $operation) {
            foreach ($roles as $role => $letter) {
                $expected = $letter === null ? null : $this->expectedOutcome($case, $spec, $operation, $role, $letter);

                if ($expected !== null) {
                    $this->attempt($case, $spec, $operation, $role, $letter, $expected);
                    $this->assertOwnCansMatch($cans[$letter] ?? [], $spec, $operation, $role, $letter, $expected);
                    $checked++;
                }
            }
        }

        return $checked;
    }

    /**
     * 自分として操作する役割の can の突き合わせ(代理元として操作する役割の can は処理担当者の候補ごとの札で比べる。画面の can は処理者 = 自分だけ)。
     *
     * @param  array<string, mixed>  $can
     * @param  Spec  $spec
     */
    private function assertOwnCansMatch(
        array $can,
        array $spec,
        WorkflowOperation $operation,
        string $role,
        string $letter,
        string $expected,
    ): void {
        if (! isset($spec['principals'][$role])) {
            $this->assertCansMatch($can, $operation, "{$role}({$letter})", $expected === 'success');
        }
    }

    /**
     * 期待(success・404・403・422)。null = 該当なし(案件の無い状況の既にある案件の操作、案件のある状況の一時保存(新規))。
     *
     * @param  Spec  $spec
     */
    private function expectedOutcome(?WorkflowCase $case, array $spec, WorkflowOperation $operation, string $role, string $letter): ?string
    {
        $isManager = str_contains($this->tableManagers(), $letter);
        $succeeds = $this->succeedsByRole($spec, $operation, $role) || ($isManager && isset($spec['manager'][$operation->value]));

        if ($case === null) {
            return in_array($operation, [WorkflowOperation::SaveDraft, WorkflowOperation::Apply], true) ? ($succeeds ? 'success' : '403') : null;
        }

        return match (true) {
            $operation === WorkflowOperation::SaveDraft => null,
            ! str_contains($spec['visible'], $letter) => '404',
            $this->isForbidden($operation, $letter, $isManager) => '403',
            $succeeds => 'success',
            default => $spec['rejections'][$role][$operation->value] ?? '422',
        };
    }

    /**
     * 閲覧できる人の 403: 申請の系統で申請できない人と、案件操作で案件の管理者でない人。
     */
    private function isForbidden(WorkflowOperation $operation, string $letter, bool $isManager): bool
    {
        $isApplyKind = in_array($operation, [WorkflowOperation::OverwriteDraft, WorkflowOperation::Apply], true);

        return ($isApplyKind && ! in_array($letter, self::CAN_APPLY, true))
            || (in_array($operation->value, self::MANAGER_OPERATIONS, true) && ! $isManager);
    }

    /**
     * 1 組を savepoint の中で操作し、結果を確かめて巻き戻す。
     *
     * @param  Spec  $spec
     */
    private function attempt(?WorkflowCase $case, array $spec, WorkflowOperation $operation, string $role, string $letter, string $expected): void
    {
        $label = "{$operation->value} by {$role}({$letter})";
        DB::beginTransaction();

        try {
            $before = $this->tableSnapshot($case);
            $contacts = $this->contactNoticeCount($spec['inputs'] ?? null, $case);
            $principalLetter = $spec['principals'][$role] ?? null;
            $principal = $principalLetter === null ? null : $this->person($principalLetter)?->id;
            $inputs = $spec['inputs'] ?? null;
            $perform = fn () => $this->perform($operation, $this->person($letter), $case, $inputs, $principal);
            [$outcome, $newId] = $this->outcomeOf($perform);
            $this->assertSame($expected, $outcome, $label);
            $result = $this->succeedsByRole($spec, $operation, $role)
                ? $spec['success'][$operation->value][1]
                : $spec['manager'][$operation->value] ?? null;

            if ($expected === 'success') {
                $this->assertContactSent($operation, $spec['inputs'] ?? null, $case, $contacts, $label);
            }

            if ($expected === 'success' && $result === self::ONLY_INVARIANTS) {
                $this->assertRouteInvariants(WorkflowCase::query()->findOrFail($newId ?? $case?->id));
            } elseif ($expected === 'success') {
                $this->assertIsArray($result, "{$label} の成功の結果が無い");
                $this->assertTableResult($newId ?? $case?->id, $before, $result, $label);
            } else {
                $this->assertSame($before, $this->tableSnapshot($case), "{$label} で何か変わった");
            }
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Spec  $spec
     */
    private function succeedsByRole(array $spec, WorkflowOperation $operation, string $role): bool
    {
        return in_array($role, (array) ($spec['success'][$operation->value][0] ?? []), true);
    }

    /**
     * @param  Closure(): ?int  $action
     * @return array{string, int|null}
     */
    private function outcomeOf(Closure $action): array
    {
        try {
            return ['success', $action()];
        } catch (ModelNotFoundException) {
            return ['404', null];
        } catch (AuthorizationException) {
            return ['403', null];
        } catch (ValidationException $exception) {
            $keys = array_keys($exception->errors());

            return [$keys === ['case'] ? '422' : '422:'.implode(',', $keys), null];
        }
    }

    /**
     * 行の数・案件の行・ノードの形(失敗で何も変わらないことの確かめ)。
     *
     * @return array{array<string, int>, array<string, mixed>|null, list<string>}
     */
    private function tableSnapshot(?WorkflowCase $case): array
    {
        $row = $case === null ? null : WorkflowCase::query()->find($case->id);

        return [$this->workflowCounts(), $row?->getAttributes(), $row === null ? [] : $this->compactNodes($row)];
    }

    /**
     * 成功の結果: 案件の列(状態・完了の理由・完了日時・改訂番号 = 既にある行 +1・新しい行 0)・ノード・履歴の行数・4 タブ・不変の条件。
     *
     * @param  array{array<string, int>, array<string, mixed>|null, list<string>}  $before
     * @param  Result  $result
     */
    private function assertTableResult(?int $caseId, array $before, array $result, string $label): void
    {
        $caseId ??= 0;
        $this->assertTabs($caseId, $result['tabs'], $label);

        if ($result['state'] === 'deleted') {
            $this->assertFalse(WorkflowCase::query()->whereKey($caseId)->exists(), $label);

            return;
        }

        $case = WorkflowCase::query()->findOrFail($caseId);
        [$state, $reason] = [...explode(':', $result['state']), null];
        $this->assertSame([$state, $reason, $reason !== null], [$case->state->value, $case->completion_reason?->value, $case->completed_at !== null], $label);
        $this->assertSame($before[1] === null ? 0 : $before[1]['revision'] + 1, $case->revision, "{$label} の改訂番号");
        $this->assertSame($result['nodes'], $this->compactNodes($case), "{$label} のノード");
        $this->assertSame($result['histories'], $this->workflowCounts()['histories'] - $before[0]['histories'], "{$label} の履歴の行数");
        $this->assertNodeInvariant($case);

        if (isset($result['row'])) {
            $this->assertSame($result['row'], $this->latestRowLetters($case), "{$label} の履歴の行");
        }
    }

    /**
     * @param  array{draft: string, pending: string, processed: string, referenced: string}  $expected  タブ → そこに出る人の記号
     */
    private function assertTabs(int $caseId, array $expected, string $label): void
    {
        $actual = array_fill_keys(self::TABS, '');

        foreach (array_keys($this->tablePeople()) as $letter) {
            foreach (self::TABS as $tab) {
                $actual[$tab] .= app(CaseVisibility::class)->{$tab}(WorkflowCase::query()->whereKey($caseId), new Requester($this->person($letter)?->id, null), $this->operationDay())->exists() ? $letter : '';
            }
        }

        $this->assertSame(array_map(fn (string $letters) => $this->sortedLetters($letters), $expected), array_map(fn (string $letters) => $this->sortedLetters($letters), $actual), "{$label} の一覧への出方");
    }
}
