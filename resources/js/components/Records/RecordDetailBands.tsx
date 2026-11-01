import { useState } from 'react';
import { RecordFoldSection } from '@/components/RecordFoldSection';
import { RecordDetailsTable } from '@/components/Records/RecordDetailsTable';
import type { RecordDetailRow, RecordFieldDefinition } from '@/types';

/** 明細の帯 1 つ(帯「<明細の名前> <n> 件」と全幅の表(字下げは見出しのまとまりの中と同じ)。0 件でも出す。畳みは保たない = 既定は開く) */
function DetailBand({
    field,
    rows,
    idPrefix,
}: {
    field: RecordFieldDefinition;
    rows: RecordDetailRow[];
    idPrefix: string;
}) {
    const [isOpen, setOpen] = useState(true);

    return (
        <RecordFoldSection
            bodyId={`${idPrefix}-details-${field.code}`}
            name={field.name}
            note={`${rows.length} 件`}
            isOpen={isOpen}
            onToggle={() => setOpen(!isOpen)}
        >
            <RecordDetailsTable field={field} rows={rows} />
        </RecordFoldSection>
    );
}

/**
 * 詳細タブの明細(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節): 見出しのまとまりの後に、
 * 明細の項目の並びの順で、見出しの行と同じ薄い帯と全幅の表(字下げは見出しのまとまりの中と同じ)を出す。
 */
export function RecordDetailBands({
    fields,
    details,
    idPrefix,
}: {
    fields: RecordFieldDefinition[];
    details: Record<string, RecordDetailRow[]>;
    idPrefix: string;
}) {
    return fields.map((field) => (
        <DetailBand
            key={field.code}
            field={field}
            rows={details[field.code] ?? []}
            idPrefix={idPrefix}
        />
    ));
}
