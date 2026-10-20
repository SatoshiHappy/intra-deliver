<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use Illuminate\Validation\ValidationException;

/**
 * 一本道のルート(先頭が申請、ほかは承認。処理対象者は対象者で指定)の入力を、形つきの版の入力に直して {@see RouteVersionWriter} で書く
 * (呼び手は {@see CreateWorkflowFlow})。辺は位置の順の一本道、差戻しできる先は承認のノードごとに前の全部のノード(docs の
 * 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.5、初期データ.md 3.1 の一本道 A)。ノードの入力の確かめは 設計/ワークフロー/データモデル/表.md 2.3 の
 * 「業務処理で止める規則」のまま。呼び手のトランザクションの中で書く。
 */
class LinearRouteWriter
{
    use ValidatesWorkflowDefinition;

    public function __construct(private RouteVersionWriter $routes) {}

    /**
     * ノードの入力の誤り(キー = nodes・nodes.<添字>.<項目>。誤りの無いキーは null)。
     *
     * @param  list<WorkflowRouteNodeInput>  $nodes
     * @return array<string, string|null>
     */
    public function errors(array $nodes): array
    {
        $codes = array_map(fn (WorkflowRouteNodeInput $node) => $this->normalized($node->code), $nodes);
        $names = array_map(fn (WorkflowRouteNodeInput $node) => $this->normalized($node->name), $nodes);

        return ['nodes' => $this->sequenceError($nodes), ...$this->nodeErrors($nodes, $codes, $names)];
    }

    /**
     * 入力のノードを、一本道の辺と差戻しできる先(承認のノードごとに前の全部)つきの形つきの版の入力にして書く({@see RouteVersionWriter}。ノードごとに
     * 処理対象者の専用の対象者を認可の公開の口で作って指す。その口の 422 は下位のキーのまま伝わる)。入力は {@see self::errors()} で確かめた後に渡す。
     *
     * @param  list<WorkflowRouteNodeInput>  $nodes
     *
     * @throws ValidationException 処理対象者の誤り(対象者の口のキーのまま)
     */
    public function write(int $versionId, int $recordModuleId, array $nodes): void
    {
        $inputs = [];
        $edges = [];
        $codes = [];

        foreach ($nodes as $node) {
            $code = (string) $this->normalized($node->code);
            $inputs[] = new RouteNodeInput(
                code: $code,
                name: (string) $this->normalized($node->name),
                kind: $node->kind,
                targetKind: WorkflowTargetKind::Fixed,
                targetConditions: $node->targetConditions,
                targetTranslations: $node->targetTranslations,
                sendBackCodes: $node->kind === WorkflowNodeKind::Approve ? $codes : [],
                deadlineDays: $node->deadlineDays,
                confirmSubjectGroupId: $node->confirmSubjectGroupId,
            );
            $edges = $codes === [] ? $edges : [...$edges, new RouteEdgeInput($codes[count($codes) - 1], $code)];
            $codes[] = $code;
        }

        $this->routes->write($versionId, $recordModuleId, $inputs, $edges);
    }

    /**
     * 並び全体の誤り(空 → 先頭が申請でない → 承認が無い の順で最初の 1 つ)。
     *
     * @param  list<WorkflowRouteNodeInput>  $nodes
     */
    private function sequenceError(array $nodes): ?string
    {
        return match (true) {
            $nodes === [] => 'ステップを 1 つ以上指定してください',
            $nodes[0]->kind !== WorkflowNodeKind::Apply => '先頭のステップは申請にしてください',
            ! in_array(WorkflowNodeKind::Approve, array_map(fn (WorkflowRouteNodeInput $node) => $node->kind, $nodes), true) => '承認のステップを 1 つ以上指定してください',
            default => null,
        };
    }

    /**
     * ノードごとの誤り(コードの書式と同じルートの中の重複 = 後に出たもの、名前、2 番目以降の申請・申請と承認でない種類、処理対象者の表示名)。
     *
     * @param  list<WorkflowRouteNodeInput>  $nodes
     * @param  list<string|null>  $nodeCodes
     * @param  list<string|null>  $nodeNames
     * @return array<string, string|null>
     */
    private function nodeErrors(array $nodes, array $nodeCodes, array $nodeNames): array
    {
        $errors = [];

        foreach ($nodes as $index => $node) {
            $earlierCodes = array_slice($nodeCodes, 0, $index);
            $errors["nodes.{$index}.code"] = $this->codeError($nodeCodes[$index], fn (string $code) => in_array($code, $earlierCodes, true), 'このコードはルートの中で既に使用されています');
            $errors["nodes.{$index}.name"] = $this->textError($nodeNames[$index], '名前');
            $errors["nodes.{$index}.kind"] = $this->kindError($index, $node->kind);
            $errors = [...$errors, ...$this->translationErrors($node->targetTranslations, "nodes.{$index}.target_translations", '処理対象者の表示名')];
        }

        return $errors;
    }

    /**
     * 種類の誤り(一本道のルートは申請と承認だけ。申請は先頭だけ)。
     */
    private function kindError(int $index, WorkflowNodeKind $kind): ?string
    {
        return match (true) {
            $index > 0 && $kind === WorkflowNodeKind::Apply => '申請のステップは先頭だけにしてください',
            ! $kind->isIn(WorkflowNodeKind::linear()) => '申請か承認のステップを指定してください',
            default => null,
        };
    }
}
