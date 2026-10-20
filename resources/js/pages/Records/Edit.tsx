import { Head } from '@inertiajs/react';
import { RecordForm } from '@/components/Records/RecordForm';
import { RecordHoldNotice } from '@/components/Records/RecordHoldNotice';
import { editFormData } from '@/lib/recordForm';
import { recordHeading } from '@/lib/recordValues';
import { edit, index, show, update } from '@/routes/records/data';
import type {
    RecordEditable,
    RecordHold,
    RecordModuleDefinition,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    record: RecordEditable;
    hold: RecordHold | null;
};

/**
 * 見出し = 「<表示の項目の値か #id>の編集」(画面/業務の画面.md 2.2)。
 */
function headingOf(module: RecordModuleDefinition, record: RecordEditable) {
    return `${recordHeading(module, record)}の編集`;
}

/**
 * 業務の編集(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2): 画面の流れ・欄の初期値・持ち主があるときの出し方の正本は同じ節。
 */
export default function Edit({ module, record, hold }: Props) {
    const heading = headingOf(module, record);
    const detail = show({ module: module.code, record: record.id });

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{heading}</h1>
                {hold !== null && <RecordHoldNotice hold={hold} />}
                <RecordForm
                    module={module}
                    recordId={record.id}
                    initialData={editFormData(module, record)}
                    version={record.version}
                    action={update({ module: module.code, record: record.id })}
                    submitLabel="更新"
                    submitDisabled={hold !== null}
                    cancelHref={detail}
                />
            </div>
        </>
    );
}

Edit.layout = (props: Props) => ({
    breadcrumbs: [
        { title: props.module.name, href: index(props.module.code) },
        {
            title: headingOf(props.module, props.record),
            href: edit({ module: props.module.code, record: props.record.id }),
        },
    ],
});
