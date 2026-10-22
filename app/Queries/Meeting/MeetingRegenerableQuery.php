<?php

namespace App\Queries\Meeting;

use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * 再作成の押せる会議か、作り直す材料(書き起こしか、受ける音声)があるか(docs の 設計/業務ツール/会議/処理.md の 3.7)を決める 1 か所。
 * 画面の操作の出し方と再作成の入口が同じ判定を使う(定義が 2 つに分かれて食い違わないように)。
 * 主体の判定をしない(呼び手が会議の閲覧を判定した後に呼ぶ)。
 */
class MeetingRegenerableQuery
{
    /** 書き起こしも受ける音声も無く、作り直す材料が無いときの文(再作成の入口の 422 と、画面の再作成の押せない理由で同じ) */
    public const NO_MATERIAL = '書き起こしと音声が無いため、再作成できません';

    public function __construct(private MeetingAudioQuery $audio) {}

    /**
     * 押せる会議(書き起こしか、会議と実行の対応がある会議)か、書き起こしの id(無ければ null)、書き起こしが無い押せる会議の受ける音声の
     * 実体の id(書き起こしがあれば音声は探さず null。書き起こしがあれば書き起こしから作るため)。
     *
     * @return array{regenerable: bool, transcriptId: int|null, audioUploadId: int|null}
     *
     * @throws ModelNotFoundException 書き起こしが無い押せる会議で、会議が無いとき
     */
    public function of(int $recordId): array
    {
        $found = DB::query()
            ->selectSub(Transcript::query()->select('id')->where('record_id', $recordId)->limit(1), 'transcript_id')
            ->selectSub(MeetingRun::query()->select('id')->where('record_id', $recordId)->limit(1), 'run_id')
            ->first();
        $transcriptId = self::idOf($found?->transcript_id);

        $regenerable = $transcriptId !== null || self::idOf($found?->run_id) !== null;

        return [
            'regenerable' => $regenerable,
            'transcriptId' => $transcriptId,
            'audioUploadId' => $regenerable && $transcriptId === null ? $this->audio->latestAudioOf($recordId) : null,
        ];
    }

    private static function idOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
