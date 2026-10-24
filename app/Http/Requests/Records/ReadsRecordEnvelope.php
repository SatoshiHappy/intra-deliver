<?php

namespace App\Http\Requests\Records;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;

/**
 * レコードの入力の封筒の 1 か所(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.1、設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節):
 * values が連想配列、details が明細の項目のコード → 行の配列、行の id が整数か無し、行の values が連想配列。業務データの入れ物の登録・更新の入口
 * ({@see RecordInputRequest})と、ワークフローの申請・一時保存・再申請の入口が同じ規則・文言・変換を使う(同じ封筒に同じ結果)。
 * 項目ごとの規則(型・必須・選択肢・参照・規則)はここに置かず、業務処理が宣言で評価する(ほかの呼び手にも同じものが効くように)。
 */
trait ReadsRecordEnvelope
{
    /**
     * @return array<string, list<string>>
     */
    private function recordEnvelopeRules(): array
    {
        return [
            'values' => ['array'],
            'details' => ['array'],
            'details.*' => ['bail', 'array', 'list'],
            'details.*.*' => ['array'],
            'details.*.*.id' => ['nullable', 'integer'],
            'details.*.*.values' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function recordEnvelopeMessages(): array
    {
        return [
            'values.array' => '値の形が正しくありません',
            'details.array' => '明細の形が正しくありません',
            'details.*.array' => '明細の行の形が正しくありません',
            'details.*.list' => '明細の行の形が正しくありません',
            'details.*.*.array' => '明細の行の形が正しくありません',
            'details.*.*.id.integer' => '明細の行の指定が正しくありません',
            'details.*.*.values.array' => '明細の行の値の形が正しくありません',
        ];
    }

    /**
     * 検証を通った封筒を、業務処理の入力にする(参照は id の整数のまま、ほかの値も送られた形のまま渡す)。validated() は使わない
     * (規則の無い配列のキーを落とすので、値の無い行 {} が明細から消え、明細のキーの有無の意味 = 項目と規則/README.md 6 節が変わる)。
     */
    public function recordInput(): RecordInput
    {
        /** @var array<array-key, mixed> $values */
        $values = $this->input('values', []);
        /** @var array<array-key, list<array{id?: int|string|null, values?: array<array-key, mixed>}>> $details */
        $details = $this->input('details', []);

        return new RecordInput($values, array_map(fn (array $rows) => array_map(fn (array $row) => new RecordDetailRowInput(
            isset($row['id']) ? (int) $row['id'] : null,
            $row['values'] ?? [],
        ), $rows), $details));
    }
}
