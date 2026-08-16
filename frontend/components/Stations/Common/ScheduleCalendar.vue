<template>
    <div
        class="card-body-flush"
        style="position: relative;"
    >
        <schedule
            ref="$schedule"
            :options="calendarOptions"
        />
        <div
            v-if="showCreateButton"
            style="position: absolute; bottom: 1.25rem; right: 1.25rem; z-index: 10;"
        >
            <button
                type="button"
                class="btn btn-primary btn-lg rounded-pill shadow"
                @click="emit('create')"
            >
                <icon-ic-add />
                {{ $gettext('Create Event') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import Schedule from "~/components/Common/ScheduleView.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import {Calendar, CalendarOptions, DateClickArg, EventApi, EventClickArg, SlotLabelContentArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {computed, nextTick, useTemplateRef, toValue} from "vue";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useLuxon} from "~/vendor/luxon.ts";

const props = withDefaults(defineProps<{
    scheduleUrl: string | string[],
    showCreateButton?: boolean,
}>(), {
    showCreateButton: false,
});

const emit = defineEmits<{
    click: [event: EventImpl],
    create: [],
    dateClick: [date: Date],
    move: [event: EventApi, revert: () => void],
}>();

const stationData = useStationData();
const {timezone} = toRefs(stationData);
const {DateTime} = useLuxon();

const renderSlotLabel = (arg: SlotLabelContentArg): string => {
    const slotTime = DateTime.fromJSDate(arg.date);
    const utcTime = slotTime.setZone('UTC').toFormat('HH:mm');
    const stationTime = slotTime.setZone(timezone.value).toFormat('HH:mm');

    if (timezone.value === 'UTC') {
        return `${stationTime} UTC`;
    }

    return `${stationTime} · ${utcTime} UTC`;
};

const calendarOptions = computed<CalendarOptions>(() => {
    const rawUrls = props.scheduleUrl;
    const urls = Array.isArray(rawUrls)
        ? rawUrls.map(u => toValue(u))
        : [toValue(rawUrls)];
    return {
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: 'timeGridWeek,timeGridDay'
        },
        timeZone: timezone.value,
        slotLabelContent: renderSlotLabel,
        eventSources: urls,
        editable: true,
        eventClick: onClick,
        dateClick: onDateClick,
        eventDrop: (arg) => emit('move', arg.event, arg.revert),
        eventResize: (arg) => emit('move', arg.event, arg.revert),
    };
});

const onClick = (arg: EventClickArg) => {
    emit('click', arg.event);
}

const onDateClick = (arg: DateClickArg) => {
    emit('dateClick', arg.date);
};

const $schedule = useTemplateRef('$schedule');

const getCalendarApi = (): Calendar | undefined => {
    return $schedule.value?.getCalendarApi();
};

const refresh = () => getCalendarApi()?.refetchEvents();

const updateSize = async () => {
    await nextTick();
    getCalendarApi()?.updateSize();
};

defineExpose({
    getCalendarApi,
    refresh,
    updateSize
});
</script>
