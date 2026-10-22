<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;

/**
 * 業務モジュールの項目の見出しのまとまり(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 1 節)。
 * 見出しは自分の sort_key 以上の項目を次の見出しの前まで束ねる(同じ値なら見出しが先)。最初の見出しより前の項目は見出しなしの頭のまとまり。
 * 明細の項目は見出しに属さず数えない。項目が 0 の見出しと、項目が 0 の頭のまとまりは出さない。管理の画面は見出しと項目の 1 本の並びと、
 * 見出しごとの項目の数も要る({@see self::adminProps()})。並びは見出しも項目も sort_key、次に id。
 *
 * @phpstan-type HeadingGroup array{id: int|null, name: string|null, fieldCodes: list<string>}
 * @phpstan-type ListItem array{kind: 'heading', id: int}|array{kind: 'field', code: string}
 */
class RecordHeadingGroups
{
    /**
     * 詳細とフォームのまとまり(並びの順)。頭のまとまりは id・name とも null。見出しの無い業務モジュールは全部が頭のまとまり。
     *
     * @return list<HeadingGroup>
     */
    public function groups(int $recordModuleId): array
    {
        // 頭のまとまりから始め、見出しに出会うたびに閉じる。項目が 0 のまとまり(項目が 0 の見出し・頭)は最後に除く
        $groups = [];
        [$id, $name, $codes] = [null, null, []];

        foreach ($this->ordered($recordModuleId) as $row) {
            if ($row instanceof RecordFieldHeading) {
                $groups[] = ['id' => $id, 'name' => $name, 'fieldCodes' => $codes];
                [$id, $name, $codes] = [$row->id, $row->name, []];
            } elseif ($row->type !== RecordFieldType::Details) {
                $codes[] = $row->code;
            }
        }

        $groups[] = ['id' => $id, 'name' => $name, 'fieldCodes' => $codes];

        return array_values(array_filter($groups, fn (array $group) => $group['fieldCodes'] !== []));
    }

    /**
     * 管理の画面の props: 見出し(id・名前・項目の数。項目が 0 の見出しも出す)と、見出しと項目の 1 本の並び(明細の項目も含む)。
     *
     * @return array{headings: list<array{id: int, name: string, fieldCount: int}>, items: list<ListItem>}
     */
    public function adminProps(int $recordModuleId): array
    {
        $names = [];
        $counts = [];
        $items = [];
        $currentId = null;

        foreach ($this->ordered($recordModuleId) as $row) {
            if ($row instanceof RecordFieldHeading) {
                [$names[$row->id], $counts[$row->id], $currentId] = [$row->name, 0, $row->id];
                $items[] = ['kind' => 'heading', 'id' => $row->id];

                continue;
            }

            $items[] = ['kind' => 'field', 'code' => $row->code];

            if ($currentId !== null && $row->type !== RecordFieldType::Details) {
                $counts[$currentId]++;
            }
        }

        $headings = array_map(
            fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'fieldCount' => $counts[$id]],
            array_keys($names),
            $names,
        );

        return ['headings' => $headings, 'items' => $items];
    }

    /**
     * 見出しと項目を 1 本の並びにする(sort_key、同じ値なら見出しが先、次に id)。
     *
     * @return list<RecordField|RecordFieldHeading>
     */
    private function ordered(int $recordModuleId): array
    {
        $headings = RecordFieldHeading::query()->where('record_module_id', $recordModuleId)
            ->get(['id', 'name', 'sort_key']);
        $fields = RecordField::query()->where('record_module_id', $recordModuleId)
            ->get(['id', 'code', 'type', 'sort_key']);
        $rows = [...$headings->all(), ...$fields->all()];
        $position = fn (RecordField|RecordFieldHeading $row): array => [
            $row->sort_key, $row instanceof RecordField ? 1 : 0, $row->id,
        ];
        usort($rows, fn (RecordField|RecordFieldHeading $a, RecordField|RecordFieldHeading $b) => (
            $position($a) <=> $position($b)
        ));

        return $rows;
    }
}
