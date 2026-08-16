<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="isEditMode ? $gettext('Edit Web Stream') : $gettext('Add Web Stream')"
        :error="error"
        :disable-save-button="!isValid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="web_stream_name">{{ $gettext('Stream Name') }}</label>
                <input id="web_stream_name" v-model.trim="form.name" type="text" class="form-control">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="web_stream_url">{{ $gettext('Stream URL') }}</label>
                <input id="web_stream_url" v-model.trim="form.remote_url" type="url" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="web_stream_type">{{ $gettext('URL Type') }}</label>
                <select id="web_stream_type" v-model="form.remote_type" class="form-select">
                    <option :value="PlaylistRemoteTypes.Stream">{{ $gettext('Icecast/Shoutcast Stream') }}</option>
                    <option :value="PlaylistRemoteTypes.Playlist">{{ $gettext('M3U/PLS Playlist') }}</option>
                    <option :value="PlaylistRemoteTypes.Other">{{ $gettext('Other URL') }}</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="web_stream_buffer">{{ $gettext('Playback Buffer (Seconds)') }}</label>
                <input
                    id="web_stream_buffer"
                    v-model.number="form.remote_buffer"
                    type="number"
                    min="0"
                    max="120"
                    class="form-control"
                >
            </div>
            <div class="col-md-8 d-flex align-items-end pb-2">
                <div class="form-check">
                    <input id="web_stream_enabled" v-model="form.is_enabled" type="checkbox" class="form-check-input">
                    <label for="web_stream_enabled" class="form-check-label">{{ $gettext('Enabled') }}</label>
                </div>
            </div>
        </div>
    </modal-form>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from "vue";
import ModalForm from "~/components/Common/ModalForm.vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios, getErrorAsString} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {PlaylistRemoteTypes, PlaylistSources} from "~/entities/ApiInterfaces.ts";

type WebStreamForm = {
    name: string,
    remote_url: string,
    remote_type: PlaylistRemoteTypes,
    remote_buffer: number,
    is_enabled: boolean,
    backend_options: string[],
}

const props = defineProps<{createUrl: string}>();
const emit = defineEmits<{
    relist: [],
    needsRestart: [],
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const defaultForm = (): WebStreamForm => ({
    name: '',
    remote_url: '',
    remote_type: PlaylistRemoteTypes.Stream,
    remote_buffer: 0,
    is_enabled: true,
    backend_options: [],
});

const form = ref<WebStreamForm>(defaultForm());
const loading = ref(false);
const error = ref<string | null>(null);
const editUrl = ref<string | null>(null);
const isEditMode = computed(() => null !== editUrl.value);
const isValid = computed(() => '' !== form.value.name && '' !== form.value.remote_url);
const $modal = useTemplateRef('$modal');

const clearContents = (): void => {
    form.value = defaultForm();
    loading.value = false;
    error.value = null;
    editUrl.value = null;
};

const create = (): void => {
    clearContents();
    $modal.value?.show();
};

const edit = async (url: string): Promise<void> => {
    clearContents();
    editUrl.value = url;
    loading.value = true;
    $modal.value?.show();

    try {
        const {data} = await axios.get<WebStreamForm>(url);
        form.value = {
            ...defaultForm(),
            ...data,
        };
    } catch (e: unknown) {
        error.value = getErrorAsString(e);
    } finally {
        loading.value = false;
    }
};

const doSubmit = async (): Promise<void> => {
    if (!isValid.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const payload = {
            ...form.value,
            source: PlaylistSources.RemoteUrl,
        };

        if (null !== editUrl.value) {
            await axios.put(editUrl.value, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }

        notifySuccess();
        $modal.value?.hide();
        emit('relist');
        emit('needsRestart');
    } catch (e: unknown) {
        error.value = getErrorAsString(e);
    } finally {
        loading.value = false;
    }
};

defineExpose({create, edit});
</script>
