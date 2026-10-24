<?php

namespace App\Queries\Records\Api;

/**
 * レコードから申請できる入口({@see RecordApplyEntries} が返す値。docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節)。
 */
final readonly class RecordApplyEntry
{
    /**
     * @param  string  $name  フローの名前
     * @param  string|null  $description  フローの説明
     * @param  string  $url  申請の画面の URL
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $url,
    ) {}
}
