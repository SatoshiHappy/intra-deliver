<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Ai\AiRun;
use App\Queries\Master\Api\MasterNames;

/**
 * 道具の引数の中のユーザー・組織の相手を引く ③ の共通の部品(docs の 設計/AI/道具と権限/README.md 10.1 の形の外の検証・10.2 のユーザー・組織の名前、
 * 決まらないとき = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B1、直すの今の値に無い無効な相手 = B19)。名前の物 {"name": "…"} を候補の口で
 * 引き、引数の形の id に直す。id で渡した相手も名前で引いた相手も、コードと名前と所属を同じ口で読んで道具に渡す(道具のクラスは共通マスタの口に
 * 依存しない = 10.4)。条件の id には有効かの確かめを掛けない(画面の絞り込みと同じく、過去の担当者でも探せる)。書く値の id は確かめる
 * ({@see ToolInputParties})。定義を使うので、渡せる範囲の業務モジュールにだけ掛ける。条件に使えるかは実行の始めの定義で見る
 * (説明と同じ定義で確かめる = 10.2 の値の形の表)。
 *
 * @phpstan-import-type Party from AgentToolParties
 * @phpstan-import-type PartyType from ToolPartyFields
 * @phpstan-import-type Slot from ToolInputParties
 *
 * @phpstan-type Found array{field: string, type: 'user'|'department', id: int}
 */
class ToolPartyNames
{
    /** 条件の形の相手の id(正の整数の 10 進。一覧の条件の口と同じ) */
    private const ID_PATTERN = '[1-9][0-9]{0,17}';

    /** 決まらなかったときに、誤りの文の終わりに足す文 */
    public const CHOOSE = 'あなたは相手を推し量らない。候補があれば利用者に選んでもらい、選んだ候補の値で呼び直す。候補が無ければ、'
        .'相手が見つからなかったことを利用者に伝える。';

    public function __construct(
        private PartyCandidates $candidates,
        private MasterNames $names,
        private ToolSubjects $subjects,
        private ToolInputParties $inputs,
    ) {}

    /**
     * 呼ばれた時の引き当て(道具の検証の段)。条件の形は名前の物を引いて読む。入れ物の入力の形は名前の物を引き、作るは id を確かめて読む
     * (直すは今の値を読んだ後に {@see self::afterCurrent()} で確かめる = 10.1 の確認の内容を作る口)。
     *
     * @param  array<string, mixed>  $arguments  形の検証を通った引数
     */
    public function resolve(
        AgentRecordTool $tool,
        array $arguments,
        AgentToolScope $scope,
        AiRun $run,
    ): ToolPartyResolution {
        $module = $tool->moduleOf($arguments);

        if ($tool->partyArguments() === [] || $module === null || ! $scope->has($module)) {
            return new ToolPartyResolution($arguments, new AgentToolParties);
        }

        $subject = $this->subjects->for($run);
        $definition = $scope->modules[$module]['definition'];
        $forms = $tool->partyArguments();
        [$resolved, $found, $errors] = $this->conditionArguments($arguments, $forms, $definition, $subject);
        $chooses = $errors !== [];
        $inputs = self::inputs($forms);
        [$resolved, $slots, $problems, $names] = $this->inputs->resolve($resolved, $inputs, $definition, $subject);
        [$errors, $chooses] = [[...$errors, ...$problems], $chooses || $names];

        if ($errors === [] && ! $tool instanceof AgentUpdateTool) {
            $byId = array_values(array_filter($slots, fn (array $slot): bool => ! $slot['byName']));
            $errors = $this->inputs->invalid($byId, $subject);
            $found = [...$found, ...self::found($slots)];
        }

        return $errors === []
            ? new ToolPartyResolution($resolved, new AgentToolParties($this->named($found, $subject)))
            : new ToolPartyResolution($arguments, new AgentToolParties, self::error($errors, $chooses));
    }

