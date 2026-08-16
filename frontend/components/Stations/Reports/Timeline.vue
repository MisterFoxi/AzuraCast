<template>
    <div class="card">
        <div class="card-header text-bg-primary">
            <div class="d-lg-flex align-items-center">
                <h2 class="card-title flex-fill my-0">
                    {{ $gettext('Song Playback Timeline') }}
                </h2>
                <div class="flex-shrink buttons mt-2 mt-lg-0">
                    <a
                        id="btn-export"
                        class="btn btn-dark"
                        :href="exportUrl"
                        target="_blank"
                    >
                        <icon-ic-cloud-download/>

                        <span>
                            {{ $gettext('Download CSV') }}
                        </span>
                    </a>
                </div>
                <div class="flex-shrink buttons ms-lg-2 mt-2 mt-lg-0">
                    <date-range-dropdown
                        v-model="dateRange"
                        :options="{
                            timeConfig: {
                                enableTimePicker: true,
                            },
                            timezone: timezone
                        }"
                        class="btn-dark"
                    />
                </div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label" for="timeline_playlist_filter">{{ $gettext('Playlist') }}</label>
                    <select id="timeline_playlist_filter" v-model="playlistId" class="form-select">
                        <option :value="null">{{ $gettext('All Playlists') }}</option>
                        <option v-for="item in playlistOptions" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="timeline_group_filter">{{ $gettext('Group List') }}</label>
                    <select id="timeline_group_filter" v-model="groupListId" class="form-select">
                        <option :value="null">{{ $gettext('All Group Lists') }}</option>
                        <option v-for="item in groupListOptions" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="timeline_via_group_filter">{{ $gettext('Group List Source') }}</label>
                    <select id="timeline_via_group_filter" v-model="viaGroupList" class="form-select">
                        <option value="all">{{ $gettext('All Entries') }}</option>
                        <option value="yes">{{ $gettext('Played via a Group List') }}</option>
                        <option value="no">{{ $gettext('Not played via a Group List') }}</option>
                    </select>
                </div>
            </div>
        </div>
        <data-table
            ref="$dataTable"
            paginated
            select-fields
            :fields="fields"
            :provider="listItemProvider"
        >
            <template #cell(delta)="row">
                <span class="typography-subheading">
                    <template v-if="row.item.delta_total > 0">
                        <span class="text-success">
                            <icon-ic-trending-up/>
                            {{ abs(row.item.delta_total) }}
                        </span>
                    </template>
                    <template v-else-if="row.item.delta_total < 0">
                        <span class="text-danger">
                            <icon-ic-trending-down/>
                            {{ abs(row.item.delta_total) }}
                        </span>
                    </template>
                    <template v-else>
                        0
                    </template>
                </span>
            </template>
            <template #cell(song)="row">
                <div :class="{'text-muted': !row.item.is_visible}">
                    <template v-if="row.item.song.title">
                        <b>{{ row.item.song.title }}</b><br>
                        {{ row.item.song.artist }}
                    </template>
                    <template v-else>
                        {{ row.item.song.text }}
                    </template>
                </div>
            </template>
            <template #cell(source)="row">
                <template v-if="row.item.is_request">
                    {{ $gettext('Listener Request') }}
                </template>
                <template v-else-if="row.item.streamer">
                    {{ $gettext('Live Streamer:') }}
                    {{ row.item.streamer }}
                </template>
                <template v-else>
                    &nbsp;
                </template>
            </template>
            <template #cell(playlist)="row">
                <div>{{ row.item.playlist || '—' }}</div>
                <span
                    v-for="groupList in row.item.group_lists ?? []"
                    :key="groupList"
                    class="badge text-bg-info me-1"
                >
                    {{ $gettext('Group List') }}: {{ groupList }}
                </span>
            </template>
        </data-table>
    </div>
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import DateRangeDropdown from "~/components/Common/DateRangeDropdown.vue";
import {computed, nextTick, onMounted, ref, useTemplateRef, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import useHasDatatable from "~/functions/useHasDatatable.ts";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useLuxon} from "~/vendor/luxon.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import IconIcCloudDownload from "~icons/ic/baseline-cloud-download";
import IconIcTrendingDown from "~icons/ic/baseline-trending-down";
import IconIcTrendingUp from "~icons/ic/baseline-trending-up";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios.ts";
import {
    ApiDetailedSongHistory,
    PlaylistSources,
    StationPlaylist
} from "~/entities/ApiInterfaces.ts";

