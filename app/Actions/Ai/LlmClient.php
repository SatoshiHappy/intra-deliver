<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\KindSettings;
use LogicException;
use Throwable;

/**
 * LLM を呼ぶ唯一のクラス(docs の 設計/AI/実行と費用.md 4 節。副作用 ai は台帳②で App\Actions\Ai だけ)。接続先・モデル・出力の上限は実行に写した値を使う。
 * 拡張点(名前を伏せる処理を足すときはここ = 呼ぶ前。設計/AI/データと失敗と検査.md 6 節)。
 */
class LlmClient
{
    public function __construct(private AiSettings $settings, private KindSettings $kinds) {}

    /**
     * @throws LlmCallFailed 呼び出しに失敗したとき(時間の上限 = timeout、ほか = provider)
     * @throws LogicException 種類が文章の種類の設定に無い・実行に出力の上限が無いとき
     */
    public function call(AiRun $run, AiRunText $text, PromptKind $kind): LlmResult
    {
        $settings = $this->kinds->prompt($run->kind)
            ?? throw new LogicException("AI の実行の種類 {$run->kind} が文章の種類の設定(config/ai_runs.php の kinds)に無い");
        $maxOutputTokens = $run->max_output_tokens ?? throw new LogicException("AI の実行 {$run->id} に出力の上限が無い");
        $agent = new PromptAgent(
            $text->system_text,
            $kind,
            $maxOutputTokens,
            $settings['timeout_seconds'],
            $this->settings->providerDriver($run->provider),
            $settings['provider_options'],
        );

        try {
            return LlmResult::fromResponse($agent->prompt($text->user_text, provider: $run->provider, model: $run->model));
        } catch (Throwable $exception) {
            throw LlmCallFailed::from($exception);
        }
    }
}
