<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Records\FieldValueParser;
use Illuminate\Validation\ValidationException;

/**
 * 項目の定義の入力を確かめる。規則と誤りの段 = 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節(項目と規則/README.md 1・3・4 節、
 * 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)。
 * 入力の形(キーはエラーのキー)= 設計/業務データの入れ物/画面/管理の画面.md 3.4。
 *
 * @phpstan-type ValidatedField array{attributes: array<string, mixed>, options: list<array{code: string, name: string}>, child: array{code: string, name: string}|null, referencedModuleId: int|null}
 */
class FieldDefinitionValidator
{
    use ValidatesDefinitionText;

    /** 作った後に変えない入力(項目と規則/規則と業務処理/失敗と定義の変更.md 11 節。1 行か複数行か、1 つか複数かも型と同じ扱い) */
    private const IMMUTABLE_KEYS = ['code', 'type', 'is_multiline', 'is_multiple', 'referenced_module', 'child_module'];

    /** 型を分ける旗 → その旗を持てる型(旗を変える入力を「変えられない」と比べるのは、持てる型に真偽で与えたときだけ) */
    private const TYPE_FLAGS = [
        'is_multiline' => [RecordFieldType::String],
        'is_multiple' => [RecordFieldType::User, RecordFieldType::Department],
    ];

    /** 真偽の属性(旗) */
    private const FLAGS = [
        'is_required', 'is_multiline', 'is_multiple', 'is_listed', 'is_searchable', 'is_title', 'grants_row_access',
    ];

    /** 関係する人の旗を持てる型(認可とメニュー/README.md 2.4。変えられる旗なので、変えられないキーの比較には掛けない) */
    private const ROW_ACCESS_TYPES = [RecordFieldType::User, RecordFieldType::Department];

    public function __construct(
        private FieldDefaultValidator $defaults,
        private FieldNameUniqueness $names,
        private FieldOptionsValidator $options,
        private RecordSortKeys $sortKeys,
    ) {}

