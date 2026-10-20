<?php

namespace App\Actions\Workflow;

use App\Support\DefinitionText;
use App\Support\InputText;
use Illuminate\Validation\ValidationException;

/**
 * 定義(コンテンツ・ルート・フロー)の入力の確かめ(docs の 設計/ワークフロー/データモデル/表.md 2.1〜2.4 の検査制約・列の長さを、書く前に項目つきで拒む)。
 * 処理対象者・参照者の表示名(対象者の口へ渡す入力)も、表示名の列(authz_subject_group_translations.display_name = varchar(1000))の長さを名前と同じ規則で先に確かめる
 * (定義の入力は DB の制約より前に業務処理が確かめて 422 = 直列化と検査.md 6 節「定義を作る業務処理」)。
 * 規則と文言は業務データの入れ物と共有の {@see DefinitionText} へ委譲する(予約語は業務モジュール・項目のコードのもので、定義のコードには掛けない)。
 * ここに残すのは、同じコードの重複の確かめ・入力の変換(前後の空白)・誤りをまとめて投げることだけ。
 */
trait ValidatesWorkflowDefinition
{
    /**
     * 前後の空白を除き、空なら null(本体は {@see InputText::normalize()}。業務データの入れ物の入力の変換と同じ)。
     */
    private function normalized(?string $text): ?string
    {
        $normalized = InputText::normalize($text);

        return is_string($normalized) ? $normalized : null;
    }

    /**
     * コードの誤り(無ければ null)。$isTaken = 同じコードが既にあるか(書式が正しいときだけ見る)。
     *
     * @param  callable(string): bool  $isTaken
     */
    private function codeError(?string $code, callable $isTaken, string $takenMessage = 'このコードは既に使用されています'): ?string
    {
        return DefinitionText::codeError($code) ?? ($code !== null && $isTaken($code) ? $takenMessage : null);
    }

    /**
     * 名前の誤り(無ければ null)。
     */
    private function textError(?string $text, string $label): ?string
    {
        return DefinitionText::textError($text, $label);
    }

    /**
     * 処理対象者・参照者の表示名の誤り(ロケールごと。キー = <$key>.<ロケールのコード>)。表示名が無い・無いロケール・空の名前は対象者の口が確かめる。
     *
     * @param  array<string, string>  $translations  表示名(ロケールのコード → 名前)
     * @return array<string, string|null>
     */
    private function translationErrors(array $translations, string $key, string $label): array
    {
        $errors = [];

        foreach ($translations as $localeCode => $displayName) {
            $errors["{$key}.{$localeCode}"] = $this->textError($displayName, $label);
        }

        return $errors;
    }

    /**
     * 説明の誤り(無ければ null。空 = null は誤りでない)。
     */
    private function descriptionError(?string $description): ?string
    {
        return DefinitionText::descriptionError($description);
    }

    /**
     * 並び順の誤り(無ければ null)。
     */
    private function sortKeyError(int $sortKey): ?string
    {
        return DefinitionText::sortKeyError($sortKey);
    }

    /**
     * @param  array<string, string|null>  $errors  入力の名前 → 文言(null は誤りなし)
     *
     * @throws ValidationException 誤りがあるとき
     */
    private function throwIfAny(array $errors): void
    {
        $errors = array_filter($errors, fn (?string $message) => $message !== null);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }
    }
}
