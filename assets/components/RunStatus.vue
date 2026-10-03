<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true } });
const statuses = {
    queued: ['В очереди', 'air-secondary-no-accent'],
    running: ['Собирается', 'air-primary'],
    waiting: ['Ожидание повтора', 'air-primary-warning'],
    complete: ['Готов', 'air-primary-success'],
    complete_with_errors: ['Готов с ошибками', 'air-primary-warning'],
    failed: ['Ошибка сбора', 'air-primary-alert'],
};
const value = computed(() => statuses[props.status] || [props.status, 'air-secondary-no-accent']);
</script>

<template><B24Badge :color="value[1]" :label="value[0]" /></template>
