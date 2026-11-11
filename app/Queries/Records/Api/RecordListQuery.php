<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordListing;
use App\Queries\Records\RecordPageQuery;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 業務モジュールのコードで受ける一覧の読み取り(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の RecordListQuery の行)。判定は画面の一覧と同じ口
 * ({@see RecordPageQuery::open()})を通り、数えて並べるのは画面と同じ 1 か所({@see RecordListing::listed()})。
 *
 * @phpstan-import-type ListedRecords from RecordListing
 *
 * @phpstan-type ListedQuery array{filters: array<string, mixed>, sort: string, fields: list<string>}
 */
class RecordListQuery
{
    public function __construct(
        private RecordPageQuery $pages,
        private RecordListing $listing,
    ) {}

    /**
     * 業務モジュールを引き(無い・明細のモジュールは 404)、read を判定し(403)、行の判定で絞って数えて並べる。鍵を取らない。
     *
     * @param  array<array-key, mixed>  $conditions  条件(項目のコード → 値。画面のクエリの filter と同じ形)
     * @param  string|null  $sort  並び(画面のクエリの sort と同じ文字列。null = 既定 = 新しい順)
     * @param  int  $page  引きたいページ(最後のページに詰める。1 未満は 1)
     * @param  array<array-key, mixed>|null  $fieldCodes  値を返す項目のコード(null = 一覧の項目。明細の項目・無いコードは外す)
     * @return array{records: ListedRecords, query: ListedQuery}
     *
     * @throws LogicException $perPage が 1 未満のとき(呼び手の誤り)
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException read を許されないとき
     */
    public function list(
        Requester $requester,
        string $moduleCode,
        array $conditions,
        ?string $sort,
        int $page,
        int $perPage,
        ?array $fieldCodes,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        PageNumbers::throwIfPerPageBelowOne($perPage);
        $module = $this->pages->open($requester, $moduleCode, $date, RecordModuleAction::Read);
        $fields = $fieldCodes === null ? $this->listing->listedFields($module) : $this->fieldsOf($module, $fieldCodes);
        $listed = $this->listing
            ->listed($requester, $module, $conditions, $sort, $page, $perPage, $fields, $date, $localeCode);
        $codes = array_values($fields->map(fn (RecordField $field) => $field->code)->all());

        return ['records' => $listed['records'], 'query' => [...$listed['query'], 'fields' => $codes]];
    }

    /**
     * 渡したコードの項目(項目の並び。明細の項目・無いコード・文字列でない値は外す)。
     *
     * @param  array<array-key, mixed>  $fieldCodes
     * @return Collection<int, RecordField>
     */
    private function fieldsOf(RecordModule $module, array $fieldCodes): Collection
    {
        return $module->recordFields
            ->filter(fn (RecordField $field) => $field->type !== RecordFieldType::Details
                && in_array($field->code, $fieldCodes, true))
            ->values();
    }
}
