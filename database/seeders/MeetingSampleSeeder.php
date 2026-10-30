<?php

namespace Database\Seeders;

use App\Actions\Meeting\MeetingTranscriptWriter;
use App\Actions\Meeting\TranscriptLine;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\TranscriptSource;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RelativeDefault;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 公開デモの見本の会議(docs の 設計/業務ツール/会議/公開デモと画面.md の 8.4): 初期データのプロジェクト「社内ポータル刷新」の架空の会議 2 件
 * ({@see MeetingKickoffSample}・{@see MeetingWeeklySample})を、テキストのファイルの取り込みと同じ形(件名を人が入れた会議のレコードと、
 * 書き起こし・発言の行)で入れる。AI は呼ばず、会議と実行の対応は書かない。日付は流す日からの相対({@see SeedingDates})。
 * 削除予定日は相対の既定値を基準の日に当てた日付を流す日へずらす(既定値は登録の時の時計で決まり、流す日に合わないため)。
 *
 * 関係者 = デモユーザーと組織(8.4)。2 件とも同じ組織と別のユーザーを持つ(一覧の検索で人と組織を選ぶと、両方を持つ会議だけに当たる =
 * 設計/業務ツール/会議/README.md の 1.2 の関係者)。
 *
 * @phpstan-type Sample array{title: string, held_at: string, parties: list<array{'user'|'department', string}>,
 *     file: string, agenda: string, notes: string|null, summary: string, topics: string, decisions: string,
 *     open_issues: string, next_actions: string, lines: list<array{string, string, string}>}
 */
class MeetingSampleSeeder extends Seeder
{
    use FindsCommonMaster;

    /** 見本を作る人(システム管理者) */
    private const AUTHOR = 'u0004';

    /** 見本がつながるプロジェクト(RecordsSeeder の初期データ) */
    private const PROJECT_NAME = '社内ポータル刷新';

    /** 最後の発言の終わり = 開始の秒 + この秒(ほかの発言の終わりは次の発言の開始) */
    private const LAST_LINE_SECONDS = 8;

    public function __construct(private CreateRecord $createRecord, private MeetingTranscriptWriter $transcripts) {}

    /**
     * @throws LogicException 会議の削除予定日の既定値が相対の既定値でないとき
     */
    public function run(): void
    {
        $context = new AccessContext(
            $this->userId(self::AUTHOR),
            null,
            null,
            SeedingDates::seedingDay(),
            AccessContext::LOCALE_CODE,
        );
        $projectId = $this->projectId();
        $purgeDueOn = self::purgeDueOn();

        foreach ([MeetingKickoffSample::MEETING, MeetingWeeklySample::MEETING] as $sample) {
            DB::transaction(function () use ($context, $projectId, $purgeDueOn, $sample) {
                $recordId = $this->createRecord->execute(
                    $context,
                    Meeting::CODE,
                    new RecordInput($this->values($sample, $projectId, $purgeDueOn)),
                );
                $lines = self::lines($sample['lines']);
                $this->transcripts->write($recordId, TranscriptSource::TextFile, $sample['file'], $lines);
            });
        }
    }

    /**
     * 会議の欄の値(人が入れた件名と、書いて置く議事録の 5 欄)。
     *
     * @param  Sample  $sample
     * @return array<string, mixed>
     */
    private function values(array $sample, int $projectId, string $purgeDueOn): array
    {
        return [
            Meeting::Title->value => $sample['title'],
            Meeting::HeldAt->value => SeedingDates::dateTime($sample['held_at']),
            Meeting::RelatedParties->value => array_map($this->party(...), $sample['parties']),
            Meeting::Project->value => $projectId,
            Meeting::PurgeDueOn->value => $purgeDueOn,
            Meeting::Summary->value => $sample['summary'],
            Meeting::Topics->value => $sample['topics'],
            Meeting::Decisions->value => $sample['decisions'],
            Meeting::OpenIssues->value => $sample['open_issues'],
            Meeting::NextActions->value => $sample['next_actions'],
            Meeting::Agenda->value => $sample['agenda'],
            Meeting::Notes->value => $sample['notes'],
        ];
    }

    /**
     * 関係者の相手(種類とコード)を入力の相手 {type, id} にする。
     *
     * @param  array{'user'|'department', string}  $party
     * @return array{type: 'user'|'department', id: int}
     */
    private function party(array $party): array
    {
        [$type, $code] = $party;

        return ['type' => $type, 'id' => $type === 'user' ? $this->userId($code) : $this->departmentId($code)];
    }

    /**
     * 発言の行(時刻 MM:SS・話者・発言)を書き起こしの行にする。終わりの秒は次の発言の開始。
     *
     * @param  list<array{string, string, string}>  $lines
     * @return list<TranscriptLine>
     */
    private static function lines(array $lines): array
    {
        $starts = array_map(fn (array $line) => self::seconds($line[0]), $lines);
        $transcript = [];

        foreach ($lines as $index => [, $speaker, $text]) {
            $end = $starts[$index + 1] ?? $starts[$index] + self::LAST_LINE_SECONDS;
            $transcript[] = new TranscriptLine($text, $speaker, "{$starts[$index]}.000", "{$end}.000");
        }

        return $transcript;
    }

    private static function seconds(string $clock): int
    {
        [$minutes, $seconds] = explode(':', $clock);

        return (int) $minutes * 60 + (int) $seconds;
    }

    /**
     * 削除予定日(会議の削除予定日の相対の既定値を基準の日に当て、流す日へずらした日付)。
     *
     * @throws LogicException 既定値が相対の既定値でないとき
     */
    private static function purgeDueOn(): string
    {
        $default = RecordModule::query()->where('code', Meeting::CODE)->sole()->recordFields()
            ->where('code', Meeting::PurgeDueOn->value)->sole()->default_value;

        if (! $default instanceof RelativeDefault) {
            throw new LogicException('会議の削除予定日の既定値が相対の既定値でない');
        }

        $baseDay = CarbonImmutable::parse(SeedingDates::BASE, AccessContext::TIMEZONE);

        return SeedingDates::day($default->valueAt($baseDay, RecordFieldType::Date));
    }

    /**
     * 初期データのプロジェクトのうち、プロジェクト名が {@see self::PROJECT_NAME} のもの。
     */
    private function projectId(): int
    {
        $module = RecordModule::query()->with('recordFields')->where('code', Meeting::PROJECT_MODULE)
            ->whereNull('parent_record_module_id')->sole();
        $nameFieldId = $module->recordFields->firstOrFail(fn (RecordField $field) => $field->is_title)->id;

        return Record::query()->where('record_module_id', $module->id)->get()
            ->sole(fn (Record $record) => ($record->field_values[$nameFieldId] ?? null) === self::PROJECT_NAME)->id;
    }
}
