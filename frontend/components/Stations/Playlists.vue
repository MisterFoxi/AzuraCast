<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_playlists"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_playlists"
                        class="card-title"
                    >
                        {{ $gettext('Playlists') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <time-zone />
                </div>
            </div>
        </div>

        <div class="card-body">
            <tabs
                content-class="mt-3"
                destroy-on-hide
            >
                <tab
                    v-for="playlistTab in playlistTabs"
                    :id="playlistTab.id"
                    :key="playlistTab.id"
                    :label="$gettext(playlistTab.label)"
                >
                    <div class="card-body-flush">
                        <div class="card-body buttons">
                            <add-button
                                :text="$gettext('Add Playlist')"
                                @click="doCreate"
                            />
                        </div>

                        <data-table
                            :id="playlistTab.tableId"
                            paginated
                            :fields="fields"
                            :provider="playlistTab.provider"
                            detailed
                        >
                            <template #cell(name)="row">
                                <h5 class="m-0">
                                    {{ row.item.name }}
                                </h5>
                                <p v-if="row.item.description" class="text-muted mb-1">
                                    {{ row.item.description }}
                                </p>
                                <div class="badges">
                                    <span
                                        v-if="isDynamicList(row.item)"
                                        class="badge text-bg-success"
                                    >
                                        {{ $gettext('Dynamic List') }}
                                    </span>
                                    <span
                                        v-else
                                        class="badge text-bg-secondary"
                                    >
                                        <template v-if="row.item.source === 'songs'">
                                            {{ $gettext('Song-based') }}
                                        </template>
                                        <template v-else-if="row.item.source === 'remote_url'">
                                            {{ $gettext('Remote URL') }}
                                        </template>
                                        <template v-else-if="row.item.source === 'group'">
                                            {{ $gettext('Playlist Group') }}
                                        </template>
                                    </span>
                                    <span
                                        v-if="row.item.is_jingle"
                                        class="badge text-bg-primary"
                                    >
                                        {{ $gettext('Jingle Mode') }}
                                    </span>
                                    <span
                                        v-if="row.item.source === 'songs' && row.item.order === 'sequential'"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('Sequential') }}
                                    </span>
                                    <span
                                        v-if="row.item.include_in_on_demand"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('On-Demand') }}
                                    </span>
                                    <span
                                        v-if="row.item.schedule_items.length > 0"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('Scheduled') }}
                                    </span>
                                    <span
                                        v-if="!row.item.is_enabled"
                                        class="badge text-bg-danger"
                                    >
                                        {{ $gettext('Disabled') }}
                                    </span>
                                    <span
                                        v-for="group in row.item.member_of_groups"
                                        :key="group.id"
                                        class="badge text-bg-warning"
                                    >
                                        {{ $gettext('Member of: %{group}', {group: group.name}) }}
                                    </span>
                                </div>
                            </template>
                            <template #cell(scheduling)="{ item }">
                                <template v-if="!item.is_enabled">
                                    {{ $gettext('Disabled') }}
                                </template>
                                <template v-else-if="item.source === 'remote_url'">
                                    {{ $gettext('Remote URL') }}
                                </template>
                                <template v-else-if="item.type === 'default'">
                                    {{ $gettext('General Rotation') }}<br>
                                    {{ $gettext('Weight') }}: {{ item.weight }}
                                </template>
                                <template v-else-if="item.type === 'once_per_x_songs'">
                                    {{
                                        $gettext(
                                            'Once per %{songs} Songs',
                                            {songs: item.play_per_songs}
                                        )
                                    }}
                                </template>
                                <template v-else-if="item.type === 'once_per_x_minutes'">
                                    {{
                                        $gettext(
                                            'Once per %{minutes} Minutes',
                                            {minutes: item.play_per_minutes}
                                        )
                                    }}
                                </template>
                                <template v-else-if="item.type === 'once_per_hour'">
                                    {{
                                        $gettext(
                                            'Once per Hour (at %{minute})',
                                            {minute: item.play_per_hour_minute}
                                        )
                                    }}
                                </template>
                                <template v-else>
                                    {{ $gettext('Custom') }}
                                </template>
                            </template>
                            <template #cell(num_songs)="row">
                                <template v-if="row.item.source === 'songs' && !isDynamicList(row.item)">
                                    <router-link
                                        :to="{
                                            name: 'stations:files:index',
                                            params: {
                                                path: 'playlist:'+row.item.id
                                            }
                                        }"
                                    >
                                        {{ row.item.num_songs }}
                                    </router-link>

                                    ({{ formatLength(row.item.total_length) }})
                                </template>
                                <template v-else>
                                    &nbsp;
                                </template>
                            </template>
                            <template #cell(actions)="{ item, isActive, toggleDetails }">
                                <div class="btn-group btn-group-sm">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        @click="doEdit(item.links.self)"
                                    >
                                        {{ $gettext('Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger"
                                        @click="doDelete(item.links.self)"
                                    >
                                        {{ $gettext('Delete') }}
                                    </button>

                                    <button
                                        class="btn btn-sm btn-secondary"
                                        type="button"
                                        @click="toggleDetails()"
                                    >
                                        <icon-bi-contract v-if="isActive"/>
                                        <icon-bi-expand v-else/>

                                        {{ $gettext('More') }}
                                    </button>
                                </div>
                            </template>
                            <template #detail="{ item }">
                                <div
                                    class="buttons"
                                    style="line-height: 2.5;"
                                >
                                    <button
                                        v-if="item.links.order"
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        @click="doReorder(item.links.order)"
                                    >
                                        {{ $gettext('Reorder') }}
                                    </button>
                                    <button
                                        v-if="item.links.members"
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        @click="doManageMembers(item.links.members)"
                                    >
                                        {{ $gettext('Manage Members') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        :class="(item.is_enabled) ? 'btn-warning' : 'btn-success'"
                                        @click="doModify(item.links.toggle)"
                                    >
                                        {{ (item.is_enabled) ? $gettext('Disable') : $gettext('Enable') }}
                                    </button>
                                    <button
                                        v-if="item.links.empty"
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        @click="doEmpty(item.links.empty)"
                                    >
                                        {{ $gettext('Empty') }}
                                    </button>
                                    <button
                                        v-if="item.links.reshuffle"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doModify(item.links.reshuffle)"
                                    >
                                        {{ $gettext('Reshuffle') }}
                                    </button>
                                    <button
                                        v-if="item.links.import"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doImport(item.links.import)"
                                    >
                                        {{ $gettext('Import from PLS/M3U') }}
                                    </button>
                                    <button
                                        v-if="item.links.queue"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doQueue(item.links.queue)"
                                    >
                                        {{ $gettext('Playback Queue') }}
                                    </button>
                                    <button
                                        v-if="item.links.applyto"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doApplyTo(item.links.applyto)"
                                    >
                                        {{ $gettext('Apply to Folders') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doClone(item.name, item.links.clone)"
                                    >
                                        {{ $gettext('Duplicate') }}
                                    </button>
                                    <a
                                        v-for="format in ['pls', 'm3u']"
                                        :key="format"
                                        class="btn btn-sm btn-secondary"
                                        :href="item.links.export[format]"
                                        target="_blank"
                                    >
                                        {{
                                            $gettext(
                                                'Export %{format}',
                                                {format: format.toUpperCase()}
                                            )
                                        }}
                                    </a>
                                </div>
                            </template>
                        </data-table>
                    </div>
                </tab>

            </tabs>
        </div>
    </section>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="() => relist()"
        @needs-restart="() => mayNeedRestart()"
    />
    <reorder-modal ref="$reorderModal" />
    <group-members-modal
        ref="$groupMembersModal"
        :playlists-url="listUrl"
        @saved="relist"
    />
    <queue-modal ref="$queueModal" />
    <reorder-modal ref="$reorderModal" />
    <import-modal
        ref="$importModal"
        @relist="() => relist()"
    />
    <clone-modal
        ref="$cloneModal"
        @relist="() => relist()"
        @needs-restart="() => mayNeedRestart()"
    />
    <apply-to-modal
        ref="$applyToModal"
        @relist="() => relist()"
    />

</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import EditModal from "~/components/Stations/Playlists/EditModal.vue";
import ReorderModal from "~/components/Stations/Playlists/ReorderModal.vue";
import GroupMembersModal from "~/components/Stations/Playlists/GroupMembersModal.vue";
import ImportModal from "~/components/Stations/Playlists/ImportModal.vue";
import QueueModal from "~/components/Stations/Playlists/QueueModal.vue";
import CloneModal from "~/components/Stations/Playlists/CloneModal.vue";
import ApplyToModal from "~/components/Stations/Playlists/ApplyToModal.vue";
import {useTranslate} from "~/vendor/gettext";
import {computed, useTemplateRef} from "vue";
import useHasEditModal from "~/functions/useHasEditModal";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useAxios} from "~/vendor/axios";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete";
import {useLuxon} from "~/vendor/luxon";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import Tabs from "~/components/Common/Tabs.vue";
import Tab from "~/components/Common/Tab.vue";
import AddButton from "~/components/Common/AddButton.vue";

import {useClientItemProvider} from "~/functions/dataTable/useClientItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import IconBiContract from "~icons/bi/chevron-contract";
import IconBiExpand from "~icons/bi/chevron-expand";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useQuery} from "@tanstack/vue-query";
import {PlaylistSources} from "~/entities/ApiInterfaces.ts";
import type {StationPlaylist} from "~/entities/ApiInterfaces.ts";
import type {DataTableItemProvider} from "~/functions/useHasDatatable.ts";

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/playlists');


const {$gettext} = useTranslate();
const {axios} = useAxios();

type GroupMemberResponse = {
    playlist_id: number,
}

type PlaylistGroupReference = {
    id: number,
    name: string,
}

type PlaylistRow = StationPlaylist & {
    id: number,
    name: string,
    source: PlaylistSources,
    links: {
        self: string,
        toggle: string,
        clone: string,
        export: Record<string, string>,
        order?: string,
        members?: string,
        empty?: string,
        reshuffle?: string,
        import?: string,
        queue?: string,
        applyto?: string,
    },
    member_of_groups: PlaylistGroupReference[],
}

type PlaylistListResponse = {
    rows: PlaylistRow[],
}

const fields: DataTableField[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Playlist'), sortable: true},
    {key: 'scheduling', label: $gettext('Scheduling'), sortable: false},
    {key: 'num_songs', label: $gettext('# Songs'), sortable: false},
    {key: 'actions', label: $gettext('Actions'), sortable: false, class: 'shrink'}
];

const playlistsQuery = useQuery<PlaylistRow[]>({
    queryKey: queryKeyWithStation([QueryKeys.StationPlaylists]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<PlaylistListResponse>(listUrl.value, {
            params: {
                internal: true,
                rowCount: 0,
            },
            signal,
        });

        const rows = data.rows ?? [];
        const membershipGroups = new Map<number, Map<number, PlaylistGroupReference>>();

        await Promise.all(
            rows
                .filter((playlist) => (
                    playlist.source === PlaylistSources.Group
                    && playlist.links.members !== undefined
                ))
                .map(async (group) => {
                    const {data: members} = await axios.get<GroupMemberResponse[]>(
                        group.links.members as string,
                        {signal}
                    );

                    members.forEach((member) => {
                        const groups = membershipGroups.get(member.playlist_id) ?? new Map();
                        groups.set(group.id, {
                            id: group.id,
                            name: group.name,
                        });
                        membershipGroups.set(member.playlist_id, groups);
                    });
                })
        );

        return rows.map((playlist) => ({
            ...playlist,
            member_of_groups: Array.from(
                membershipGroups.get(playlist.id)?.values() ?? []
            ),
        }));
    },
});

const isDynamicList = (playlist: PlaylistRow): boolean =>
    playlist.is_smart_block === true && playlist.smart_block_type === 'dynamic';

const standardPlaylists = computed(() =>
    (playlistsQuery.data.value ?? []).filter(
        (playlist) => playlist.source !== PlaylistSources.Group && !isDynamicList(playlist)
    )
);

const dynamicLists = computed(() =>
    (playlistsQuery.data.value ?? []).filter(isDynamicList)
);

const groupLists = computed(() =>
    (playlistsQuery.data.value ?? []).filter(
        (playlist) => playlist.source === PlaylistSources.Group
    )
);

const refreshPlaylists = async (): Promise<void> => {
    await playlistsQuery.refetch();
};

const standardPlaylistProvider = useClientItemProvider(
    standardPlaylists,
    playlistsQuery.isFetching,
    undefined,
    refreshPlaylists
);

const dynamicListProvider = useClientItemProvider(
    dynamicLists,
    playlistsQuery.isFetching,
    undefined,
    refreshPlaylists
);

const groupListProvider = useClientItemProvider(
    groupLists,
    playlistsQuery.isFetching,
    undefined,
    refreshPlaylists
);

type PlaylistTab = {
    id: string,
    tableId: string,
    label: string,
    provider: DataTableItemProvider<PlaylistRow>,
}

const playlistTabs: PlaylistTab[] = [
    {
        id: 'playlists',
        tableId: 'station_playlists',
        label: 'Playlists',
        provider: standardPlaylistProvider,
    },
    {
        id: 'dynamic_lists',
        tableId: 'station_dynamic_lists',
        label: 'Dynamic Lists',
        provider: dynamicListProvider,
    },
    {
        id: 'group_lists',
        tableId: 'station_group_lists',
        label: 'Group Lists',
        provider: groupListProvider,
    },
];

const {Duration} = useLuxon();

const formatLength = (length: number) => {
    if (0 === length) {
        return $gettext('None');
    }

    const duration = Duration.fromMillis(length * 1000);
    return duration.rescale().toHuman();
};

const relist = () => {
    void refreshPlaylists();
}

const $editModal = useTemplateRef('$editModal');
const {doCreate, doEdit} = useHasEditModal($editModal);


const $reorderModal = useTemplateRef('$reorderModal');

const doReorder = (url: string) => {
    $reorderModal.value?.open(url);
};

const $groupMembersModal = useTemplateRef('$groupMembersModal');

const doManageMembers = (url: string) => {
    void $groupMembersModal.value?.open(url);
};

const $queueModal = useTemplateRef('$queueModal');

const doQueue = (url: string) => {
    $queueModal.value?.open(url);
};

const $importModal = useTemplateRef('$importModal');

const doImport = (url: string) => {
    $importModal.value?.open(url);
};

const $cloneModal = useTemplateRef('$cloneModal');

const doClone = (name: string, url: string) => {
    $cloneModal.value?.open(name, url);
};

const $applyToModal = useTemplateRef('$applyToModal');

const doApplyTo = (url: string) => {
    $applyToModal.value?.open(url);
}

// Remote Stream playlists require a Liquidsoap reload even under normal AutoDJ,
// so the restart signal must not be suppressed based on useManualAutoDj here.
// The backend (StationRequiresRestart) is authoritative for the needs_restart
// flag; this only refreshes station data so the sidebar banner reflects it.
const {mayNeedRestart} = useMayNeedRestart();

const {notifySuccess} = useNotify();

const doModify = async (url: string) => {
    const {data} = await axios.put(url);

    mayNeedRestart();

    notifySuccess(data.message);
    relist();
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Playlist?'),
    () => {
        relist();
        mayNeedRestart();
    },
);

const {doDelete: doEmpty} = useConfirmAndDelete(
    $gettext('Clear all media from playlist?'),
    () => {
        relist();
        mayNeedRestart();
    },
);
</script>
