<?php

namespace App\Actions\Records;

use App\Enums\Menu\MenuIcon;
use App\Queries\Records\RecordConditionValidator;
use App\Support\DefinitionText;
use Illuminate\Validation\ValidationException;

/**
 * 定義の入力のコードと文字列の確かめ(docs の 設計/業務データの入れ物/データモデル/ 1 節のコードの書式・予約語・名称、6 節の例外 3)。
 * DB の検査制約・列の長さに反する入力を、書く前に項目つきで拒むための文言を返す。コードの書式・名前・説明・並び順の規則と文言は {@see DefinitionText}
 * (ワークフローの定義と共有)へ委譲し、ここは業務データの入れ物に固有のもの(予約語・選択肢のコード・アイコン・真偽・既定のキー)を持つ。
 */
trait ValidatesDefinitionText
{
    /** 選択肢のコードの書式(数字で始まってよい。長さは DefinitionText::CODE_MAX から。画面の入力時の検査 resources/js/lib/Records/recordDefinitionCode.ts が本体と文言を写している。整合は tests/Architecture/RecordDefinitionCodeMirrorTest) */
    private const OPTION_CODE_PATTERN = '/^[a-z0-9_]{1,'.DefinitionText::CODE_MAX.'}$/D';

    /** 業務モジュール・項目のコードに使えない語(records の列の名前と、URL に固定で出る語と new) */
    private const RESERVED_CODES = [
        'id', 'record_module_id', 'parent_record_module_id', 'parent_record_id', 'sort_key', 'field_values', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at',
        'records', 'data', 'admin', 'modules', 'fields', 'rules', 'create', 'edit', 'reorder', 'candidates', 'categories', 'new',
    ];

    /** 項目の並び順の間隔(追加の既定 = 最大 + この値、並び替えの振り直し) */
    public const SORT_KEY_STEP = 10;

    /** 既定を持つ入力のキー(null = 未指定。{@see withoutUnspecifiedKeys}) */
    private const DEFAULTED_KEYS = ['is_required', 'is_multiline', 'is_multiple', 'is_listed', 'is_searchable', 'is_title', 'grants_row_access', 'sort_key'];

    /**
     * 業務モジュール・項目のコードの誤り(無ければ null)。
     */
    private function codeError(mixed $code): ?string
    {
        return DefinitionText::codeError($code) ?? (in_array($code, self::RESERVED_CODES, true) ? "{$code} は予約語のためコードに使用できません" : null);
    }

    /**
     * 選択肢のコードの誤り(無ければ null)。
     */
    private function optionCodeError(mixed $code): ?string
    {
        return match (true) {
            $code === null => 'コードを入力してください',
            ! is_string($code) || preg_match(self::OPTION_CODE_PATTERN, $code) !== 1 => 'コードは英小文字・数字・_ の 50 文字までにしてください',
            default => null,
        };
    }

    /**
     * 名称・文言の誤り(無ければ null)。
     */
    private function textError(mixed $text, string $label, bool $isRequired = true, int $max = DefinitionText::NAME_MAX): ?string
    {
        return DefinitionText::textError($text, $label, $isRequired, $max);
    }

    /**
     * 業務モジュールの説明の誤り(無ければ null。空 = null は誤りでない)。
     */
    private function descriptionError(mixed $description): ?string
    {
        return DefinitionText::descriptionError($description);
    }

    /**
     * 業務モジュールのアイコンの誤り(無ければ null。{@see MenuIcon::recordModuleChoices()} にあること。null は呼び手が未指定として先に分ける)。
     */
    private function iconError(mixed $icon): ?string
    {
        return MenuIcon::tryRecordModuleChoice($icon) === null ? 'アイコンは一覧から選択してください' : null;
    }

    /**
     * 真偽の入力の誤り(無ければ null。null は先に {@see withoutUnspecifiedKeys} が落とす)。
     */
    private function flagError(mixed $flag): ?string
    {
        return $flag === null || is_bool($flag) ? null : '真偽(true・false)で指定してください';
    }

    /**
     * ソートキーの入力の誤り(無ければ null。null は先に {@see withoutUnspecifiedKeys} が落とす)。
     */
    private function sortKeyError(mixed $sortKey): ?string
    {
        return DefinitionText::sortKeyError($sortKey);
    }

    /**
     * 既定を持つキー(旗 7 つと並び順)の null は、キーが無いのと同じ(追加では既定、変更では今の値のまま)。
     * 値のキー(名前・最大の文字数・既定値)の null は空のまま残す(名前は誤り、最大の文字数と既定値は消す)。
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function withoutUnspecifiedKeys(array $input): array
    {
        return array_filter($input, fn (mixed $value, int|string $key) => $value !== null || ! in_array($key, self::DEFAULTED_KEYS, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, string|null>  $errors  入力の名前 → 文言(null は誤りなし)
     *
     * @throws ValidationException 誤りがあるとき
     */
    private function throwIfAny(array $errors): void
    {
        RecordConditionValidator::throwIfAny($errors);
    }
}
