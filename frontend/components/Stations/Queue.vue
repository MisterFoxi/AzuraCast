<template>
    <card-page :title="$gettext('Upcoming Song Queue')">
        <template #info>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label" for="queue_playlist_filter">{{ $gettext('Playlist') }}</label>
                    <select id="queue_playlist_filter" v-model="playlistId" class="form-select">
                        <option :value="null">{{ $gettext('All Playlists') }}</option>
                        <option v-for="item in playlistOptions" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="queue_group_filter">{{ $gettext('Group List') }}</label>
                    <select id="queue_group_filter" v-model="groupListId" class="form-select">
                        <option :value="null">{{ $gettext('All Group Lists') }}</option>
                        <option v-for="item in groupListOptions" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="queue_via_group_filter">{{ $gettext('Group List Source') }}</label>
                    <select id="queue_via_group_filter" v-model="viaGroupList" class="form-select">
                        <option value="all">{{ $gettext('All Entries') }}</option>
                        <option value="yes">{{ $gettext('Played via a Group List') }}</option>
                        <option value="no">{{ $gettext('Not played via a Group List') }}</option>
                    </select>
                </div>
            </div>
        </template>
        <template #actions>
            <button
                type="button"
                class="btn btn-danger"
                @click="doClear()"
            >
                <icon-ic-remove/>

                <span>
                    {{ $gettext('Clear Upcoming Song Queue') }}
                </span>
            </button>
        </template>

        <data-table
            id="station_queue"
            :fields="fields"
            :provider="listItemProvider"
            :hide-on-loading="false"
        >
            <template #cell(actions)="row">
                <div class="btn-group btn-group-sm">
                    <button
                        v-if="row.item.log"
                        type="button"
                        class="btn btn-primary"
                        @click.prevent="doShowLogs(row.item.log)"
                    >
                        {{ $gettext('Logs') }}
                    </button>
                    <button
                        v-if="!row.item.sent_to_autodj"
                        type="button"
                        class="btn btn-danger"
                        @click.prevent="doDelete(row.item.links.self)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </template>
            <template #cell(song_title)="row">
                <div v-if="row.item.autodj_custom_uri">
                    {{ row.item.autodj_custom_uri }}
                </div>
                <div v-else-if="row.item.song.title">
                    <b>{{ row.item.song.title }}</b><br>
                    {{ row.item.song.artist }}
                </div>
                <div v-else>
                    {{ row.item.song.text }}
                </div>
            </template>
            <template #cell(played_at)="row">
                {{ formatTimestampAsTime(row.item.played_at) }}<br>
                <small>{{ formatTimestampAsRelative(row.item.played_at) }}</small>
            </template>
            <template #cell(source)="row">
                <div v-if="row.item.top_of_hour_legal_id">
                    <span class="badge text-bg-info">
                        {{ $gettext('Top of Hour ID') }}
                    </span>
                </div>
                <div v-else-if="row.item.is_request">
                    {{ $gettext('Listener Request') }}
                </div>
                <div v-else-if="row.item.playlist">
                    <div>
                        {{ $gettext('Playlist') }}: {{ row.item.playlist }}
                    </div>
                    <div v-if="row.item.group_lists?.length">
                        <span
                            v-for="groupList in row.item.group_lists"
                            :key="groupList"
                            class="badge text-bg-info me-1"
                        >
                            {{ $gettext('Group List') }}: {{ groupList }}
                        </span>
                    </div>
                </div>
            </template>
        </data-table>
    </card-page>

    <queue-logs-modal ref="$logsModal" />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import QueueLogsModal from "~/components/Stations/Queue/LogsModal.vue";
import {useTranslate} from "~/vendor/gettext";
import {computed, onMounted, ref, useTemplateRef} from "vue";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useAxios} from "~/vendor/axios";
import CardPage from "~/components/Common/CardPage.vue";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import {
    ApiNowPlayingStationQueue,
    ApiStationQueueDetailed,
    ApiStatus,
    PlaylistSources,
    StationPlaylist
} from "~/entities/ApiInterfaces.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import IconIcRemove from "~icons/ic/baseline-remove";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/queue');
const clearUrl = getStationApiUrl('/queue/clear');
const playlistsUrl = getStationApiUrl('/playlists');

const {$gettext} = useTranslate();
const {axios} = useAxios();

type Row = Required<ApiNowPlayingStationQueue & ApiStationQueueDetailed>;

const fields: DataTableField<Row>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false},
    {key: 'song_title', isRowHeader: true, label: $gettext('Song Title'), sortable: false},
    {key: 'played_at', label: $gettext('Expected to Play at'), sortable: false},
    {key: 'source', label: $gettext('Source'), sortable: false}
];

const playlistId = ref<number | null>(null);
const groupListId = ref<number | null>(null);
const viaGroupList = ref<'all' | 'yes' | 'no'>('all');

type PlaylistOption = Pick<StationPlaylist, 'id' | 'name' | 'source'>;
type PlaylistListResponse = {rows: PlaylistOption[]};
const playlistOptions = ref<PlaylistOption[]>([]);
const groupListOptions = ref<PlaylistOption[]>([]);

const filteredListUrl = computed(() => {
    const url = new URL(listUrl.value, document.location.href);
    if (null !== playlistId.value) {
        url.searchParams.set('playlist_id', String(playlistId.value));
    }
    if (null !== groupListId.value) {
        url.searchParams.set('group_list_id', String(groupListId.value));
    }
    if ('all' !== viaGroupList.value) {
        url.searchParams.set('via_group_list', 'yes' === viaGroupList.value ? 'true' : 'false');
    }
    return url.toString();
});

const listItemProvider = useApiItemProvider(
    filteredListUrl,
    queryKeyWithStation([
        QueryKeys.StationQueue,
        playlistId,
        groupListId,
        viaGroupList,
    ]),
    {
        refetchInterval: 30000
    }
);

onMounted(async () => {
    const {data} = await axios.get<PlaylistListResponse>(playlistsUrl.value, {
        params: {internal: true, rowCount: 0},
    });
    playlistOptions.value = data.rows.filter((item) => item.source !== PlaylistSources.Group);
    groupListOptions.value = data.rows.filter((item) => item.source === PlaylistSources.Group);
});

const relist = () => {
    void listItemProvider.refresh();
};

const {
    formatTimestampAsTime,
    formatTimestampAsRelative
} = useStationDateTimeFormatter();

const $logsModal = useTemplateRef('$logsModal');

const doShowLogs = (logs: string[]) => {
    $logsModal.value?.show(logs);
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Queue Item?'),
    () => relist()
);

const {confirmDelete} = useDialog();
const {notifySuccess} = useNotify();

const doClear = async () => {
    const {value} = await confirmDelete({
        title: $gettext('Clear Upcoming Song Queue?'),
        confirmButtonText: $gettext('Clear'),
    });

    if (value) {
        const {data} = await axios.post<ApiStatus>(clearUrl.value);

        notifySuccess(data.message);
        relist();
    }
}
</script>
