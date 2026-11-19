<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordMarker;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;

/**
 * 書く道具が入れ物の判定する登録・更新を呼ぶ 1 か所(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の「実行」の後の書き方と
 * 更新履歴)。登録・更新の口が変わるときに付け替える所を 1 つにするため。書く値の引数を入れ物の入力に直し、版の契機を付ける(操作した人と
 * 更新者は依頼者本人なので、実行者を渡さない)。更新は持ち主の鍵を渡さない。
 */
class RecordToolWrites
{
    /** 版の契機の処理の種別(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節の語) */
    public const OPERATION = 'AI(AI エージェント)';

    public function __construct(private readonly CreateRecord $create, private readonly UpdateRecord $update) {}

    /**
     * @param  array<string, mixed>  $written
     * @return int 作ったレコードの id
     */
    public function create(AgentToolSubject $subject, string $module, array $written): int
    {
        $origin = new RecordRevisionOrigin(self::OPERATION);

        return $this->create->execute($subject->accessContext(), $module, self::input($written), $origin);
    }

    /**
     * @param  array<string, mixed>  $written
     */
    public function update(AgentToolSubject $subject, string $module, int $id, array $written, string $version): void
    {
        $origin = new RecordRevisionOrigin(self::OPERATION);
        $input = self::input($written);

        $this->update->execute($subject->accessContext(), $module, $id, $input, $version, null, $origin);
    }

    /**
     * 書いた結果(書く先の印と書けたこと = 10.1)。表示の名前は書く値の表示の項目の値、無ければ $fallback(直すの書く先の名前)か `#<id>`
     * (作るは閲覧の無い依頼者がいるので、書いた後に読み直さない)。
     *
     * @param  array<string, mixed>  $written
     * @param  array<array-key, mixed>  $definition  実行の始めの定義
     */
    public static function result(
        string $module,
        int $id,
        array $written,
        array $definition,
        ?string $fallback,
    ): AgentToolResult {
        $values = is_array($written['values'] ?? null) ? $written['values'] : [];
        $titleCode = RecordToolValues::titleCode($definition);
        $title = $titleCode === null ? null : ($values[$titleCode] ?? null);
        $name = is_string($title) && $title !== '' || $fallback === null
            ? RecordToolValues::displayName($values, $titleCode, $id)
            : $fallback;

        return new AgentToolResult(
            ['record' => new RecordMarker($module, $id), 'written' => true],
            [['module' => $module, 'id' => $id, 'name' => $name]],
        );
    }

    /**
     * 書く値の引数(項目の値・明細の行・参照制限の 2 つのキー)を入れ物の入力にする。形は包みの引数の形の検証を通った物。
     *
     * @param  array<string, mixed>  $written
     */
    private static function input(array $written): RecordInput
    {
        $values = is_array($written['values'] ?? null) ? $written['values'] : [];
        $rowAccess = is_array($written['row_access'] ?? null) ? $written['row_access'] : [];

        return new RecordInput(
            $values,
            self::details(is_array($written['details'] ?? null) ? $written['details'] : []),
            rowAccessRestricted: $rowAccess['restricted'] ?? null,
            rowAccessViewers: $rowAccess['viewers'] ?? null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $details
     * @return array<array-key, list<RecordDetailRowInput>>
     */
    private static function details(array $details): array
    {
        $input = [];

        foreach ($details as $code => $rows) {
            $input[$code] = array_map(static fn (mixed $row): RecordDetailRowInput => new RecordDetailRowInput(
                is_array($row) && is_int($row['id'] ?? null) ? $row['id'] : null,
                is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [],
            ), is_array($rows) ? array_values($rows) : []);
        }

        return $input;
    }
}
