<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\ExchangeNotice;
use App\Actions\Ai\Core\SendFailure;
use App\Actions\Ai\Core\StepFailedNotice;
use App\Actions\Ai\Core\StepStoppedNotice;
use App\Actions\Ai\RunCosts;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunStepText;
use App\Queries\Ai\RunEstimates;
use Carbon\CarbonImmutable;

/**
 * ステップの行への知らせの書き込み(行の中身 = docs の 設計/AI/実行の記録.md 2.3、知らせ = 設計/AI/会話の中核の口と受け手.md 13.3)。
 * 行は送る前の確かめの関数が作るので、ここは作られた行を書き足すだけ。使用量は行が終わる時に書く(処理中の行は使用量を持たない =
 * 表の検査制約)ので、送った後の知らせの使用量を、終わりの知らせまで覚えておく。
 *
 * @phpstan-import-type Usage from ConversationItem
 */
final class StepRecorder
{
    /** @var array<int, Usage> 終わりの知らせを待つステップの使用量 */
    private array $usages = [];

    /** @var array<int, int> 終わりの知らせを待つステップの、接続先が返した誤りの状態コード(流れの途中の誤りは 200 の後なので持たない) */
    private array $statuses = [];

    public function __construct(
        private readonly RunEstimates $estimates,
        private readonly RunCosts $costs,
    ) {}

    /** 送り直しの何回目か(1 回目の送り直し = 1)と、送り直した元のステップ */
    public function beforeSend(BeforeSendNotice $notice): void
    {
        if ($notice->retryOfStepId === null) {
            return;
        }

        $this->step($notice->stepId)->update([
            'retry_attempt' => $notice->attempt - 1,
            'retried_from_step_id' => $notice->retryOfStepId,
        ]);
    }

    public function exchange(ExchangeNotice $notice): void
    {
        AiRunStepText::query()->create([
            'ai_run_step_id' => $notice->stepId,
            'sent_text' => self::json($notice->request),
            'output_text' => $notice->response === null ? null : self::json($notice->response),
        ]);
    }

    /** 応答が終わりまで届けば成功にする(失敗と止めたは、それぞれの知らせが書く) */
    public function afterSend(AfterSendNotice $notice): void
    {
        if ($notice->usage !== null) {
            $this->usages[$notice->stepId] = $notice->usage;
        }

        if ($notice->errorStatus !== null && ! $notice->cutMidStream) {
            $this->statuses[$notice->stepId] = $notice->errorStatus;
        }

        if ($notice->finish !== null) {
            $this->finish($notice->stepId, ['status' => AiRunStepStatus::Succeeded]);
        }
    }

    /**
     * 長すぎるは接続先が断った送信なので接続先の失敗に数える(実行の終わり方は中核の止めた(長さ))。接続先が返した状態コードは使用量の
     * 無い失敗にだけ書く(状態コード 400 以上の費用の分からない行を 0 で数える = 設計/AI/実行と費用/接続先と費用.md 5.2。行の CHECK)。
     */
    public function failed(StepFailedNotice $notice): void
    {
        $status = $this->statuses[$notice->stepId] ?? null;
        $usageKnown = isset($this->usages[$notice->stepId]);

        $this->finish($notice->stepId, [
            'status' => AiRunStepStatus::Failed,
            'error_code' => self::errorCode($notice->failure),
            ...($status === null || $usageKnown ? [] : ['provider_status' => $status]),
        ]);
    }

    public function stopped(StepStoppedNotice $notice): void
    {
        $this->finish($notice->stepId, ['status' => AiRunStepStatus::Stopped]);
    }

    /**
     * 行を終える(使用量が届いていれば実費に替える。届かなければ費用は空のまま、使った額への数え方 = 設計/AI/実行と費用/接続先と費用.md 5.2)。
     *
     * @param  array<string, mixed>  $values
     */
    private function finish(int $stepId, array $values): void
    {
        $step = $this->step($stepId);
        $usage = $this->usages[$stepId] ?? null;
        unset($this->usages[$stepId], $this->statuses[$stepId]);

        $step->update([
            ...$values,
            ...($usage === null ? [] : $this->usageValues($step, $usage)),
            'finished_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  Usage  $usage
     * @return array<string, mixed>
     */
    private function usageValues(AiRunStep $step, array $usage): array
    {
        $tier = $this->estimates->tierFor($step->provider, $step->model, $usage['input_tokens']);
        $cost = $this->costs->costOf(
            $tier,
            $usage['input_tokens'],
            $usage['output_tokens'],
            $usage['cache_read_input_tokens'],
            $usage['cache_write_input_tokens'],
        );

        return [...$usage, 'pricing' => $tier, 'cost_usd' => $cost];
    }

    private static function errorCode(SendFailure $failure): AiRunErrorCode
    {
        return match ($failure) {
            SendFailure::Timeout => AiRunErrorCode::Timeout,
            SendFailure::Provider, SendFailure::TooLong => AiRunErrorCode::Provider,
        };
    }

    private function step(int $stepId): AiRunStep
    {
        return AiRunStep::query()->findOrFail($stepId);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
