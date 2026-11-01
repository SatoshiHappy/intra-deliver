import { Head, Link } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, invalidProps, UnshownErrors } from '@/components/FormBlock';
import {
    FormActions,
    FormSurface,
    TWO_SURFACES_GRID,
} from '@/components/FormLayout';
import { HeadingHelp } from '@/components/HeadingHelp';
import { MenuIcon } from '@/components/MenuIcon';
import { ModuleAttributes } from '@/components/Records/RecordModuleAttributes';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useRecordModuleForm } from '@/hooks/Records/use-record-module-form';
import { toModulePayload } from '@/lib/Records/recordModuleForm';
import { edit, index, show, update } from '@/routes/records/admin/modules';
import type {
    DefinitionTextMaxLengths,
    ModuleCategoryChoice,
    RecordAdminModuleForm,
    RecordModuleIconChoice,
} from '@/types';

/** 画面名(見出し・パンくず・題) */
const HEADING = '業務モジュールの編集';

/** 欄として描くキー(明細のモジュール・親の無い業務モジュール。ほかのキーの誤りはフォームの面の先頭に出す) */
const DETAILS_KEYS = ['name', 'description'];

const MODULE_KEYS = [
    ...DETAILS_KEYS,
    'icon',
    'is_visible',
    'categories',
    'is_attachment_enabled',
    'row_access_restricted_by_default',
];

/**
 * icons = アイコンの選択肢(作成と同じ)、categories = すべてのモジュール分類(並びの順)、textMaxLengths = 名前・説明の文字数の上限、
 * visibilityStopReason = 表示の止めの理由(止めが無い・明細のモジュールは null)
 */
type Props = {
    module: RecordAdminModuleForm;
    icons: RecordModuleIconChoice[];
    categories: ModuleCategoryChoice[];
    textMaxLengths: DefinitionTextMaxLengths;
    visibilityStopReason: string | null;
};

/**
 * 管理の業務モジュールの編集(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5、モジュール分類/画面と検査.md 4.2)。
 * 欄として描いていないキーの誤りはフォームの面の先頭に出す(3 節の冒頭)。
 */
export default function ModuleEdit({
    module,
    icons,
    categories,
    textMaxLengths,
    visibilityStopReason,
}: Props) {
    const form = useRecordModuleForm(module, visibilityStopReason);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            toModulePayload(data, module.isDetails, categories),
        );
        form.submit(update(module.code));
    };

    const basic = (
        <FormSurface aria-labelledby="module-basic" inner="max-w-2xl">
            <h2 id="module-basic" className="text-[15px] font-semibold">
                基本
            </h2>
            <div className="flex items-center gap-1.5">
                <p className="text-sm">
                    <span className="text-muted-foreground">コード: </span>
                    {module.code}
                </p>
                <HeadingHelp label="コード">作成後は変更できません</HeadingHelp>
            </div>
            <FormBlock
                id="module-name"
                label="名前"
                isRequired
                error={form.errors.name}
            >
                <CountedTextInput
                    id="module-name"
                    value={form.data.name}
                    max={textMaxLengths.name}
                    invalid={form.errors.name !== undefined}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </FormBlock>
            <FormBlock
                id="module-description"
                label="説明"
                error={form.errors.description}
            >
                <CountedTextInput
                    id="module-description"
                    isMultiline
                    value={form.data.description}
                    max={textMaxLengths.description}
                    invalid={form.errors.description !== undefined}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </FormBlock>
            {!module.isDetails && (
                <FormBlock
                    id="module-icon"
                    label="アイコン"
                    hint="サイドバーとサイトマップに表示されます"
                    error={form.errors.icon}
                >
                    <Select
                        value={form.data.icon}
                        onValueChange={(icon) => form.setData('icon', icon)}
                    >
                        <SelectTrigger
                            id="module-icon"
                            className="w-full"
                            {...invalidProps('module-icon', form.errors.icon)}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {icons.map((icon) => (
                                <SelectItem key={icon.value} value={icon.value}>
                                    <MenuIcon
                                        name={icon.value}
                                        className="size-4"
                                    />
                                    {icon.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormBlock>
            )}
        </FormSurface>
    );

    return (
        <>
            <Head title={HEADING} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{HEADING}</h1>
                <form
                    noValidate
                    onSubmit={submit}
                    aria-label="業務モジュールの入力"
                    className="flex flex-col gap-6"
                >
                    <UnshownErrors
                        errors={
                            form.errors as Record<string, string | undefined>
                        }
                        shownKeys={
                            module.isDetails ? DETAILS_KEYS : MODULE_KEYS
                        }
                    />
                    {module.isDetails ? (
                        basic
                    ) : (
                        <div className={TWO_SURFACES_GRID}>
                            {basic}
                            <ModuleAttributes
                                data={form.data}
                                errors={form.errors}
                                categories={categories}
                                visibilityStopReason={visibilityStopReason}
                                onChange={form.setData}
                            />
                        </div>
                    )}
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={show(module.code)}>キャンセル</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            更新
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

ModuleEdit.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '業務モジュール', href: index() },
        { title: props.module.name, href: show(props.module.code) },
        { title: HEADING, href: edit(props.module.code) },
    ],
});
