<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\DraftInput;
use App\Http\Requests\Records\ReadsRecordEnvelope;

/**
 * 申請・一時保存・再申請の入力の封筒(docs の 設計/ワークフロー/業務データとの接続/README.md 2 節・3 節、画面.md 3 節の送る形 { name, comment, values, details }):
 * name・comment が文字列か null、values・details は業務データの入れ物の入口と同じ 1 か所({@see ReadsRecordEnvelope})の規則・文言・変換。
 * 入口が見るのは封筒の形だけで、案件名・コメントの長さと使えない文字・一時保存の大きさは業務処理が 422 にする(案件の操作/同時実行と検査.md 6 節)。
 * 添付とその multipart の送り方は {@see ReadsAttachmentEnvelope}。
 */
trait ReadsApplicationEnvelope
{
    use ReadsAttachmentEnvelope;
    use ReadsRecordEnvelope;

    /**
     * @return array<string, list<string>>
     */
    private function applicationEnvelopeRules(): array
    {
        return [
            'name' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            ...$this->recordEnvelopeRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function applicationEnvelopeMessages(): array
    {
        return [
            'name.string' => '申請名の形が正しくありません',
            'comment.string' => 'コメントの形が正しくありません',
            ...$this->recordEnvelopeMessages(),
        ];
    }

    /**
     * 申請・一時保存の申請基準日の封筒(文字列か null。日付として正しいか・範囲・版は業務処理が 422 base_date にする = ルートの深さ/案件の操作/操作.md 10.1)。
     * 再申請は申請基準日を変えないので受けない。
     *
     * @return array{rules: array<string, list<string>>, messages: array<string, string>}
     */
    private function baseDateEnvelope(): array
    {
        return ['rules' => ['base_date' => ['nullable', 'string']], 'messages' => ['base_date.string' => '申請基準日の形が正しくありません']];
    }

    /**
     * 申請基準日の入力(封筒の規則が nullable・string を確かめた後に呼ぶ)。
     */
    public function baseDate(): ?string
    {
        return $this->nullableString('base_date');
    }

    /**
     * 申請・再申請の入力(レコードの入力は業務データの入れ物の入口と同じ変換。明細の行の id は送られたまま)。
     */
    public function applicationInput(): ApplicationInput
    {
        return new ApplicationInput($this->nullableString('name'), $this->nullableString('comment'), $this->recordInput(), $this->attachFiles(), $this->removeAttachmentIds());
    }

    /**
     * 一時保存の入力(レコードの入力と同じ変換の後、明細の行の id を捨てる = 業務データとの接続/README.md 2 節)。
     */
    public function draftInput(): DraftInput
    {
        $record = $this->recordInput();
        $details = array_map(fn (array $rows) => array_map(fn (RecordDetailRowInput $row) => ['values' => $row->values], $rows), $record->details);

        return new DraftInput($this->nullableString('name'), $this->nullableString('comment'), $record->values, $details, $this->baseDate(), $this->attachFiles(), $this->removeAttachmentIds());
    }

    /**
     * 検証を通った文字列か null の値(封筒の規則が nullable・string を確かめた後に呼ぶ)。
     */
    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
