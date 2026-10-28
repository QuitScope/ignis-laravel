<script setup lang="ts">
/** Footer of a focus view (legacy edivi__freigabe-buttons): back (discard) / delete / save. */
import { trans } from 'laravel-vue-i18n';

defineProps<{ remove?: boolean; save?: boolean; saveLabel?: string; busy?: boolean }>();
const emit = defineEmits<{ back: []; remove: []; save: [] }>();
</script>

<template>
    <div class="enotf-footer">
        <button type="button" class="enotf-footer__btn" @click="emit('back')">{{ trans('enotf.ui.back') }}</button>
        <div class="flex gap-2">
            <button v-if="remove" type="button" class="enotf-footer__btn enotf-footer__btn--danger" :disabled="busy" @click="emit('remove')">{{ trans('enotf.ui.delete') }}</button>
            <button v-if="save !== false" type="button" class="enotf-footer__btn enotf-footer__btn--primary" :disabled="busy" @click="emit('save')">
                {{ saveLabel ?? trans('enotf.ui.save') }}
            </button>
        </div>
    </div>
</template>
