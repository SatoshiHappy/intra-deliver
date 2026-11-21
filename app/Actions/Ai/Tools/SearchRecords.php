<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordMarker;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use App\Enums\Ai\AiToolPartyForm;
use App\Queries\Records\Api\RecordListQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 汎用の道具「探す」(docs の 設計/AI/道具と権限/README.md 10.2)。一覧の読み取りを依頼者を主体に 1 回呼び、行の判定で絞った件数とページの行を
 * 返す。読めない条件・並び・項目は口が黙って外すので、外した物を結果で返し({@see SearchIgnored})、モデルが直せるようにする。条件の
 * ユーザー・組織の相手は ③ の共通の部品が名前を引いて id に直し、相手の名前と所属を引数と別に渡すので、当てた条件の相手を結果に写す。判定の後に業務モジュールが消えた競合
 * (見つからない例外)は見つからないにし、閲覧を外された競合(権限の例外)は捕まえずに包みの失敗の結果にする(B10 の注と同じ扱い = 10.3)。
 */
final class SearchRecords implements AgentRecordTool
{
    /** 1 ページの行の数(画面の一覧と同じ) */
    public const PER_PAGE = 20;

    /** 1 つの結果の長さの上限(残りは条件かページで読む = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.7) */
    public const MAX_RESULT_LENGTH = 20_000;

    /** 名前を読めない相手(基準日に有効でない・無い)に添える文。有効でないとは言わない */
    public const UNREADABLE = '名前を読めない';

    public function __construct(private readonly RecordListQuery $lists) {}

    public function name(): string
    {
        return 'ai_search_records';
    }

    public function aliases(): array
    {
        return [];
    }

    public function displayName(): string
    {
        return '検索';
    }

    public function description(AgentToolScope $scope): string
    {
        return RecordToolDescriptions::search($scope);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('業務モジュールのコード。')->required(),
            'conditions' => $schema->object()->description('項目のコードから条件の値へのオブジェクト。'),
            'sort' => $schema->string()->description('並び(項目のコード。降順は頭に - を付ける)。'),
            'page' => $schema->integer()->description('ページの番号(1 から)。'),
            'fields' => $schema->array()->items($schema->string())->description('値を返す項目のコードの列。'),
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isConcurrencySafe(): bool
    {
        return true;
    }

    public function requiredAction(): AiToolAction
    {
        return AiToolAction::View;
    }

    public function targetModules(): ?array
    {
        return null;
    }

    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string
    {
        return null;
    }

    public function maxResultLength(): int
    {
        return self::MAX_RESULT_LENGTH;
    }

    public function largeResultTemplate(): AiInstructionTemplate
    {
        return AiInstructionTemplate::LargeResultSearch;
    }

    public function moduleOf(array $arguments): ?string
    {
        return is_string($arguments['module'] ?? null) ? $arguments['module'] : null;
    }

    public function partyArguments(): array
    {
        return ['conditions' => AiToolPartyForm::Condition];
    }

    public function execute(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): AgentToolResult {
        $module = $this->moduleOf($arguments) ?? '';
        $conditions = is_array($arguments['conditions'] ?? null) ? $arguments['conditions'] : [];
        $sort = is_string($arguments['sort'] ?? null) ? $arguments['sort'] : null;
        $fields = is_array($arguments['fields'] ?? null)
            ? array_values(array_filter($arguments['fields'], is_string(...)))
            : null;
        $definition = $scope->modules[$module]['definition'] ?? [];
        $titleCode = RecordToolValues::titleCode($definition);

        try {
            $listed = $this->lists->list(
                $subject->requester(),
                $module,
                $conditions,
                $sort,
                is_int($arguments['page'] ?? null) ? $arguments['page'] : 1,
                self::PER_PAGE,
                self::fieldCodes($fields, $definition, $titleCode),
                $subject->date(),
                $subject->localeCode(),
            );
        } catch (ModelNotFoundException) {
            return AgentToolResult::notFound();
        }

        ['records' => $records, 'query' => $query] = $listed;
        [$rows, $found] = self::rows($module, $records['data'], $titleCode);
        $used = self::usedParties($parties, $query['filters']);

        return new AgentToolResult([
            'total' => $records['total'],
            'currentPage' => $records['currentPage'],
            'lastPage' => $records['lastPage'],
            'records' => $rows,
            ...($used === [] ? [] : ['parties' => $used]),
            ...SearchIgnored::of($conditions, RecordToolValues::types($definition), $sort, $fields, $query),
        ], $found);
    }

    /**
     * 当てた条件の相手(名前を読めない相手は、名前と所属の代わりに「名前を読めない」とだけ添える。コードは添えない)。
     *
     * @param  array<string, mixed>  $filters  口が当てた条件
     * @return list<array<string, mixed>>
     */
    private static function usedParties(AgentToolParties $parties, array $filters): array
    {
        $used = array_filter($parties->parties, fn (array $party): bool => isset($filters[$party['field']]));

        return array_values(array_map(function (array $party): array {
            $shown = ['field' => $party['field'], 'type' => $party['type'], 'id' => $party['id']];

            return $party['name'] === null
                ? [...$shown, 'note' => self::UNREADABLE]
                : [...$shown, 'name' => $party['name'], 'affiliation' => $party['affiliation']];
        }, $used));
    }

    /**
     * 結果の行と、記録の名前。
     *
     * @param  list<array{id: int, values: array<string, mixed>, updatedAt: mixed}>  $data  口の行
     * @return array{list<array<string, mixed>>, list<array{module: string, id: int, name: string}>}
     */
    private static function rows(string $module, array $data, ?string $titleCode): array
    {
        [$rows, $found] = [[], []];

        foreach ($data as $row) {
            $rows[] = [
                'record' => new RecordMarker($module, $row['id']),
                'id' => $row['id'],
                'values' => RecordToolValues::shown($row['values']),
                'updatedAt' => $row['updatedAt'],
            ];
            $name = RecordToolValues::displayName($row['values'], $titleCode, $row['id']);
            $found[] = ['module' => $module, 'id' => $row['id'], 'name' => $name];
        }

        return [$rows, $found];
    }

    /**
     * 値を返す項目のコード(渡した項目か、無ければ定義の一覧の項目に、表示の項目を足す)。
     *
     * @param  list<string>|null  $fields
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    private static function fieldCodes(?array $fields, array $definition, ?string $titleCode): array
    {
        $codes = $fields ?? [];

        foreach ($fields === null ? RecordToolValues::fields($definition) : [] as $field) {
            $listed = ($field['isListed'] ?? false) === true && is_string($field['code'] ?? null);
            $codes = $listed ? [...$codes, $field['code']] : $codes;
        }

        return array_values(array_unique($titleCode === null ? $codes : [...$codes, $titleCode]));
    }
}
