import { SubjectCandidatePicker } from '@/components/Authz/SubjectCandidatePicker';
import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { SUBJECT_KINDS, subjectKindLabel } from '@/lib/Authz/subjectKinds';
import type { AuthzSubjectCandidate, AuthzSubjectKind } from '@/types';

const KIND_ID = 'authz-column-kind';
const TARGET_ID = 'authz-column-target';

/**
 * 種類のラジオ(名前 = 見出し「種類」)。
 */
function KindField({
    kind,
    onChange,
}: {
    kind: AuthzSubjectKind;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock
            id={KIND_ID}
            label="種類"
            isRequired
            hint="条件は 1 つだけ指定できます。組み合わせや除外は指定できません。"
        >
            <RadioGroup
                value={kind}
                onValueChange={onChange}
                aria-labelledby={`${KIND_ID}-label`}
                className="flex gap-4"
            >
                {SUBJECT_KINDS.map((item) => (
                    <div key={item.value} className="flex items-center gap-2">
                        <RadioGroupItem
                            id={`${KIND_ID}-${item.value}`}
                            value={item.value}
                        />
                        <Label htmlFor={`${KIND_ID}-${item.value}`}>
                            {item.label}
                        </Label>
                    </div>
                ))}
            </RadioGroup>
        </FormBlock>
    );
}

/**
 * 「列を追加」の入力欄(種類のラジオと、その種類の候補の選択欄)。target_id の誤りは候補の欄の下、ほかの誤りは先頭に出す。
 * 候補の欄は種類ごとに作り直す(前の種類の語と結果を持ち越さない)。
 */
export function AddColumnFields({
    kind,
    picked,
    errors,
    onKindChange,
    onPick,
}: {
    kind: AuthzSubjectKind;
    picked: AuthzSubjectCandidate | null;
    errors: Record<string, string | undefined>;
    onKindChange: (value: string) => void;
    onPick: (candidate: AuthzSubjectCandidate) => void;
}) {
    return (
        <>
            <UnshownErrors errors={errors} shownKeys={['target_id']} />
            <KindField kind={kind} onChange={onKindChange} />
            <FormBlock
                id={TARGET_ID}
                label={subjectKindLabel(kind)}
                isRequired
                hint="追加した列は、すべてのセルが未設定です。"
                error={errors.target_id}
            >
                <SubjectCandidatePicker
                    key={kind}
                    id={TARGET_ID}
                    kind={kind}
                    value={picked}
                    onChange={onPick}
                    error={errors.target_id}
                />
            </FormBlock>
        </>
    );
}
