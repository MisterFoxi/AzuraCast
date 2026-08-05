<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <tabs>
            <form-basic-info/>
            <form-memberships v-if="isEditMode" />
            <form-advanced/>
        </tabs>
    </modal-form>
</template>

<script setup lang="ts">
import FormBasicInfo from "~/components/Stations/Playlists/Form/BasicInfo.vue";
import FormMemberships from "~/components/Stations/Playlists/Form/Memberships.vue";
import FormAdvanced from "~/components/Stations/Playlists/Form/Advanced.vue";
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from "~/functions/useBaseEditModal";
import {computed, toRef, useTemplateRef} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import ModalForm from "~/components/Common/ModalForm.vue";
import Tabs from "~/components/Common/Tabs.vue";
import {storeToRefs} from "pinia";
import {useAppCollectScope} from "~/vendor/regle.ts";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import mergeExisting from "~/functions/mergeExisting.ts";

const props = defineProps<BaseEditModalProps>();

const emit = defineEmits<BaseEditModalEmits & {
    (e: 'needs-restart'): void
}>();

const $modal = useTemplateRef('$modal');

const {notifySuccess} = useNotify();

const formStore = useStationsPlaylistsForm();
const {form, r$} = storeToRefs(formStore);
const {$reset: resetForm} = formStore;

const {r$: validatedr$} = useAppCollectScope('stations-playlists');

const {
    loading,
    error,
    isEditMode,
    clearContents,
    create,
    edit,
    doSubmit,
    close
} = useBaseEditModal(
    toRef(props, 'createUrl'),
    emit,
    $modal,
    resetForm,
    (data) => {
        if (data.order === 'smart_shuffle') {
            data.order = 'shuffle';
        }
        r$.value.$reset({
            toState: mergeExisting(r$.value.$value, data)
        })
    },
    async () => {
        const {valid} = await validatedr$.$validate();
        const data = { ...form.value } as Record<string, unknown>;

        // Never send a null playlist id on create — serializer requires int.
        if (data.id == null) {
            delete data.id;
        }

        // These are read-only relations managed via the Playlist Grouping tab / members API,
        // not writable fields on the playlist record itself.
        delete data.playlists;
        delete data.playlist_groups;

        // Phase 0 - data guard: the playlist PUT is PARTIAL.
        // Omit the key => schedules untouched; sending [] => ALL schedules wiped.
        // Scheduling is edited exclusively via the agenda (CreateEventModal).
        delete data.schedule_items;
        return { valid, data };
    },
    {
        onSubmitSuccess: () => {
            notifySuccess();
            emit('relist');
            emit('needs-restart');
            close();
        },
    }
);

const {$gettext} = useTranslate();

const langTitle = computed(() => {
    return isEditMode.value
        ? $gettext('Edit Playlist')
        : $gettext('Add Playlist');
});

defineExpose({
    create,
    edit,
    close
});
</script>
