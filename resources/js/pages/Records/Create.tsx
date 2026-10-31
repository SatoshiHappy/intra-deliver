import { Head } from '@inertiajs/react';
import { RecordForm } from '@/components/Records/RecordForm';
import { createFormData, editFormData } from '@/lib/recordForm';
import { create, index, show, store } from '@/routes/records/data';
import type {
    RecordCopySource,
    RecordHeadingGroup,
    RecordModuleDefinition,
    RowAccessInput,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    /** 見出しのまとまり(フォームの欄を見出しごとに畳める) */
    headings: RecordHeadingGroup[];
    /** 一覧を開けるか(登録だけを許された利用者には、キャンセルとパンくずの一覧へのリンクを出さない) */
    can: { read: boolean };
    /** 複製の元の値(クエリ copy のときだけ。レコードの共通機能/複製.md の 1 節) */
    copySource: RecordCopySource | null;
    /** 参照制限の初期値(業務モジュールの参照制限の既定と空の参照者 = 画面/業務の画面.md 2.2) */
    rowAccess: RowAccessInput;
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
 * 「キャンセル」の移り先(複製は元のレコードの詳細 = 複製.md の 1 節、ほかは一覧)。
 */
function cancelHrefOf(props: Props) {
    return props.copySource === null
        ? listHrefOf(props.module, props.can)
        : show({ module: props.module.code, record: props.copySource.id });
}

/**
 * 業務の登録(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2)。複製は元の値(項目と明細。明細の行は新しい行。参照制限も
 * 業務モジュールの既定でなく元の値)を初期値にする(設計/業務データの入れ物/レコードの共通機能/複製.md の 1 節・2 節)。
 */
export default function Create(props: Props) {
    const { module, headings, copySource, rowAccess } = props;
    const heading = headingOf(module);

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{heading}</h1>
                <RecordForm
                    module={module}
                    headings={headings}
                    recordId={null}
                    initialData={
                        copySource === null
                            ? createFormData(module)
                            : editFormData(module, copySource)
                    }
                    initialRowAccess={copySource?.rowAccess ?? rowAccess}
                    action={store(module.code)}
                    submitLabel="登録"
                    cancelHref={cancelHrefOf(props)}
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
