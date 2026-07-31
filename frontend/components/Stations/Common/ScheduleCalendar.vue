<template>
    <div
        class="card-body-flush"
        style="position: relative;"
        @click.self="overlayProps.visible = false"
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

    <Teleport to="body">
        <div
            v-if="overlayProps.visible && overlayProps.event"
            ref="$overlay"
            class="schedule-event-overlay card position-absolute shadow-lg"
            role="tooltip"
            @mouseenter="cancelHide"
            @mouseleave="scheduleHide"
        >
            <div class="card-header d-flex align-items-center gap-2 p-2 bg-body-tertiary border-bottom border-2">
                <playlist-source-icon
                    v-if="overlayProps.event.extendedProps.source"
                    :source="overlayProps.event.extendedProps.source"
                />
                <span class="fw-bold flex-grow-1 text-truncate">{{ overlayProps.event.title }}</span>
                <span
                    v-if="overlayProps.headerCount !== null"
                    class="badge text-bg-light rounded-pill shadow-none"
                >{{ overlayProps.headerCount }}</span>
            </div>

            <div class="card-body p-2 d-flex flex-column gap-2">
                <div
                    v-if="overlayProps.event.extendedProps.group_schedule_warning"
                    class="d-flex align-items-start gap-2 text-warning"
                >
                    <span class="flex-shrink-0 mt-1">⚠</span>
                    <span class="small">
                        {{ $gettext("This playlist only plays while its group is scheduled. Its current schedule falls outside the group's window, so it will not play during this time.") }}
                    </span>
                </div>

                <div
                    v-if="overlayProps.event.extendedProps.total_length"
                    class="text-muted small"
                >
                    {{ formatSeconds(overlayProps.event.extendedProps.total_length) }}
                </div>

                <div class="d-flex flex-wrap gap-1">
                    <span
                        v-if="overlayProps.event.extendedProps.order"
                        class="badge text-bg-secondary"
                    >
                        {{ getOrderLabel(overlayProps.event.extendedProps.order) }}
                    </span>
                    <span
                        v-if="overlayProps.rotationLabel"
                        class="badge text-bg-secondary"
                    >
                        {{ overlayProps.rotationLabel }}
                    </span>
                    <span
                        v-if="overlayProps.event.extendedProps.avoid_duplicates"
                        class="badge text-bg-info"
                    >
                        {{ $gettext('Avoid Duplicates') }}
                    </span>
                    <span
                        v-if="overlayProps.event.extendedProps.is_jingle"
                        class="badge text-bg-info"
                    >
                        {{ $gettext('Jingle Mode') }}
                    </span>
                </div>
            </div>

            <ul
                v-if="overlayProps.members && overlayProps.members.length > 0"
                class="list-group list-group-flush overflow-y-auto border-top"
                style="max-height: 16rem;"
            >
                <li
                    v-for="member in overlayProps.members"
                    :key="member.id"
                    class="list-group-item d-flex align-items-center gap-2 p-2"
                >
                    <playlist-source-icon :source="member.source ?? 'songs'" />
                    <span class="flex-grow-1 text-truncate">{{ member.name }}</span>
                    <span
                        v-if="member.consecutive_plays > 0 || member.play_full_cycle"
                        class="badge text-bg-secondary d-inline-flex align-items-center gap-1"
                    >
                        ↻
                        {{
                            member.play_full_cycle
                                ? $gettext('Plays fully')
                                : $gettext('Plays %{count}', {count: member.consecutive_plays})
                        }}
                    </span>
                </li>
            </ul>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import Schedule from "~/components/Common/ScheduleView.vue";
import PlaylistSourceIcon from "~/components/Stations/Common/PlaylistSourceIcon.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import {createPopper, Instance} from "@popperjs/core";
import {useTimeoutFn} from "@vueuse/core";
import {Calendar, EventClickArg, EventHoveringArg, EventMountArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {computed, nextTick, reactive, ref, useTemplateRef, toValue, watch} from "vue";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useTranslate} from "~/vendor/gettext";
import {useLuxon} from "~/vendor/luxon";
import {PlaylistOrders, PlaylistTypes} from "~/entities/ApiInterfaces.ts";

const props = withDefaults(defineProps<{
    scheduleUrl: string | string[],
    showCreateButton?: boolean,
}>(), {
    showCreateButton: false,
});

const emit = defineEmits<{
    click: [event: EventImpl],
    create: [],
}>();

const {$gettext} = useTranslate();
const {Duration} = useLuxon();

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const formatSeconds = (seconds: number): string => {
    if (!seconds) return '';
    return Duration.fromMillis(seconds * 1000).rescale().toHuman();
};