    /**
     * 直すの 1 段目の後の確かめ(10.3 の B19 の分): 今の値(欄ごと)に無い相手を確かめて読む。誤りは B19 の文。引数を歩き直した誤り
     * (形の誤り。名前の物は 1 段目の前に id に直してあるので、名前の誤りは起きない)は捨てずに、呼ぶ時の確かめと同じ文(B1)にする。
     *
     * @param  array<string, mixed>  $arguments  1 段目の後の引数(参照制限を今の値で決め直した値。名前の物は id に直してある)
     * @return array{AgentToolParties, string|null} 今の値に無かった相手と、誤りの文
     */
    public function afterCurrent(
        AgentUpdateTool $tool,
        array $arguments,
        AgentToolScope $scope,
        RecordCurrentValues $current,
        AgentToolSubject $subject,
    ): array {
        $module = $tool->moduleOf($arguments);
        $inputs = self::inputs($tool->partyArguments());

        if ($inputs === [] || $module === null || ! $scope->has($module)) {
            return [new AgentToolParties, null];
        }

        $definition = $scope->modules[$module]['definition'];
        [, $slots, $walkErrors] = $this->inputs->resolve($arguments, $inputs, $definition, $subject, $current);

        if ($walkErrors !== []) {
            return [new AgentToolParties, self::error($walkErrors, false)];
        }

        $added = ToolInputParties::notInCurrent($slots);
        $errors = $this->inputs->invalid($added, $subject);

        return $errors === []
            ? [new AgentToolParties($this->named(self::found($added), $subject)), null]
            : [new AgentToolParties, self::error($errors, false)];
    }

    /**
     * 条件の形の引数の相手を直す。
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, AiToolPartyForm>  $forms
     * @param  array<array-key, mixed>  $definition
     * @return array{array<string, mixed>, list<Found>, list<string>}
     */
    private function conditionArguments(
        array $arguments,
        array $forms,
        array $definition,
        AgentToolSubject $subject,
    ): array {
        $types = ToolPartyFields::of($definition, true);
        [$resolved, $found, $errors] = [$arguments, [], []];

        foreach ($forms as $key => $form) {
            if ($form === AiToolPartyForm::Condition && is_array($arguments[$key] ?? null)) {
                [$resolved[$key], $parties, $problems] = $this->conditions($arguments[$key], $types, $subject);
                array_push($found, ...$parties);
                array_push($errors, ...$problems);
            }
        }

        return [$resolved, $found, $errors];
    }

