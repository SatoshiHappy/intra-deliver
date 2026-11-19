<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Enums\Ai\AiToolPartyForm;
use App\Queries\Master\Api\DepartmentTree;
use App\Queries\Master\Api\EffectiveUsers;

/**
 * 書く道具の引数(入れ物の入力の形)の中のユーザー・組織の相手を引いて確かめる部品(docs の 設計/AI/道具と権限/README.md 10.2 のユーザー・組織の名前と
 * id の確かめ)。名前の物 {"name": "…"} を候補の口で引いて入力の形の id に直し、id は共通マスタの口で
 * 確かめる(候補の口で引かない)。今の値に残す相手は確かめない(入れ物の項目と規則の 5 節の書くときの規則と同じ範囲)。今の値の照らし方は欄ごと
 * (項目の値は同じ項目、見せる人は今の参照者、明細は同じ id の行の同じ項目。新しい行は今の値を持たない)。
 *
 * @phpstan-import-type PartyType from ToolPartyFields
 * @phpstan-import-type Found from PartyCandidates
 *
 * @phpstan-type Slot array{field: string, type: 'user'|'department', id: int, byName: bool, current: list<string>}
 */
class ToolInputParties
{
    /** 見せる人の型(ユーザーと組織の両方) */
    private const VIEWER_TYPE = 'user_or_department';

    public function __construct(
        private PartyCandidates $candidates,
        private EffectiveUsers $users,
        private DepartmentTree $tree,
    ) {}

    /**
     * 名前の物を id に直す。誤り(決まらない名前・相手の値の形の誤り)があれば渡した引数のまま。
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, AiToolPartyForm>  $forms  入れ物の入力の形の相手を含む引数の鍵と形
     * @param  array<array-key, mixed>  $definition  実行の始めの定義
     * @param  RecordCurrentValues|null  $current  今の値(直すの 1 段目の後。作ると、呼ばれた時の名前の引き当てでは null)
     * @return array{array<string, mixed>, list<Slot>, list<string>, bool} 直した引数・相手・誤りの文・名前の誤りを含むか
     */
    public function resolve(
        array $arguments,
        array $forms,
        array $definition,
        AgentToolSubject $subject,
        ?RecordCurrentValues $current = null,
    ): array {
        $walk = new ToolInputPartyWalk($this->candidates, $subject);
        $resolved = $arguments;

        foreach ($forms as $key => $form) {
            if (array_key_exists($key, $arguments)) {
                $resolved[$key] = match ($form) {
                    AiToolPartyForm::Input => $this->values(
                        $walk,
                        $arguments[$key],
                        $definition,
                        '',
                        $current?->values,
                    ),
                    AiToolPartyForm::InputDetails => $this->details($walk, $arguments[$key], $definition, $current),
                    AiToolPartyForm::InputRowAccess => $this->rowAccess($walk, $arguments[$key], $current),
                    AiToolPartyForm::Condition => $arguments[$key],
                };
            }
        }

        return $walk->errors === []
            ? [$resolved, $walk->slots, [], false]
            : [$arguments, [], $walk->errors, $walk->nameErrors];
    }

