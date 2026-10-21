<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordScreenAddons;
use App\Support\AccessContext;

/**
 * テストの中だけで使う、一覧と詳細に足す実装(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5 の口)。決めた操作・札・注を返し、
 * 呼ばれた回数と badges() に渡った行の id を覚える。結果は口の型に反した値も渡せる(Records が形を確かめるのを見るため)。
 * 行の札は、渡された行の id のうち $badges に載せたものだけを返す($badgesForAnyId なら渡していない id も返す)。
 */
final class FixedRecordScreenAddons implements RecordScreenAddons
{
    public int $indexCalls = 0;

    public int $showCalls = 0;

    /** @var list<list<int>> badges() に渡った行の id(呼ばれた順) */
    public array $badgeCalls = [];

    /**
     * @param  mixed  $index  index() の結果
     * @param  array<int, mixed>  $badges  行の id → 札の一覧
     * @param  mixed  $show  show() の結果
     */
    public function __construct(
        private string $moduleCode,
        private mixed $index = ['actions' => []],
        private array $badges = [],
        private mixed $show = ['actions' => [], 'badges' => [], 'fieldNotes' => []],
        private bool $badgesForAnyId = false,
    ) {}

    public function moduleCode(): string
    {
        return $this->moduleCode;
    }

    public function index(AccessContext $context): array
    {
        $this->indexCalls++;

        return $this->index;
    }

    public function badges(AccessContext $context, array $recordIds): array
    {
        $this->badgeCalls[] = $recordIds;

        return $this->badgesForAnyId
            ? $this->badges
            : array_intersect_key($this->badges, array_flip($recordIds));
    }

    public function show(AccessContext $context, int $recordId): array
    {
        $this->showCalls++;

        return $this->show;
    }
}
