<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="modalTitle"
        :error="error"
        :disable-save-button="!isFormValid"
        @submit="doSave"
        @hidden="clearForm"
    >
        <!-- Entity selection -->
        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Playlist') }}</label>
            <select
                v-model="form.entity_id"
                class="form-select"
                :disabled="playlists.length === 0"
            >
                <option
                    v-for="e in playlists"
                    :key="e.id"
                    :value="e.id"
                >
                    {{ e.name }}
                </option>
            </select>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input
                    id="edit_form_is_emergency"
                    v-model="scheduleRow.is_emergency"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_emergency">
                    {{ $gettext('Emergency override') }}
                </label>
            </div>
            <small class="form-text text-warning">
                {{ $gettext('While this schedule is active, automatic top-of-hour ID insertion is deferred. Use for breaking news or other must-play windows.') }}
            </small>
        </div>

        <!-- Schedule Row - Time section -->
        <div class="row g-3 mb-3">
            <form-group-field
                id="edit_form_start_time"
                class="col-md-4"
                :field="r$.start_time"
                :label="$gettext('Start Time')"
                :description="$gettext('To play once per day, set start and end to the same value.')"
            >
                <template #default="{id, model, fieldClass}">
                    <time-code
                        :id="id"
                        v-model="model.$model"
                        :class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-group-field
                id="edit_form_end_time"
                class="col-md-4"
                :field="r$.end_time"
                :label="$gettext('End Time')"
                :description="$gettext('If end is before start, the event plays overnight. To avoid overlapping the next event, you can end at :59 (e.g. 1:59 PM before 2:00 PM).')"
            >
                <template #default="{id, model, fieldClass}">
                    <time-code
                        :id="id"
                        v-model="model.$model"
                        :class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-markup
                id="edit_form_duration"
                class="col-md-4"
                :label="$gettext('Duration')"
                :description="$gettext('Hours:Minutes')"
            >
                <div class="input-group">
                    <input
                        v-model.number="durationHours"
                        type="number"
                        class="form-control"
                        min="0"
                        max="23"
                        placeholder="HH"
                        @input="updateDurationFromHours"
                    >
                    <span class="input-group-text">:</span>
                    <input
                        v-model.number="durationMinutes"
                        type="number"
                        class="form-control"
                        min="0"
                        max="59"
                        placeholder="MM"
                        @input="updateDurationFromMinutes"
                    >
                </div>
            </form-markup>

            <form-markup
                id="station_time_zone"
                class="col-md-4"
                :label="$gettext('Station Time Zone')"
            >
                <time-zone />
            </form-markup>

            <!-- Date section -->
            <form-group-field
                id="edit_form_start_date"
                class="col-md-4"
                :field="r$.start_date"
                input-type="date"
                :label="$gettext('Start Date')"
                :description="isRecurring ? $gettext('Required. First date this schedule becomes active; combine with End Date/Repeat below to control when it runs.') : $gettext('Required. This is a one-time event -- it plays only on this date.')"
            />

            <form-group-field
                id="edit_form_end_date"
                class="col-md-4"
                :field="r$.end_date"
                input-type="date"
                :label="$gettext('End Date')"
                :description="!isRecurring
                    ? $gettext('Locked to Start Date -- this is a one-time event. Check Recurring above to enable a different End Date.')
                    : (scheduleRow.recurrence_end_type === 'after'
                        ? $gettext('Not used when stopping after a number of occurrences (see below).')
                        : $gettext('Use with Start date to limit when the schedule runs. Recurrence uses this as the last day.'))"
                :required="isRecurring && scheduleRow.recurrence_end_type !== 'after'"
                :input-attrs="{ disabled: !isRecurring || scheduleRow.recurrence_end_type === 'after' }"
            />

            <div
                v-if="recurringEndDateInvalid"
                class="col-12"
            >
                <div class="alert alert-danger py-2 px-3 mb-0 small">
                    {{ $gettext('End Date must be after Start Date for a recurring event.') }}
                </div>
            </div>

            <form-markup
                id="edit_form_scheduling"
                class="col-md-4"
                :label="$gettext('Start Timing')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_flexible"
                            v-model="startTimingMode"
                            class="form-check-input"
                            type="radio"
                            value="flexible"
                        >
                        <label class="form-check-label" for="scheduling_flexible">
                            {{ $gettext('Flexible') }}
                        </label>
                    </div>
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_strict"
                            v-model="startTimingMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <small class="form-text text-muted d-block mt-2">
                    {{ $gettext('Flexible waits for the currently playing track to finish before starting. Strict cuts the current track to start exactly on time.') }}
                </small>
                <div class="form-check mt-3">
                    <input
                        id="scheduling_loop_once"
                        v-model="scheduleRow.loop_once"
                        class="form-check-input"
                        type="checkbox"
                    >
                    <label class="form-check-label" for="scheduling_loop_once">
                        {{ $gettext('Loop Once') }}
                    </label>
                </div>
                <small class="form-text text-muted d-block">
                    {{ $gettext('Independent of Start Timing above -- controls whether this playlist loops back through its media during its window, rather than playing through once.') }}
                </small>
            </form-markup>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input
                    id="edit_form_is_recurring"
                    v-model="isRecurring"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_recurring">
                    {{ $gettext('Recurring') }}
                </label>
            </div>
            <small class="form-text text-muted">
                {{ $gettext('Check this to repeat the event on a schedule (weekly by default, or bi-weekly/monthly/custom below). Leave unchecked for a one-time event that only plays on its exact Start/End Date.') }}
            </small>
        </div>

        <template v-if="isRecurring">
            <!-- Days of Week -->
            <form-group-multi-check
                id="edit_form_days"
                class="mb-3"
                :field="r$.days"
                :label="$gettext('Scheduled Play Days of Week')"
                :description="daysOfWeekFieldDescription"
                :options="dayOptions"
                :required="!isMonthlyDatePattern"
                :disabled="isMonthlyDatePattern"
                stacked
            />

            <!-- Repeat section -->
            <div class="mb-3">
                <h6 class="text-muted mb-2">
                    {{ $gettext('Repeat') }}
                </h6>
            </div>

            <div class="row g-3 mb-3">
                <form-group-select
                    id="edit_form_recurrence_type"
                    class="col-md-4"
                    :field="r$.recurrence_type"
                    :label="$gettext('Repeat')"
                    :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Custom = every N weeks; Monthly = by date or specific day of week.')"
                    :options="recurrenceTypeOptions"
                />

                <form-group-field
                    v-if="scheduleRow.recurrence_type === 'custom'"
                    id="edit_form_recurrence_interval"
                    class="col-md-4"
                    :field="r$.recurrence_interval"
                    input-type="number"
                    min="1"
                    max="52"
                    :label="$gettext('Every (weeks)')"
                    :description="$gettext('E.g. 3 = every 3 weeks. Set Start date for correct alignment.')"
                />

                <template v-if="scheduleRow.recurrence_type === 'monthly'">
                    <form-group-select
                        id="edit_form_recurrence_monthly_pattern"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_pattern"
                        :label="$gettext('Monthly Pattern')"
                        :options="recurrenceMonthlyPatternOptions"
                    />

                    <form-group-field
                        v-if="scheduleRow.recurrence_monthly_pattern === 'date'"
                        id="edit_form_recurrence_monthly_day"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_day"
                        input-type="number"
                        min="1"
                        max="31"
                        :label="$gettext('Day of Month')"
                        :description="$gettext('Day of the month (1–31).')"
                    />

                    <template v-if="scheduleRow.recurrence_monthly_pattern === 'day_of_week'">
                        <form-group-select
                            id="edit_form_recurrence_monthly_week"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_week"
                            :label="$gettext('Week of Month')"
                            :description="$gettext('For monthly specific day of week.')"
                            :options="recurrenceMonthlyWeekOptions"
                        />
                    </template>
                </template>

                <form-group-select
                    id="edit_form_recurrence_end_type"
                    class="col-md-4"
                    :field="r$.recurrence_end_type"
                    :label="$gettext('Stop Recurrence')"
                    :description="$gettext('Optional: stop after a number of occurrences or use End date above.')"
                    :options="recurrenceEndTypeOptions"
                />

                <form-group-field
                    v-if="scheduleRow.recurrence_end_type === 'after'"
                    id="edit_form_recurrence_end_after"
                    class="col-md-4"
                    :field="r$.recurrence_end_after"
                    input-type="number"
                    min="1"
                    :label="$gettext('Stop After (occurrences)')"
                />
            </div>
        </template>

        <template
            v-if="editingScheduleId !== null"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                :disabled="loading"
                @click="doDelete"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || !isFormValid"
                @click="doSave"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import TimeCode from '~/components/Common/TimeCode.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupMultiCheck from '~/components/Form/FormGroupMultiCheck.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormMarkup from '~/components/Form/FormMarkup.vue';
