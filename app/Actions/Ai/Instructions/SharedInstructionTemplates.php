<?php

namespace App\Actions\Ai\Instructions;

use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 持ち主が ③ の定型({@see AiInstructionTemplate::isShared()})と、定型でない ③ の決まった文を引く 1 か所(docs の 設計/AI/設定とプロンプト/README.md
 * 11.4: Claude Code の文でないので公開の側に 1 つだけ置き、2 つの版がここから同じ文を返す)。
 * 文の置き場 = resources/ai/instructions/shared.php。
 */
final readonly class SharedInstructionTemplates
{
    /** 文の置き場(resources の下の位置) */
    public const PATH = 'ai/instructions/shared.php';

    /** 要約の指示の追加の指示の口 custom_instructions に入れる ③ の決まった文(docs の 設計/AI/設定とプロンプト/README.md 11.1) */
    public const COMPACTION_CUSTOM_INSTRUCTIONS = 'compaction_custom_instructions';

    /** 定型でない文の鍵 */
    public const TEXT_KEYS = [self::COMPACTION_CUSTOM_INSTRUCTIONS];

    private InstructionTextFile $texts;

    /** @param  string|null  $path  文の置き場(null = {@see self::PATH}。テストが差し替える) */
    public function __construct(?string $path = null)
    {
        $this->texts = new InstructionTextFile($path ?? resource_path(self::PATH));
    }

    /**
     * 定型の文に、種類の値の鍵({@see AiInstructionTemplate::valueKeys()})の値だけを 1 回で入れた文(③ の定型は値を入れないで済む口を持たない)。
     *
     * @param  array<string, string|list<string>>  $values
     *
     * @throws LogicException 持ち主が ③ でない種類・文が無い・値の無い置き換え口があるとき(作りの誤り)
     */
    public function text(AiInstructionTemplate $template, array $values): string
    {
        if (! $template->isShared()) {
            throw new LogicException("定型 {$template->value} の持ち主は ③ でない(版が持つ)");
        }

        $passed = array_intersect_key($values, array_flip($template->valueKeys()));

        return InstructionPlaceholders::fill($template->value, $this->texts->text($template->value), $passed);
    }

    /**
     * 要約に根拠の印と作ったファイルの名前と印を残す求めの文(2 つの版が要約の指示の custom_instructions の口に入れる)。
     *
     * @throws LogicException 文が無いとき(作りの誤り)
     */
    public function compactionCustomInstructions(): string
    {
        return $this->texts->text(self::COMPACTION_CUSTOM_INSTRUCTIONS);
    }

    /**
     * ③ の文の全部(文の鍵 → 置き換え口を入れる前の文)。
     *
     * @return array<string, string>
     *
     * @throws LogicException ファイルが無い・読めない・形が合わないとき
     */
    public function texts(): array
    {
        return $this->texts->all();
    }

    /**
     * 起動の時の確かめ(文の欠けと、許す一覧と呼び手が渡す鍵に無い置き換え口)。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->texts->problems(self::valueKeys(), []);
    }

    /**
     * 文の鍵と、その文に呼び手が渡す値の鍵(定型でない文は値を持たない。③ の定型は種類の値の鍵)。
     *
     * @return array<string, list<string>>
     */
    public static function valueKeys(): array
    {
        $keys = array_fill_keys(self::TEXT_KEYS, []);

        foreach (AiInstructionTemplate::sharedCases() as $template) {
            $keys[$template->value] = $template->valueKeys();
        }

        return $keys;
    }
}
