<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\AiSettings;
use Illuminate\Http\Client\ConnectionException;
use LogicException;
use Throwable;

/**
 * LLM を呼ぶ唯一のクラス(docs の 設計/AI/実行と費用.md 4 節。副作用 ai は台帳②で App\Actions\Ai だけ)。接続先・モデル・出力の上限は実行に写した値を使う。
 * 拡張点(名前を伏せる処理を足すときはここ = 呼ぶ前。設計/AI/データと失敗と検査.md 6 節)。
 */
class LlmClient
{
    /** Guzzle の curl の時間切れの誤り(CURLE_OPERATION_TIMEDOUT)の文の頭。laravel/ai は Laravel の HTTP クライアントで接続先を呼ぶ */
    private const CURL_TIMEOUT = 'cURL error 28';

    public function __construct(private AiSettings $settings) {}

    /**
     * @throws LlmCallFailed 呼び出しに失敗したとき(時間の上限 = timeout、ほか = provider)
     * @throws LogicException 種類が設定に無いとき
     */
    public function call(AiRun $run, AiRunText $text, PromptKind $kind): LlmResult
    {
        $settings = $this->settings->kind($run->kind)
            ?? throw new LogicException("AI の実行の種類 {$run->kind} が設定(config/ai_runs.php の kinds)に無い");
        $agent = new PromptAgent(
            $text->system_text,
            $kind,
            $run->max_output_tokens,
            $settings['timeout_seconds'],
            $this->settings->providerDriver($run->provider),
            $settings['provider_options'],
        );

        try {
            return LlmResult::fromResponse($agent->prompt($text->user_text, provider: $run->provider, model: $run->model));
        } catch (Throwable $exception) {
            throw new LlmCallFailed(self::isTimeout($exception) ? AiRunErrorCode::Timeout : AiRunErrorCode::Provider, $exception);
        }
    }

    /**
     * 時間の上限で切れたか(laravel/ai は接続の誤りを包むので、元の例外まで辿る)。
     */
    private static function isTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionException && str_starts_with($current->getMessage(), self::CURL_TIMEOUT)) {
                return true;
            }
        }

        return false;
    }
}