import TimeZone from '~/components/Stations/Common/TimeZone.vue';
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from '@regle/rules';
import {createRule} from '@regle/core';
import {useAppScopedRegle} from '~/vendor/regle.ts';
import {ref, computed, onMounted, watch, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {
    type PlaylistScheduleRow,
    createScheduleItemDefaults,
} from '~/components/Stations/Common/scheduleItemDefaults.ts';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';
import type {EventImpl} from '@fullcalendar/core/internal';
import {useStationData} from '~/functions/useStationQuery.ts';
import {toRefs} from '@vueuse/core';
import {useLuxon} from '~/vendor/luxon.ts';

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess} = useNotify();
const {confirmDelete} = useDialog();
const {timezone: stationTimezone} = toRefs(useStationData());
const {DateTime} = useLuxon();

const emit = defineEmits<{
    relist: [];
}>();

interface EntityOption {
    id: number;
    name: string;
    self_url: string;
}

const playlists = ref<EntityOption[]>([]);

onMounted(async () => {
    const plResp = await axios.get(getStationApiUrl('/playlists').value);

    playlists.value = (plResp.data as Array<Record<string, unknown>>).map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
    }));

});

const blankForm = () => ({
    entity_id: null as number | null,
});

const form = ref(blankForm());

const startTimingMode = ref<'flexible' | 'strict'>('flexible');

