<?php

namespace App\Queries\Workflow;

use App\Queries\Records\Api\RecordRelations;
use App\Support\MixedValues;
use LogicException;

/**
 * つながる申請の起点(申請書のレコードと申請対象)から、参照先・参照元でたどった先のレコードを、経由する項目ごとに束ねる
 * (docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節の「たどり方」と「引き方」)。レコードの値は返さず、
 * 群の見出しに使う業務モジュールと項目の名前と、たどった先のレコードの id だけを返す。どの口も呼び手が起点の閲覧を判定してから呼ぶ。
 *
 * @phpstan-import-type Bundle from RecordRelations
 *
 * @phpstan-type RelatedLink array{field: string, moduleCode: string, moduleName: string, recordIds: list<int>}
 * @phpstan-type RelatedOrigin array{recordId: int, moduleCode: string, moduleName: string,
 *     referenced: list<RelatedLink>, referencing: list<RelatedLink>}
 */
class RelatedCaseOrigins
{
    public function __construct(private RecordRelations $relations) {}

    /**
     * 申請書のレコードの起点。業務モジュールは、申請の詳細が表示の口で読んだ定義から読む(呼び手が recordAccess を確かめてから呼ぶ)。
     *
     * @param  array{module: array<string, mixed>, record: array<string, mixed>}  $content  表示の口の結果
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @return RelatedOrigin
     */
    public function ofApplication(array $content, array $moduleCodes): array
    {
        return $this->origin(
            MixedValues::intOf($content['record']['id'] ?? null),
            MixedValues::stringOf($content['module']['code'] ?? null),
            MixedValues::stringOf($content['module']['name'] ?? null),
            $moduleCodes,
        );
    }

    /**
     * 申請対象の起点(参照先・参照元の読み取りは主体の判定をしない口なので、呼び手が申請対象の閲覧を確かめてから呼ぶ =
     * 設計/ワークフロー/業務データとの接続/公開の口.md の 7 節)。
     *
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @return RelatedOrigin
     */
    public function ofApplicationTarget(
        int $recordId,
        string $moduleCode,
        string $moduleName,
        array $moduleCodes,
    ): array {
        return $this->origin($recordId, $moduleCode, $moduleName, $moduleCodes);
    }

    /**
     * 起点の参照先と参照元の束(どちらも項目で引く読み取りの並び)。
     *
     * @param  array<int, string>  $moduleCodes
     * @return RelatedOrigin
     */
    private function origin(int $recordId, string $moduleCode, string $moduleName, array $moduleCodes): array
    {
        return [
            'recordId' => $recordId,
            'moduleCode' => $moduleCode,
            'moduleName' => $moduleName,
            'referenced' => self::linksOf($this->relations->referencedByField($recordId), $moduleCodes),
            'referencing' => self::linksOf($this->relations->referencingByField($recordId), $moduleCodes),
        ];
    }

    /**
     * 束を群の材料にする(明細の項目を経由するときは「明細の項目の名前の項目の名前」)。
     *
     * @param  list<Bundle>  $bundles
     * @param  array<int, string>  $moduleCodes
     * @return list<RelatedLink>
     */
    private static function linksOf(array $bundles, array $moduleCodes): array
    {
        return array_map(fn (array $bundle) => [
            'field' => ($bundle['field']['detailFieldName'] === null ? '' : "{$bundle['field']['detailFieldName']}の")
                .$bundle['field']['name'],
            'moduleCode' => $moduleCodes[$bundle['module']['id']]
                ?? throw new LogicException("業務モジュール {$bundle['module']['id']} が親の無い業務モジュールに無い"),
            'moduleName' => $bundle['module']['name'],
            'recordIds' => $bundle['recordIds'],
        ], $bundles);
    }
}
