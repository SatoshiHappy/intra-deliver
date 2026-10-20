import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { RichText } from '@/components/RichText';
import type { RecordValuePlace } from '@/lib/recordValues';
import { formatRecordValue, isNumericValue } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import { show } from '@/routes/records/data';
import type { RecordFieldDefinition, RecordFieldValue } from '@/types';

/**
 * 複数行の文字列の場所ごとの見え方(docs の 設計/業務データの入れ物/画面/README.md 1.4)。null なら下の今の表示。
 * 表のセルは折り返さない置き場(whitespace-nowrap の表)でも 2 行で切れるよう、折り返しを戻す。
 */
function multilineValue(
    field: RecordFieldDefinition,
    value: RecordFieldValue,
    place: RecordValuePlace,
    text: string,
): ReactElement | null {
    if (field.type !== 'string' || !field.isMultiline) {
        return null;
    }

    if (place === 'cell') {
        return (
            <div className="line-clamp-2 min-h-[2lh] max-w-xl wrap-anywhere whitespace-normal">
                {text}
            </div>
        );
    }

    return place === 'detail' && typeof value === 'string' && value !== '' ? (
        <RichText source={value} />
    ) : null;
}

/**
 * 業務データの値 1 つ(docs の 設計/業務データの入れ物/画面/README.md 1.3・1.4)。表示の文字列は formatRecordValue で作る。
 * 長い連続した文字列は折り返す(wrap-anywhere。画面の意匠の README 方針 9 (c))。場所の見え方(place)は呼ぶ所から受ける。
 */
export function RecordValue({
    field,
    value,
    place = 'detail',
}: {
    field: RecordFieldDefinition;
    value: RecordFieldValue;
    place?: RecordValuePlace;
}) {
    const text = formatRecordValue(field, value, place);
    const className = cn(
        'wrap-anywhere',
        isNumericValue(field, value) && 'font-num tabular-nums',
        field.isMultiline && 'whitespace-pre-line',
    );
    const referenced = field.referencedModule;

    const multiline = multilineValue(field, value, place, text);

    if (multiline !== null) {
        return multiline;
    }

    if (
        field.type === 'record' &&
        referenced !== null &&
        typeof value === 'object' &&
        value !== null &&
        'id' in value
    ) {
        return (
            <Link
                href={show({ module: referenced.code, record: value.id })}
                className={cn(
                    'text-primary underline-offset-4 hover:underline',
                    className,
                )}
            >
                {text}
            </Link>
        );
    }

    return <span className={className}>{text}</span>;
}
