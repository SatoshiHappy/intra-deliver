<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\TranscriptionRequest;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingAiKind;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Meeting\MeetingRun;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingAudioBudget;
use App\Queries\Meeting\MeetingAudioQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 会議の音声から文字起こしを依頼し、会議と実行の対応(段 = 文字起こし)を書く 1 か所(作成と再作成が呼ぶ。docs の 設計/業務ツール/会議/処理.md の 3.2・3.7・3.9)。
 * 依頼の前に、依頼者が会議を閲覧できることと、音声が会議の今の添付であることを確かめる(AI の公開の口の「呼び手が守ること」の (1) =
 * 設計/AI/公開の口.md の 3 節)。続けて出す議事録の区分を鍵を取らずに先に確かめ、受けられなければ 422 で断る。呼び手のトランザクションの中で呼ぶ。
 */
class MeetingAudioRequest
{
    /** 作成で音声を読み取れなかったときに添える文 */
    public const SUPPORTED_FORMATS = '対応する形式は mp3、m4a(AAC・ALAC)、wav(PCM・A-law・μ-law・ADPCM)です。'
        .'別の形式の場合は mp3 に変換してから上げ直してください。';

    public function __construct(
        private RecordViewAccess $access,
        private MeetingAudioQuery $audio,
        private MeetingAudioBudget $budget,
        private RequestTranscription $requestTranscription,
    ) {}

    /**
     * @param  int  $uploadId  文字起こしする音声(会議の今の添付の実体)
     * @param  bool  $writesTitle  AI が件名を書くか(作成で人が件名を入れなかった)
     * @return int 依頼した実行の id
     *
     * @throws ModelNotFoundException 依頼者が会議を閲覧できないとき
     * @throws LogicException 音声が会議の今の添付でないとき(呼び手の誤り)
     * @throws RunRequestDeclined 議事録の区分が受けられない・文字起こしの依頼が断られたとき(キー ai。作成では画面に添える文を後ろに足す)
     * @throws AuthorizationException ゲストのとき
     */
    public function execute(
        AccessContext $context,
        int $recordId,
        int $uploadId,
        MeetingRunOrigin $origin,
        bool $writesTitle,
    ): int {
        $requester = Requester::fromAccessContext($context);

        if (! $this->access->canView($requester, Meeting::CODE, $recordId, $context->date)) {
            throw new ModelNotFoundException("会議 {$recordId} が無いか、閲覧できない");
        }

        if (! $this->audio->isAttached($recordId, $uploadId)) {
            throw new LogicException("音声 {$uploadId} は会議 {$recordId} の今の添付でない");
        }

        $declineMessage = $this->budget->minutesDeclineMessage($origin, CarbonImmutable::now());

        if ($declineMessage !== null) {
            throw new RunRequestDeclined(RunRequestDeclined::BUDGET, $declineMessage);
        }

        $request = new TranscriptionRequest(MeetingAiKind::Transcription->value, $recordId, $uploadId);

        try {
            $runId = $this->requestTranscription->execute($context, $request);
        } catch (RunRequestDeclined $declined) {
            throw $origin === MeetingRunOrigin::Create ? self::withGuide($declined) : $declined;
        }

        MeetingRun::query()->create([
            'record_id' => $recordId,
            'ai_run_id' => $runId,
            'stage' => MeetingRunStage::Transcription,
            'origin' => $origin,
            'writes_title' => $writesTitle,
        ]);

        return $runId;
    }

    /**
     * 作成の画面に添える文(公開デモと画面.md の 9.2): 上限 = テキストは取り込める、読み取れない = 対応する形式。ほかの理由は添えない。
     * 元の文と空白なしでつなぐ(元の文が句点で終わらなければ句点を足す)。
     */
    private static function withGuide(RunRequestDeclined $declined): RunRequestDeclined
    {
        $guide = match ($declined->reason) {
            RunRequestDeclined::BUDGET => MeetingAudioBudget::TEXT_STILL_WORKS,
            RunRequestDeclined::UNREADABLE => self::SUPPORTED_FORMATS,
            default => null,
        };

        if ($guide === null) {
            return $declined;
        }

        $message = $declined->validator->errors()->first();
        $sentence = str_ends_with($message, '。') ? $message : $message.'。';

        return new RunRequestDeclined($declined->reason, $sentence.$guide);
    }
}
