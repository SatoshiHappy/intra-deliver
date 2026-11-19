<?php

namespace App\Actions\Ai\Tools;

use App\Enums\Ai\AiToolPartyForm;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * 作る・直すに共通の引数の形と相手を含む引数(docs の 設計/AI/道具と権限/README.md 10.2 の作る・直すの行。入れ物の登録・更新の入力の形 =
 * 項目の値・明細の行・参照制限の 2 つのキー)。説明の文は {@see RecordWriteToolDescriptions}。
 */
final class RecordWriteSchema
{
    /** 相手を含む引数(項目の値・明細の行・参照制限) */
    public const PARTIES = [
        'values' => AiToolPartyForm::Input,
        'details' => AiToolPartyForm::InputDetails,
        'row_access' => AiToolPartyForm::InputRowAccess,
    ];

    /**
     * @return array<string, Type>
     */
    public static function common(JsonSchema $schema): array
    {
        $texts = RecordWriteToolDescriptions::ARGUMENTS;

        return [
            'module' => $schema->string()->description($texts['module'])->required(),
            'values' => $schema->object()->description($texts['values']),
            'details' => $schema->object()->description($texts['details']),
            'row_access' => $schema->object(fn (JsonSchema $schema): array => [
                'restricted' => $schema->boolean()->description($texts['row_access.restricted']),
                'viewers' => $schema->array()->description($texts['row_access.viewers']),
            ])->description($texts['row_access']),
        ];
    }

    /** 引数の業務モジュール(無いコードもそのまま = 10.3 の B10) */
    public static function moduleOf(mixed $module): ?string
    {
        return is_string($module) ? $module : null;
    }
}
