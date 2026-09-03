import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useDhakaTime } from '@/composables/useDhakaTime';

interface AccountLockSettings {
    enabled: boolean;
    start: string | null;
    end: string | null;
}

/**
 * The daily window during which agents may not edit or delete accounts.
 *
 * Mirrors App\Support\AccountLockWindow, which is what actually enforces it — this only decides
 * what the UI offers. It re-evaluates against the ticking server-synced Dhaka clock, so a tab that
 * was open before the window opened locks itself without a reload. Start later than end wraps past
 * midnight; an unset or zero-length range locks nothing.
 */
export function useAccountLock() {
    const page = usePage();
    const { dhakaTime } = useDhakaTime();

    const LOCK_MESSAGE = 'Accounts are locked right now. Ask a manager, or try again after the lock window closes.';

    const settings = computed<AccountLockSettings>(
        () => (page.props.accountLock as AccountLockSettings | undefined) ?? { enabled: false, start: null, end: null },
    );

    const isAgent = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'agent');

    const secondsOfDay = (time: string | null | undefined): number | null => {
        if (!time) return null;
        const [h, m, s = '0'] = time.split(':');
        const parsed = [h, m, s].map((part) => parseInt(part, 10));
        if (parsed.some((part) => Number.isNaN(part))) return null;
        return parsed[0] * 3600 + parsed[1] * 60 + parsed[2];
    };

    const windowOpen = computed(() => {
        if (!settings.value.enabled) return false;

        const start = secondsOfDay(settings.value.start);
        const end = secondsOfDay(settings.value.end);
        const now = secondsOfDay(dhakaTime.value);
        if (start === null || end === null || now === null || start === end) return false;

        return start < end ? now >= start && now < end : now >= start || now < end;
    });

    /** True only for the users the lock actually applies to — managers and admins are exempt. */
    const accountsLocked = computed(() => isAgent.value && windowOpen.value);

    const lockRangeLabel = computed(() =>
        settings.value.start && settings.value.end
            ? `${settings.value.start.slice(0, 5)}–${settings.value.end.slice(0, 5)} BDT`
            : '',
    );

    return { accountsLocked, windowOpen, lockRangeLabel, LOCK_MESSAGE };
}
