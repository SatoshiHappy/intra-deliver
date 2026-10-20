<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * レコードの登録・更新の 3〜9(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2)。呼び手(登録・更新の業務処理)がトランザクションと 1・2 を持つ。
 *
 * 3・4(入力の形と値。親と明細の全行)の失敗をまとめて返し、5 以降に進まない。6〜8(親と、保存後に残る明細の全行)の失敗も、
 * すべて集めてから一度に返す(キーごとに最初の 1 つ)。どの段で失敗しても何も書かない。
 */
class RecordSaver
{
    use NormalizesInput;

    public function __construct(
        private RecordInputReader $reader,
        private RecordDetailDrafts $details,
        private RecordValidator $validator,
        private RecordWriter $writer,
        private ReferenceTargets $targets,
    ) {}

    /**
     * @param  RecordModule  $module  定義を読み込んだ親の無い業務モジュール
     * @param  Record|null  $record  更新する今のレコード(参照の行を読み込んでおく。null = 登録)
     * @param  int|null  $userId  作成者・更新者(null = ゲスト)
     *
     * @throws ValidationException 検証に失敗したとき
     */
    public function save(Requester $requester, DateTimeInterface $date, ?int $userId, RecordModule $module, ?Record $record, RecordInput $input): Record
    {
        $errors = [];
        $values = $this->normalizeInput($input->values);
        $parent = new RecordDraft($module, $record, '', $values);
        $plan = $this->details->build($module, $record, $input->details, $errors);
        $rows = array_merge([], ...array_values($plan['drafts']));
        $check = new ReferenceCheck($requester, $date);
        $this->targets->prepare($check, array_merge([], ...array_map(fn (RecordDraft $draft) => $this->reader->changedReferences($draft), [$parent, ...$rows])));

        $parentInput = $this->reader->read($parent, $check, $errors);
        $rowInputs = array_map(function (RecordDraft $row) use ($check, &$errors) {
            return $this->reader->read($row, $check, $errors);
        }, $rows);

        $this->throwIfAny($errors);
        // 相対の既定値の登録の時。親と明細の行に同じ時点を渡す(基準日 $date とは別 = 項目と規則/README.md 3 節の「登録の時」)
        $now = CarbonImmutable::now(AccessContext::TIMEZONE);
        $this->validator->merge($parent, $parentInput, $now);
        $this->validator->validate($parent, array_map('count', $plan['drafts']), $errors);

        foreach ($rows as $index => $row) {
            $this->validator->merge($row, $rowInputs[$index], $now);
            $this->validator->validate($row, [], $errors);
        }

        $this->throwIfAny($errors);
        $saved = $this->writer->write($parent, null, $userId, true);
        Record::query()->whereKey(array_map(fn (Record $removed) => $removed->id, $plan['removed']))->delete();

        foreach ($rows as $row) {
            $this->writer->write($row, $saved, $userId, false);
        }

        return $saved;
    }

    /**
     * @param  array<string, string>  $errors
     *
     * @throws ValidationException 失敗があるとき
     */
    private function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }
    }
}
