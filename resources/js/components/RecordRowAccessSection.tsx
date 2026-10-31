import { FormBlock, formErrorId, invalidProps } from '@/components/FormBlock';
import { RecordMultipleReferencePicker } from '@/components/RecordMultipleReferencePicker';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { fieldErrorOf, referenceItemErrors } from '@/lib/recordFormErrors';
import type { RowAccessDraft } from '@/lib/recordRowAccess';
import {
    RESTRICTED_KEY,
    VIEWERS_FIELD,
    VIEWERS_KEY,
} from '@/lib/recordRowAccess';

/** チェックの説明(設計/業務データの入れ物/画面/業務の画面.md の 2.2 の「参照制限」) */
const RESTRICTED_HINT =
    'チェックすると、作成者、「関係者」の札の付いた項目に入っている人(組織は所属する人)、下の「参照者」と、全件の参照を許された人だけが表示できます。申請に使われたレコードの値は、その申請を閲覧できる人も申請の詳細で見られます';

/** 欄「参照者」の説明(同じ節) */
const VIEWERS_HINT =
    '組織を選ぶと、その組織に所属する人(下の組織は含みません)が表示できます。';

const RESTRICTED_ID = 'record-row-access-restricted';

const VIEWERS_ID = 'record-row-access-viewers';

type Props = {
    draft: RowAccessDraft;
    onChange: (draft: RowAccessDraft) => void;
    errors: Record<string, string | undefined>;
    /** prefix で始まるキーの誤りを消す(チップを外したとき) */
    onClearErrors: (prefix: string) => void;
};

/**
 * チェック「参照を制限する」と、その説明・誤り。
 */
function RestrictedCheck({
    checked,
    onChange,
    error,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    error: string | undefined;
}) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={RESTRICTED_ID}
                    checked={checked}
                    onCheckedChange={(next) => onChange(next === true)}
                    {...invalidProps(RESTRICTED_ID, error)}
                />
                <Label htmlFor={RESTRICTED_ID}>参照を制限する</Label>
            </div>
            <p className="text-xs text-muted-foreground">{RESTRICTED_HINT}</p>
            {error !== undefined && (
                <p
                    id={formErrorId(RESTRICTED_ID)}
                    className="text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * 欄「参照者」(複数の参照の欄と同じ部品。添字の誤りはチップの印、文言は欄の下)。
 */
function ViewersField({ draft, onChange, errors, onClearErrors }: Props) {
    const error = fieldErrorOf(errors, VIEWERS_KEY);

    return (
        <FormBlock
            id={VIEWERS_ID}
            label={VIEWERS_FIELD.name}
            hint={VIEWERS_HINT}
            error={error}
        >
            <RecordMultipleReferencePicker
                id={VIEWERS_ID}
                field={VIEWERS_FIELD}
                value={draft.viewers}
                onChange={(viewers) => onChange({ ...draft, viewers })}
                invalid={error !== undefined}
                itemErrors={referenceItemErrors(
                    errors,
                    VIEWERS_KEY,
                    onClearErrors,
                )}
                describedBy={
                    invalidProps(VIEWERS_ID, error)['aria-describedby']
                }
            />
        </FormBlock>
    );
}

/**
 * 区画「参照制限」(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.2): 管理者の見出しのまとまりの外に置く。欄「参照者」は
 * チェックを付けている間だけ出し、外しても値を保つ(付け直すと戻る)。
 */
export function RecordRowAccessSection(props: Props) {
    const { draft, onChange, errors } = props;

    return (
        <section
            aria-labelledby="record-row-access"
            className="flex flex-col gap-4"
        >
            <h2 id="record-row-access" className="text-sm font-semibold">
                参照制限
            </h2>
            <RestrictedCheck
                checked={draft.restricted}
                onChange={(restricted) => onChange({ ...draft, restricted })}
                error={errors[RESTRICTED_KEY]}
            />
            {draft.restricted && <ViewersField {...props} />}
        </section>
    );
}
