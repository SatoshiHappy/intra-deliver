import { formErrorId } from '@/components/FormBlock';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

/** 選択肢の語(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 2 節)。値はラジオの文字列 */
const CHOICES: { value: 'false' | 'true'; label: string }[] = [
    { value: 'false', label: '制限しない' },
    { value: 'true', label: '制限する' },
];

/** ラジオ「参照制限の既定」の説明(業務モジュールの管理.md 2 節) */
const HINT =
    '新しく登録するレコードの「参照を制限する」の初期値です。変更しても、登録済みのレコードの参照制限は変わりません。' +
    '参照を制限したレコードは、作成者、「関係者」を設定した項目に入っている人(組織は所属する人)、' +
    'レコードごとに選んだ「参照者」と、全件の参照を許された人(初期設定ではシステム管理者)だけが表示できます。' +
    '申請に使われたレコードの値は、その申請を閲覧できる人も申請の詳細で見られます。';

/**
 * 選択肢のラジオ(名前 = 見出し「参照制限の既定」、説明と誤りの文言に結ぶ)。
 */
function DefaultRadios({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: boolean;
    onChange: (value: boolean) => void;
    error: string | undefined;
}) {
    const describedBy = [
        `${id}-hint`,
        ...(error === undefined ? [] : [formErrorId(id)]),
    ];

    return (
        <RadioGroup
            value={value ? 'true' : 'false'}
            onValueChange={(next) => onChange(next === 'true')}
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
 * 業務モジュールの編集の枠「属性」のラジオ「参照制限の既定」(明細のモジュールでは呼び手が出さない)。今あるレコードを変えず
 * 戻せる操作なので確認の Dialog は出さない。
 */
export function RecordRowAccessDefaultField({
    value,
    onChange,
    error,
}: {
    value: boolean;
    onChange: (value: boolean) => void;
    error: string | undefined;
}) {
    const id = 'module-row_access_restricted_by_default';

    return (
        <div className="flex flex-col gap-2">
            <p id={`${id}-label`} className="text-sm font-medium">
                参照制限の既定
            </p>
            <DefaultRadios
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