    /**
     * 有効でない・無い id の相手の誤りの文(ユーザー = 基準日に有効か、組織 = 基準日に組織図の木にあるか)。
     *
     * @param  list<Slot>  $slots  確かめる相手
     * @return list<string>
     */
    public function invalid(array $slots, AgentToolSubject $subject): array
    {
        $ids = fn (string $type): array => array_values(array_unique(array_column(
            array_filter($slots, fn (array $slot): bool => $slot['type'] === $type),
            'id',
        )));
        $users = $this->users->idsOn($ids('user'), $subject->date());
        $departments = $this->tree->idsInTreeOn($ids('department'), $subject->date());
        $errors = [];

        foreach ($slots as $slot) {
            $isUser = $slot['type'] === 'user';

            if (! in_array($slot['id'], $isUser ? $users : $departments, true)) {
                $kind = $isUser ? 'ユーザー' : '組織';
                $errors[] = "{$slot['field']} の{$kind} {$slot['id']} は、今日は有効でないか無い。";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * 今の値(欄ごと)に無い相手。
     *
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    public static function notInCurrent(array $slots): array
    {
        return array_values(array_filter(
            $slots,
            fn (array $slot): bool => ! in_array("{$slot['type']}:{$slot['id']}", $slot['current'], true),
        ));
    }

    /**
     * 項目の値(項目のコード → 書く値)の相手を直す。
     *
     * @param  array<array-key, mixed>  $definition
     * @param  array<string, mixed>|null  $current  同じ欄の今の値(画面の初期値の形)
     */
    private function values(
        ToolInputPartyWalk $walk,
        mixed $values,
        array $definition,
        string $place,
        ?array $current,
    ): mixed {
        if (! is_array($values)) {
            return $values;
        }

        foreach (ToolPartyFields::of($definition, false) as $code => $type) {
            if (array_key_exists($code, $values) && $values[$code] !== null) {
                $field = "{$place}項目 `{$code}`";
                $keys = self::currentKeys($current[$code] ?? null, $type);
                $values[$code] = $walk->value($field, $type, $values[$code], $keys);
            }
        }

        return $values;
    }

    /**
     * 明細の行の相手を直す(行の値は項目の値と同じ。今の値は同じ id の行)。
     *
     * @param  array<array-key, mixed>  $definition
     */
    private function details(
        ToolInputPartyWalk $walk,
        mixed $details,
        array $definition,
        ?RecordCurrentValues $current,
    ): mixed {
        if (! is_array($details)) {
            return $details;
        }

        foreach (ToolPartyFields::children($definition) as $code => $child) {
            if (is_array($details[$code] ?? null)) {
                $rows = array_column($current->details[$code] ?? [], 'values', 'id');
                $details[$code] = $this->rows($walk, $code, $details[$code], $child, $rows);
            }
        }

        return $details;
    }

    /**
     * 明細の 1 つの表の行。
     *
     * @param  array<array-key, mixed>  $rows
     * @param  array<array-key, mixed>  $child  明細のモジュールの定義
     * @param  array<int, array<string, mixed>>  $currentRows  今の行の id → 値
     * @return array<array-key, mixed>
     */
    private function rows(ToolInputPartyWalk $walk, string $code, array $rows, array $child, array $currentRows): array
    {
        foreach ($rows as $index => $row) {
            if (is_array($row) && array_key_exists('values', $row)) {
                $id = is_int($row['id'] ?? null) ? $row['id'] : null;
                $place = "明細 `{$code}` の ".((int) $index + 1).' 行目の';
                $currentValues = $currentRows[$id ?? 0] ?? null;
                $rows[$index]['values'] = $this->values($walk, $row['values'], $child, $place, $currentValues);
            }
        }

        return $rows;
    }

    /** 参照制限の見せる人の相手を直す(今の値は今の参照者) */
    private function rowAccess(ToolInputPartyWalk $walk, mixed $rowAccess, ?RecordCurrentValues $current): mixed
    {
        if (! is_array($rowAccess) || ! is_array($rowAccess['viewers'] ?? null)) {
            return $rowAccess;
        }

        $currentKeys = array_map(
            fn (array $viewer): string => "{$viewer['type']}:{$viewer['id']}",
            $current->rowAccess['viewers'] ?? [],
        );
        $rowAccess['viewers'] = $walk->value('見せる人', self::VIEWER_TYPE, $rowAccess['viewers'], $currentKeys);

        return $rowAccess;
    }

    /**
     * 今の値の相手のキー(種類:id)。
     *
     * @param  PartyType  $type
     * @return list<string>
     */
    private static function currentKeys(mixed $value, string $type): array
    {
        $values = is_array($value) && array_is_list($value) ? $value : [$value];

        $keys = array_map(fn (mixed $party): ?string => self::currentKey($party, $type), $values);

        return array_values(array_filter($keys));
    }

    /**
     * @param  PartyType  $type
     */
    private static function currentKey(mixed $party, string $type): ?string
    {
        if (! is_array($party) || ! is_int($party['id'] ?? null)) {
            return null;
        }

        $kind = $type === 'user_or_department' ? ($party['type'] ?? null) : $type;

        return is_string($kind) ? "{$kind}:{$party['id']}" : null;
    }
}
