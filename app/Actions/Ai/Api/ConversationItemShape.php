<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;

/**
 * 会話の保存の項目の中身が、種類の形に合うか(作り手が作る時と {@see ConversationItem::fromArray()} が読む前に見る。docs の
 * 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。鍵は順を問わず、過不足を許さない。定型の列挙は鍵と述語を持ち、欄との組み合わせはここが決める。
 *
 * @phpstan-import-type Usage from ConversationItem
 */
final class ConversationItemShape
{
    /** 使用量の鍵と形(SDK の使用量の写しと同じ鍵。鍵の順の正本) */
    public const USAGE = [
        'input_tokens' => 'int',
        'output_tokens' => 'int',
        'cache_read_input_tokens' => '?int',
        'cache_write_input_tokens' => '?int',
        'reasoning_tokens' => '?int',
    ];

    /**
     * 使用量を {@see self::USAGE} の鍵の順に並べ直す(作った時の写しと読み戻した写しを同じにするため。順の正本は USAGE で、
     * 同じ順であることは ConversationItemShapeTest が見る)。
     *
     * @param  Usage  $usage
     * @return Usage
     */
    public static function orderedUsage(array $usage): array
    {
        return [
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'cache_read_input_tokens' => $usage['cache_read_input_tokens'],
            'cache_write_input_tokens' => $usage['cache_write_input_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
        ];
    }

    /**
     * 中身が種類の形に合うか。
     *
     * @param  array<array-key, mixed>  $content
     */
    public static function fits(ConversationItemType $type, array $content): bool
    {
        return self::fieldsFit($type, $content) && self::templateFits($type, $content);
    }

    /**
     * 欄の鍵と値の形。
     *
     * @param  array<array-key, mixed>  $content
     */
    private static function fieldsFit(ConversationItemType $type, array $content): bool
    {
        return match ($type) {
            ConversationItemType::UserText,
            ConversationItemType::CompactSummary => self::hasOnly($content, ['text' => 'string']),
            ConversationItemType::ModelText => self::hasOnly(
                $content,
                ['response_id' => 'string', 'blocks' => 'blocks'],
            ),
            ConversationItemType::ResponseEnd => self::hasOnly($content, [
                'response_id' => 'string',
                'usage' => 'usage',
                'raw_state' => '?array',
                'provider' => 'string',
                'model' => 'string',
                'block_count' => 'count',
                'call_ids' => 'strings',
                'finished_at' => 'time',
            ]),
            ConversationItemType::Cancellation => self::hasOnly($content, ['response_id' => 'string']),
            ConversationItemType::ToolResult => self::hasOnly($content, [
                'call_id' => 'string',
                'template' => '?toolResultTemplate',
                'values' => 'values',
                'body' => '?string',
                'is_error' => 'bool',
            ]),
            ConversationItemType::Interruption => self::hasOnly($content, ['during_tool' => 'bool']),
            ConversationItemType::AddedText => self::hasOnly(
                $content,
                ['template' => 'addedTextTemplate', 'values' => 'values', 'call_ids' => 'strings'],
            ),
            ConversationItemType::CompactBoundary => self::hasOnly($content, ['estimate' => 'count', 'date' => 'date']),
            ConversationItemType::TurnLimit => $content === [],
        };
    }

    /**
     * 定型の種類ごとの欄の規則(定義 = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の項目の種類。欄の形が合った中身に当てる)。
     *
     * @param  array<array-key, mixed>  $content
     */
    private static function templateFits(ConversationItemType $type, array $content): bool
    {
        return match ($type) {
            ConversationItemType::ToolResult => self::toolResultFits(
                $content['template'] === null ? null : AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['body'],
            ),
            ConversationItemType::AddedText => self::addedTextFits(
                AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['call_ids'],
            ),
            default => true,
        };
    }

    /**
     * 値の空は見ない(道具の結果の値は外から来る値で、モデルが渡した空の道具の名前などを含むため)。
     *
     * @param  array<string, string|list<string>>  $values
     */
    private static function toolResultFits(?AiInstructionTemplate $template, array $values, ?string $body): bool
    {
        if ($template === null) {
            return $values === [] && $body !== null;
        }

        return ($body !== null) === $template->takesBody() && self::valuesFitTemplate($values, $template);
    }

    /**
     * @param  array<string, string|list<string>>  $values
     * @param  list<string>  $callIds
     */
    private static function addedTextFits(AiInstructionTemplate $template, array $values, array $callIds): bool
    {
        $callIdsMismatch = ($callIds !== []) !== $template->carriesCallIds();

        if ($callIdsMismatch || ! self::valuesFitTemplate($values, $template)) {
            return false;
        }

        foreach ($values as $key => $value) {
            $isDate = in_array($key, $template->dateValueKeys(), true);

            if (! self::isFilled($value) || ($isDate && ! self::isDate($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 値の鍵が定型の保存する値の鍵の組と同じで(順は問わない)、鍵ごとの値の形(文字列か並び)が定型に合うか。
     *
     * @param  array<string, string|list<string>>  $values
     */
    private static function valuesFitTemplate(array $values, AiInstructionTemplate $template): bool
    {
        $lists = $template->listValueKeys();

        foreach ($values as $key => $value) {
            if (is_array($value) !== in_array($key, $lists, true)) {
                return false;
            }
        }

        return array_diff(array_keys($values), $template->storedValueKeys()) === []
            && count($values) === count($template->storedValueKeys());
    }

    /**
     * 値が空でないか(列なら、空でなく、どの要素も空の文字列でない)。
     *
     * @param  string|list<string>  $value
     */
    private static function isFilled(string|array $value): bool
    {
        return is_string($value) ? $value !== '' : $value !== [] && ! in_array('', $value, true);
    }

    /** 値が、項目の種類が持てる定型の種類の値か */
    private static function isTemplateOf(mixed $value, ConversationItemType $type): bool
    {
        return is_string($value) && AiInstructionTemplate::tryFrom($value)?->itemType() === $type;
    }

    /** 値が日の形({@see ConversationItem::DATE_FORMAT})の文字列か */
    private static function isDate(mixed $value): bool
    {
        return is_string($value) && self::isFormatted($value, ConversationItem::DATE_FORMAT);
    }

    /**
     * 配列が渡した鍵だけを持ち(順は問わない)、値が形に合うか。
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, string>  $forms
     */
    private static function hasOnly(array $values, array $forms): bool
    {
        if (count($values) !== count($forms) || array_diff_key($forms, $values) !== []) {
            return false;
        }

        foreach ($forms as $key => $form) {
            if (! self::isForm($values[$key], $form)) {
                return false;
            }
        }

        return true;
    }

    /** 値が形の名前に合うか */
    private static function isForm(mixed $value, string $form): bool
    {
        if (str_starts_with($form, '?') && $value === null) {
            return true;
        }

        return match (ltrim($form, '?')) {
            'string' => is_string($value),
            'int' => is_int($value),
            'count' => is_int($value) && $value >= 0,
            'bool' => is_bool($value),
            'array' => is_array($value),
            'toolResultTemplate' => self::isTemplateOf($value, ConversationItemType::ToolResult),
            'addedTextTemplate' => self::isTemplateOf($value, ConversationItemType::AddedText),
            'strings' => self::isStringList($value),
            'values' => self::valuesFit($value),
            'blocks' => self::blocksFit($value),
            'usage' => is_array($value) && self::hasOnly($value, self::USAGE),
            'time' => is_string($value) && self::isFormatted($value, DATE_ATOM),
            'date' => self::isDate($value),
            default => false,
        };
    }

    /** モデルの文のブロックの列が、文のブロックと道具の呼び出しのブロックだけか */
    private static function blocksFit(mixed $blocks): bool
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return false;
        }

        foreach ($blocks as $block) {
            $fits = is_array($block) && match ($block['type'] ?? null) {
                'text' => self::hasOnly($block, ['type' => 'string', 'text' => 'string']),
                'tool_call' => self::hasOnly($block, [
                    'type' => 'string',
                    'id' => 'string',
                    'name' => 'string',
                    'arguments' => 'array',
                    'result_id' => '?string',
                ]),
                default => false,
            };

            if (! $fits) {
                return false;
            }
        }

        return true;
    }

    /** 定型の値が、文字列の鍵 → 文字列か文字列の列か */
    private static function valuesFit(mixed $values): bool
    {
        if (! is_array($values)) {
            return false;
        }

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! (is_string($value) || self::isStringList($value))) {
                return false;
            }
        }

        return true;
    }

    /** 文字列の列か */
    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value;
    }

    /** 文字列が形のとおりに書かれた時刻か(読んで同じ形に書き戻すと同じ文字列になる) */
    private static function isFormatted(string $value, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $parsed !== false && $parsed->format($format) === $value;
    }
}
