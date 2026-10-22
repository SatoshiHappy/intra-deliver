<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\AudioConverter;
use App\Actions\Ai\AudioUnreadable;
use App\Actions\Ai\RunBudget;
use App\Actions\Ai\TranscriptionKinds;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunTranscriptionJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\Api\TranscriptionFormats;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\TranscriptionSettings;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Upload\Api\UploadQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * AI の文字起こしを依頼する(docs の 設計/AI/公開の口.md 3 節の文字起こしの依頼の行、実行と費用.md 4.2・5.2)。音声の長さを測り、上限を確かめて
 * 実行(queued)と空の送る文を書き、コミットの後にジョブを投入する。区間の一覧は種類の実装({@see TranscriptionKind::complete()})が受け取る。
 * 対象のレコードの閲覧と、音声がそのレコードの添付であることの判定は呼び手。
 *
 * Ai の公開の口。自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる(ジョブは外側のコミットの後に出る)。
 * 長さは勧告ロックの外で測り(外部のプロセスを待つ間、ほかの依頼を止めない)、上限の確認と行の追加は文章の依頼と同じ鍵で直列にする。
 */
class RequestTranscription
{
    public const MISSING = '音声が削除されたため、処理できません';

    public const UNREADABLE = '音声を読み取れませんでした';

    public const TOO_LONG = '音声が長すぎます。:hours 時間までの音声を取り込んでください';

    private const SECONDS_PER_HOUR = 3600;

    public function __construct(
        private TranscriptionKinds $kinds,
        private KindSettings $settings,
        private TranscriptionSettings $transcription,
        private RunEstimates $estimates,
        private RunBudget $budget,
        private RecordDisplayQuery $records,
        private UploadQuery $uploads,
        private TranscriptionFormats $formats,
        private AudioConverter $converter,
    ) {}

    /**
     * @return int 実行の id(ai_runs.id)
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws LogicException 未登録・設定に無い種類、音声の形式でない添付、無い対象のレコードのとき(呼び手の誤り)
     * @throws RunRequestDeclined 音声の実体が無い・読み取れない・長すぎる・上限を超えるとき(422。キー ai)
     */
    public function execute(AccessContext $context, TranscriptionRequest $request): int
    {
        $requesterUserId = $context->userId ?? throw new AuthorizationException(RequestPrompt::GUEST);
        $this->kinds->of($request->kindCode);
        $kind = $this->settings->transcription($request->kindCode)
            ?? throw new LogicException("AI の文字起こしの種類 {$request->kindCode} が設定(config/ai_runs.php の kinds)に無い");
        $audioSeconds = $this->measure($this->audioPath($request->uploadId), $kind['max_audio_seconds']);
        $moduleCode = $this->records->topLevelModuleCodeOf($request->recordId)
            ?? throw new LogicException("AI の実行の対象のレコード {$request->recordId} が無い");
        $reserved = $this->estimates->transcriptionEstimate($kind['provider'], $kind['model'], $audioSeconds);

        $attributes = [
            'kind' => $request->kindCode,
            'status' => AiRunStatus::Queued,
            'requester_user_id' => $requesterUserId,
            'selected_department_id' => $context->selectedDepartmentId,
            'record_id' => $request->recordId,
            'original_record_id' => $request->recordId,
            'record_module_code' => $moduleCode,
            'source_record_ids' => [$request->recordId],
            'input_upload_id' => $request->uploadId,
            'original_input_upload_id' => $request->uploadId,
            'audio_seconds' => number_format($audioSeconds, 3, '.', ''),
            'provider' => $kind['provider'],
            'model' => $kind['model'],
            'budget' => $kind['budget'],
            'max_output_tokens' => null,
            'reserved_cost_usd' => $reserved,
        ];
        $jobTimeout = $this->transcription->jobTimeout($kind);

        return DB::transaction(function () use ($attributes, $kind, $reserved, $jobTimeout): int {
            $this->budget->lock();
            $this->budget->ensureWithin($kind['budget'], $reserved);

            $run = AiRun::query()->create($attributes);
            AiRunText::query()->create(['ai_run_id' => $run->id, 'system_text' => '', 'user_text' => '']);
            RunTranscriptionJob::dispatch($run->id, $jobTimeout)->afterCommit();

            return $run->id;
        });
    }

    /**
     * 音声の実体のパス。添付が音声の形式でなければ呼び手の誤り。
     *
     * @throws LogicException 添付の型が音声の形式でないとき
     * @throws RunRequestDeclined 台帳の行・実体が無いとき(422)
     */
    private function audioPath(int $uploadId): string
    {
        $file = $this->uploads->find([$uploadId])[$uploadId] ?? null;

        if ($file !== null && ! in_array($file->mime, $this->formats->mimes(), true)) {
            throw new LogicException("添付 {$uploadId} の型 {$file->mime} は音声の形式でない");
        }

        $path = $this->uploads->pathOf($uploadId);

        return $path !== null && is_file($path)
            ? $path
            : throw new RunRequestDeclined(RunRequestDeclined::MISSING, self::MISSING);
    }

    /**
     * 音声の長さ(秒。小数 3 桁で、記録の制約に合わせて 0 より大きい)。測った時間をログに出す(依頼の口の中で走る外部の処理の見直しに使う)。
     *
     * @throws RunRequestDeclined 測れない・1 本の長さの上限を超えるとき(422)
     */
    private function measure(string $path, int $maxAudioSeconds): float
    {
        $startedAt = microtime(true);

        try {
            $seconds = max(0.001, round($this->converter->measure($path), 3));
        } catch (AudioUnreadable) {
            throw new RunRequestDeclined(RunRequestDeclined::UNREADABLE, self::UNREADABLE);
        } finally {
            Log::info('文字起こしの依頼の音声の長さを測った', ['milliseconds' => (int) round((microtime(true) - $startedAt) * 1000)]);
        }

        if ($seconds > $maxAudioSeconds) {
            $hours = (string) intdiv($maxAudioSeconds, self::SECONDS_PER_HOUR);

            throw new RunRequestDeclined(RunRequestDeclined::TOO_LONG, str_replace(':hours', $hours, self::TOO_LONG));
        }

        return $seconds;
    }
}
