<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * 会話の型の道具の約束(依存の逆転。正本 = docs の 設計/AI/道具と権限/README.md 10.1 の約束の表。1 つの項を 1 つのメソッドにする)。
 * 汎用の道具は Ai が、製品に固有の道具は製品のモジュールが実装し、起動がタグ {@see self::TAG} で登録する。実装は道具の名前空間にだけ置ける
 * (10.1 の置き場。機械検査 (b))。Ai は実装を直接呼ばず、束ねる 1 か所(内部の AgentTools)と道具を呼ぶ包みを通す。
 * 業務データを読み書きする道具は {@see AgentRecordTool} を実装する(呼び出しが指す業務モジュールを答える)。
 * 道具は業務の権限の判定を自分で持たず、断りと例外の分け方は 10.1 の「断りと例外」に従う。
 */
interface AgentTool
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'ai.agent-tools';

    /** 名前(登録したモジュールの頭を付けた英小文字・数字・_ の 64 文字まで = 10.1 の名前) */
    public function name(): string;

    /**
     * 別名(名前を変えた道具の古い名前)。
     *
     * @return list<string>
     */
    public function aliases(): array;

    /** ① の設定の画面の候補に出す日本語の名前 */
    public function displayName(): string;

    /** モデルに渡す説明(実行の始めの渡せる範囲と定義から組む = 10.1 の説明) */
    public function description(AgentToolScope $scope): string;

    /**
     * 引数の形(SDK の道具の約束と同じ形。1 ステップの口へは SDK の道具の約束で渡す)。包みの引数の形の検証もこの形だけで照らす(10.3 の B11)。
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;

    /** 読むだけか(入力を受けない。入力で読むか書くかが変わる道具は 2 つに分けて登録する) */
    public function isReadOnly(): bool;

    /** 要る権限(対象の業務モジュールの操作。無し = null) */
    public function requiredAction(): ?AiToolAction;

    /**
     * 対象の業務モジュールのコード(null = 定義のナレッジ。汎用の道具は null、製品の道具は自分の対象)。
     *
     * @return list<string>|null
     */
    public function targetModules(): ?array;

    /**
     * 形の外の検証(引数の形の検証の後に包みが呼ぶ)。定義を使う確かめは $scope にある業務モジュールにだけ掛ける。誤りは道具の文(10.3 の B1)、
     * 通れば null。
     *
     * @param  array<string, mixed>  $arguments  形の検証を通った引数
     * @param  list<PriorToolCall>  $priorCalls  同じステップでこの呼び出しより前にモデルが返した呼び出し(呼び出しの順)
     */
    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string;

    /** 1 つの結果がモデルに返す長さの上限(null = 既定の上限 = 10.7) */
    public function maxResultLength(): ?int;

    /** 結果が上限を超えたときの大きい結果の定型(残りを読む道。答えない道具は元の長さと切ったことだけの定型) */
    public function largeResultTemplate(): AiInstructionTemplate;

    /**
     * 実行。業務データの読み書きは $subject を主体にして入れ物の判定する口だけを通す(10.4)。
     *
     * @param  array<string, mixed>  $arguments  形の検証と形の外の検証を通った引数
     */
    public function execute(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): AgentToolResult;
}
