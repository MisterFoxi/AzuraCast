<template>
    <tab
        :label="$gettext('Memberships')"
    >
        <p>{{ $gettext('This playlist is a member of the following Playlist Groups:') }}</p>

        <p
            v-if="!form.playlist_groups || form.playlist_groups.length === 0"
            class="text-muted"
        >
            {{ $gettext('This playlist is not currently a member of any Playlist Group.') }}
        </p>

        <div
            v-else
            class="list-group"
        >
            <router-link
                v-for="group in form.playlist_groups"
                :key="group.id"
                class="list-group-item list-group-item-action"
                :to="{name: 'stations:playlists:index', query: {tab: 'playlist_grouping', playlist: group.id}}"
            >
                {{ group.name }}
            </router-link>
        </div>
    </tab>
</template>

<script setup lang="ts">
import {useTranslate} from "~/vendor/gettext";
import Tab from "~/components/Common/Tab.vue";
import {storeToRefs} from "pinia";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";

const {form} = storeToRefs(useStationsPlaylistsForm());

const {$gettext} = useTranslate();
</script>
