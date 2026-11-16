<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Ai\AiRun;
use App\Queries\Master\Api\MasterNames;
use LogicException;

/**
 * 道具の引数の中のユーザー・組織の相手を引く ③ の共通の部品(docs の 設計/AI/道具と権限/README.md 10.1 の形の外の検証・10.2 のユーザー・組織の名前、
 * 決まらないとき = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B1)。名前の物 {"name": "…"} を候補の口で引き、引数の形の id に直す。
 * id で渡した相手も名前で引いた相手も、名前と所属を同じ口で読んで道具に渡す(道具のクラスは共通マスタの口に依存しない = 10.4)。条件の id には
 * 有効かの確かめを掛けない(画面の絞り込みと同じく、過去の担当者でも探せる)。定義を使うので、渡せる範囲の業務モジュールにだけ掛ける。
 * 条件に使えるかは実行の始めの定義で見る(説明と同じ定義で確かめる = 10.2 の値の形の表)。
 *
 * @phpstan-import-type Party from AgentToolParties
 *
 * @phpstan-type Found array{field: string, type: 'user'|'department', id: int}
 */
class ToolPartyNames
{
    /** 相手を指す項目の型 */
    private const PARTY_TYPES = ['user', 'department', 'user_or_department'];

    /** 条件の形の相手の id(正の整数の 10 進。一覧の条件の口と同じ) */
    private const ID_PATTERN = '[1-9][0-9]{0,17}';

    /** 決まらなかったときに、誤りの文の終わりに足す文 */
    public const CHOOSE = 'あなたは相手を推し量らない。候補があれば利用者に選んでもらい、選んだ候補の値で呼び直す。候補が無ければ、'
        .'相手が見つからなかったことを利用者に伝える。';

    public function __construct(
        private PartyCandidates $candidates,
        private MasterNames $names,
        private ToolSubjects $subjects,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  形の検証を通った引数
     *
     * @throws LogicException 道具が入れ物の入力の形の引数を答えたとき(設定の確かめで断る。確かめを経ずに登録された道具が呼ばれたら、
     *                        この例外で止まる)
     */
    public function resolve(
        AgentRecordTool $tool,
        array $arguments,
        AgentToolScope $scope,
        AiRun $run,
    ): ToolPartyResolution {
        foreach ($tool->partyArguments() as $form) {
            if ($form !== AiToolPartyForm::Condition) {
                throw new LogicException("道具 {$tool->name()} の入れ物の入力の形の相手は引けない");
            }
        }

        $module = $tool->moduleOf($arguments);

        if ($tool->partyArguments() === [] || $module === null || ! $scope->has($module)) {
            return new ToolPartyResolution($arguments, new AgentToolParties);
        }

        $subject = $this->subjects->for($run);
        $types = self::partyTypes($scope->modules[$module]['definition']);
        [$resolved, $found, $errors] = [$arguments, [], []];

        foreach (array_keys($tool->partyArguments()) as $key) {
            if (is_array($arguments[$key] ?? null)) {
                [$resolved[$key], $parties, $problems] = $this->conditions($arguments[$key], $types, $subject);
                array_push($found, ...$parties);
                array_push($errors, ...$problems);
            }
        }

        return $errors === []
            ? new ToolPartyResolution($resolved, new AgentToolParties($this->named($found, $subject)))
            : new ToolPartyResolution($arguments, new AgentToolParties, implode("\n", [...$errors, self::CHOOSE]));
    }

    /**
     * 条件のうち相手を指す項目の値を直す(名前の物は引いた id に、ほかは渡したまま)。
     *
     * @param  array<array-key, mixed>  $conditions
     * @param  array<string, string>  $types  項目のコード → 相手を指す型
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
     * @return array{mixed, Found|null, string|null}
     */
    private function element(string $code, string $type, mixed $element, AgentToolSubject $subject): array
    {
        $name = is_array($element) && array_keys($element) === ['name'] && is_string($element['name'])
            ? $element['name']
            : null;

        if ($name !== null) {
            [$element, $error] = $this->candidates->resolve($code, $type, $name, $subject);

            if ($element === null) {
                return [null, null, $error];
            }
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
     * 相手の名前と所属(基準日の名前。読めない相手は名前と所属が null)。同じ項目の同じ相手は 1 つにする。
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
            $name = ($isUser ? $users : $departments)[$party['id']]['name'] ?? null;
            $affiliation = $isUser && $name !== null ? $affiliations[$party['id']] ?? null : null;

            return [...$party, 'name' => $name, 'affiliation' => $affiliation];
        }, $found);
    }

    /**
     * 定義の項目のうち、条件に使えて相手を指す型の物(項目のコード → 型)。条件に使えない項目は名前の物も引かず、一覧の口に外させる
     * (引いても条件に効かず、決まらなければ利用者に選ばせる手間だけが残るため)。
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private static function partyTypes(array $definition): array
    {
        $types = [];
        $fields = is_array($definition['fields'] ?? null) ? array_filter($definition['fields'], is_array(...)) : [];

        foreach ($fields as $field) {
            $code = $field['code'] ?? null;
            $type = $field['type'] ?? null;
            $searchable = ($field['isSearchable'] ?? false) === true;

            if (is_string($code) && $searchable && in_array($type, self::PARTY_TYPES, true)) {
                $types[$code] = $type;
            }
        }

        return $types;
    }
}
