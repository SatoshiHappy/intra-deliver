<?php

/*
 * モジュール Ai の設定(鍵の意味と公開デモの値の正本 = docs の 設計/AI/実行と費用.md 5 節)。接続先の鍵と URL は config/ai.php(鍵は環境変数だけ)。
 * 送り先は送ったデータを学習に使わない枠だけ(設計/AI/データと失敗と検査.md 6 節)。どの枠かはコードで判別できないので、ここと配備の手順で守る。
 * 出力・入力の上限・呼び出しの時間の上限と見積もりの係数は【推測】で T08 の実測で直す。単価は Anthropic の公式の料金表(2026-10-18)。
 * 上限の環境変数を空にすると上限なし。数でない値は作りの誤り(上限なしに倒さない)。
 */

$usd = static function (string $key, int|float|null $default): mixed {
    $value = env($key, $default);

    return $value === '' ? null : $value;
};

return [
    'kinds' => [
        'meeting.minutes' => [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 300,
            'max_output_tokens' => 32000,
            'max_input_chars' => 80000,
            'provider_options' => ['output_config' => ['effort' => 'low']],
        ],
        'meeting.extraction' => [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'budget' => 'default',
            'timeout_seconds' => 300,
            'max_output_tokens' => 12000,
            'max_input_chars' => 40000,
            'provider_options' => ['output_config' => ['effort' => 'medium']],
        ],
    ],

    'pricing' => [
        'anthropic' => [
            'claude-haiku-5-5' => [
                ['up_to_input_tokens' => 100000, 'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
                ['up_to_input_tokens' => null, 'input' => 0.50, 'output' => 2.50, 'cache_read' => 0.05, 'cache_write' => 0.625],
            ],
            'claude-sonnet-5-5' => [
                ['up_to_input_tokens' => null, 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
            ],
        ],
    ],

    'budget' => [
        'default' => [
            'daily_usd' => $usd('AI_BUDGET_DAILY_USD', 5),
            'monthly_usd' => $usd('AI_BUDGET_MONTHLY_USD', 50),
            'total_usd' => $usd('AI_BUDGET_TOTAL_USD', 200),
        ],
        'transcription' => [
            'daily_usd' => $usd('AI_TRANSCRIPTION_BUDGET_DAILY_USD', 2),
            'monthly_usd' => $usd('AI_TRANSCRIPTION_BUDGET_MONTHLY_USD', 20),
            'total_usd' => $usd('AI_TRANSCRIPTION_BUDGET_TOTAL_USD', null),
        ],
    ],

    'estimate' => [
        'chars_factor' => 2,
        'overhead_tokens' => 2000,
    ],

    'retention_days' => 90,
];
