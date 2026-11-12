<?php

namespace App\Enums\Ai;

use App\Enums\Records\RecordModuleAction;

/**
 * 道具の性質(docs の 設計/AI/道具と権限/README.md 10.1 の性質の表。読むだけかと要る権限から導く)。起きない組は性質を持たない
 * (起動の時に止める = 設計/AI/データと失敗と検査.md 8 節)。
 */
enum AiToolNature: string
{
    case Read = 'read';

    case Create = 'create';

    case Update = 'update';

    case CreateFile = 'create_file';

    /** 読むだけかと要る権限の組の性質。起きない組は null */
    public static function of(bool $readOnly, ?AiToolAction $action): ?self
    {
        return match (true) {
            $readOnly => $action === AiToolAction::View ? self::Read : null,
            $action === AiToolAction::Create => self::Create,
            $action === AiToolAction::Update => self::Update,
            $action === null => self::CreateFile,
            default => null,
        };
    }

    /**
     * 性質が要る業務モジュールの操作(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3)。
     *
     * @return list<RecordModuleAction>
     */
    public function requiredActions(): array
    {
        return match ($this) {
            self::Read => [RecordModuleAction::Read],
            self::Create => [RecordModuleAction::Create],
            self::Update => [RecordModuleAction::Read, RecordModuleAction::Update],
            self::CreateFile => [],
        };
    }
}
