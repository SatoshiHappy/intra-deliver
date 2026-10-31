<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\RecordConditionEvaluator;
use App\Queries\Records\RecordConditionText;
use App\Queries\Records\RecordConditionValidator;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordQuery;
use App\Support\InputText;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 規則の条件の JSON(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2)を、ほかのモジュール(ワークフローの分岐の条件)が書いて評価し、文にする口。
 * 主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表。
 * 検証は {@see RecordConditionValidator}、評価は {@see RecordConditionEvaluator} で、どちらも規則の保存と条件つき必須が使うものと同じ 1 か所。
 * 判定しない。鍵を取らない。
 */
class RecordConditions
{
    public function __construct(
        private RecordQuery $records,
        private RecordDefinitionQuery $definitions,
        private RecordConditionValidator $validator,
        private RecordConditionEvaluator $evaluator,
        private FieldValueParser $parser,
        private RecordConditionText $text,
    ) {}

    /**
     * 条件を業務モジュールの今の定義で検証し、規則の保存と同じ形に正規化した条件を返す(呼び手はこれを書く)。入力は規則の保存と同じく、文字列の
     * 前後の空白を除き、空文字を null にしてから確かめる。
     *
     * @param  array<array-key, mixed>  $condition  {"all": [{field, op, value}, …]}
     * @return array{all: list<array<string, mixed>>}
     *
     * @throws ValidationException 規則に反するとき(キー = condition・condition.all.<添字>(.field・.op・.value)。呼び手が付け替える)
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細の業務モジュールのとき(呼び手の誤り。matches が親の無いレコードだけを受けるのとそろえる)
     */
    public function validate(int $moduleId, array $condition): array
    {
        $module = $this->definitions->load(RecordModule::query()->whereNull('parent_record_module_id')->findOrFail($moduleId));

        return $this->validator->validate($module, InputText::normalize($condition));
    }

    /**
     * 条件の JSON の形だけが合うか(式 1〜{@see RecordConditionValidator::MAX_EXPRESSIONS} 個・式のキーは field・op・value の中・field と op は文字列)。
     * 業務モジュールの定義に照らさない確かめで、呼び手が形と上限を自分で写さないための口。{@see self::validate()} が返した条件は常に真。主体の判定・鍵はない。
     */
    public function isWellFormed(mixed $condition): bool
    {
        return $this->validator->isWellFormed($condition);
    }

    /**
     * 条件の人が読める文(規則の画面の要約と同じ文 = {@see RecordConditionText}。例「予算(万円) < 300」、式どうしは「、」)。
     * 項目は今の定義の名前(無い項目はコード)、値を名前に引き直すのは選択肢だけ。主体の判定・鍵はない。
     *
     * @param  array<array-key, mixed>  $condition  形の合う条件({@see self::isWellFormed()} が真)
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細の業務モジュールのとき(呼び手の誤り)
     * @throws LogicException 形の合わない条件(呼び手の誤り)
     */
    public function describe(int $moduleId, array $condition): string
    {
        $module = RecordModule::query()->whereNull('parent_record_module_id')
            ->with('recordFields.recordFieldOptions')->findOrFail($moduleId);

        return $this->text->of($module, $condition);
    }

    /**
     * 主体の判定をしない。呼び手が案件の操作の判定を済ませてから呼ぶ。
     *
     * 写した条件が、レコード(親の無いもの)の今の値で真か(規則の条件と同じ意味)。式は今の業務モジュールの定義に照らし、合わない式(項目が無い・明細・
     * 演算子がその型に使えない・写した値が今の型の正規の形でない)は項目が空として評価する。値の検証・正規化はしない。
     *
     * @param  array<array-key, mixed>  $condition  写した条件({@see self::validate()} が返した形)
     *
     * @throws ModelNotFoundException<Record|RecordModule> レコードが無い・明細の行のとき(呼び手の誤り)
     */
    public function matches(int $recordId, array $condition): bool
    {
        $record = $this->records->topLevel($recordId)->load('recordReferences');
        $module = $this->definitions->load(RecordModule::query()->findOrFail($record->record_module_id));

        return $this->evaluator->holds($module->recordFields, $this->valuesOf($module, $record), $condition);
    }

    /**
     * レコードの明細を除く項目の値(項目のコード → 値)。通常の項目は保存された値の読み方({@see FieldValueParser::stored()}。真偽はキーが無ければ偽)、
     * 参照の項目は {@see Record::referenceValue()} の形(相手の id か型「ユーザーと組織」の {type, id}、複数選べる参照はその配列。
     * 無ければ null)。条件つき必須が評価する合わせた値と同じ形。
     *
     * @return array<string, mixed>
     */
    private function valuesOf(RecordModule $module, Record $record): array
    {
        $values = [];

        foreach ($this->definitions->valueFields($module) as $field) {
            $values[$field->code] = $field->type->isReference()
                ? $record->referenceValue($field)
                : $this->parser->stored($field, $record->field_values ?? []);
        }

        return $values;
    }
}
