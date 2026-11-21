<?php

namespace App\Queries\Ai;

use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * 並べて動かす組の子の処理の設定(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 同時に動かす数の上限(環境変数
 * `AI_MAX_TOOL_USE_CONCURRENCY`。既定 10、配備の値 = 公開の配備の構成の 3.4)・子の時間の上限(秒)・子の PHP のメモリの上限(どの環境も
 * 同じ値 = 構成の 3.6)。形は起動の時に確かめる(設計/AI/データと失敗と検査.md 8 節)。
 */
final class ToolChildSettings
{
    private const SECTION = 'ai_runs.conversation.tool_children';

    /**
     * @return positive-int
     *
     * @throws LogicException 形が合わないとき
     */
    public function concurrency(): int
    {
        // 環境変数の文字列も、型を変えずに正の整数の字だけを受ける(「2x」や真偽を数にしない)
        $value = Config::get(self::SECTION.'.max_concurrency');
        $value = is_string($value) && preg_match('/^[1-9][0-9]*\z/', $value) === 1 ? (int) $value : $value;

        return is_int($value) && $value > 0
            ? $value
            : throw new LogicException(self::problem('max_concurrency', '正の整数'));
    }

    /**
     * @return positive-int
     *
     * @throws LogicException 形が合わないとき
     */
    public function timeoutSeconds(): int
    {
        return $this->positive('timeout_seconds');
    }

    /**
     * @throws LogicException 形が合わないとき
     */
    public function memoryLimit(): string
    {
        $value = Config::get(self::SECTION.'.memory_limit');

        return is_string($value) && preg_match('/^[1-9][0-9]*[KMG]\z/', $value) === 1
            ? $value
            : throw new LogicException(self::problem('memory_limit', '数と K・M・G の 1 字'));
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ([$this->concurrency(...), $this->timeoutSeconds(...), $this->memoryLimit(...)] as $read) {
            try {
                $read();
            } catch (LogicException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        return $problems;
    }

    /**
     * @return positive-int
     */
    private function positive(string $key): int
    {
        $value = Config::get(self::SECTION.'.'.$key);

        return is_int($value) && $value > 0 ? $value : throw new LogicException(self::problem($key, '正の整数'));
    }

    private static function problem(string $key, string $form): string
    {
        return "config/ai_runs.php の conversation.tool_children.{$key} が{$form}でない";
    }
}
