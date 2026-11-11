<?php

/*
 * ③ の自前の文(公開の側に 1 つだけ置き、2 つの版が同じ文を返す = docs の 設計/AI/設定とプロンプト/README.md 11.4)。
 * 出どころ = 自前(設計/AI/設定とプロンプト/使う文と組み立て.md 11.7)。場面 = 同じ 11.5 の定型の表(定型でない文は README.md 11.1・使う文と組み立て.md 11.6)。
 * コメントはモデルに渡らない。
 */

return [
    'compaction_custom_instructions' => <<<'TEXT'
        あなたは、すべてのレコードの印を、会話に現れたとおりの形で残す。
        会話で作ったファイルを 1 つずつ、名前と印を添えて挙げる。
        TEXT,

    'stopped_by_time_limit' => 'この道具の呼び出しは、依頼が時間の上限に達したため実行されなかった。',

    'source_file_not_found' => '直す対象のファイルが見つからなかった。',

    'tool_exception' => '道具 {{tool_name}} は予期しない誤りのため失敗した。',

    'large_result_search' => <<<'TEXT'
        結果が長すぎる({{original_length}} 文字)。先頭の {{preview_length}} 文字だけを示す。
        あなたは、残りを得るには、検索の条件を絞るか、次のページを求める。

        {{preview}}
        TEXT,

    'large_result_read' => <<<'TEXT'
        結果が長すぎる({{original_length}} 文字)。先頭の {{preview_length}} 文字だけを示す。
        あなたは、残りを得るには、コメントと変更の履歴をページごとに読む。

        {{preview}}
        TEXT,

    'large_result_aggregate' => <<<'TEXT'
        結果が長すぎる({{original_length}} 文字)。先頭の {{preview_length}} 文字だけを示す。
        あなたは、残りを得るには、グループ化する項目を減らすか、条件を絞る。

        {{preview}}
        TEXT,

    'large_result_truncated' => <<<'TEXT'
        結果が長すぎる({{original_length}} 文字)ので、切り詰めた。
        先頭の {{preview_length}} 文字だけを示し、この道具は残りを返せない。

        {{preview}}
        TEXT,

    'created_files_after_compaction' => <<<'TEXT'
        この会話でこれまでに作ったファイル(新しい順):
        {{created_files}}
        TEXT,

    'unknown_outcome_notice' => <<<'TEXT'
        前の依頼は次の道具の呼び出しの実行中に止まったので、それらが終わったかどうかは分からない。
        あなたは、それらに頼ったり、もう一度実行したりする前に、結果を確かめる:
        {{unknown_calls}}
        TEXT,
];
