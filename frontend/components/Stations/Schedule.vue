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
                @date-click="doDateClick"
                @move="doCalendarMove"
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
import type {EventApi} from "@fullcalendar/core";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useLuxon} from "~/vendor/luxon.ts";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {mayNeedRestart} = useMayNeedRestart();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {timezone} = toRefs(useStationData());
const {DateTime} = useLuxon();

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

const doDateClick = (date: Date) => {
    $createEventModal.value?.openAtTime(date);
};

interface ScheduleEventProperties {
    schedule_id?: unknown,
    playlist_id?: unknown,
    recurrence_type?: unknown,
    recurrence_monthly_pattern?: unknown,
    recurrence_monthly_week?: unknown,
}

const doCalendarMove = async (event: EventApi, revert: () => void) => {
    const properties = event.extendedProps as ScheduleEventProperties;
    const scheduleId = Number(properties.schedule_id);
    const playlistId = Number(properties.playlist_id);

    if (!event.start || !event.end || !Number.isInteger(scheduleId) || !Number.isInteger(playlistId)) {
        revert();
        notifyError($gettext('This event could not be updated.'));
        return;
    }

    const start = DateTime.fromJSDate(event.start).setZone(timezone.value);
    const end = DateTime.fromJSDate(event.end).setZone(timezone.value);
    const recurrenceType = typeof properties.recurrence_type === 'string'
        ? properties.recurrence_type
        : null;
    const payload: Record<string, unknown> = {
        start_time: Number(start.toFormat('HHmm')),
        end_time: Number(end.toFormat('HHmm')),
    };

    if (recurrenceType === null) {
        const eventDate = start.toFormat('yyyy-MM-dd');
        payload.start_date = eventDate;
        payload.end_date = eventDate;
    } else if (recurrenceType === 'monthly') {
        if (properties.recurrence_monthly_pattern === 'date') {
            payload.recurrence_monthly_day = start.day;
        } else {
            payload.days = [start.weekday];
            payload.recurrence_monthly_day_of_week = start.weekday;

            const existingWeek = Number(properties.recurrence_monthly_week);
            payload.recurrence_monthly_week = existingWeek === 5
                ? 5
                : Math.min(4, Math.ceil(start.day / 7));
        }
    } else {
        payload.days = [start.weekday];
    }

    try {
        await axios.put(
            getStationApiUrl(`/playlist/${playlistId}/schedule/${scheduleId}`).value,
            payload
        );
        notifySuccess($gettext('Event updated.'));
        $scheduleTab.value?.refresh();
        mayNeedRestart();
    } catch (e: unknown) {
        revert();
        const err = e as {response?: {data?: {message?: string}}};
        notifyError(err.response?.data?.message ?? $gettext('This event could not be updated.'));
    }
};

const relist = () => {
    $scheduleTab.value?.refresh();
    mayNeedRestart();
};
</script>