// Schedule row state - matches PlaylistScheduleRow interface
const scheduleRow = ref<PlaylistScheduleRow>(createScheduleItemDefaults());

const loading = ref(false);
const error = ref<string | null>(null);
const $modal = useTemplateRef('$modal');

// Duration state
const durationHours = ref(1);
const durationMinutes = ref(0);

// Recurring toggle
const isRecurring = ref(false);

// Checking "Recurring" requires an explicit repeat pattern (defaults to Weekly)
// rather than silently staying null. Unchecking clears it back to plain,
// non-recurring behavior (event only plays on its exact Start/End Date).
watch(isRecurring, (recurring) => {
    if (recurring) {
        if (!scheduleRow.value.recurrence_type) {
            scheduleRow.value.recurrence_type = 'weekly';
        }
        scheduleRow.value.end_date = '';
    } else {
        scheduleRow.value.recurrence_type = null;
        scheduleRow.value.recurrence_monthly_pattern = null;
        scheduleRow.value.recurrence_monthly_day = null;
        scheduleRow.value.recurrence_monthly_week = null;
        scheduleRow.value.recurrence_monthly_day_of_week = null;
        scheduleRow.value.recurrence_end_type = 'never';
        scheduleRow.value.recurrence_end_after = null;
        scheduleRow.value.days = [];
        scheduleRow.value.end_date = scheduleRow.value.start_date;
    }
});

// Keep End Date locked to Start Date for one-time (non-recurring) events at all
// times -- not just at the moment Recurring is unchecked -- so later changing
// Start Date can never leave a stale End Date that predates it.
watch(
    () => scheduleRow.value.start_date,
    (newStartDate) => {
        if (!isRecurring.value) {
            scheduleRow.value.end_date = newStartDate;
        }
    }
);

// Update end_time from duration inputs
const updateDuration = () => {
    const startTime = scheduleRow.value.start_time;
    const startHours = Math.floor(startTime / 100);
    const startMinutes = startTime % 100;
    const durationTotalMinutes = durationHours.value * 60 + durationMinutes.value;
    let endTotalMinutes = startHours * 60 + startMinutes + durationTotalMinutes;
    endTotalMinutes = endTotalMinutes % (24 * 60);
    const endHours = Math.floor(endTotalMinutes / 60);
    const endMinutes = endTotalMinutes % 60;
    const newEndTime = endHours * 100 + endMinutes;

    // Write through r$.end_time.$value (the same path the End Time field itself
    // writes to) rather than the raw scheduleRow object directly -- the field's
    // validation wrapper may not reactively pick up a direct object mutation.
    r$.end_time.$value = newEndTime;
    scheduleRow.value.end_time = newEndTime;
};

const updateDurationFromHours = () => updateDuration();
const updateDurationFromMinutes = () => updateDuration();

