<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * 書く道具の参照制限の 2 つのキーの書く値を、入れ物の W 表で決める部品(docs の 設計/AI/道具と権限/README.md 10.2 の参照制限。W 表 =
 * 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 のチェックと参照者の書き込み)。元のチェックと元の参照者は、作る = 業務モジュールの既定と空、
 * 直す = 1 段目で読んだレコードの今の値。決めた書く値は、渡さなかったキーを含めて 2 つのキーとも持つ(確認の内容に出し、書く時に両方渡す)。
 * W3・W8 は確認を出さずに断る(作る = B1、直す = B19)。
 *
 * @phpstan-type Viewer array{type: 'user'|'department', id: int}
 * @phpstan-type RowAccess array{restricted: bool, viewers: list<Viewer>}
 */
final class ToolRowAccessWrites
{
    /** W3・W8 の誤りの文(入れ物の 422 `row_access_viewers` と同じ組) */
    public const VIEWERS_WITHOUT_RESTRICTION = '参照を制限しないレコードに見せる人は置けない。見せる人を置くなら、row_access の restricted を'
        .' true にするか、参照を制限するレコードにする。';

    /**
     * 書く値(W3・W8 なら null)。
     *
     * @param  array<array-key, mixed>|null  $input  引数の参照制限(渡さなければ null。形は引数の形の検証を通った物)
     * @param  list<Viewer>  $originViewers
     * @return RowAccess|null
     */
    public static function decide(?array $input, bool $origin, array $originViewers): ?array
    {
        $restricted = is_bool($input['restricted'] ?? null) ? $input['restricted'] : null;
        $viewers = is_array($input['viewers'] ?? null) ? self::viewers($input['viewers']) : null;

        return match ($restricted) {
            null => self::withoutCheck($viewers, $origin, $originViewers),
            true => ['restricted' => true, 'viewers' => $viewers ?? ($origin ? $originViewers : [])],
            false => $viewers === null || $viewers === [] ? ['restricted' => false, 'viewers' => []] : null,
        };
    }

    /**
     * チェックのキーが無い行(W1a・W1b・W2・W3・W4)。
     *
     * @param  list<Viewer>|null  $viewers
     * @param  list<Viewer>  $originViewers
     * @return RowAccess|null
     */
    private static function withoutCheck(?array $viewers, bool $origin, array $originViewers): ?array
    {
        return match (true) {
            $viewers === null => ['restricted' => $origin, 'viewers' => $origin ? $originViewers : []],
            $viewers === [] => ['restricted' => $origin, 'viewers' => []],
            $origin => ['restricted' => true, 'viewers' => $viewers],
            default => null,
        };
    }

    /**
     * 見せる人の相手(id の形の物だけ。名前の物は前の段で id に直っている)。
     *
     * @param  array<array-key, mixed>  $viewers
     * @return list<Viewer>
     */
    private static function viewers(array $viewers): array
    {
        $found = [];

        foreach ($viewers as $viewer) {
            $type = is_array($viewer) ? ($viewer['type'] ?? null) : null;
            $id = is_array($viewer) ? ($viewer['id'] ?? null) : null;

            if (($type === 'user' || $type === 'department') && is_int($id)) {
                $found[] = ['type' => $type, 'id' => $id];
            }
        }

        return $found;
    }
}