    /**
     * 入れ物の入力の形の相手を含む引数。
     *
     * @param  array<string, AiToolPartyForm>  $forms
     * @return array<string, AiToolPartyForm>
     */
    private static function inputs(array $forms): array
    {
        return array_filter($forms, fn (AiToolPartyForm $form): bool => $form !== AiToolPartyForm::Condition);
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<Found>
     */
    private static function found(array $slots): array
    {
        return array_map(fn (array $slot): array => [
            'field' => $slot['field'],
            'type' => $slot['type'],
            'id' => $slot['id'],
        ], $slots);
    }

    /**
     * @param  list<string>  $errors
     */
    private static function error(array $errors, bool $chooses): string
    {
        return implode("\n", [...$errors, ...($chooses ? [self::CHOOSE] : [])]);
    }

    /**
     * 条件のうち相手を指す項目の値を直す(名前の物は引いた id に、ほかは渡したまま)。
     *
     * @param  array<array-key, mixed>  $conditions
     * @param  array<string, PartyType>  $types  項目のコード → 相手を指す型
     * @return array{array<array-key, mixed>, list<Found>, list<string>}
     */
    private function conditions(array $conditions, array $types, AgentToolSubject $subject): array
    {
        [$found, $errors] = [[], []];

        foreach ($conditions as $code => $value) {
            $type = $types[(string) $code] ?? null;

            if ($type !== null) {
                [$conditions[$code], $parties, $problems] = $this->value((string) $code, $type, $value, $subject);
                [$found, $errors] = [[...$found, ...$parties], [...$errors, ...$problems]];
            }
        }

        return [$conditions, $found, $errors];
    }

    /**
     * 条件の値 1 つ(複数の相手の列は要素ごと)。
     *
     * @param  PartyType  $type
     * @return array{mixed, list<Found>, list<string>}
     */
    private function value(string $code, string $type, mixed $value, AgentToolSubject $subject): array
    {
        $isList = is_array($value) && $value !== [] && array_is_list($value);
        [$elements, $found, $errors] = [[], [], []];

        foreach ($isList ? $value : [$value] as $element) {
            [$elements[], $party, $error] = $this->element($code, $type, $element, $subject);
            $found = $party === null ? $found : [...$found, $party];
            $errors = $error === null ? $errors : [...$errors, $error];
        }

        return [$isList ? $elements : $elements[0], $found, $errors];
    }

    /**
     * 値 1 つ(名前の物は引いて id の形に、id の形はそのまま。どちらでもない値は一覧の口が外す)。
     *
     * @param  PartyType  $type
     * @return array{mixed, Found|null, string|null}
     */
    private function element(string $code, string $type, mixed $element, AgentToolSubject $subject): array
    {
        $name = is_array($element) && array_keys($element) === ['name'] && is_string($element['name'])
            ? $element['name']
            : null;

        if ($name !== null) {
            $form = AiToolPartyForm::Condition;
            [$found, $error] = $this->candidates->resolve("条件 `{$code}`", $type, $name, $subject, $form);

            if ($found === null) {
                return [null, null, $error];
            }

            $element = PartyCandidates::value($found['type'], $found['id'], $type === 'user_or_department', $form);
        }

        return [$element, is_string($element) ? self::party($code, $type, $element) : null, null];
    }

    /**
     * 条件の形の id が指す相手(id の形でなければ null)。
     *
     * @return Found|null
     */
    private static function party(string $code, string $type, string $value): ?array
    {
        $kind = $type === 'user_or_department'
            ? (preg_match('/^(user|department):('.self::ID_PATTERN.')$/D', $value, $parts) === 1 ? $parts[1] : null)
            : (preg_match('/^'.self::ID_PATTERN.'$/D', $value) === 1 ? $type : null);
        $id = (int) ($type === 'user_or_department' ? ($parts[2] ?? 0) : $value);

        return $kind === 'user' || $kind === 'department' ? ['field' => $code, 'type' => $kind, 'id' => $id] : null;
    }

    /**
     * 相手のコードと名前と所属(基準日の名前。読めない相手は名前と所属が null、無い id はコードも null)。同じ項目の同じ相手は 1 つにする。
     *
     * @param  list<Found>  $found
     * @return list<Party>
     */
    private function named(array $found, AgentToolSubject $subject): array
    {
        $found = array_values(array_unique($found, SORT_REGULAR));
        $ids = fn (string $type): array => array_values(array_unique(array_column(
            array_filter($found, fn (array $party): bool => $party['type'] === $type),
            'id',
        )));
        [$date, $locale] = [$subject->date(), $subject->localeCode()];
        $users = $this->names->users($ids('user'), $date, $locale);
        $affiliations = $this->names->mainDepartmentNames($ids('user'), $date, $locale);
        $departments = $this->names->departments($ids('department'), $date, $locale);

        return array_map(function (array $party) use ($users, $affiliations, $departments): array {
            $isUser = $party['type'] === 'user';
            $read = ($isUser ? $users : $departments)[$party['id']] ?? null;
            $name = $read['name'] ?? null;
            $affiliation = $isUser && $name !== null ? $affiliations[$party['id']] ?? null : null;

            return [...$party, 'code' => $read['code'] ?? null, 'name' => $name, 'affiliation' => $affiliation];
        }, $found);
    }
}
