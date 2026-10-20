<?php

namespace App\Actions\Workflow;

use App\Queries\Records\Api\RecordConditions;
use Illuminate\Validation\ValidationException;

/**
 * 形つきの版の入力の確かめ({@see RouteVersionWriter} が書く前に呼ぶ)。コードの書式と版の中の重複・名前・処理対象者の表示名・同じ版のノードを指すコード・
 * 辺の名前を項目つきの 422 にし、分岐の条件は業務データの入れ物の公開の口で検証・正規化する(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.4
 * 「条件の項目・演算子・値を業務モジュールの定義に照らす検証は、条件を書く業務処理の入力の検証で行う」)。ルートの形の規則は定義の検査(書いた後)。
 */
class RouteVersionInputRules
{
    use ValidatesWorkflowDefinition;

    private const UNKNOWN_CODE = 'この版のステップのコードを指定してください';

    public function __construct(private RecordConditions $conditions) {}

    /**
     * @param  list<RouteNodeInput>  $nodes
     * @param  list<RouteEdgeInput>  $edges
     * @param  int  $recordModuleId  フローのコンテンツの業務モジュール(分岐の条件を照らす)
     * @return array<int, array{all: list<array<string, mixed>>}|null> 辺の添字 → 正規化した条件(条件の無い辺は null)
     *
     * @throws ValidationException キー = nodes・nodes.<添字>.<項目>・edges.<添字>.<項目>(条件は edges.<添字>.condition(.all.<添字>.…))
     */
    public function check(array $nodes, array $edges, int $recordModuleId): array
    {
        $codes = array_map(fn (RouteNodeInput $node) => $node->code, $nodes);
        $this->throwIfAny([
            'nodes' => $nodes === [] ? 'ステップを 1 つ以上指定してください' : null,
            ...$this->nodeErrors($nodes, $codes),
            ...$this->edgeErrors($edges, $codes),
        ]);

        $normalized = [];

        foreach ($edges as $index => $edge) {
            $normalized[$index] = $edge->condition === null ? null : $this->condition($recordModuleId, $edge->condition, $index);
        }

        return $normalized;
    }

    /**
     * @param  list<RouteNodeInput>  $nodes
     * @param  list<string>  $codes
     * @return array<string, string|null>
     */
    private function nodeErrors(array $nodes, array $codes): array
    {
        $errors = [];

        foreach ($nodes as $index => $node) {
            $earlier = array_slice($codes, 0, $index);
            $errors["nodes.{$index}.code"] = $this->codeError($node->code, fn (string $code) => in_array($code, $earlier, true), 'このコードは版の中で既に使用されています');
            $errors["nodes.{$index}.name"] = $this->textError($node->name, '名前');
            $errors["nodes.{$index}.designator_code"] = $this->unknownCode($node->designatorCode, $codes);
            $errors["nodes.{$index}.closing_code"] = $this->unknownCode($node->closingCode, $codes);
            $errors = [...$errors, ...$this->translationErrors($node->targetTranslations, "nodes.{$index}.target_translations", '処理対象者の表示名')];

            foreach ($node->sendBackCodes as $position => $sendBackCode) {
                $errors["nodes.{$index}.send_back_codes.{$position}"] = $this->unknownCode($sendBackCode, $codes);
            }
        }

        return $errors;
    }

    /**
     * @param  list<RouteEdgeInput>  $edges
     * @param  list<string>  $codes
     * @return array<string, string|null>
     */
    private function edgeErrors(array $edges, array $codes): array
    {
        $errors = [];

        foreach ($edges as $index => $edge) {
            $errors["edges.{$index}.from_code"] = $this->unknownCode($edge->fromCode, $codes);
            $errors["edges.{$index}.to_code"] = $this->unknownCode($edge->toCode, $codes);
            $errors["edges.{$index}.label"] = $edge->label === null ? null : $this->textError($edge->label, '枝の名前');
        }

        return $errors;
    }

    /**
     * @param  list<string>  $codes
     */
    private function unknownCode(?string $code, array $codes): ?string
    {
        return $code === null || in_array($code, $codes, true) ? null : self::UNKNOWN_CODE;
    }

    /**
     * 業務データの入れ物の口で条件を検証・正規化する(誤りのキー condition… を edges.<添字>.condition… に付け替える)。
     *
     * @param  array<array-key, mixed>  $condition
     * @return array{all: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function condition(int $recordModuleId, array $condition, int $index): array
    {
        try {
            return $this->conditions->validate($recordModuleId, $condition);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors["edges.{$index}.{$key}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