// Compute Duration display (hours/minutes) from the actual loaded start/end times,
// so editing an existing event shows its real duration instead of the 1:00 default.
const syncDurationFromTimes = () => {
    const startTime = scheduleRow.value.start_time;
    const endTime = scheduleRow.value.end_time;
    const startTotalMinutes = Math.floor(startTime / 100) * 60 + (startTime % 100);
    let endTotalMinutes = Math.floor(endTime / 100) * 60 + (endTime % 100);

    if (endTotalMinutes < startTotalMinutes) {
        endTotalMinutes += 24 * 60;
    }

    const diffMinutes = endTotalMinutes - startTotalMinutes;
    durationHours.value = Math.floor(diffMinutes / 60);
    durationMinutes.value = diffMinutes % 60;
};

// Auto-select the first playlist whenever options change.
watch(playlists, (opts) => {
    if (opts.length > 0 && (form.value.entity_id === null || !opts.find(e => e.id === form.value.entity_id))) {
        form.value.entity_id = opts[0].id;
    }
}, {immediate: true});

watch(startTimingMode, (mode) => {
    scheduleRow.value.strict_start = mode === 'strict';
});

// Regle validation for schedule row
const isMonthlyDatePattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => isRecurring.value && !isMonthlyDatePattern.value);

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used when monthly pattern is "On day of month" — pick the calendar day below instead.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For monthly "specific day of week", select one or more days; each gets that week-of-month (e.g. 1st + Mon–Wed).');
    }
    return $gettext('Select at least one day of the week.');
});

const endDateAfterStart = createRule({
    validator: (endDate: unknown) => {
        if (!isRecurring.value) {
            return true;
        }

        const end = typeof endDate === 'string' ? endDate.trim() : '';
        const start = scheduleRow.value.start_date.trim();
        return !end || !start || end > start;
    },
    message: () => $gettext('End Date must be after Start Date for recurring events.'),
});

const {r$} = useAppScopedRegle(
    scheduleRow,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => isRecurring.value && scheduleRow.value.recurrence_end_type !== 'after'),
            endDateAfterStart,
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_type: {
            required: requiredIf(() => isRecurring.value),
        },
        recurrence_end_after: {
            required: requiredIf(() => scheduleRow.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
            ),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);

// Keep Duration display live and consistent: whenever Start Time or End Time
// is edited directly (not via the Duration boxes themselves), recalculate
// Duration to reflect the new gap. Duration only ever drives End Time in the
// other direction (see updateDuration above) -- this just mirrors that so
// editing any one of the three fields keeps the other two in sync.
watch(() => scheduleRow.value.start_time, () => {
    syncDurationFromTimes();
});
watch(() => scheduleRow.value.end_time, () => {
    syncDurationFromTimes();
});

// Sync recurrence_interval when type changes
watch(
    () => scheduleRow.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            scheduleRow.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            scheduleRow.value.recurrence_interval = 1;
        }
    }
);

// Clear days when monthly date pattern is selected
watch(
    () => [scheduleRow.value.recurrence_type, scheduleRow.value.recurrence_monthly_pattern] as const,
    () => {
        if (isMonthlyDatePattern.value) {
            scheduleRow.value.days = [];
        }
    }
);

const recurringEndDateInvalid = computed(() =>
    isRecurring.value
    && Boolean(scheduleRow.value.end_date)
    && Boolean(scheduleRow.value.start_date)
    && scheduleRow.value.end_date <= scheduleRow.value.start_date
);

const isFormValid = computed(() =>
    form.value.entity_id !== null &&
    !r$.$invalid &&
    !recurringEndDateInvalid.value
);

const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')}
];

const recurrenceTypeOptions = [
    {value: 'weekly', text: $gettext('Weekly (default)')},
    {value: 'biweekly', text: $gettext('Bi-weekly (every 2 weeks)')},
    {value: 'monthly', text: $gettext('Monthly')},
    {value: 'custom', text: $gettext('Custom (every N weeks)')}
];

const recurrenceMonthlyPatternOptions = [
    {value: 'date', text: $gettext('On day of month (e.g. 15th)')},
    {value: 'day_of_week', text: $gettext('Specific day of week (e.g. 3rd Monday)')}
];

const recurrenceMonthlyWeekOptions = [
    {value: 1, text: $gettext('1st')},
    {value: 2, text: $gettext('2nd')},
    {value: 3, text: $gettext('3rd')},
    {value: 4, text: $gettext('4th')},
    {value: 5, text: $gettext('Last')}
];

