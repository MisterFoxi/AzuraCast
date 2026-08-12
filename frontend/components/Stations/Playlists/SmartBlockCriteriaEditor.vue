<template>
    <div>
        <div
            v-if="loading"
            class="p-5 text-center"
        >
            <div class="spinner-border" />
        </div>

        <template v-else>
            <div class="row g-3 mb-3">
                <form-group-checkbox
                    id="smart_block_enabled"
                    class="col-12"
                    v-model="editor.is_smart_block"
                    :label="$gettext('Enable Smart Block')"
                    :description="$gettext('Automatically keep this playlist populated with media matching the criteria below. The playlist can then be used normally, including as a Group List member.')"
                />

                <form-group-multi-check
                    v-if="editor.is_smart_block"
                    id="smart_block_type"
                    class="col-md-6"
                    v-model="editor.smart_block_type"
                    :options="typeOptions"
                    stacked
                    radio
                    :label="$gettext('Synchronization')"
                />

                <form-group-multi-check
                    v-if="editor.is_smart_block"
                    id="smart_block_match_type"
                    class="col-md-6"
                    v-model="editor.smart_block_match_type"
                    :options="matchTypeOptions"
                    stacked
                    radio
                    :label="$gettext('Criteria Matching')"
                />
            </div>

            <template v-if="editor.is_smart_block">
                <div
                    v-if="availableCategories.length === 0"
                    class="alert alert-info"
                >
                    {{ $gettext('No media categories exist for this station yet. Create a category in Music Files before adding a Category criterion.') }}
                </div>

                <div
                    v-for="(row, index) in editor.criteria"
                    :key="row.rowId"
                    class="row g-2 align-items-end mb-3 pb-3 border-bottom"
                >
                    <form-group-select
                        :id="`smart_block_field_${index}`"
                        class="col-12 col-md-3"
                        :model-value="row.field"
                        :options="fieldOptions"
                        :label="$gettext('Field')"
                        @update:model-value="updateField(index, $event as SmartBlockCriteriaField)"
                    />

                    <form-group-select
                        :id="`smart_block_comparison_${index}`"
                        class="col-12 col-md-3"
                        :model-value="row.comparison"
                        :options="comparisonOptionsFor(row)"
                        :label="$gettext('Comparison')"
                        @update:model-value="updateRow(index, {comparison: $event as SmartBlockCriteriaComparison})"
                    />

                    <form-group-select
                        v-if="row.field === SmartBlockCriteriaField.Category"
                        :id="`smart_block_category_${index}`"
                        class="col-12 col-md-4"
                        :model-value="row.value ?? ''"
                        :options="categoryOptions"
                        :label="$gettext('Category')"
                        @update:model-value="updateRow(index, {value: String($event)})"
                    />

                    <form-group-select
                        v-else-if="row.field === SmartBlockCriteriaField.CustomField"
                        :id="`smart_block_custom_field_${index}`"
                        class="col-12 col-md-2"
                        :model-value="row.custom_field_id ? String(row.custom_field_id) : ''"
                        :options="customFieldOptions"
                        :label="$gettext('Custom Field')"
                        @update:model-value="updateRow(index, {custom_field_id: Number($event) || null})"
                    />

                    <form-group-field
                        v-if="row.field !== SmartBlockCriteriaField.Category"
                        :id="`smart_block_value_${index}`"
                        :class="row.field === SmartBlockCriteriaField.CustomField ? 'col-12 col-md-2' : 'col-12 col-md-4'"
                        :model-value="row.value ?? ''"
                        :label="valueLabelFor(row)"
                        @update:model-value="updateRow(index, {value: String($event)})"
                    />

                    <form-group-field
                        v-if="row.comparison === SmartBlockCriteriaComparison.Between"
                        :id="`smart_block_value2_${index}`"
                        class="col-12 col-md-2"
                        :model-value="row.value2 ?? ''"
                        :label="$gettext('And')"
                        @update:model-value="updateRow(index, {value2: String($event)})"
                    />

                    <div class="col-12 col-md-auto">
                        <button
                            type="button"
                            class="btn btn-danger"
                            :title="$gettext('Remove Criterion')"
                            @click="removeRow(index)"
                        >
                            <icon-ic-delete />
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="addRow"
                >
                    <icon-ic-add class="me-1" />{{ $gettext('Add Criterion') }}
                </button>

                <div class="row g-3 mt-1">
                    <form-group-multi-check
                        id="smart_block_limit_type"
                        class="col-md-6"
                        v-model="editor.smart_block_limit_type"
                        :options="limitTypeOptions"
                        stacked
                        radio
                        :label="$gettext('Optional Limit')"
                    />

                    <form-group-field
                        id="smart_block_limit"
                        class="col-md-6"
                        v-model="displayLimit"
                        input-type="number"
                        :input-attrs="{min: '1'}"
                        :label="limitLabel"
                        :description="$gettext('Leave empty for no limit.')"
                    />
                </div>
            </template>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="saving || !canSave"
                    @click="save"
                >
                    <span
                        v-if="saving"
                        class="spinner-border spinner-border-sm me-1"
                    />
                    {{ editor.is_smart_block ? $gettext('Save and Synchronize') : $gettext('Disable Smart Block') }}
                </button>

                <span
                    v-if="editor.is_smart_block"
                    class="text-muted"
                >
                    {{ $gettext('%{count} matching tracks; %{members} currently in playlist', {
                        count: preview.matching_count,
                        members: preview.current_member_count
                    }) }}
                </span>
            </div>

            <div
                v-if="editor.is_smart_block && preview.preview.length > 0"
                class="mt-3"
            >
                <h3 class="h6">{{ $gettext('Matching Track Preview') }}</h3>
                <ul class="list-group list-group-flush">
                    <li
                        v-for="track in preview.preview"
                        :key="track.id"
                        class="list-group-item"
                    >
                        {{ track.artist ? `${track.artist} — ` : '' }}{{ track.title || $gettext('(Untitled)') }}
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref, watch} from "vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {
    SmartBlockCriteriaComparison,
    SmartBlockCriteriaField,
    SmartBlockLimitType,
    SmartBlockMatchType,
    SmartBlockType,
    type StationPlaylistSmartBlockCriterion,
} from "~/entities/ApiInterfaces.ts";
import {getErrorAsString, useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import IconIcAdd from "~icons/ic/baseline-add";
import IconIcDelete from "~icons/ic/baseline-delete";

const props = defineProps<{
    smartBlockUrl: string,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();

type CriterionRow = StationPlaylistSmartBlockCriterion & {rowId: number};
type SmartBlockResponse = {
    is_smart_block: boolean,
    smart_block_type: SmartBlockType,
    smart_block_match_type: SmartBlockMatchType,
    smart_block_limit: number | null,
    smart_block_limit_type: SmartBlockLimitType,
    criteria: StationPlaylistSmartBlockCriterion[],
    current_member_count: number,
    matching_count: number,
    preview: Array<{id: number, title?: string | null, artist?: string | null}>,
    available_categories: Array<{id: number, name: string}>,
    available_custom_fields: Array<{id: number, name: string, short_name: string}>,
};

const loading = ref(true);
const saving = ref(false);
let nextRowId = 1;

const editor = ref({
    is_smart_block: false,
    smart_block_type: SmartBlockType.Dynamic,
    smart_block_match_type: SmartBlockMatchType.All,
    smart_block_limit: null as number | null,
    smart_block_limit_type: SmartBlockLimitType.Tracks,
    criteria: [] as CriterionRow[],
});

const preview = ref({
    current_member_count: 0,
    matching_count: 0,
    preview: [] as SmartBlockResponse['preview'],
});

const availableCategories = ref<SmartBlockResponse['available_categories']>([]);
const availableCustomFields = ref<SmartBlockResponse['available_custom_fields']>([]);

const typeOptions = [
    {value: SmartBlockType.Dynamic, text: $gettext('Dynamic — refresh before playback')},
    {value: SmartBlockType.Static, text: $gettext('Static — synchronize only when saved')},
];

const matchTypeOptions = [
    {value: SmartBlockMatchType.All, text: $gettext('Match all criteria (AND)')},
    {value: SmartBlockMatchType.Any, text: $gettext('Match any criterion (OR)')},
];

const limitTypeOptions = [
    {value: SmartBlockLimitType.Tracks, text: $gettext('Number of Tracks')},
    {value: SmartBlockLimitType.Duration, text: $gettext('Duration')},
];

const fieldOptions = {
    [SmartBlockCriteriaField.Category]: $gettext('Category'),
    [SmartBlockCriteriaField.Genre]: $gettext('Genre'),
    [SmartBlockCriteriaField.Artist]: $gettext('Artist'),
    [SmartBlockCriteriaField.Album]: $gettext('Album'),
    [SmartBlockCriteriaField.Title]: $gettext('Title'),
    [SmartBlockCriteriaField.Duration]: $gettext('Duration'),
    [SmartBlockCriteriaField.LastPlayed]: $gettext('Last Played (days ago)'),
    [SmartBlockCriteriaField.CustomField]: $gettext('Custom Field'),
};

const categoryOptions = computed<Record<string, string>>(() => Object.fromEntries(
    availableCategories.value.map((category) => [String(category.id), category.name])
));

const customFieldOptions = computed<Record<string, string>>(() => Object.fromEntries(
    availableCustomFields.value.map((field) => [String(field.id), field.name])
));

const textComparisonOptions = {
    [SmartBlockCriteriaComparison.Is]: $gettext('Is'),
    [SmartBlockCriteriaComparison.IsNot]: $gettext('Is Not'),
    [SmartBlockCriteriaComparison.Contains]: $gettext('Contains'),
    [SmartBlockCriteriaComparison.NotContains]: $gettext('Does Not Contain'),
};

const numericComparisonOptions = {
    [SmartBlockCriteriaComparison.Is]: $gettext('Is'),
    [SmartBlockCriteriaComparison.IsNot]: $gettext('Is Not'),
    [SmartBlockCriteriaComparison.GreaterThan]: $gettext('Greater Than'),
    [SmartBlockCriteriaComparison.LessThan]: $gettext('Less Than'),
    [SmartBlockCriteriaComparison.Between]: $gettext('Between'),
};

const comparisonOptionsFor = (row: CriterionRow): Record<string, string> => {
    if (row.field === SmartBlockCriteriaField.Category) {
        return {
            [SmartBlockCriteriaComparison.Is]: $gettext('Is'),
            [SmartBlockCriteriaComparison.IsNot]: $gettext('Is Not'),
        };
    }
    if (row.field === SmartBlockCriteriaField.Duration || row.field === SmartBlockCriteriaField.LastPlayed) {
        return numericComparisonOptions;
    }
    if (row.field === SmartBlockCriteriaField.CustomField) {
        return {...textComparisonOptions, ...numericComparisonOptions};
    }
    return textComparisonOptions;
};

const valueLabelFor = (row: CriterionRow): string => {
    if (row.field === SmartBlockCriteriaField.Duration) {
        return $gettext('Value (seconds)');
    }
    if (row.field === SmartBlockCriteriaField.LastPlayed) {
        return $gettext('Value (days)');
    }
    return $gettext('Value');
};

const displayLimit = computed<number | null>({
    get: () => {
        if (editor.value.smart_block_limit === null) {
            return null;
        }
        return editor.value.smart_block_limit_type === SmartBlockLimitType.Duration
            ? Math.round(editor.value.smart_block_limit / 60)
            : editor.value.smart_block_limit;
    },
    set: (value) => {
        const numericValue = Number(value);
        editor.value.smart_block_limit = !numericValue || numericValue < 1
            ? null
            : editor.value.smart_block_limit_type === SmartBlockLimitType.Duration
                ? Math.round(numericValue * 60)
                : Math.round(numericValue);
    },
});

const limitLabel = computed(() => editor.value.smart_block_limit_type === SmartBlockLimitType.Duration
    ? $gettext('Maximum Duration (minutes)')
    : $gettext('Maximum Tracks'));

const canSave = computed(() => {
    if (!editor.value.is_smart_block) {
        return true;
    }
    if (editor.value.criteria.length === 0) {
        return false;
    }
    return editor.value.criteria.every((row) => {
        if (!row.value?.trim()) {
            return false;
        }
        if (row.field === SmartBlockCriteriaField.CustomField && !row.custom_field_id) {
            return false;
        }
        return row.comparison !== SmartBlockCriteriaComparison.Between || Boolean(row.value2?.trim());
    });
});

const load = async (): Promise<void> => {
    loading.value = true;
    try {
        const {data} = await axios.get<SmartBlockResponse>(props.smartBlockUrl);
        editor.value = {
            is_smart_block: data.is_smart_block,
            smart_block_type: data.smart_block_type,
            smart_block_match_type: data.smart_block_match_type,
            smart_block_limit: data.smart_block_limit,
            smart_block_limit_type: data.smart_block_limit_type,
            criteria: data.criteria.map((criterion) => ({...criterion, rowId: nextRowId++})),
        };
        preview.value = {
            current_member_count: data.current_member_count,
            matching_count: data.matching_count,
            preview: data.preview,
        };
        availableCategories.value = data.available_categories;
        availableCustomFields.value = data.available_custom_fields;
    } catch (error) {
        notifyError(`${$gettext('Failed to load Smart Block.')}: ${getErrorAsString(error)}`);
    } finally {
        loading.value = false;
    }
};

const addRow = (): void => {
    const firstCategory = availableCategories.value[0];
    editor.value.criteria.push({
        rowId: nextRowId++,
        field: firstCategory ? SmartBlockCriteriaField.Category : SmartBlockCriteriaField.Genre,
        comparison: SmartBlockCriteriaComparison.Is,
        value: firstCategory ? String(firstCategory.id) : '',
        value2: null,
        custom_field_id: null,
    });
};

const removeRow = (index: number): void => {
    editor.value.criteria.splice(index, 1);
};

const updateRow = (index: number, changes: Partial<StationPlaylistSmartBlockCriterion>): void => {
    Object.assign(editor.value.criteria[index], changes);
};

const updateField = (index: number, field: SmartBlockCriteriaField): void => {
    const firstCategory = availableCategories.value[0];
    const isNumeric = field === SmartBlockCriteriaField.Duration || field === SmartBlockCriteriaField.LastPlayed;
    updateRow(index, {
        field,
        comparison: isNumeric ? SmartBlockCriteriaComparison.GreaterThan : SmartBlockCriteriaComparison.Is,
        value: field === SmartBlockCriteriaField.Category && firstCategory ? String(firstCategory.id) : '',
        value2: null,
        custom_field_id: null,
    });
};

const save = async (): Promise<void> => {
    saving.value = true;
    try {
        const criteria = editor.value.criteria.map((criterion, weight) => ({
            field: criterion.field,
            comparison: criterion.comparison,
            value: criterion.value,
            value2: criterion.value2,
            custom_field_id: criterion.custom_field_id,
            weight,
        }));
        const {data} = await axios.put(props.smartBlockUrl, {
            is_smart_block: editor.value.is_smart_block,
            smart_block_type: editor.value.smart_block_type,
            smart_block_match_type: editor.value.smart_block_match_type,
            smart_block_limit: editor.value.smart_block_limit,
            smart_block_limit_type: editor.value.smart_block_limit_type,
            criteria,
        });

        notifySuccess(editor.value.is_smart_block
            ? $gettext('%{added} tracks added and %{removed} removed.', {
                added: data.added ?? 0,
                removed: data.removed ?? 0,
            })
            : $gettext('Smart Block disabled.'));
        await load();
    } catch (error) {
        notifyError(`${$gettext('Failed to save Smart Block.')}: ${getErrorAsString(error)}`);
    } finally {
        saving.value = false;
    }
};

watch(() => props.smartBlockUrl, () => void load());
onMounted(() => void load());
</script>
