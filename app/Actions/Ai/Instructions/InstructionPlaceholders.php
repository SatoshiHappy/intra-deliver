<?php

namespace App\Actions\Ai\Instructions;

use LogicException;

/**
 * 指示の文の置き換え口 `{{name}}`(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6)の、2 つの版が使う 1 か所(入れた値には外から来る文が
 * 入るので、置き換えは 1 回 = 設計/AI/設定とプロンプト/README.md 11.4 の「守ること」)。値を入れないで済む口の一覧は版が持って渡す。
 */
final class InstructionPlaceholders
{
    /**
     * 許す置き換え口。前の組 = docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6 の表の口(表の順。作ったファイルの一覧の口の名前は
     * 実装で決めた)、後ろの組 = ③ の自前の定型の口で、実装で名前を決めた口(同じ 11.6 の表の後の段落の「口の名前と一覧は実装の依頼書」)。
     * 引数の誤りの 3 つの行の形の口(param など)は表の口でなく、値を入れないで済む口として版が持つ。
     * 拡張点(ファイルを作るの子の環境の口を足すときはここ = 同じ 11.6 の表の「子の環境」の行)。
     */
    public const ALLOWED = [
        'agent_name',
        'cyber_risk_instruction',
        'hooks_section',
        'instructions_file_name',
        'language',
        'output_style_name',
        'output_style_prompt',
        'model_marketing_name',
        'model_id',
        'knowledge_cutoff',
        'current_date',
        'summary',
        'custom_instructions',
        'created_files',
        'user_feedback',
        'tool_name',
        'problems',
        'error',
        'denial_detail',
        'is_git',
        'step_tool_names',
        'original_length',
        'preview_length',
        'preview',
        'unknown_calls',
    ];

    private const PATTERN = '/\{\{([A-Za-z0-9_]+)\}\}/';

    /**
     * 文の中の置き換え口の名前(現れた順、重なりなし)。
     *
     * @return list<string>
     */
    public static function namesIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * 値を 1 回で入れた文。値を入れないで済む口は、値が無ければ入れずに残す。並びの値は改行で継ぐ。
     *
     * @param  string  $label  文の名前(誤りの文に出す)
     * @param  array<string, string|list<string>>  $values
     * @param  list<string>  $optional  値を入れないで済む口
     *
     * @throws LogicException 値の無い置き換え口があるとき(作りの誤り)
     */
    public static function fill(string $label, string $text, array $values, array $optional = []): string
    {
        $missing = array_diff(self::namesIn($text), array_keys($values), $optional);

        if ($missing !== []) {
            throw new LogicException("指示の文 {$label} に値の無い置き換え口がある: ".implode(', ', $missing));
        }

        $pairs = [];

        foreach ($values as $name => $value) {
            $pairs['{{'.$name.'}}'] = is_array($value) ? implode("\n", $value) : $value;
        }

        return strtr($text, $pairs);
    }
}