    /**
     * 項目を足す入力。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     * @param  array<array-key, mixed>  $input  前後の空白を除いた入力
     * @return ValidatedField
     *
     * @throws ValidationException 規則に反するとき
     */
    public function validateNew(RecordModule $module, array $input): array
    {
        $input = $this->withoutUnspecifiedKeys($input);
        $code = $input['code'] ?? null;
        $type = is_string($input['type'] ?? null) ? RecordFieldType::tryFrom($input['type']) : null;
        $codeError = $this->codeError($code) ?? ($module->recordFields->contains('code', $code) ? 'このコードは既に使用されています' : null);

        if ($type === null) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], array_filter(['code' => $codeError, 'type' => '型を選択してください'])));
        }

        // 既定の並び = 見出しと項目の最大 + 10(末尾に見出しを足した後の項目が、最後の見出しのまとまりに入る = 詳細の画面/見出し.md の 4 節)
        $next = $this->sortKeys->next($module->id);
        $errors = ['code' => $codeError, 'sort_key' => ($input['sort_key'] ?? null) !== null ? null : $this->sortKeys->limitError($next)];
        $input['sort_key'] ??= $next;

        $referencedModuleId = $this->referencedModuleId($type, $input, $errors);
        $child = $this->childModule($module, $type, $input, $errors);
        $validated = $this->validated($module, null, $type, $input, $errors);

        return [...$validated, 'attributes' => ['code' => $code, 'type' => $type, ...$validated['attributes']], 'child' => $child, 'referencedModuleId' => $referencedModuleId];
    }

    /**
     * 他モジュール参照の参照先(親の無い業務モジュールのコード)。ほかの型は持てない。
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string|null>  $errors
     */
    private function referencedModuleId(RecordFieldType $type, array $input, array &$errors): ?int
    {
        $code = $input['referenced_module'] ?? null;

        if ($type !== RecordFieldType::Record) {
            $errors['referenced_module'] = $code === null ? null : '参照先を設定できるのは他モジュール参照の項目だけです';

            return null;
        }

        $referenced = is_string($code) ? RecordModule::query()->where('code', $code)->whereNull('parent_record_module_id')->first() : null;
        $errors['referenced_module'] = $referenced === null ? '参照先の業務モジュールを選択してください(明細のモジュールは選択できません)' : null;

        return $referenced?->id;
    }

    /**
     * 明細の項目と一緒に作る明細のモジュールのコードと名前(明細は 1 段だけ)。ほかの型は持てない。
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string|null>  $errors
     * @return array{code: string, name: string}|null
     */
    private function childModule(RecordModule $module, RecordFieldType $type, array $input, array &$errors): ?array
    {
        $child = $input['child_module'] ?? null;
        $isDetails = $type === RecordFieldType::Details;
        $errors['child_module'] = $child === null || $isDetails ? null : '明細のモジュールを設定できるのは明細の項目だけです';
        $errors['type'] = $isDetails && $module->parent_record_module_id !== null ? '明細のモジュールに明細の項目は追加できません(明細は 1 段だけ)' : null;

        return $isDetails && $errors['type'] === null ? $this->childModuleInput($child, $errors) : null;
    }

    /**
     * @param  array<string, string|null>  $errors
     * @return array{code: string, name: string}|null
     */
    private function childModuleInput(mixed $child, array &$errors): ?array
    {
        $code = is_array($child) ? ($child['code'] ?? null) : null;
        $name = is_array($child) ? ($child['name'] ?? null) : null;
        $errors['child_module.code'] = $this->codeError($code) ?? (RecordModule::query()->where('code', $code)->exists() ? 'このコードは既に使用されています' : null);
        $errors['child_module.name'] = $this->textError($name, '明細のモジュールの名前');

        return is_string($code) && is_string($name) ? ['code' => $code, 'name' => $name] : null;
    }

    /**
     * 項目を変える入力(コード・型・複数行・複数・参照先・明細のモジュールは変えない)。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     * @param  array<array-key, mixed>  $input  前後の空白を除いた入力
     * @return ValidatedField
     *
     * @throws ValidationException 規則に反するとき
     */
    public function validateUpdate(RecordModule $module, RecordField $field, array $input): array
    {
        $current = [
            'code' => $field->code,
            'type' => $field->type->value,
            'is_multiline' => $field->is_multiline,
            'is_multiple' => $field->is_multiple,
            'referenced_module' => $field->referencedRecordModule?->code,
            'child_module' => $field->childRecordModule?->code,
        ];
        $input = $this->withoutUnspecifiedKeys($input);
        $errors = [];

        foreach (self::IMMUTABLE_KEYS as $key) {
            $given = $key === 'child_module' && is_array($input[$key] ?? null) ? ($input[$key]['code'] ?? null) : ($input[$key] ?? null);
            $errors[$key] = array_key_exists($key, $input) && $given !== $current[$key] && $this->isComparable($key, $given, $field) ? '追加後は変更できません' : null;
        }

        return [...$this->validated($module, $field, $field->type, $input, $errors), 'child' => null, 'referencedModuleId' => $field->referenced_record_module_id];
    }

    /**
     * 変えられないキーの比較に掛ける値か。型を分ける旗は、持てる型の項目に bool で与えたときだけ(ほかは旗の形の誤り・組の誤り =
     * 「複数行は文字列だけ」「複数はユーザー・組織だけ」が言う)。
     */
    private function isComparable(string $key, mixed $given, RecordField $field): bool
    {
        return ! isset(self::TYPE_FLAGS[$key])
            || (is_bool($given) && in_array($field->type, self::TYPE_FLAGS[$key], true));
    }

    /**
     * 型に依らない属性と、型と属性の組・既定値・選択肢を確かめる。
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string|null>  $errors  先に見つけた誤り
     * @return array{attributes: array<string, mixed>, options: list<array{code: string, name: string}>}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function validated(RecordModule $module, ?RecordField $current, RecordFieldType $type, array $input, array $errors): array
    {
        $attributes = [];

        foreach (['name' => null, 'is_required' => false, 'is_multiline' => false, 'is_multiple' => false, 'max_length' => null, 'default_value' => null, 'is_listed' => false, 'is_searchable' => false, 'is_title' => false, 'grants_row_access' => false, 'sort_key' => 0] as $key => $default) {
            $attributes[$key] = array_key_exists($key, $input) ? $input[$key] : ($current === null ? $default : $current->getAttribute($key));
        }

        foreach ($this->attributeErrors($attributes) as $key => $message) {
            $errors[$key] ??= $message;
        }

        $errors['name'] ??= $this->names->error($module, $current, $attributes['name']);
        $this->throwIfAny($errors);
        $attributes = [...$attributes, ...array_map(fn (mixed $value) => (bool) $value, array_intersect_key($attributes, array_flip(self::FLAGS))), 'sort_key' => $attributes['sort_key'] ?? 0];
        $options = $this->options->validate($type, $current, $input, $errors);
        $combination = $this->combinationErrors($type, $attributes);
        $errors = [...$errors, ...$combination, 'is_title' => $combination['is_title'] ?? $this->secondTitleError($module, $current, $attributes['is_title'])];
        $this->throwIfAny($errors);
        $attributes['default_value'] = $this->defaults->validate($type, $attributes, $options);

        return ['attributes' => $attributes, 'options' => $options];
    }

    /**
     * 型に依らない属性の入力の形の誤り。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function attributeErrors(array $attributes): array
    {
        $errors = ['name' => $this->textError($attributes['name'], '名前'), 'sort_key' => $this->sortKeyError($attributes['sort_key'])];

        foreach (self::FLAGS as $flag) {
            $errors[$flag] = $this->flagError($attributes[$flag]);
        }

        $maxLength = $attributes['max_length'];
        $errors['max_length'] = $maxLength === null || is_int($maxLength) ? null : '最大の文字数は整数で入力してください';

        return $errors;
    }

    /**
     * 型と属性の組の誤り(設計/業務データの入れ物/データモデル/表.md 2.2 の検査制約と同じ規則)。
     *
     * @param  array<string, mixed>  $attributes  形を確かめた属性
     * @return array<string, string|null>
     */
    private function combinationErrors(RecordFieldType $type, array $attributes): array
    {
        $isString = $type === RecordFieldType::String;
        $isDetails = $type === RecordFieldType::Details;
        $violations = [
            'is_required' => [$attributes['is_required'] && $type === RecordFieldType::Boolean, '真偽の項目は必須にできません(常に値があります)'],
            'is_multiline' => [$attributes['is_multiline'] && ! $isString, '複数行は文字列の項目だけに設定できます'],
            'is_multiple' => [
                $attributes['is_multiple'] && ! in_array($type, self::TYPE_FLAGS['is_multiple'], true),
                '複数はユーザー・組織の項目だけに設定できます',
            ],
            'is_listed' => [$attributes['is_listed'] && $isDetails, '明細の項目は一覧に表示できません'],
            'is_searchable' => [$attributes['is_searchable'] && $isDetails, '明細の項目は検索条件にできません'],
            'is_title' => [$attributes['is_title'] && ($attributes['is_multiline'] || ! $isString), '表示の項目は 1 行の文字列の項目だけにできます'],
            'default_value' => [$attributes['default_value'] !== null && ! $type->isPlain(), 'この型の項目には既定値を設定できません'],
        ];

        return [
            ...array_map(fn (array $violation) => $violation[0] ? $violation[1] : null, $violations),
            'max_length' => $this->maxLengthError($isString, $attributes),
            'grants_row_access' => $this->rowAccessError($type, $attributes),
        ];
    }

    /**
     * 関係する人の旗の誤り(ユーザー・組織の項目だけ。データモデル/表.md 2.2 の検査制約と同じ規則)。
     *
     * @param  array<string, mixed>  $attributes  形を確かめた属性
     */
    private function rowAccessError(RecordFieldType $type, array $attributes): ?string
    {
        $isAllowed = in_array($type, self::ROW_ACCESS_TYPES, true);

        return $attributes['grants_row_access'] && ! $isAllowed ? '関係する人はユーザー・組織の項目だけに設定できます' : null;
    }

    /**
     * 最大の文字数の誤り(文字列だけ。範囲 = 項目と規則/README.md 1 節の最後)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function maxLengthError(bool $isString, array $attributes): ?string
    {
        $maxLength = $attributes['max_length'];
        $limit = $attributes['is_multiline']
            ? FieldValueParser::MULTILINE_MAX_LIMIT
            : FieldValueParser::SINGLE_LINE_MAX;

        return match (true) {
            $maxLength === null => null,
            ! $isString => '最大の文字数は文字列の項目だけに設定できます',
            $maxLength < 1 || $maxLength > $limit => "最大の文字数は 1〜{$limit} にしてください",
            default => null,
        };
    }

    /**
     * 業務モジュールに、ほかの表示の項目があるときの誤り(付け替えるには前の項目の旗を先に外す)。
     */
    private function secondTitleError(RecordModule $module, ?RecordField $current, bool $isTitle): ?string
    {
        $other = $module->recordFields->contains(fn (RecordField $field) => $field->is_title && $field->id !== $current?->id);

        return $isTitle && $other ? '表示の項目は業務モジュールに 1 つまでです(前の項目の表示の項目を先に解除してください)' : null;
    }
}
