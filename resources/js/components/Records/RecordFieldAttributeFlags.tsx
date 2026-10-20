import { formErrorId } from '@/components/FormBlock';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { AttributeFlag } from '@/lib/Records/recordFieldFlags';
import { attributeFlags } from '@/lib/Records/recordFieldFlags';
import type { FieldFormData } from '@/lib/Records/recordFieldForm';

/**
 * 真偽の旗(チェックボックスとラベル、説明、エラー)。
 */
function Flag({
    flag,
    checked,
    onChange,
    error,
}: {
    flag: AttributeFlag;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error: string | undefined;
}) {
    const id = `field-${flag.inputKey}`;
    const describedBy = [
        ...(flag.hint === null ? [] : [`${id}-hint`]),
        ...(error === undefined ? [] : [formErrorId(id)]),
    ];

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    aria-describedby={
                        describedBy.length === 0
                            ? undefined
                            : describedBy.join(' ')
                    }
                    aria-invalid={error !== undefined || undefined}
                    onCheckedChange={(next) => onChange(next === true)}
                />
                <Label htmlFor={id}>{flag.label}</Label>
            </div>
            {flag.hint !== null && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {flag.hint}
                </p>
            )}
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * 項目の追加・編集の右の枠「属性」の旗(型ごとの出し分けと並び = lib/Records/recordFieldFlags.ts)。
 */
export function RecordFieldAttributeFlags({
    data,
    errors,
    onChange,
}: {
    data: FieldFormData;
    errors: Record<string, string | undefined>;
    onChange: (key: AttributeFlag['key'], checked: boolean) => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            {attributeFlags(data).map((flag) => (
                <Flag
                    key={flag.key}
                    flag={flag}
                    checked={data[flag.key]}
                    onChange={(checked) => onChange(flag.key, checked)}
                    error={errors[flag.inputKey]}
                />
            ))}
        </div>
    );
}
