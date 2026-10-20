<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Queries\Ai\AiSettings;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * AI の実行の種類の全体 = タグ {@see PromptKind::TAG} で束ねた実装を引く 1 か所(内部。docs の 設計/AI/公開の口.md 3.1)。
 * Ai の業務処理は種類の実装を直接呼ばずにここを通す。登録と設定の食い違い({@see self::problems()})は起動のテストで落とす。
 */
class PromptKinds
{
    /**
     * @param  iterable<PromptKind>  $kinds
     */
    public function __construct(
        #[Tag(PromptKind::TAG)] private iterable $kinds,
        private AiSettings $settings,
        private KindLimitChecks $limits,
    ) {}

    /**
     * コードの種類の実装。
     *
     * @throws LogicException 登録の無いコードのとき(作りの誤り)
     */
    public function of(string $code): PromptKind
    {
        return $this->find($code) ?? throw new LogicException(self::notRegistered($code));
    }

    /** コードの種類の実装。登録が無ければ null */
    public function find(string $code): ?PromptKind
    {
        foreach ($this->kinds as $kind) {
            if ($kind->code() === $code) {
                return $kind;
            }
        }

        return null;
    }

    /** 登録の無い種類の文 */
    public static function notRegistered(string $code): string
    {
        return "AI の実行の種類 {$code} の実装が登録されていない(タグ ".PromptKind::TAG.')';
    }

    /**
     * 登録と設定の食い違い(起動の時に確かめるもの = 設計/AI/データと失敗と検査.md 8 節。種類と区分の分は {@see KindLimitChecks})。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $seen = [];

        foreach ($this->kinds as $kind) {
            $code = $kind->code();
            $problems = [
                ...$problems,
                ...(isset($seen[$code]) ? ["種類 {$code} の実装が 2 つある"] : []),
                ...($this->settings->kind($code) === null ? ["種類 {$code} が設定(config/ai_runs.php の kinds)に無い"] : []),
            ];
            $seen[$code] = true;
        }

        $pricing = [];

        foreach ($this->settings->kindCodes() as $code) {
            $pricing = [...$pricing, ...$this->pricingProblems($code)];
            $problems = [...$problems, ...$this->limits->kindProblems($code)];
        }

        return [...$problems, ...$pricing, ...($pricing === [] ? $this->limits->budgetProblems() : [])];
    }

    /**
     * 単価の食い違い。最後の段に入力の上限があると、どの段にも収まらない入力が残る(設計/AI/実行と費用.md 5 節)。
     *
     * @return list<string>
     */
    private function pricingProblems(string $code): array
    {
        $kind = $this->settings->kind($code);
        $tiers = $kind === null ? [] : $this->settings->priceTiers($kind['provider'], $kind['model']);

        return $tiers === [] || end($tiers)['up_to_input_tokens'] !== null
            ? ["種類 {$code} の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)"]
            : [];
    }
}
