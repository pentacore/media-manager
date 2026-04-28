import { ref } from 'vue';

const open = ref(false);

export function useCommandPalette() {
    return {
        open,
        toggle: () => {
            open.value = !open.value;
        },
        show: () => {
            open.value = true;
        },
        hide: () => {
            open.value = false;
        },
    };
}
