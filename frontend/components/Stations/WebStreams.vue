<template>
    <card-page :title="$gettext('Web / Remote Streams')">
        <template #info>
            <p class="card-text">
                {{ $gettext('Manage external streams separately from song playlists and Group Lists.') }}
            </p>
        </template>

        <template #actions>
            <add-button
                :text="$gettext('Add Stream')"
                @click="$editModal?.create()"
            />
        </template>

        <data-table
            id="station_web_streams"
            paginated
            :fields="fields"
            :provider="listItemProvider"
        >
            <template #cell(name)="row">
                <h5 class="m-0">{{ row.item.name }}</h5>
                <a
                    :href="row.item.remote_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-muted small text-break"
                >
                    {{ row.item.remote_url }}
                </a>
            </template>
            <template #cell(remote_type)="row">
                {{ typeLabel(row.item.remote_type) }}
            </template>
            <template #cell(schedule)="row">
                <span
                    v-if="row.item.schedule_items.length > 0"
                    class="badge text-bg-info"
                >
                    {{ $gettext('%{count} scheduled event(s)', {count: row.item.schedule_items.length}) }}
                </span>
                <span v-else class="text-muted">{{ $gettext('Not scheduled') }}</span>
            </template>
            <template #cell(status)="row">
                <span
                    class="badge"
                    :class="row.item.is_enabled ? 'text-bg-success' : 'text-bg-secondary'"
                >
                    {{ row.item.is_enabled ? $gettext('Enabled') : $gettext('Disabled') }}
                </span>
            </template>
            <template #cell(actions)="row">
                <div class="btn-group btn-group-sm">
                    <button
                        v-if="row.item.links?.self"
                        type="button"
                        class="btn btn-primary"
                        @click="$editModal?.edit(row.item.links.self)"
                    >
                        {{ $gettext('Edit') }}
                    </button>
                    <router-link
                        class="btn btn-secondary"
                        :to="{name: 'stations:schedule:index'}"
                    >
                        {{ $gettext('Schedule') }}
                    </router-link>
                    <button
                        v-if="row.item.links?.self"
                        type="button"
                        class="btn btn-danger"
                        @click="doDelete(row.item.links.self)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </template>
        </data-table>
    </card-page>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="relist"
        @needs-restart="mayNeedRestart"
    />
</template>

<script setup lang="ts">
import {useTemplateRef} from "vue";
import CardPage from "~/components/Common/CardPage.vue";
import AddButton from "~/components/Common/AddButton.vue";
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import EditModal from "~/components/Stations/WebStreams/EditModal.vue";
import {useTranslate} from "~/vendor/gettext";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {ApiWebStream, PlaylistRemoteTypes} from "~/entities/ApiInterfaces.ts";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart.ts";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/playlists');

const fields: DataTableField<ApiWebStream>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Name / URL'), sortable: true},
    {key: 'remote_type', label: $gettext('Type'), sortable: false},
    {key: 'schedule', label: $gettext('Schedule'), sortable: false},
    {key: 'status', label: $gettext('Status'), sortable: false},
    {key: 'actions', label: $gettext('Actions'), sortable: false, class: 'shrink'},
];

const listItemProvider = useApiItemProvider<ApiWebStream>(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'web-streams']),
    undefined,
    (config) => ({
        ...config,
        params: {
            ...config.params,
            source: 'remote_url',
        },
    })
);

const typeLabel = (type: PlaylistRemoteTypes): string => {
    switch (type) {
        case PlaylistRemoteTypes.Stream:
            return $gettext('Icecast/Shoutcast Stream');
        case PlaylistRemoteTypes.Playlist:
            return $gettext('M3U/PLS Playlist');
        case PlaylistRemoteTypes.Other:
            return $gettext('Other URL');
    }
};

const relist = (): void => {
    void listItemProvider.refresh();
};

const {mayNeedRestart} = useMayNeedRestart();

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this web stream?'),
    () => {
        relist();
        mayNeedRestart();
    }
);

const $editModal = useTemplateRef('$editModal');
</script>