const recurrenceEndTypeOptions = [
    {value: 'never', text: $gettext('Never (use End date above to limit range)')},
    {value: 'after', text: $gettext('After number of occurrences')}
];

const editingScheduleId = ref<number | null>(null);

const modalTitle = computed(() =>
    editingScheduleId.value !== null
        ? $gettext('Edit Event')
        : $gettext('Create Event')
);

const applyCalendarTimesToRow = (start: Date, end?: Date) => {
    const startInStation = DateTime.fromJSDate(start).setZone(stationTimezone.value);
    scheduleRow.value.start_date = startInStation.toFormat('yyyy-MM-dd');
    scheduleRow.value.end_date = scheduleRow.value.start_date;
    scheduleRow.value.start_time = Number(startInStation.toFormat('HHmm'));

    if (end) {
        const endInStation = DateTime.fromJSDate(end).setZone(stationTimezone.value);
        scheduleRow.value.end_time = Number(endInStation.toFormat('HHmm'));
    }
};

const apiScheduleItemToRow = (item: Record<string, unknown>): PlaylistScheduleRow => {
    const endType = (item.recurrence_end_type as string | undefined) ?? 'never';
    const recurrenceType = item.recurrence_type as string | null | undefined;

    const row: PlaylistScheduleRow = {
        start_time: Number(item.start_time),
        end_time: Number(item.end_time),
        start_date: (item.start_date as string | null | undefined) ?? '',
        end_date: (item.end_date as string | null | undefined) ?? '',
        days: normalizeStationScheduleDays(item.days),
        loop_once: Boolean(item.loop_once),
        is_emergency: Boolean(item.is_emergency),
        strict_start: Boolean(item.strict_start),
        recurrence_type: recurrenceType ?? null,
        recurrence_interval: Number(item.recurrence_interval ?? 1),
        recurrence_monthly_pattern: (item.recurrence_monthly_pattern as string | null) ?? null,
        recurrence_monthly_day: item.recurrence_monthly_day != null ? Number(item.recurrence_monthly_day) : null,
        recurrence_monthly_week: item.recurrence_monthly_week != null ? Number(item.recurrence_monthly_week) : null,
        recurrence_monthly_day_of_week: item.recurrence_monthly_day_of_week != null
            ? Number(item.recurrence_monthly_day_of_week)
            : null,
        recurrence_end_type: endType === 'on_date' ? 'never' : endType,
        recurrence_end_after: endType === 'after' && item.recurrence_end_after != null
            ? Number(item.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (
        row.recurrence_type === 'monthly'
        && row.recurrence_monthly_pattern === 'day_of_week'
        && row.recurrence_monthly_day_of_week != null
        && row.days.length === 0
    ) {
        row.days = [row.recurrence_monthly_day_of_week];
    }

    return row;
};

const buildSchedulePayload = (
    row: PlaylistScheduleRow,
    scheduleId?: number
): PlaylistScheduleRow & {id?: number} => {
    const out: PlaylistScheduleRow & {id?: number} = {
        ...row,
        end_date: row.end_date || row.start_date,
        recurrence_type: row.recurrence_type,
        recurrence_interval: (row.recurrence_type === 'biweekly' ? 2 : Number(row.recurrence_interval)) || 1,
        recurrence_end_type: row.recurrence_end_type ?? 'never',
        recurrence_end_after: (row.recurrence_end_type === 'after' && row.recurrence_end_after != null)
            ? Number(row.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (out.recurrence_end_type === 'after') {
        out.end_date = '';
    }

    const normalizedDays = normalizeStationScheduleDays(row.days);
    if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date') {
        out.days = [];
    } else {
        out.days = normalizedDays;
    }

    if (
        out.recurrence_type === 'monthly'
        && out.recurrence_monthly_pattern === 'day_of_week'
        && normalizedDays.length > 0
    ) {
        out.recurrence_monthly_day_of_week = normalizedDays[0];
    }

    if (scheduleId !== undefined) {
        out.id = scheduleId;
    }

    return out;
};

const clearForm = () => {
    form.value = blankForm();
    startTimingMode.value = 'flexible';
    scheduleRow.value = createScheduleItemDefaults();
    error.value = null;
    editingScheduleId.value = null;
};

const open = () => {
    clearForm();
    // If options are already loaded, auto-select the first one (watch won't re-fire if options didn't change)
    if (playlists.value.length > 0) {
        form.value.entity_id = playlists.value[0].id;
    }
    ($modal.value as any)?.show();
};

const openAtTime = (date: Date) => {
    clearForm();
    if (playlists.value.length > 0) {
        form.value.entity_id = playlists.value[0].id;
    }

    const end = DateTime.fromJSDate(date).plus({hours: 1}).toJSDate();
    applyCalendarTimesToRow(date, end);
    syncDurationFromTimes();
    ($modal.value as any)?.show();
};

const openForEdit = async (event: EventImpl) => {
    clearForm();

    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;
    editingScheduleId.value = Number.isFinite(scheduleId) ? scheduleId : null;

    if (editUrl) {
        const m = editUrl.match(/\/playlist\/(\d+)/);
        if (m?.[1]) {
            form.value.entity_id = Number(m[1]);
        }
    }

    if (!form.value.entity_id && playlists.value.length > 0) {
        form.value.entity_id = playlists.value[0].id;
    }

    ($modal.value as any)?.show();

    const start = event.start;
    const end = event.end ?? undefined;

    if (form.value.entity_id && editingScheduleId.value !== null) {
        loading.value = true;
        error.value = null;

        try {
            const entityApiUrl = getStationApiUrl(`/playlist/${form.value.entity_id}`).value;
            const {data: entityData} = await axios.get(entityApiUrl);
            const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
            const existing = items.find((row) => Number(row.id) === editingScheduleId.value);

            if (existing) {
                scheduleRow.value = apiScheduleItemToRow(existing);
                syncDurationFromTimes();
                isRecurring.value = existing.recurrence_type != null && existing.recurrence_type !== '';
                startTimingMode.value = scheduleRow.value.strict_start ? 'strict' : 'flexible';
            } else if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } catch (e: unknown) {
            const err = e as {response?: {data?: {message?: string}}};
            error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
            if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } finally {
            loading.value = false;
        }
    } else if (start) {
        applyCalendarTimesToRow(start, end);
    }
};

const doSave = async () => {
    if (!form.value.entity_id) return;

    loading.value = true;
    error.value = null;

    try {
        const entityApiUrl = getStationApiUrl(`/playlist/${form.value.entity_id}`).value;

        // Fetch current entity data
        const {data: entityData} = await axios.get(entityApiUrl);

        const newScheduleItem = buildSchedulePayload(
            scheduleRow.value,
            editingScheduleId.value ?? undefined
        );
        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        let updatedScheduleItems: unknown[];
        if (editingScheduleId.value !== null) {
            let replaced = false;
            updatedScheduleItems = existingScheduleItems.map((row: any) => {
                if (row?.id === editingScheduleId.value) {
                    replaced = true;
                    return newScheduleItem;
                }
                return row;
            });

            if (!replaced) {
                updatedScheduleItems = [...updatedScheduleItems, newScheduleItem];
            }
        } else {
            updatedScheduleItems = [...existingScheduleItems, newScheduleItem];
        }

        // Only send schedule_items — a full entity PUT includes relation arrays (e.g. podcasts)
        // that the serializer cannot denormalize back into Doctrine collections.
        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess(editingScheduleId.value !== null ? $gettext('Event updated.') : $gettext('Event created.'));
        ($modal.value as any)?.hide();
        emit('relist');
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const close = () => {
    ($modal.value as any)?.hide();
};

const doDelete = async () => {
    if (!form.value.entity_id || editingScheduleId.value === null) {
        return;
    }

    // Capture these before closing the modal -- @hidden triggers clearForm(),
    // which resets the reactive form/editingScheduleId back to blank.
    const entityId = form.value.entity_id;
    const scheduleId = editingScheduleId.value;

    // Close first, matching the pattern used by MediaCategories/EditModal.vue --
    // opening the confirm dialog while this modal is still open leaves it
    // stacked behind the modal (same z-index, this modal painted later) until
    // this modal is dismissed.
    close();

    const {value} = await confirmDelete({
        title: $gettext('Delete this scheduled event?'),
    });

    if (!value) {
        return;
    }

    try {
        const entityApiUrl = getStationApiUrl(`/playlist/${entityId}`).value;

        const {data: entityData} = await axios.get(entityApiUrl);
        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        const updatedScheduleItems = existingScheduleItems.filter(
            (row: any) => row?.id !== scheduleId
        );

        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess($gettext('Event deleted.'));
        emit('relist');
    } catch {
        // Errors are already surfaced globally via the axios response interceptor.
    }
};

defineExpose({open, openAtTime, openForEdit});
</script>
