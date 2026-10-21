<?php

namespace Database\Seeders;

use App\Actions\Records\PostRecordComment;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Support\AccessContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * 見本のコメント(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 4 節): 「プロジェクト」の最初の見本のレコード
 * (RecordsSeeder が最初に作る「社内ポータル刷新」)に 3 件(宛先つき 2 件)。業務処理 PostRecordComment を通すので、通知も同じ業務処理で入る
 * (宛先 − 書いた人)。書き手・宛先は見本のユーザーのうち「プロジェクト」を閲覧できる人(初期ポリシーで認証済みユーザの全員)。
 * 操作ごとに時計と判定の入力の基準日をその日に合わせ(Carbon の setTestNow)、日時は流す日からの相対の過去にする。終わりに時計を元に戻す。
 */
class RecordCommentsSeeder extends Seeder
{
    /** 見本のコメント: [基準の日の日時(ISO 8601。流す日からの相対にする), 書く人のコード, 本文, 宛先のコード] */
    private const COMMENTS = [
        ['2026-10-12T10:00:00+09:00', 'u0011', '要件定義の成果物を確認しました。設計の着手は予定どおり進めてください。', []],
        ['2026-10-13T15:30:00+09:00', 'u0004', "検索の応答が遅い件を課題に登録しました。\n次の定例までに対応方針を決めてください。", ['u0012', 'u0011']],
        ['2026-10-14T09:45:00+09:00', 'u0012', '旧ポータルのデータ移行手順を、受け入れの前までに整理します。', ['u0004']],
    ];

    public function __construct(private PostRecordComment $postComment) {}

    public function run(): void
    {
        $moduleId = RecordModule::query()->where('code', 'project')->sole()->id;
        $recordId = Record::query()->where('record_module_id', $moduleId)->orderBy('id')->firstOrFail()->id;
        $carbonNow = Carbon::getTestNow();
        $immutableNow = CarbonImmutable::getTestNow();

        try {
            foreach (self::COMMENTS as [$at, $writer, $body, $mentions]) {
                $now = CarbonImmutable::parse(SeedingDates::dateTime($at))->setTimezone(AccessContext::TIMEZONE);
                Carbon::setTestNow($now);
                CarbonImmutable::setTestNow($now);
                $context = new AccessContext(
                    $this->userId($writer),
                    null,
                    null,
                    $now->startOfDay(),
                    AccessContext::LOCALE_CODE,
                );
                $mentionIds = array_map($this->userId(...), $mentions);
                $this->postComment->execute($context, 'project', $recordId, $body, $mentionIds);
            }
        } finally {
            Carbon::setTestNow($carbonNow);
            CarbonImmutable::setTestNow($immutableNow);
        }
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
