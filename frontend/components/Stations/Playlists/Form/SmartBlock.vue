<template>
    <tab :label="$gettext('Smart Block')">
        <smart-block-criteria-editor
            v-if="form.id !== null"
            :smart-block-url="smartBlockUrl"
        />
    </tab>
</template>

<script setup lang="ts">
import {computed} from "vue";
import {storeToRefs} from "pinia";
import Tab from "~/components/Common/Tab.vue";
import SmartBlockCriteriaEditor from "~/components/Stations/Playlists/SmartBlockCriteriaEditor.vue";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {form} = storeToRefs(useStationsPlaylistsForm());
const {getStationApiUrl} = useApiRouter();

const smartBlockUrl = getStationApiUrl(computed(() => (
    form.value.id === null
        ? '/playlist/0/smart-block'
        : `/playlist/${form.value.id}/smart-block`
)));
</script>
