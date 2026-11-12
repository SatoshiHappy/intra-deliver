<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Str;

/**
 * 会話の型の道具の全体 = タグ {@see AgentTool::TAG} で束ねた実装を引く 1 か所(内部。docs の 設計/AI/道具と権限/README.md 10.1 の置き場・名前・並び)。
 * 道具が汎用か製品か・名前の頭は、道具のクラスの名前空間の頭の対応で決める(いちばん長い頭の対応を使う)。登録の食い違い({@see self::problems()})は
 * 起動の時の確かめ(PromptKinds::problems())が出す。
 *
 * @phpstan-type NamespacePrefix array{namespace: string, prefix: string|null, generic: bool}
 */
class AgentTools
{
    /**
     * 既定の頭の対応(汎用 = Ai の道具の名前空間 → ai_、製品 = App\Actions\<M>\ → <M> の蛇の形 + _。prefix が null の対応は次の段の名前から作る)。
     *
     * @var list<NamespacePrefix>
     */
    public const DEFAULT_NAMESPACES = [
        ['namespace' => 'App\Actions\Ai\Tools\\', 'prefix' => 'ai_', 'generic' => true],
        ['namespace' => 'App\Actions\\', 'prefix' => null, 'generic' => false],
    ];

    /**
     * @param  iterable<AgentTool>  $tools
     * @param  list<NamespacePrefix>  $namespaces  名前空間の頭の対応(テストが偽の道具の分を渡す)
     */
    public function __construct(
        #[Tag(AgentTool::TAG)] private iterable $tools,
        private array $namespaces = self::DEFAULT_NAMESPACES,
    ) {}

    /**
     * 登録した道具(汎用を名前の順に頭に置き、製品を名前の順に続ける = 10.1 の渡す道具の並び)。
     *
     * @return list<AgentTool>
     */
    public function all(): array
    {
        $tools = $this->registered();
        usort($tools, fn (AgentTool $left, AgentTool $right): int => [! $this->isGeneric($left), $left->name()]
            <=> [! $this->isGeneric($right), $right->name()]);

        return $tools;
    }

    /**
     * 登録の順の道具。
     *
     * @return list<AgentTool>
     */
    public function registered(): array
    {
        $tools = [];

        foreach ($this->tools as $tool) {
            $tools[] = $tool;
        }

        return $tools;
    }

    /** 名前か別名の道具(承認待ちの件を引く = 9.3)。無ければ null */
    public function find(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name || in_array($name, $tool->aliases(), true)) {
                return $tool;
            }
        }

        return null;
    }

    /** 名前の道具(別名では当てない = 10.3 の A5)。無ければ null */
    public function named(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * 登録の食い違い(起動の時の確かめ = docs の 設計/AI/データと失敗と検査.md 8 節)。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return (new ToolChecks($this))->problems();
    }

    /** 汎用の道具か(頭の対応に当たらない道具は製品の側に並べる) */
    public function isGeneric(AgentTool $tool): bool
    {
        return $this->namespaceOf($tool)['generic'] ?? false;
    }

    /** 道具の名前と別名の頭(頭の対応に当たらなければ null) */
    public function prefixOf(AgentTool $tool): ?string
    {
        $namespace = $this->namespaceOf($tool);

        if ($namespace === null) {
            return null;
        }

        if ($namespace['prefix'] !== null) {
            return $namespace['prefix'];
        }

        $rest = substr($tool::class, strlen($namespace['namespace']));
        $module = strstr($rest, '\\', true);

        return $module === false ? null : Str::snake($module).'_';
    }

    /**
     * 道具のクラスに当たる、いちばん長い頭の対応。
     *
     * @return NamespacePrefix|null
     */
    private function namespaceOf(AgentTool $tool): ?array
    {
        $matched = null;

        foreach ($this->namespaces as $namespace) {
            $fits = str_starts_with($tool::class, $namespace['namespace'])
                && strlen($namespace['namespace']) > strlen($matched['namespace'] ?? '');
            $matched = $fits ? $namespace : $matched;
        }

        return $matched;
    }
}
