<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Enums\Ai\AiToolNature;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiAgentDefinition;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 1 回の 1 段目の中の判定({@see ToolGrants} が区間の中で作って捨てる)。業務モジュールの一覧・表示の止め・依頼者の有効・操作の判定は、同じ 1 段目の
 * 中で同じ業務モジュールと操作に 1 回だけ呼ぶ(定義と参照制限の既定の読み取りは、それぞれの口が判定を含む)。
 */
final class ToolGrantJudgements
{
    /** @var array<string, string>|null 業務モジュール(親の無いもの)のコード → 名前。読む前は null */
    private ?array $modules = null;

    private ?bool $effective = null;

    /** @var array<string, bool> 業務モジュールのコード → 表示の止めが無いか */
    private array $open = [];

    /** @var array<string, bool> 業務モジュールのコードと操作 → 許すか */
    private array $allowed = [];

    /**
     * @param  Closure(): bool  $isEffective
     * @param  list<string>  $markerForms  会話の印の形(説明に入れる名前の写しに当てる = {@see ShownDefinitions})
     */
    public function __construct(
        private readonly RecordModuleDirectory $directory,
        private readonly RecordFormQuery $forms,
        private readonly Closure $isEffective,
        private readonly AgentToolSubject $subject,
        private readonly array $markerForms,
    ) {}

    public function granted(AgentTools $tools, AiAgentDefinition $definition): GrantedTools
    {
        $named = array_values(array_filter((array) $definition->tools, is_string(...)));
        $ranges = ToolRanges::of($tools, $definition, $this->passes(...));
        $removed = array_values(array_filter($named, fn (string $name): bool => $tools->named($name) === null));

        return new GrantedTools($this->scoped($ranges), $removed);
    }

    /** 業務モジュールを範囲に入れるか(一覧に無い・明細・止めがある・依頼者が有効でない・操作を持たない業務モジュールは入れない) */
    private function passes(string $code, AiToolNature $nature): bool
    {
        if (! array_key_exists($code, $this->modules()) || ! $this->isOpen($code) || ! $this->isEffective()) {
            return false;
        }

        foreach ($nature->requiredActions() as $action) {
            if (! $this->allows($code, $action)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 範囲が空でない道具だけを渡す(A2 は渡さない)。
     *
     * @param  list<array{AgentTool, list<string>|null}>  $ranges
     * @return list<GrantedTool>
     */
    private function scoped(array $ranges): array
    {
        [$definitions, $defaults] = $this->definitions($ranges);

        return ToolScopes::of($ranges, $this->modules(), $definitions, $defaults, $this->markerForms);
    }

    /**
     * 範囲に入れた業務モジュールの定義(業務モジュールごとに 1 回。範囲に入れた性質のうち、閲覧が要る物があれば閲覧、無ければ登録で読む = 10.4)。
     * 判定の後に操作の権限か業務モジュールが無くなって読めない業務モジュールは、範囲に入れない(判定で外れたのと同じ)。
     *
     * 参照制限の既定のチェックも、同じ操作で読む(作るの W 表の元のチェック = 設計/AI/道具と権限/README.md 10.2。定義と既定の読み取りは
     * それぞれの口が判定を含むので、どちらかが断られた業務モジュールは範囲に入れない)。
     *
     * @param  list<array{AgentTool, list<string>|null}>  $ranges
     * @return array{array<string, array<string, mixed>>, array<string, bool>} 定義と、参照制限の既定
     */
    private function definitions(array $ranges): array
    {
        [$definitions, $defaults] = [[], []];

        foreach (self::definitionActions($ranges) as $code => $action) {
            [$requester, $date] = [$this->subject->requester(), $this->subject->date()];

            try {
                $definition = $this->forms->definition($requester, $code, $action, $date);
                $defaults[$code] = $this->forms->rowAccessRestrictedByDefault($requester, $code, $action, $date);
                $definitions[$code] = $definition;
            } catch (AuthorizationException|ModelNotFoundException) {
                continue;
            }
        }

        return [$definitions, $defaults];
    }

    /**
     * 業務モジュールごとの、定義を読む操作(範囲に入れた性質のうち、閲覧が要る物があれば閲覧、無ければ登録)。
     *
     * @param  list<array{AgentTool, list<string>|null}>  $ranges
     * @return array<string, RecordModuleAction>
     */
    private static function definitionActions(array $ranges): array
    {
        $actions = [];

        foreach ($ranges as [$tool, $codes]) {
            $nature = AiToolNature::of($tool->isReadOnly(), $tool->requiredAction());
            $reads = in_array(RecordModuleAction::Read, $nature?->requiredActions() ?? [], true);

            foreach ($codes ?? [] as $code) {
                $actions[$code] = $reads || ($actions[$code] ?? null) === RecordModuleAction::Read
                    ? RecordModuleAction::Read
                    : RecordModuleAction::Create;
            }
        }

        return $actions;
    }

    /** @return array<string, string> */
    private function modules(): array
    {
        return $this->modules ??= array_column($this->directory->topLevelModules(), 'name', 'code');
    }

    private function isOpen(string $code): bool
    {
        return $this->open[$code] ??= $this->directory->visibilityStopReason($code) === null;
    }

    private function isEffective(): bool
    {
        return $this->effective ??= ($this->isEffective)();
    }

    private function allows(string $code, RecordModuleAction $action): bool
    {
        return $this->allowed["{$code} {$action->value}"] ??= $this->forms->allows(
            $this->subject->requester(),
            $code,
            $action,
            $this->subject->date(),
        );
    }
}
