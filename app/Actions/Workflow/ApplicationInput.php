<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\RecordInput;

/**
 * 申請・再申請の入力(docs の 設計/ワークフロー/案件の操作/操作.md 3.5・3.9)。レコードの入力は業務データの入れ物の登録・更新の口へそのまま渡す。
 */
final readonly class ApplicationInput
{
    /**
     * @param  string|null  $name  案件名(前後の空白を除いて空なら既定の案件名)
     * @param  string|null  $comment  コメント(前後の空白を除いて空なら null)
     */
    public function __construct(
        public ?string $name,
        public ?string $comment,
        public RecordInput $record,
    ) {}
}
