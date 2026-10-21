<?php

namespace Tests\Support;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;

/**
 * ルートの見本 R1〜R3 の状態 × 操作の表のテスト(docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節)の仕組み。
 * 判定の順・savepoint・can の突き合わせは {@see ChecksCaseOperationTable} を使い回し、状況は本物の業務処理({@see OperatesOnRoutes})で作る。
 * 期待 = 可否(成功 / 404 / 403 / 422(キー))だけを全組で、成功の組は 2.7 の不変の条件だけ(8 節の結果は場面のテスト)。期待は 操作.md 7 節・8 節・10.4・10.5 と
 * 同時実行と検査.md 13 節から手で書く(実装から作らない)。
 *
 * 人の記号: A = 申請者(u0001)、P = 申請できる人(u0012)、N = 申請できない人(u0013)、U = 関わらない人(u0008)、R = 参照者(経理部の u0010。R2・R3 の final も
 * u0010)、G = ゲスト、B = u0002(R1 の課長・R2 の a1・R3 の chief)、C = u0003(R1 の部長・R2 の a2)、D = u0004(R2 の b1)、O = u0007(R1 の責任者の指定)、
 * X・Y・Z = u0005・u0009・u0011(R1 の合議・R3 の横配置の指定の順)。申請のノードだけを A・P に替える(見本の形は {@see BuildsRouteVersions} のまま)。
 *
 * 操作の入力(状況ごとに 1 つ。全員に同じ): ノード = 注目のノード、差戻し先 = 10.4 の候補の先頭(position の順)、引戻すノード = 引戻せるノード X(10.5。無ければ
 * 注目のノード)、指定 = 注目のノードが指定するノードなら候補の最小人数の有効な人、振替先 = U、申請基準日 = 運用日、コメント = なし。
 */
trait ChecksRouteOperationTable
{
    use ChecksCaseOperationTable;
    use OperatesOnRoutes;

    /** 案件の操作の表の記号({@see ChecksCaseOperationTable})から替える・足す人(A・B・C・R・G は同じ) */
    private const ROUTE_PEOPLE = [
        'P' => 'u0012', 'N' => 'u0013', 'U' => 'u0008', 'D' => 'u0004', 'O' => 'u0007', 'X' => 'u0005', 'Y' => 'u0009', 'Z' => 'u0011',
    ];

    /** 既にある案件の操作の数(一時保存(新規)を除く 14) */
    protected const OPERATIONS = 14;

    /**
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return [...self::PEOPLE, ...self::ROUTE_PEOPLE];
    }

    /**
     * テストのフローに見本の版を足す(申請のノード = A・P)。
     *
     * @param  array{list<RouteNodeInput>, list<RouteEdgeInput>}  $route
     */
    protected function addTableVersion(array $route): void
    {
        $applicants = [$this->userCondition($this->codeOf('A')), $this->userCondition($this->codeOf('P'))];
        $this->createFlow(applicants: $applicants);
        [$nodes, $edges] = $route;
        $nodes[0] = new RouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed, $applicants, ['ja' => '申請']);
        $this->addVersion([$nodes, $edges]);
    }

    /**
     * 状況の操作の入力(名札で渡す)。
     *
     * @param  array<string, string>  $assignments  指定されるノードの名札 → 人の記号を並べた文字列
     * @return array{node: int, sendBackTo: int, pullBack: int, assignments: array<int, list<int>>}
     */
    protected function tableInputs(WorkflowCase $case, string $focus, string $sendBackTo, string $pullBack, array $assignments = []): array
    {
        $ids = $this->labelIds($case);
        $byNode = [];

        foreach ($assignments as $label => $letters) {
            $byNode[$ids[$label]] = array_map(fn (string $letter) => $this->person($letter)->id ?? 0, str_split($letters));
        }

        return ['node' => $ids[$focus], 'sendBackTo' => $ids[$sendBackTo], 'pullBack' => $ids[$pullBack], 'assignments' => $byNode];
    }

    /**
     * 成功する役割(操作 → 役割)。成功の組は不変の条件だけを確かめる(結果 = {@see self::ONLY_INVARIANTS})。
     *
     * @param  array<string, string>  $roles
     * @return array<string, array{string, self::ONLY_INVARIANTS}>
     */
    protected function successBy(array $roles): array
    {
        return array_map(fn (string $role) => [$role, self::ONLY_INVARIANTS], $roles);
    }

    /**
     * 注目のノード(承認待ち)の処理対象者の 6 つの操作(承認・承認終了・否認・差戻し・保留・振替)が成功し、ほかは引戻しだけが成功する
     * (差戻し先・振替先は入力のとおり)。
     *
     * @return array<string, array{string, self::ONLY_INVARIANTS}>
     */
    protected function targetAndPullBack(string $target, ?string $pullBacker): array
    {
        $roles = array_fill_keys(['approve', 'approve_early', 'reject', 'send_back', 'hold', 'transfer'], $target);

        return $this->successBy($pullBacker === null ? $roles : [...$roles, 'pull_back' => $pullBacker]);
    }

    /**
     * R1 の課長(B)が、合議 = $council の人と責任者 = O を指定して承認する。
     *
     * @param  string  $council  合議の人の記号
     */
    protected function approveSectionChief(WorkflowCase $case, string $council): void
    {
        $this->approve($case, 'section_chief', $this->codeOf('B'), [
            'council' => array_map(fn (string $letter) => $this->codeOf($letter), str_split($council)),
            'owner' => [$this->codeOf('O')],
        ]);
    }

    /**
     * 人の記号の初期データのコード。
     */
    protected function codeOf(string $letter): string
    {
        return (string) $this->tablePeople()[$letter];
    }
}
