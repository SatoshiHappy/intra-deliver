<?php

namespace App\Actions\Workflow;

use Illuminate\Http\UploadedFile;

/**
 * 一時保存の入力(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節)。業務処理は入口と同じ変換(App\Support\InputText = 前後の空白を除き空なら null)を
 * 掛けた後のまま保つ(検証しない)。封筒から作る変換は入口の仕事。
 */
final readonly class DraftInput
{
    /**
     * @param  string|null  $name  案件名(業務処理が前後の空白を除き、空なら null にする。検証しない)
     * @param  string|null  $comment  コメント(同じ)
     * @param  array<array-key, mixed>  $values  項目のコード → 値
     * @param  array<array-key, mixed>  $details  明細の項目のコード → 行の配列(行 = ['values' => 項目のコード → 値]。id を持たない)
     * @param  string|null  $baseDate  申請基準日(検証せず保つ = ルートの深さ/案件の操作/操作.md 10.1)
     * @param  list<UploadedFile>  $attachFiles  この送信で足す添付(設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節の attachments)
     * @param  list<int>  $removeAttachmentIds  外す一時保存の添付の行の id(上書きだけが読む。新規は外す相手が無いので読まない)
     */
    public function __construct(
        public ?string $name,
        public ?string $comment,
        public array $values,
        public array $details,
        public ?string $baseDate = null,
        public array $attachFiles = [],
        public array $removeAttachmentIds = [],
    ) {}
}
