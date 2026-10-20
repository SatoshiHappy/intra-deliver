<?php

namespace UpdateCheck;

/**
 * npm が JSON で返すエラー({"error": {"code": ..., "summary": ...}} の形)を確認できなかったとして扱う。
 * 429(上限)は理由を「上限」にする(設計/更新チェック.md 5 節)。
 */
final class NpmErrors
{
    /**
     * @param  array<array-key, mixed>  $output
     *
     * @throws UnconfirmedException
     */
    public static function throwIfError(array $output, string $what): void
    {
        $error = $output['error'] ?? null;

        if (! is_array($error) || ! (array_key_exists('code', $error) || array_key_exists('summary', $error)) || array_key_exists('latest', $error)) {
            return;
        }

        $detail = trim(Json::optionalString($error, 'code').' '.Json::optionalString($error, 'summary').' '.Json::optionalString($output, 'message'));
        $limit = str_contains($detail, '429') ? '上限: ' : '';

        throw new UnconfirmedException("{$limit}{$what} がエラーを返した: {$detail}");
    }
}
