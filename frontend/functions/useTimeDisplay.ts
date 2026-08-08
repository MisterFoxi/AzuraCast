import {computed} from "vue";
import {useAzuraCast} from "~/vendor/azuracast.ts";

export const parseTimeInput = (value: string): number | null => {
    const normalized = value.trim().toUpperCase().replace(/\./g, '');
    const twelveHourMatch = normalized.match(/^(\d{1,2}):([0-5]\d)\s*(AM|PM)$/);

    if (twelveHourMatch) {
        const hour12 = Number(twelveHourMatch[1]);
        const minutes = Number(twelveHourMatch[2]);

        if (hour12 < 1 || hour12 > 12) {
            return null;
        }

        const hours = (hour12 % 12) + (twelveHourMatch[3] === 'PM' ? 12 : 0);
        return hours * 100 + minutes;
    }

    const twentyFourHourMatch = normalized.match(/^([01]?\d|2[0-3]):([0-5]\d)$/);
    if (!twentyFourHourMatch) {
        return null;
    }

    return Number(twentyFourHourMatch[1]) * 100 + Number(twentyFourHourMatch[2]);
};

export const parseTimeCode = (value: string | number | null): number | null => {
    if (value === null || value === '') {
        return null;
    }

    if (typeof value === 'string' && value.includes(':')) {
        return parseTimeInput(value);
    }

    const numericValue = Number(value);
    if (!Number.isInteger(numericValue) || numericValue < 0 || numericValue > 2359) {
        return null;
    }

    const hours = Math.floor(numericValue / 100);
    const minutes = numericValue % 100;

    return hours <= 23 && minutes <= 59 ? numericValue : null;
};

export const timeCodeTo24HourString = (timeCode: number | null): string => {
    if (timeCode === null) {
        return '';
    }

    const hours = Math.floor(timeCode / 100);
    const minutes = timeCode % 100;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
};

export default function useTimeDisplay() {
    const {timeConfig} = useAzuraCast();

    const uses24HourTime = computed(() => {
        const configuredHourCycle = (timeConfig as Intl.DateTimeFormatOptions | null)?.hourCycle;

        if (configuredHourCycle === 'h23' || configuredHourCycle === 'h24') {
            return true;
        }

        if (configuredHourCycle === 'h11' || configuredHourCycle === 'h12') {
            return false;
        }

        const systemHourCycle = Intl.DateTimeFormat(undefined, {hour: 'numeric'}).resolvedOptions().hourCycle;
        return systemHourCycle === 'h23' || systemHourCycle === 'h24';
    });

    const formatTimeCode = (timeCode: number | null): string => {
        if (timeCode === null) {
            return '';
        }

        if (uses24HourTime.value) {
            return timeCodeTo24HourString(timeCode);
        }

        const hours = Math.floor(timeCode / 100);
        const minutes = timeCode % 100;
        const period = hours >= 12 ? 'PM' : 'AM';
        const hour12 = hours % 12 || 12;
        return `${hour12}:${String(minutes).padStart(2, '0')} ${period}`;
    };

    const formatTimeString = (value: string): string => {
        const timeCode = parseTimeInput(value);
        return timeCode === null ? value : formatTimeCode(timeCode);
    };

    return {
        uses24HourTime,
        formatTimeCode,
        formatTimeString,
    };
}
