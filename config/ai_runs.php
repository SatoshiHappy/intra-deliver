<?php

/*
 * モジュール Ai の設定(鍵の意味と公開デモの値の正本 = docs の 設計/AI/実行と費用/接続先と費用.md 5 節)。接続先の鍵と URL は config/ai.php(鍵は環境変数だけ)。
 * 送り先は送ったデータを学習に使わない枠だけ(設計/AI/データと失敗と検査.md 6 節)。どの枠かはコードで判別できないので、ここと配備の手順で守る。
 * 出力・入力の上限・呼び出しの時間の上限と見積もりの係数は【推測】で T08 の実測で直す。単価は Anthropic の公式の料金表(2026-10-18)。
 * 種類の型 type(prompt = 文章、transcription = 文字起こし、conversation = 会話)は必須の鍵を型ごとに起動の時に確かめるため、鍵の有無で推さずに書く。
 * 会話の型の種類は ① AI エージェントが登録するので、ここに会話の型の行を置くのは ① の分(会話の型の用途は下の conversation)。
 * 会議の文字起こしの値 = docs の 設計/業務ツール/会議/文字起こしと議事録.md の 4.1、分単価 = 設計/AI/実行と費用.md 5 節(Gemini の公式の料金表)。
 * 上限の環境変数を空にすると上限なし。数でない値は作りの誤り(上限なしに倒さない)。
 */

$usd = static function (string $key, int|float|null $default): mixed {
    $value = env($key, $default);

    return $value === '' ? null : $value;
};

return [
    'kinds' => [
        'meeting.minutes' => [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 300,
            'max_output_tokens' => 32000,
            'max_input_chars' => 80000,
            'provider_options' => ['output_config' => ['effort' => 'low']],
        ],
        'meeting.transcription' => [
            'type' => 'transcription',
            'provider' => 'gemini',
            'model' => 'gemini-3.5-transcribe',
            'budget' => 'transcription',
            'timeout_seconds' => 180,
            'language' => 'ja',
            'max_audio_seconds' => 10800,
            'chunk_seconds' => 1500,
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
        'gemini' => [
            'gemini-3.5-transcribe' => ['per_audio_minute' => ['input' => 0.003, 'output' => 0.002]],
        ],
    ],

    // コード実行の時間単価(ファイルを作るのモデル。1 時間・1 作業場あたりの米ドルと最小の秒。Anthropic の code execution tool の文書、取得 2026-11-04。
    // 組織ごとの月の無料の時間は数えない = 多めに数える側。設計/AI/実行と費用/接続先と費用.md 5 節)
    'code_execution_pricing' => [
        'anthropic' => [
            'claude-sonnet-5-5' => ['usd_per_hour' => 0.05, 'min_seconds' => 300],
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

    // 会話の型の用途(設計/AI/実行と費用/接続先と費用.md 5 節の「会話の型の用途」)と会話のジョブのキューの接続(config/queue.php。実行と費用/README.md 4.5)。
    // モデルは仮の値(会話の用途は ① を出す前に人の判断で決める。題 = 小さく速いモデル、ファイルを作る = Anthropic のモデル)。表示名と知識の期限は任意。
    // provider_options は接続先ごとの考える過程の指定(会話 = 接続先が量を決める適応型、題とファイルを作る = 切る。切る形はモデルごと:
    // Haiku 5.5 は disabled、Sonnet 5.5 は disabled を受けず between_tools。形は本物の接続の確かめで直す = データと失敗と検査.md 8 節)。
    // metadata(訪問者の ID)と container(作業場)は実行ごとに入れるので書かない。context_window_tokens = 会話の用途のモデルの読める量(9.9)
    'conversation' => [
        'queue_connection' => 'database-agent',
        'purposes' => [
            'conversation' => [
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'display_name' => 'Claude Sonnet 5.5',
                'knowledge_cutoff' => null,
                'provider_options' => ['thinking' => ['type' => 'adaptive']],
                'context_window_tokens' => 1000000,
            ],
            'title' => [
                'provider' => 'anthropic',
                'model' => 'claude-haiku-5-5',
                'display_name' => 'Claude Haiku 5.5',
                'knowledge_cutoff' => null,
                'provider_options' => ['thinking' => ['type' => 'disabled']],
            ],
            'create_file' => [
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'display_name' => 'Claude Sonnet 5.5',
                'knowledge_cutoff' => null,
                'provider_options' => ['thinking' => ['type' => 'between_tools']],
            ],
        ],
    ],

    // 種類によらない文字起こしの値(見えない値。設計/AI/実行と費用.md 4.2。本物の接続の確かめで直す)。
    // 一時ファイルは置き場の下に実行の id で分けたディレクトリに置く(設計/AI/データと失敗と検査.md 6 節)。
    // 測りの上限を変換の上限と分ける理由 = 設計/AI/実行と費用.md 4.2
    'transcription' => [
        'conversion_timeout_seconds' => 300,
        'measure_timeout_seconds' => 20,
        'decode_check_seconds' => 10,
        'silence_window_seconds' => 60,
        'silence_noise_db' => -35,
        'silence_min_seconds' => 0.5,
        'incomplete_tolerance_seconds' => 30,
        'channels' => 1,
        'sample_rate' => 16000,
        'bitrate_kbps' => 16,
        'temp_directory' => sys_get_temp_dir().'/ai-transcription',
    ],
];
