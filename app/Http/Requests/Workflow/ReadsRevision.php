<?php

namespace App\Http\Requests\Workflow;

/**
 * 既にある案件の書き込みが送る改訂番号(画面を開いた時の値。docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 4 節)。入口は整数かだけを見て、
 * 今の値との違いは業務処理が 422 case にする。
 */
trait ReadsRevision
{
    /**
     * @return array<string, list<string>>
     */
    private function revisionRules(): array
    {
        return ['revision' => ['required', 'integer']];
    }

    /**
     * @return array<string, string>
     */
    private function revisionMessages(): array
    {
        return [
            'revision.required' => '改訂番号を指定してください',
            'revision.integer' => '改訂番号の指定が正しくありません',
        ];
    }

    public function revision(): int
    {
        return $this->integer('revision');
    }

    /**
     * ルートの案件の id(引数の形 [0-9]{1,18} はルートが限る)。
     */
    public function caseId(): int
    {
        return (int) $this->route('case');
    }
}