const {getStationApiUrl} = useApiRouter();
const baseApiUrl = getStationApiUrl('/history');
const playlistsUrl = getStationApiUrl('/playlists');
const {axios} = useAxios();

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const {DateTime} = useLuxon();
const {
    now,
    formatDateTimeAsDateTime,
    formatTimestampAsDateTime
} = useStationDateTimeFormatter();

const nowTz = now();

const dateRange = ref(
    {
        startDate: nowTz.minus({days: 13}).toJSDate(),
        endDate: nowTz.toJSDate(),
    }
);

const {$gettext} = useTranslate();

const playlistId = ref<number | null>(null);
const groupListId = ref<number | null>(null);
const viaGroupList = ref<'all' | 'yes' | 'no'>('all');

type PlaylistOption = Pick<StationPlaylist, 'id' | 'name' | 'source'>;
type PlaylistListResponse = {rows: PlaylistOption[]};
const playlistOptions = ref<PlaylistOption[]>([]);
const groupListOptions = ref<PlaylistOption[]>([]);

const fields: DataTableField<ApiDetailedSongHistory>[] = [
    {
        key: 'played_at',
        label: $gettext('Date/Time (Browser)'),
        selectable: true,
        sortable: false,
        visible: false,
        formatter: (value) => formatDateTimeAsDateTime(
            DateTime.fromSeconds(value, {zone: 'system'}),
            DateTime.DATETIME_SHORT
        )
    },
    {
        key: 'played_at_station',
        label: $gettext('Date/Time (Station)'),
        sortable: false,
        selectable: true,
        visible: true,
        formatter: (_value, _key, item) => formatTimestampAsDateTime(
            item.played_at,
            DateTime.DATETIME_SHORT
        )
    },
    {
        key: 'listeners_start',
        label: $gettext('Listeners'),
        selectable: true,
        sortable: false
    },
    {
        key: 'delta',
        label: $gettext('Change'),
        selectable: true,
        sortable: false
    },
    {
        key: 'song',
        isRowHeader: true,
        label: $gettext('Song Title'),
        selectable: true,
        sortable: false
    },
    {
        key: 'playlist',
        label: $gettext('Playlist'),
        selectable: true,
        sortable: false
    },
    {
        key: 'source',
        label: $gettext('Source'),
        selectable: true,
        sortable: false
    }
];

const apiUrl = computed(() => {
    const apiUrl = new URL(baseApiUrl.value, document.location.href);

    const apiUrlParams = apiUrl.searchParams;

    const startDate = DateTime.fromJSDate(dateRange.value.startDate);
    if (startDate.isValid) {
        apiUrlParams.set('start', startDate.toISO());
    }

    const endDate = DateTime.fromJSDate(dateRange.value.endDate);
    if (endDate.isValid) {
        apiUrlParams.set('end', endDate.toISO());
    }

    if (null !== playlistId.value) {
        apiUrlParams.set('playlist_id', String(playlistId.value));
    }
    if (null !== groupListId.value) {
        apiUrlParams.set('group_list_id', String(groupListId.value));
    }
    if ('all' !== viaGroupList.value) {
        apiUrlParams.set('via_group_list', 'yes' === viaGroupList.value ? 'true' : 'false');
    }

    return apiUrl.toString();
});

const exportUrl = computed(() => {
    const exportUrl = new URL(apiUrl.value, document.location.href);
    const exportUrlParams = exportUrl.searchParams;

    exportUrlParams.set('format', 'csv');

    return exportUrl.toString();
});

const listItemProvider = useApiItemProvider(
    apiUrl,
    queryKeyWithStation([
        QueryKeys.StationReports,
        'timeline',
        dateRange,
        playlistId,
        groupListId,
        viaGroupList,
    ])
);

onMounted(async () => {
    const {data} = await axios.get<PlaylistListResponse>(playlistsUrl.value, {
        params: {internal: true, rowCount: 0},
    });
    playlistOptions.value = data.rows.filter((item) => item.source !== PlaylistSources.Group);
    groupListOptions.value = data.rows.filter((item) => item.source === PlaylistSources.Group);
});

const abs = (val: number) => {
    return Math.abs(val);
};

const $dataTable = useTemplateRef('$dataTable');
const {navigate} = useHasDatatable($dataTable);

watch(dateRange, () => void nextTick(navigate));
</script>
