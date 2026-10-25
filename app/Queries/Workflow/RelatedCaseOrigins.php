<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordRelations;
use App\Support\MixedValues;
use DateTimeInterface;

/**
 * つながる申請の起点(申請書のレコードと申請対象)から、参照先・参照元でたどった先のレコードを、経由する項目ごとに束ねる
 * (docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節の「たどり方」と「引き方」)。レコードの値は返さず、
 * 群の見出しに使う業務モジュールと項目の名前と、たどった先のレコードの id だけを返す。どの口も呼び手が起点の閲覧を判定してから呼ぶ。
 *
 * @phpstan-type RelatedLink array{field: string, moduleCode: string, moduleName: string, recordIds: list<int>}
 * @phpstan-type RelatedOrigin array{recordId: int, moduleCode: string, moduleName: string,
 *     referenced: list<RelatedLink>, referencing: list<RelatedLink>}
 */
class RelatedCaseOrigins
{
    public function __construct(
        private RecordFormQuery $forms,
        private RecordRelations $relations,
    ) {}

    /**
     * 申請書のレコードの起点。参照先は、申請の詳細が表示の口で読んだ定義と値から読む(呼び手が recordAccess を確かめてから呼ぶ)。
     *
     * @param  array{module: array<string, mixed>, record: array<string, mixed>}  $content  表示の口の結果
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @return RelatedOrigin
     */
    public function ofApplication(array $content, array $moduleCodes): array
    {
        $record = $content['record'];
        $recordId = MixedValues::intOf($record['id'] ?? null);

        return [
            'recordId' => $recordId,
            'moduleCode' => MixedValues::stringOf($content['module']['code'] ?? null),
            'moduleName' => MixedValues::stringOf($content['module']['name'] ?? null),
            'referenced' => $this->referenced(
                $content['module'],
                MixedValues::arrayOf($record['values'] ?? null),
                MixedValues::arrayOf($record['details'] ?? null),
            ),
            'referencing' => $this->referencing($recordId, $moduleCodes),
        ];
    }

    /**
     * 申請対象の起点。項目の名前は定義(read)、参照の値は editValues から読む(主体の判定をしない口なので、呼び手が
     * 申請対象の閲覧を確かめてから呼ぶ = 設計/ワークフロー/業務データとの接続/公開の口.md の 7 節)。
     *
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @return RelatedOrigin
     */
    public function ofApplicationTarget(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
        string $localeCode,
        array $moduleCodes,
    ): array {
        $definition = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Read, $date);
        $values = $this->forms->editValues($requester, $moduleCode, $recordId, $date, $localeCode);

        return [
            'recordId' => $recordId,
            'moduleCode' => $moduleCode,
            'moduleName' => $definition['name'],
            'referenced' => $this->referenced($definition, $values['values'], $values['details']),
            'referencing' => $this->referencing($recordId, $moduleCodes),
        ];
    }

    /**
     * 参照先の束(並びは 設計/ワークフロー/業務データとの接続/写しとつながる申請.md の 6 節)。指す先の無い項目は返さない。
     *
     * @param  array<array-key, mixed>  $definition  業務モジュールの定義(fields を持つ)
     * @param  array<array-key, mixed>  $values  項目のコード → 画面の値
     * @param  array<array-key, mixed>  $details  明細の項目のコード → 行(values を持つ)の並び
     * @return list<RelatedLink>
     */
    private function referenced(array $definition, array $values, array $details): array
    {
        $links = [];

        foreach (self::fieldsOf($definition) as $field) {
            $code = $field['code'] ?? null;
            $links = match ($field['type'] ?? null) {
                RecordFieldType::Record->value => [...$links, ...self::linkOf($field, '', [$values[$code] ?? null])],
                RecordFieldType::Details->value => [
                    ...$links,
                    ...$this->detailLinks($field, MixedValues::arrayOf($details[$code] ?? null)),
                ],
                default => $links,
            };
        }

        return $links;
    }

    /**
     * 明細の項目の、明細のモジュールの他モジュール参照の項目ごとの束(全行の値をまとめる)。
     *
     * @param  array<array-key, mixed>  $detailsField
     * @param  array<array-key, mixed>  $rows
     * @return list<RelatedLink>
     */
    private function detailLinks(array $detailsField, array $rows): array
    {
        $links = [];
        $prefix = MixedValues::stringOf($detailsField['name'] ?? null).'の';

        foreach (self::fieldsOf(MixedValues::arrayOf($detailsField['childModule'] ?? null)) as $field) {
            if (($field['type'] ?? null) === RecordFieldType::Record->value) {
                $code = $field['code'] ?? null;
                $cells = array_map(
                    fn (mixed $row) => MixedValues::arrayOf(
                        MixedValues::arrayOf($row)['values'] ?? null,
                    )[$code] ?? null,
                    $rows,
                );
                $links = [...$links, ...self::linkOf($field, $prefix, $cells)];
            }
        }

        return $links;
    }

    /**
     * 参照元の束(参照元を項目で引く読み取りの並び。明細の項目から指すときは「明細の項目の名前の項目の名前」)。
     *
     * @param  array<int, string>  $moduleCodes
     * @return list<RelatedLink>
     */
    private function referencing(int $recordId, array $moduleCodes): array
    {
        $links = [];

        foreach ($this->relations->referencingByField($recordId) as $bundle) {
            $detailName = $bundle['field']['detailFieldName'];
            $links[] = [
                'field' => ($detailName === null ? '' : "{$detailName}の").$bundle['field']['name'],
                'moduleCode' => $moduleCodes[$bundle['module']['id']] ?? '',
                'moduleName' => $bundle['module']['name'],
                'recordIds' => $bundle['recordIds'],
            ];
        }

        return $links;
    }

    /**
     * 他モジュール参照の項目 1 つの束(値の相手の id。重複を除き、出た順)。相手が無ければ空(他モジュール参照は複数を選べない)。
     *
     * @param  array<array-key, mixed>  $field
     * @param  array<array-key, mixed>  $cells  画面の値(相手 1 つの値か null)の並び
     * @return list<RelatedLink>
     */
    private static function linkOf(array $field, string $prefix, array $cells): array
    {
        $ids = array_filter(array_map(
            fn (mixed $cell) => MixedValues::intOf(MixedValues::arrayOf($cell)['id'] ?? null),
            $cells,
        ));
        $module = MixedValues::arrayOf($field['referencedModule'] ?? null);

        return $ids === [] ? [] : [[
            'field' => $prefix.MixedValues::stringOf($field['name'] ?? null),
            'moduleCode' => MixedValues::stringOf($module['code'] ?? null),
            'moduleName' => MixedValues::stringOf($module['name'] ?? null),
            'recordIds' => array_values(array_unique($ids)),
        ]];
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return list<array<array-key, mixed>>
     */
    private static function fieldsOf(array $definition): array
    {
        return array_values(array_filter(MixedValues::arrayOf($definition['fields'] ?? null), is_array(...)));
    }
}
