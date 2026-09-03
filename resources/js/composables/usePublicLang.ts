import { computed, ref } from 'vue';

export type PublicLang = 'bn' | 'en';

export interface LangPair {
    bn: string;
    en: string;
}

const STORAGE_KEY = 'publicLang';

const readInitial = (): PublicLang => {
    if (typeof localStorage !== 'undefined') {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'en' || saved === 'bn') {
            return saved;
        }
    }
    return 'bn';
};

// Shared across the public landing pages so the choice carries between them.
const lang = ref<PublicLang>(readInitial());

export function usePublicLang() {
    const setLang = (value: PublicLang): void => {
        lang.value = value;
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // ignore storage failures (private mode etc.)
        }
    };

    const toggle = (): void => setLang(lang.value === 'bn' ? 'en' : 'bn');

    const isBangla = computed(() => lang.value === 'bn');

    const t = (pair: LangPair): string => (lang.value === 'bn' ? pair.bn : pair.en);

    return { lang, isBangla, setLang, toggle, t };
}
