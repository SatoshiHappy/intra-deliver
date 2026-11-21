<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 汎用の道具「直す」(docs の 設計/AI/道具と権限/README.md 10.2 の直すの行)。呼ばれた時の 1 段目は、閲覧の判定を先に呼び、通らなければ値を読まない
 * (見えないレコードの有無が漏れる = 設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。通れば編集の画面と同じ編集の初期値の読み取りで今の値と版を
 * 読む。2 段目は確認の内容を作るだけで書かない(10.3 の B7)。書くのは利用者が確認の後(10.5)。
 */
final class UpdateRecordTool implements AgentUpdateTool
{
    public function __construct(
        private readonly RecordViewAccess $access,
        private readonly RecordFormQuery $forms,
        private readonly RecordConfirmations $confirmations,
        private readonly RecordToolWrites $writes,
    ) {}

    public function name(): string
    {
        return 'ai_update_record';
    }

    public function aliases(): array
    {
        return [];
    }

    public function displayName(): string
    {
        return '更新';
    }

    public function description(AgentToolScope $scope): string
    {
        return RecordWriteToolDescriptions::update($scope);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...RecordWriteSchema::common($schema),
            'id' => $schema->integer()->description(RecordWriteToolDescriptions::ARGUMENTS['id'])->required(),
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isConcurrencySafe(): bool
    {
        return false;
    }

    public function requiredAction(): AiToolAction
    {
        return AiToolAction::Update;
    }

    public function targetModules(): ?array
    {
        return null;
    }

    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string
    {
        return null;
    }

    public function maxResultLength(): ?int
    {
        return null;
    }

    public function largeResultTemplate(): AiInstructionTemplate
    {
        return AiInstructionTemplate::LargeResultTruncated;
    }

    public function moduleOf(array $arguments): ?string
    {
        return RecordWriteSchema::moduleOf($arguments['module'] ?? null);
    }

    public function partyArguments(): array
    {
        return RecordWriteSchema::PARTIES;
    }

    /**
     * @throws ModelNotFoundException 閲覧の判定の後にレコードか業務モジュールが消えたとき(判定の関数が見つからないにする)
     */
    public function current(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): ?RecordCurrentValues
    {
        $module = $this->moduleOf($arguments) ?? '';
        $id = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;
        [$requester, $date, $locale] = [$subject->requester(), $subject->date(), $subject->localeCode()];

        if (! $this->access->canView($requester, $module, $id, $date)) {
            return null;
        }

        $edit = $this->forms->editValues($requester, $module, $id, $date, $locale);
        $titleCode = RecordToolValues::titleCode($scope->modules[$module]['definition'] ?? []);
        $name = RecordToolValues::displayName($edit['values'], $titleCode, $id);

        $rowAccess = self::rowAccess($edit['rowAccess']);

        return new RecordCurrentValues($id, $name, $edit['values'], $edit['details'], $rowAccess, $edit['version']);
    }

    public function confirmation(
        RecordCurrentValues $current,
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation {
        return $this->confirmations->confirmation($arguments, $subject, $scope, $parties, $current);
    }

    /**
     * @throws LogicException 書く値の引数に業務モジュールか id が無いとき(包みの形の検証を通った引数には必ずある)
     */
    public function write(
        array $written,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        RecordConfirmation $confirmation,
        string $version,
    ): AgentToolResult {
        $module = $this->moduleOf($written) ?? throw new LogicException('直すの書く値の引数に業務モジュールが無い');
        $id = is_int($written['id'] ?? null) ? $written['id'] : throw new LogicException('直すの書く値の引数に id が無い');
        $this->writes->update($subject, $module, $id, $written, $version);
        $target = is_array($confirmation->content['target'] ?? null) ? $confirmation->content['target'] : [];
        $name = is_string($target['name'] ?? null) ? $target['name'] : null;
        $definition = $scope->modules[$module]['definition'] ?? [];

        return RecordToolWrites::result($module, $id, $written, $definition, $name);
    }

    /**
     * @throws LogicException いつも(呼ばれた時の判定は問うを返し、許すを返さない。書くのは確認の後の書く時の部品)
     */
    public function execute(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): AgentToolResult {
        throw new LogicException('直すは呼ばれた時に書かない(書くのは確認の後)');
    }

    /**
     * 参照制限の今の値(チェックと参照者)を、編集の初期値の読み取りが返す形から写す。
     *
     * @param  array{restricted: bool, viewers: list<array{type: string, id: int, code: string|null, label: string|null,
     *     affiliation: string|null}>}  $rowAccess
     * @return array{restricted: bool, viewers: list<array{type: 'user'|'department', id: int, code: string|null,
     *     label: string|null, affiliation: string|null}>}
     *
     * @throws LogicException 参照者にユーザーと組織のほかの型があるとき(参照者の相手はユーザーと組織だけ)
     */
    private static function rowAccess(array $rowAccess): array
    {
        $viewers = [];

        foreach ($rowAccess['viewers'] as $viewer) {
            $type = $viewer['type'];
            $viewers[] = $type === 'user' || $type === 'department'
                ? [...$viewer, 'type' => $type]
                : throw new LogicException("参照者の型 {$type} はユーザーでも組織でもない");
        }

        return ['restricted' => $rowAccess['restricted'], 'viewers' => $viewers];
    }
}
