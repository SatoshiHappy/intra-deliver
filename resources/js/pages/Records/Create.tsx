import { Head } from '@inertiajs/react';
import { RecordForm } from '@/components/Records/RecordForm';
import { createFormData } from '@/lib/recordForm';
import { create, index, store } from '@/routes/records/data';
import type { RecordModuleDefinition } from '@/types';

type Props = {
    module: RecordModuleDefinition;
    /** 一覧を開けるか(登録だけを許された利用者には、キャンセルとパンくずの一覧へのリンクを出さない) */
    can: { read: boolean };
};

/**
 * 見出し = 「<業務モジュールの名前>の登録」(画面/業務の画面.md 2.2)。
 */
function headingOf(module: RecordModuleDefinition) {
    return `${module.name}の登録`;
}

/**
 * 業務の一覧へのリンク(一覧を開けない利用者には null。キャンセルとパンくずが同じ判定を使う = 画面/業務の画面.md 2.2)。
 */
function listHrefOf(module: RecordModuleDefinition, can: Props['can']) {
    return can.read ? index(module.code) : null;
}

/**
 * 業務の登録(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2)。
 */
export default function Create({ module, can }: Props) {
    const heading = headingOf(module);

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{heading}</h1>
                <RecordForm
                    module={module}
                    recordId={null}
                    initialData={createFormData(module)}
                    action={store(module.code)}
                    submitLabel="登録"
                    cancelHref={listHrefOf(module, can)}
                />
            </div>
        </>
    );
}

Create.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: props.module.name,
            href: listHrefOf(props.module, props.can),
        },
        { title: headingOf(props.module), href: create(props.module.code) },
    ],
});
