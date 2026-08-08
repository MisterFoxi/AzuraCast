<template>
    <input
        v-bind="$attrs"
        v-model="displayValue"
        class="form-control"
        :class="{'is-invalid': invalid}"
        type="text"
        :placeholder="placeholder"
        :aria-invalid="invalid ? 'true' : undefined"
        @blur="normalizeDisplay"
    >
</template>

<script setup lang="ts">
import {computed, ref, watch} from "vue";
import useTimeDisplay, {parseTimeCode, parseTimeInput} from "~/functions/useTimeDisplay.ts";

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null
    }>(),
    {
        modelValue: null,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void
}>();

const {uses24HourTime, formatTimeCode} = useTimeDisplay();

const initialValue = parseTimeCode(props.modelValue);
const displayValue = ref(formatTimeCode(initialValue));
const invalid = computed(() => displayValue.value.trim() !== '' && parseTimeInput(displayValue.value) === null);
const placeholder = computed(() => uses24HourTime.value ? '14:30' : '2:30 PM');

watch(
    () => props.modelValue,
    (value) => {
        const parsedValue = parseTimeCode(value);
        if (parsedValue !== parseTimeInput(displayValue.value)) {
            displayValue.value = formatTimeCode(parsedValue);
        }
    }
);

watch(uses24HourTime, () => {
    displayValue.value = formatTimeCode(parseTimeCode(props.modelValue));
});

watch(displayValue, (value) => {
    if (value.trim() === '') {
        emit('update:modelValue', null);
        return;
    }

    const parsedValue = parseTimeInput(value);
    if (parsedValue !== null) {
        emit('update:modelValue', parsedValue);
    }
});

const normalizeDisplay = () => {
    const parsedValue = parseTimeInput(displayValue.value);
    if (parsedValue !== null) {
        displayValue.value = formatTimeCode(parsedValue);
    } else {
        displayValue.value = formatTimeCode(parseTimeCode(props.modelValue));
    }
};
</script>
