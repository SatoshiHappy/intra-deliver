<?php

namespace Tests\Architecture\Engine;

use JsonException;
use UnexpectedValueException;

/**
 * phpstan の JSON 出力(--error-format=json)を指摘({@see Finding})の一覧に変換する(汎用)。
 *
 * 規則 ID = 指摘の identifier、ファイル = 報告されたパス、行 = 報告された行。
 * ファイルに結び付かない全体のエラー(設定の誤りなど)は規則 ID {@see self::GENERAL_ERROR} の指摘にする(黙って捨てない)。
 */
final class PhpStanFindings
{
    public const GENERAL_ERROR = 'phpstan.generalError';

    public const MISSING_IDENTIFIER = 'phpstan.missingIdentifier';

    /**
     * @return list<Finding>
     *
     * @throws JsonException JSON として読めないとき
     * @throws UnexpectedValueException 形が違うとき(files・errors が無い・型が違う)
     */
    public static function fromJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null) || ! is_array($decoded['errors'] ?? null)) {
            throw new UnexpectedValueException('phpstan の JSON 出力の形ではない(files・errors が無い)');
        }

        $findings = [];

        foreach ($decoded['files'] as $file => $entry) {
            if (! is_array($entry) || ! is_array($entry['messages'] ?? null)) {
                throw new UnexpectedValueException("phpstan の JSON 出力のファイル {$file} に messages が無い");
            }

            foreach ($entry['messages'] as $message) {
                $findings[] = self::fromMessage((string) $file, $message);
            }
        }

        foreach ($decoded['errors'] as $error) {
            if (! is_string($error)) {
                throw new UnexpectedValueException('phpstan の JSON 出力の errors が文字列でない: '.json_encode($error));
            }

            $findings[] = new Finding(self::GENERAL_ERROR, '(全体)', null);
        }

        return $findings;
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function fromMessage(string $file, mixed $message): Finding
    {
        if (! is_array($message) || ! is_string($message['message'] ?? null)) {
            throw new UnexpectedValueException("phpstan の JSON 出力の指摘に message が無い({$file}): ".json_encode($message));
        }

        $line = $message['line'] ?? null;
        $identifier = $message['identifier'] ?? self::MISSING_IDENTIFIER;

        if (($line !== null && ! is_int($line)) || ! is_string($identifier)) {
            throw new UnexpectedValueException("phpstan の JSON 出力の指摘の line・identifier の型が違う({$file}): ".json_encode($message));
        }

        return new Finding($identifier, $file, $line);
    }
}
