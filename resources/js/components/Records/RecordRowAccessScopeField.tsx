import { formErrorId } from '@/components/FormBlock';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { RowAccessScope } from '@/types';

/** 選択肢の語(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 2 節) */
const CHOICES: { value: RowAccessScope; label: string }[] = [
    { value: 'all', label: '全員' },
    { value: 'related', label: '関係する人だけ' },
];

/** ラジオ「見える範囲」の説明(業務モジュールの管理.md 2 節) */
const HINT =
    '関係する人だけにすると、作成者と、「関係する人」を設定したユーザー・組織の項目に入っている人(組織は所属する人)だけが' +
    'レコードを表示できます。全件の参照を許可された人(初期設定ではシステム管理者)は、すべてのレコードを表示できます。';

/**
 * 選んだ値を見える範囲にする(選択肢に無い値は undefined)。
 */
function scopeOf(value: string): RowAccessScope | undefined {
    return CHOICES.find((choice) => choice.value === value)?.value;
}

/**
 * 選択肢のラジオ(名前 = 見出し「見える範囲」、説明と誤りの文言に結ぶ)。
 */
function ScopeRadios({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: RowAccessScope;
    onChange: (value: RowAccessScope) => void;
    error: string | undefined;
}) {
    const describedBy = [
        `${id}-hint`,
        ...(error === undefined ? [] : [formErrorId(id)]),
    ];

    return (
        <RadioGroup
            value={value}
            onValueChange={(next) => {
                const scope = scopeOf(next);

                if (scope !== undefined) {
                    onChange(scope);
                }
            }}
            aria-labelledby={`${id}-label`}
            aria-describedby={describedBy.join(' ')}
            aria-invalid={error !== undefined || undefined}
            className="gap-2"
        >
            {CHOICES.map((choice) => (
                <div key={choice.value} className="flex items-center gap-2">
                    <RadioGroupItem
                        id={`${id}-${choice.value}`}
                        value={choice.value}
                    />
                    <Label htmlFor={`${id}-${choice.value}`}>
                        {choice.label}
                    </Label>
                </div>
            ))}
        </RadioGroup>
    );
}

/**
 * 業務モジュールの編集の枠「属性」のラジオ「見える範囲」(明細のモジュールでは呼び手が出さない)。戻せる操作なので確認の Dialog は出さない。
 */
export function RecordRowAccessScopeField({
    value,
    onChange,
    error,
}: {
    value: RowAccessScope;
    onChange: (value: RowAccessScope) => void;
    error: string | undefined;
}) {
    const id = 'module-row_access_scope';

    return (
        <div className="flex flex-col gap-2">
            <p id={`${id}-label`} className="text-sm font-medium">
                見える範囲
            </p>
            <ScopeRadios
                id={id}
                value={value}
                onChange={onChange}
                error={error}
            />
            <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                {HINT}
            </p>
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
