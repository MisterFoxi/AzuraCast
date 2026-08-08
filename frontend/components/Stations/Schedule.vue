<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_schedule"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_schedule"
                        class="card-title"
                    >
                        {{ $gettext('Schedule') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <time-zone />
                </div>
            </div>
        </div>

        <div class="card-body pb-0">
            <nav
                class="nav nav-tabs"
                role="tablist"
            >
                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'calendar'}"
                        role="tab"
                        :aria-selected="activeTab === 'calendar'"
                        @click="activeTab = 'calendar'"
                    >
                        {{ $gettext('Calendar') }}
                    </button>
                </div>

                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'holidays'}"
                        role="tab"
                        :aria-selected="activeTab === 'holidays'"
                        @click="activeTab = 'holidays'"
                    >
                        {{ $gettext('Holidays') }}
                    </button>
                </div>
            </nav>
        </div>

        <div class="card-body">
            <schedule-calendar
                v-show="activeTab === 'calendar'"
                ref="$scheduleTab"
                :schedule-url="scheduleUrl"
                :show-create-button="true"
                @click="doCalendarClick"
                @create="doCreateEvent"
            />

            <holiday-overrides-tab
                v-show="activeTab === 'holidays'"
                :list-url="holidayOverridesUrl"
                :playlists-url="listUrl"
            />
        </div>

        <create-event-modal
            ref="$createEventModal"
            @relist="relist"
        />
    </section>
</template>

<script setup lang="ts">
import ScheduleCalendar from "~/components/Stations/Common/ScheduleCalendar.vue";
import HolidayOverridesTab from "~/components/Stations/Schedule/HolidayOverridesTab.vue";
import CreateEventModal from "~/components/Stations/Common/CreateEventModal.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {nextTick, ref, useTemplateRef, watch} from "vue";
import {EventImpl} from "@fullcalendar/core/internal";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {mayNeedRestart} = useMayNeedRestart();

const activeTab = ref<'calendar' | 'holidays'>('calendar');

const listUrl = getStationApiUrl('/playlists');
const holidayOverridesUrl = getStationApiUrl('/holiday-overrides');
const scheduleUrl = getStationApiUrl('/playlists/schedule');

const $scheduleTab = useTemplateRef('$scheduleTab');
const $createEventModal = useTemplateRef('$createEventModal');

watch(activeTab, async (newTab) => {
    if (newTab === 'calendar') {
        await nextTick();
        $scheduleTab.value?.updateSize();
    }
});

const doCalendarClick = (event: EventImpl) => {
    $createEventModal.value?.openForEdit(event);
};

const doCreateEvent = () => {
    $createEventModal.value?.open();
};

const relist = () => {
    $scheduleTab.value?.refresh();
    mayNeedRestart();
};
</script>