const getOrderLabel = (order: string): string => {
    switch (order) {
        case PlaylistOrders.Shuffle: return $gettext('Shuffle');
        case PlaylistOrders.Sequential: return $gettext('Sequential');
        case PlaylistOrders.Random: return $gettext('Random');
        default: return '';
    }
};

type OverlayMember = {id: number, name: string, source?: string, consecutive_plays: number, play_full_cycle: boolean};

const overlayProps = reactive<{
    visible: boolean,
    event: EventImpl | null,
    referenceEl: HTMLElement | null,
    headerCount: number | null,
    rotationLabel: string,
    members: OverlayMember[],
}>({
    visible: false,
    event: null,
    referenceEl: null,
    headerCount: null,
    rotationLabel: '',
    members: [],
});

const $overlay = useTemplateRef<HTMLElement>('$overlay');

let popper: Instance | null = null;

const destroyPopper = () => {
    popper?.destroy();
    popper = null;
};

const buildOverlay = async (event: EventImpl, el: HTMLElement) => {
    const ep = event.extendedProps;

    let headerCount: number | null = null;
    if (ep.source === 'songs') headerCount = ep.num_songs ?? 0;
    if (ep.source === 'playlists') headerCount = (ep.members ?? []).length;

    let rotationLabel = '';
    switch (ep.playlist_type) {
        case PlaylistTypes.Standard:
            rotationLabel = $gettext('General Rotation (%{weight})', {weight: ep.weight ?? 0});
            break;
        case PlaylistTypes.OncePerXSongs:
            rotationLabel = $gettext('Once per %{songs} Songs', {songs: ep.play_per_songs ?? 0});
            break;
        case PlaylistTypes.OncePerXMinutes:
            rotationLabel = $gettext('Once per %{minutes} Minutes', {minutes: ep.play_per_minutes ?? 0});
            break;
        case PlaylistTypes.OncePerHour:
            rotationLabel = $gettext('Once per Hour (at %{minute})', {minute: ep.play_per_hour_minute ?? 0});
            break;
    }

    overlayProps.event = event;
    overlayProps.referenceEl = el;
    overlayProps.headerCount = headerCount;
    overlayProps.rotationLabel = rotationLabel;
    overlayProps.members = ep.members ?? [];
    overlayProps.visible = true;

    await nextTick();

    destroyPopper();
    if ($overlay.value) {
        popper = createPopper(el, $overlay.value, {
            placement: 'right',
            modifiers: [
                {name: 'flip', options: {fallbackPlacements: ['top', 'left', 'bottom']}},
                {name: 'preventOverflow', options: {padding: 8}},
            ],
        });
    }
};

const {start: scheduleHide, stop: cancelHide} = useTimeoutFn(() => {
    overlayProps.visible = false;
    destroyPopper();
}, 200, {immediate: false});

const calendarOptions = computed(() => {
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
        eventSources: urls,
        eventMouseEnter: onMouseEnter,
        eventMouseLeave: onMouseLeave,
        eventClick: onClick,
        eventDidMount: onEventMount,
    };
});

const onMouseEnter = (arg: EventHoveringArg) => {
    cancelHide();
    void buildOverlay(arg.event, arg.el);
};

const onMouseLeave = (_arg: EventHoveringArg) => {
    scheduleHide();
};

const onClick = (arg: EventClickArg) => {
    if (arg.event.extendedProps.group_schedule_warning) {
        // Warning events: show overlay but don't open edit modal
        void buildOverlay(arg.event, arg.el);
        return;
    }
    overlayProps.visible = false;
    destroyPopper();
    emit('click', arg.event);
};

const onEventMount = (arg: EventMountArg) => {
    const ep = arg.event.extendedProps;

    // Add source icon to event tile
    if (ep.source) {
        const iconMap: Record<string, string> = {
            songs: '♫',
            playlists: '♫♫',
            requests: '👥',
            remote_url: '🌐',
        };
        const icon = iconMap[ep.source];
        if (icon) {
            const iconEl = document.createElement('span');
            iconEl.textContent = icon + ' ';
            iconEl.style.cssText = 'font-size: 0.75em; opacity: 0.85;';
            const titleEl = arg.el.querySelector('.fc-event-title');
            if (titleEl) titleEl.prepend(iconEl);
        }
    }

    // Add warning indicator for group schedule conflicts
    if (ep.group_schedule_warning) {
        const warnEl = document.createElement('span');
        warnEl.textContent = ' ⚠';
        warnEl.style.cssText = 'color: #ffc107;';
        const titleEl = arg.el.querySelector('.fc-event-title');
        if (titleEl) titleEl.appendChild(warnEl);
        arg.el.style.opacity = '0.8';
    }
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

<style scoped>
.schedule-event-overlay {
    z-index: 1070;
    min-width: 14rem;
    max-width: 28rem;
}
</style>
