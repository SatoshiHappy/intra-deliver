<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Queries\Records\Api\RecordFormQuery;

/**
 * 作る・直すの確認の内容を作る部品(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の確認の内容)。書く値は画面の初期値の形にする
 * (`RecordFormQuery::draftValues`。依頼者で判定した表示名で、見えない参照先は表示名なし = 画面が `#<id>` を出す)。前の件を指す参照の値は
 * 口に渡さず、そのまま書く値に置く(k は ③ の共通の部品が足す)。今の値は 1 段目で読んだ編集の初期値の値。相手は、書く値の相手(③ の共通の
 * 部品が読んだ物)と今の値の相手を、種類と id で 1 つずつ並べる。見られる人が変わる件の印は、実行の始めの定義の関係者の印で付ける。
 *
 * @phpstan-import-type CurrentParty from RecordCurrentValues
 *
 * @phpstan-type Shown array<string, mixed>
 */
class RecordConfirmations
{
    public function __construct(private readonly RecordFormQuery $forms) {}

    /**
     * @param  array<string, mixed>  $arguments  書く値を決めた引数(相手は id、参照制限は 2 つのキーとも決めた物)
     * @param  RecordCurrentValues|null  $current  直すの今の値(作るは null)
     */
    public function confirmation(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
        ?RecordCurrentValues $current,
    ): RecordConfirmation {
        $module = is_string($arguments['module'] ?? null) ? $arguments['module'] : '';
        $definition = $scope->modules[$module]['definition'] ?? [];
        $values = is_array($arguments['values'] ?? null) ? $arguments['values'] : [];
        $details = is_array($arguments['details'] ?? null) ? $arguments['details'] : [];
        $shown = $this->forms->draftValues(
            $subject->requester(),
            $module,
            RecordConfirmationValues::withoutReferences($values),
            RecordConfirmationValues::detailsWithoutReferences($details),
            $subject->date(),
            $subject->localeCode(),
        );
        $rowAccess = self::rowAccess($arguments, $scope, $module, $current);
        $names = RecordConfirmationValues::names($definition);

        return new RecordConfirmation([
            'target' => self::target($module, $scope, $current),
            'fields' => RecordConfirmationValues::fields($values, $shown['values'], $names, $current),
            'details' => RecordConfirmationValues::details($details, $shown['details'], $names, $current),
            'rowAccess' => $rowAccess,
            'parties' => self::parties($parties, $current, $definition),
            'audienceChanges' => RecordConfirmationAudience::changes(
                $definition,
                $values,
                $details,
                $rowAccess,
                $current,
            ),
        ]);
    }

    /**
     * 書く先(作る = 新しいレコード、直す = id と表示の名前)。
     *
     * @return array<string, mixed>
     */
    private static function target(string $module, AgentToolScope $scope, ?RecordCurrentValues $current): array
    {
        $moduleName = $scope->modules[$module]['name'] ?? $module;

        return $current === null
            ? ['module' => $module, 'moduleName' => $moduleName, 'name' => "新しい {$moduleName}"]
            : ['module' => $module, 'moduleName' => $moduleName, 'id' => $current->id, 'name' => $current->name];
    }

    /**
     * 参照制限の今の値と書く値(作るの今の値 = 業務モジュールの既定と空の見せる人)。見せる人は種類と id。
     *
     * @param  array<string, mixed>  $arguments
     * @return array{current: array{restricted: bool, viewers: list<array{type: string, id: int}>},
     *     next: array{restricted: bool, viewers: list<array{type: string, id: int}>}}|null
     */
    private static function rowAccess(
        array $arguments,
        AgentToolScope $scope,
        string $module,
        ?RecordCurrentValues $current,
    ): ?array {
        $next = is_array($arguments['row_access'] ?? null) ? $arguments['row_access'] : null;

        if ($next === null) {
            return null;
        }

        $currentAccess = $current === null
            ? ['restricted' => $scope->rowAccessDefault($module), 'viewers' => []]
            : [
                'restricted' => $current->rowAccess['restricted'],
                'viewers' => RecordConfirmationValues::keysOf($current->rowAccess['viewers']),
            ];
        $viewers = is_array($next['viewers'] ?? null) ? $next['viewers'] : [];

        return ['current' => $currentAccess, 'next' => [
            'restricted' => ($next['restricted'] ?? false) === true,
            'viewers' => RecordConfirmationValues::keysOf(array_values(array_filter($viewers, is_array(...)))),
        ]];
    }

    /**
     * 書く値と今の値の相手(種類と id で 1 つずつ。書く値の相手を先に)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<array{type: string, id: int, code: string|null, name: string|null, affiliation: string|null}>
     */
    private static function parties(AgentToolParties $parties, ?RecordCurrentValues $current, array $definition): array
    {
        $found = [];

        foreach ($parties->parties as $party) {
            $found["{$party['type']}:{$party['id']}"] ??= [
                'type' => $party['type'],
                'id' => $party['id'],
                'code' => $party['code'],
                'name' => $party['name'],
                'affiliation' => $party['affiliation'],
            ];
        }

        $held = $current === null ? [] : RecordConfirmationValues::currentParties($current, $definition);

        foreach ($held as $key => $party) {
            $found[$key] ??= $party;
        }

        return array_values($found);
    }
}
