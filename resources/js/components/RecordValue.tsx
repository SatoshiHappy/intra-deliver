import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactElement } from 'react';
import { PartyMark } from '@/components/PartyMark';
import { RichText } from '@/components/RichText';
import type { RecordValuePlace } from '@/lib/recordValues';
import { partiesOf, partyKindOf } from '@/lib/recordParty';
import {
    formatRecordValue,
    isNumericValue,
    referenceLabel,
    shownReferences,
} from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import { show } from '@/routes/records/data';
import type {
    RecordFieldDefinition,
    RecordFieldValue,
    RecordReferenceValue,
} from '@/types';

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
 * 他モジュール参照の値の、参照先の詳細へのリンク(docs の 設計/業務データの入れ物/画面/README.md 1.3)。他モジュール参照の相手でなければ null。
 */
function recordLinkValue(
    field: RecordFieldDefinition,
    value: RecordFieldValue,
    text: string,
    className: string,
): ReactElement | null {
    const referenced = field.referencedModule;

    if (
        field.type !== 'record' ||
        referenced === null ||
        typeof value !== 'object' ||
        value === null ||
        !('id' in value)
    ) {
        return null;
    }

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

/**
 * ユーザーと組織が混ざりうる値(相手ごとに種類の印を前に置き、「、」でつなぐ。表のセルの添え書きは文字の表示と同じ = 画面/README.md 1.3)。
 */
function PartiesValue({
    field,
    parties,
    place,
}: {
    field: RecordFieldDefinition;
    parties: RecordReferenceValue[];
    place: RecordValuePlace;
}) {
    const { shown, rest } = shownReferences(field, parties, place);

    return (
        <span className="wrap-anywhere">
            {shown.map((party, index) => {
                const label = referenceLabel(field, party);
                const kind = partyKindOf(field, party);

                return (
                    <Fragment key={`${kind}:${party.id}`}>
                        {index > 0 && '、'}
                        <span className="inline-flex items-center gap-1 align-middle">
                            <PartyMark
                                kind={kind}
                                label={label}
                                size="inline"
                                announced
                            />
                            {label}
                        </span>
                    </Fragment>
                );
            })}
            {rest !== null && ` ${rest}`}
        </span>
    );
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
    const multiline = multilineValue(field, value, place, text);

    if (multiline !== null) {
        return multiline;
    }

    const parties = partiesOf(field, value);

    if (parties !== null) {
        return <PartiesValue field={field} parties={parties} place={place} />;
    }

    return (
        recordLinkValue(field, value, text, className) ?? (
            <span className={className}>{text}</span>
        )
    );
}
