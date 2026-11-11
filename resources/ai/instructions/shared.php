<?php

/*
 * ③ の自前の文(公開の側に 1 つだけ置き、2 つの版が同じ文を返す = docs の 設計/AI/設定とプロンプト/README.md 11.4)。
 * 出どころ = 自前(設計/AI/設定とプロンプト/使う文と組み立て.md 11.7)。場面 = 同じ 11.5 の定型の表(定型でない文は README.md 11.1・使う文と組み立て.md 11.6)。
 * コメントはモデルに渡らない。
 */

return [
    'compaction_custom_instructions' => <<<'TEXT'
        Keep every record reference marker exactly as it appeared in the conversation.
        List each file created in the conversation with its name and reference marker.
        TEXT,

    'stopped_by_time_limit' => 'This tool call was not run because the request reached its time limit.',

    'source_file_not_found' => 'The file to revise could not be found.',

    'tool_exception' => 'The tool {{tool_name}} failed because of an unexpected error.',

    'large_result_search' => <<<'TEXT'
        The result is too long ({{original_length}} characters). Only the first {{preview_length}} characters are shown.
        To get the rest, narrow the search conditions or ask for the next page.

        {{preview}}
        TEXT,

    'large_result_read' => <<<'TEXT'
        The result is too long ({{original_length}} characters). Only the first {{preview_length}} characters are shown.
        To get the rest, read the comments and the change history page by page.

        {{preview}}
        TEXT,

    'large_result_aggregate' => <<<'TEXT'
        The result is too long ({{original_length}} characters). Only the first {{preview_length}} characters are shown.
        To get the rest, group by fewer fields or narrow the conditions.

        {{preview}}
        TEXT,

    'large_result_truncated' => <<<'TEXT'
        The result is too long ({{original_length}} characters) and was cut.
        Only the first {{preview_length}} characters are shown, and this tool cannot return the rest.

        {{preview}}
        TEXT,

    'created_files_after_compaction' => <<<'TEXT'
        Files created earlier in this conversation (newest first):
        {{created_files}}
        TEXT,

    'unknown_outcome_notice' => <<<'TEXT'
        The previous request stopped while these tool calls were running, so it is not known whether they finished.
        Check their results before relying on them or running them again:
        {{unknown_calls}}
        TEXT,
];
