<?php

namespace Tests\Feature\Seeders;

use App\Enums\Meeting\TranscriptSource;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Carbon\CarbonImmutable;
use Database\Seeders\MeetingSampleSeeder;
use Database\Seeders\SeedingDates;
use Tests\Feature\Meeting\UsesMeetings;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 見本の会議(docs の 設計/業務ツール/会議/公開デモと画面.md の 8.4): プロジェクト「社内ポータル刷新」の架空の会議 2 件が、テキストのファイルの
 * 取り込みの形(書き起こし・発言の行・議事録の 5 欄)で入り、会議と実行の対応を持たない。日付は流す日からの相対。
 */
final class MeetingSampleSeederTest extends TransactionalTestCase
{
    use UsesMeetings;
    use UsesSeededRecords;

    private const MINUTES_AND_AGENDA = ['summary', 'topics', 'decisions', 'open_issues', 'next_actions', 'agenda'];

    private const MIN_LINES = 30;

    private const MAX_LINES = 60;

    /** 流し直す日の基準の日からの日数 */
    private const SHIFT_DAYS = 30;

    public function test_seeds_two_meetings_of_the_portal_project_as_imported_text_files_without_runs(): void
    {
        $projectId = $this->recordNamed('project', 'name', '社内ポータル刷新')->id;
        $meetings = $this->meetingsByTitle();

        $this->assertSame(['社内ポータル刷新 キックオフ', '社内ポータル刷新 定例'], array_keys($meetings));

        foreach ($meetings as $title => $recordId) {
            $values = $this->valuesOf($recordId);
            $transcript = Transcript::query()->where('record_id', $recordId)->sole();
            $lineCount = $transcript->segments()->count();

            $this->assertSame([$projectId], $this->references($recordId, 'referenced_record_id'), $title);
            $this->assertSame([], array_diff(self::MINUTES_AND_AGENDA, array_keys(array_filter($values))), $title);
            $this->assertSame([TranscriptSource::TextFile, null], [$transcript->source, $transcript->ai_run_id]);
            $this->assertTrue($lineCount >= self::MIN_LINES && $lineCount <= self::MAX_LINES, "{$lineCount} 行");
            $this->assertFalse(MeetingRun::query()->where('record_id', $recordId)->exists(), $title);
        }
    }

    /**
     * 閲覧者は利用者の切替の候補の人(設定の初期値 = docs の 設計/デモユーザー切替/README.md の 3 節)、閲覧組織は開発部。
     * どの項目も参照は 1 つ(初期データを入れた DB でマイグレーションを戻す検査が、1 項目 1 参照の一意の索引を作り直すため)。
     */
    public function test_the_viewers_are_users_offered_by_the_user_switch_and_the_department_is_development(): void
    {
        $viewers = [];

        foreach ($this->meetingsByTitle() as $title => $recordId) {
            $viewers[$title] = [
                User::query()->whereKey($this->references($recordId, 'user_id'))->orderBy('code')->pluck('code')->all(),
                Department::query()->whereKey($this->references($recordId, 'department_id'))->pluck('code')->all(),
            ];
        }

        $this->assertSame([
            '社内ポータル刷新 キックオフ' => [['u0009'], ['development']],
            '社内ポータル刷新 定例' => [['u0001'], ['development']],
        ], $viewers);
    }

    public function test_the_dates_are_relative_to_the_seeding_day(): void
    {
        $onBase = $this->dates();
        $laterDay = CarbonImmutable::parse(SeedingDates::BASE)->addDays(self::SHIFT_DAYS);
        config(['seeding.date' => $laterDay->toDateString()]);

        $this->seed(MeetingSampleSeeder::class);

        $purgeDueOn = $onBase[0][1];
        $this->assertSame([['2026-07-03 01:00:00', $purgeDueOn], ['2026-10-14 06:00:00', $purgeDueOn]], $onBase);
        $this->assertSame(
            array_map(fn (array $dates) => [
                CarbonImmutable::parse($dates[0])->addDays(self::SHIFT_DAYS)->format('Y-m-d H:i:s'),
                CarbonImmutable::parse($dates[1])->addDays(self::SHIFT_DAYS)->toDateString(),
            ], $onBase),
            array_slice($this->dates(), 2),
        );
    }

    /**
     * 会議の件名 → id。
     *
     * @return array<string, int>
     */
    private function meetingsByTitle(): array
    {
        $meetings = [];
        $ids = $this->meetingIds();
        // 件名が重なると対応表で数が減るので、先に会議の数を確かめる(見本は 2 件)
        $this->assertCount(2, $ids);

        foreach ($ids as $id) {
            $meetings[(string) ($this->valuesOf($id)['title'] ?? '')] = $id;
        }

        return $meetings;
    }

    /**
     * 会議の参照の値の相手(列 = referenced_record_id・user_id・department_id のどれか)。
     *
     * @return list<int>
     */
    private function references(int $recordId, string $column): array
    {
        return array_values(RecordReference::query()->where('record_id', $recordId)->whereNotNull($column)
            ->orderBy('id')->pluck($column)->map(fn (mixed $id) => (int) $id)->all());
    }

    /**
     * 会議の id(昇順)。
     *
     * @return list<int>
     */
    private function meetingIds(): array
    {
        return array_values(Record::query()->where('record_module_id', $this->meetingModule()->id)->orderBy('id')
            ->pluck('id')->map(fn (mixed $id) => (int) $id)->all());
    }

    /**
     * 会議ごとの [日時(UTC), 削除予定日](id の昇順)。
     *
     * @return list<array{string, string}>
     */
    private function dates(): array
    {
        return array_map(function (int $recordId) {
            $values = $this->valuesOf($recordId);

            return [
                CarbonImmutable::parse((string) $values['held_at'])->utc()->format('Y-m-d H:i:s'),
                (string) $values['purge_due_on'],
            ];
        }, $this->meetingIds());
    }
}
