<script setup>
import { computed, reactive, ref } from 'vue';
import RunStatus from '../components/RunStatus.vue';

const props = defineProps({ page: { type: Object, required: true } });
const values = reactive({ collectNow: false, ...props.page.values });
const submitting = ref(false);
const runs = ref([...props.page.runs]);
const deleting = ref(null);
const deleteError = ref('');
async function deleteReport(run) {
    if (deleting.value || !window.confirm('Удалить отчёт и все его строки и ошибки? Это действие нельзя отменить.')) return;
    deleting.value = run.id;
    deleteError.value = '';
    try {
        const response = await fetch(`/reports/${run.id}/delete`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _token: run.deleteCsrf }) });
        const result = await response.json();
        if (!response.ok) {
            deleteError.value = response.status === 409 ? result.error : 'Не удалось удалить отчёт. Обновите страницу и повторите попытку.';
            return;
        }
        if (!Array.isArray(result.runs)) throw new Error('Invalid list');
        runs.value = result.runs;
    } catch {
        deleteError.value = 'Не удалось подтвердить удаление. Проверьте соединение и повторите попытку.';
    } finally {
        deleting.value = null;
    }
}
const employees = computed(() => props.page.employees.map(employee => ({ label: `${employee.name} (#${employee.id})`, value: String(employee.id) })));
</script>

<template>
    <div class="min-w-0 space-y-6">
        <div class="space-y-2">
            <h1 class="text-h1">Отчёт по времени сотрудника</h1>
            <p class="text-(--ui-color-base-2)">Задачи, где сотрудник сейчас исполнитель или соисполнитель и списал время за период.</p>
        </div>
        <B24Alert v-if="page.error" role="alert" color="air-primary-alert" title="Проверьте данные" :description="page.error" />
        <B24Alert v-if="page.formErrors.length" role="alert" color="air-primary-alert" title="Исправьте поля формы" :description="page.formErrors.join(' ')" />
        <B24Card class="max-w-3xl">
            <template #header><h2 class="text-h4">Параметры отчёта</h2></template>
            <form :action="page.action" method="post" class="space-y-5" @submit="submitting = true">
                <B24FormField label="Сотрудник" name="report_request[employeeId]" required>
                    <B24Select v-model="values.employeeId" :items="employees" name="report_request[employeeId]" aria-label="Сотрудник" placeholder="Выберите сотрудника" required class="w-full" :disabled="!employees.length" />
                </B24FormField>
                <div class="grid gap-5 sm:grid-cols-2">
                    <B24FormField label="С даты" name="report_request[from]" required>
                        <B24Input v-model="values.from" type="date" name="report_request[from]" aria-label="С даты" required class="w-full" />
                    </B24FormField>
                    <B24FormField label="По дату включительно" name="report_request[to]" required>
                        <B24Input v-model="values.to" type="date" name="report_request[to]" aria-label="По дату включительно" required class="w-full" />
                    </B24FormField>
                </div>
                <p class="text-sm text-(--ui-color-base-2)">Часовой пояс: {{ page.timezone }}. Время учитывается по дате регистрации записи.</p>
                <B24Checkbox v-model="values.collectNow" name="report_request[collectNow]" value="1" label="Сформировать сразу" aria-label="Сформировать сразу" />
                <p class="text-sm text-(--ui-color-base-2)">После проверки данных запустить команду сейчас, не дожидаясь крона, только если другой сборщик не занят. Бюджет — 45 секунд между шагами: может собраться лишь часть отчёта. Текущий запрос к Б24 может увеличить ожидание. Остальное соберёт следующий запуск команды.</p>
                <input type="hidden" name="report_request[_token]" :value="page.csrf">
                <B24Button type="submit" color="air-primary" :loading="submitting" :disabled="!employees.length">Сформировать отчёт</B24Button>
            </form>
        </B24Card>
        <section class="min-w-0 space-y-3" aria-labelledby="recent-reports">
            <h2 id="recent-reports" class="text-h4">Последние отчёты</h2>
            <B24Alert v-if="deleteError" role="alert" color="air-primary-warning" title="Удаление отчёта" :description="deleteError" />
            <B24TableWrapper rounded bordered class="max-w-full overflow-x-auto">
                <table class="w-full">
                    <caption class="sr-only">Последние отчёты сотрудников</caption>
                    <thead><tr><th scope="col">Сотрудник</th><th scope="col">Период</th><th scope="col">Статус</th><th scope="col">Действия</th></tr></thead>
                    <TransitionGroup name="report-list" tag="tbody">
                        <tr v-for="run in runs" :key="run.id">
                            <td><a :href="`/reports/${run.id}`" class="text-primary">{{ run.employee_name }}</a></td>
                            <td class="whitespace-nowrap">{{ run.date_from }} — {{ run.date_to }}</td>
                            <td><RunStatus :status="run.status" /></td>
                            <td><B24Button color="air-secondary-alert" :loading="deleting === run.id" :disabled="deleting !== null" @click="deleteReport(run)">Удалить</B24Button></td>
                        </tr>
                        <tr v-if="!runs.length" key="empty"><td colspan="4" class="text-(--ui-color-base-2)">Отчётов пока нет. Выберите сотрудника и период.</td></tr>
                    </TransitionGroup>
                </table>
            </B24TableWrapper>
        </section>
    </div>
</template>

<style scoped>
.report-list-enter-active, .report-list-leave-active, .report-list-move { transition: opacity 180ms ease, transform 180ms ease; }
.report-list-enter-from, .report-list-leave-to { opacity: 0; transform: translateY(-4px); }
@media (prefers-reduced-motion: reduce) {
    .report-list-enter-active, .report-list-leave-active, .report-list-move { transition: none; }
}
</style>
