<?php

namespace Database\Seeders;

use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\ReorderRecordFields;
use Illuminate\Database\Seeder;

/**
 * 見本の見出し(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 4 節): 「プロジェクト」= 頭(プロジェクト名・担当部署・責任者・状態)、
 * 見出し「期間と予算」(開始日・終了日・予算)、見出し「説明」(概要)。明細の項目(マイルストーン)は見出しに属さないので末尾に置く。
 * 「課題」は見出しなし(見出しの無い業務モジュールの形を残す)。業務処理 AddRecordFieldHeading と ReorderRecordFields を通す。
 * RecordsSeeder が項目を作った後に呼ぶ(項目の並びの正本はそちら)。
 */
class RecordHeadingsSeeder extends Seeder
{
    /** 見出しの前の項目(頭のまとまり)のコード */
    private const HEAD = ['name', 'department', 'manager', 'status'];

    /** 見出しの名前 => その下の項目のコード(並びの順) */
    private const HEADINGS = [
        '期間と予算' => ['start_date', 'end_date', 'budget'],
        '説明' => ['summary'],
    ];

    /** 見出しに属さず、末尾に置く明細の項目のコード */
    private const DETAILS = ['milestones'];

    public function __construct(
        private AddRecordFieldHeading $addHeading,
        private ReorderRecordFields $reorderFields,
    ) {}

    public function run(): void
    {
        $items = $this->fieldItems(self::HEAD);

        foreach (self::HEADINGS as $name => $codes) {
            $items[] = ['kind' => 'heading', 'id' => $this->addHeading->execute('project', ['name' => $name])->id];
            $items = [...$items, ...$this->fieldItems($codes)];
        }

        $this->reorderFields->execute('project', [...$items, ...$this->fieldItems(self::DETAILS)]);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{kind: 'field', code: string}>
     */
    private function fieldItems(array $codes): array
    {
        return array_map(fn (string $code) => ['kind' => 'field', 'code' => $code], $codes);
    }
}
