<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { calculateSalary, salaryText } from '../salary.js';
import RunStatus from '../components/RunStatus.vue';
import { formatDuration, formatEntryDate, compactReport } from '../report-format.js';

const props = defineProps({ page: { type: Object, required: true } });
const snapshot = ref({ run: props.page.run, rows: props.page.rows, errors: props.page.errors || [], total: props.page.total, complete: props.page.complete });
const submitting = ref(false);
const progressError = ref(false);
const compactOpen = ref(false);
const copyMessage = ref('');
const compactTextArea = ref();
const workingDays = ref(21);
const actualDays = ref(21);
const salary = ref('');
const hourlyRate = ref('');
const daysEdited = ref(false);
const actualEdited = ref(false);
const calendarSource = ref('loading');
const hours = computed(() => snapshot.value.rows.reduce((sum, row) => sum + row.seconds, 0) / 3600);
const pay = computed(() => calculateSalary(salary.value, workingDays.value, actualDays.value, hours.value, hourlyRate.value));
const calculationText = computed(() => salaryText(salary.value, workingDays.value, actualDays.value, hours.value, hourlyRate.value, pay.value));
const compactText = computed(() => compactReport(snapshot.value.rows, snapshot.value.run, props.page.portalBase) + (calculationText.value ? '\n\n' + calculationText.value : ''));
watch(workingDays, value => { if (!actualEdited.value) actualDays.value = value; });
const calendarController = new AbortController();
async function loadWorkingDays() {
    try {
        const response = await fetch(props.page.workdaysUrl || `/reports/${props.page.run.id}/workdays`, { credentials: 'same-origin', signal: calendarController.signal });
        if (!response.ok) throw new Error('Calendar unavailable');
        const result = await response.json();
        if (!Number.isInteger(result.days) || result.days < 0 || result.days > 31 || !['calendar', 'fallback', 'multiple_months'].includes(result.source)) throw new Error('Invalid calendar');
        if (active) {
            calendarSource.value = result.source;
            if (!daysEdited.value) workingDays.value = result.days;
        }
    } catch {
        if (active) calendarSource.value = 'fallback';
    }
}
let timer;
let controller;
let active = true;
const terminal = () => ['complete', 'complete_with_errors', 'failed'].includes(snapshot.value.run.status);

async function poll() {
    if (!active || terminal()) return;
    controller = new AbortController();
    try {
        const response = await fetch(props.page.progressUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
        if (!response.ok) throw new Error('Progress unavailable');
        const result = await response.json();
        if (!result.run || !Array.isArray(result.rows) || !Array.isArray(result.errors) || typeof result.total !== 'string') throw new Error('Invalid progress');
        if (active) {
            snapshot.value = result;
            progressError.value = false;
        }
    } catch {
        if (active) progressError.value = true;
    } finally {
        controller = null;
        if (active && !terminal()) timer = setTimeout(poll, 5000);
    }
}

onMounted(() => { loadWorkingDays(); if (!terminal()) timer = setTimeout(poll, 5000); });
onUnmounted(() => { active = false; clearTimeout(timer); controller?.abort(); calendarController.abort(); });

async function copyCompact() {
    const text = compactText.value;
    try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(text);
        copyMessage.value = 'Скопировано';
    } catch {
        compactTextArea.value?.focus();
        compactTextArea.value?.select();
        copyMessage.value = 'Автоматическое копирование недоступно. Текст выделен: нажмите Ctrl+C или скопируйте вручную.';
    }
}

function stageLabel(stage) {
    const labels = { time_entries: 'Получение записей времени', participants: 'Получение участников', task_list: 'Получение списка задач' };
    return labels[stage] ? `${labels[stage]} (${stage})` : stage;
}

function stateLabel(state) {
    const labels = { pending: 'Ожидает повтора', exhausted: 'Повторы завершены', resolved: 'Исправлено повтором' };
    return labels[state] ? `${labels[state]} (${state})` : state;
}

function retryDate(timestamp) {
    if (!timestamp) return '—';
    return `${new Intl.DateTimeFormat('ru-RU', { timeZone: snapshot.value.run.timezone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(new Date(Number(timestamp) * 1000))} ${snapshot.value.run.timezone}`;
}
</script>

<template>
    <div class="min-w-0 space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-2">
                <h1 class="break-words text-h1">{{ page.run.employee_name }}</h1>
                <p class="text-(--ui-color-base-2)">{{ page.run.date_from }} — {{ page.run.date_to }} включительно · {{ page.run.timezone }}</p>
            </div>
            <RunStatus :status="snapshot.run.status" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <B24Card>
                <p class="text-sm text-(--ui-color-base-2)">{{ snapshot.run.status === 'complete' ? 'Итого за период' : 'Промежуточный итог (неполный)' }}</p>
                <p data-testid="total" class="mt-2 text-3xl font-semibold tabular-nums">{{ snapshot.total }}</p>
            </B24Card>
            <B24Card>
                <p class="text-sm text-(--ui-color-base-2)">Обработано задач</p>
                <p data-testid="task-progress" class="mt-2 text-3xl font-semibold tabular-nums">{{ snapshot.run.checked_tasks }}<template v-if="snapshot.run.total_tasks != null"> из {{ snapshot.run.total_tasks }}</template></p>
                <p v-if="snapshot.run.total_tasks != null" class="mt-2 text-sm text-(--ui-color-base-2)">Всего — по выборке на начало сбора. Задачи на портале могут изменяться.</p>
            </B24Card>
        </div>
        <B24Alert v-if="snapshot.run.status === 'failed'" role="alert" color="air-primary-alert" title="Сбор прерван" :description="`${snapshot.run.error_code}. Итог неполный.`" />
        <B24Alert v-else-if="snapshot.run.status === 'complete_with_errors'" role="alert" color="air-primary-warning" title="Сбор завершён с ошибками" description="Итог неполный. Сохранённые строки и ошибки доступны ниже и в CSV." />
        <B24Alert v-else-if="!terminal()" role="status" color="air-primary" :title="snapshot.run.status === 'waiting' ? 'Ожидание повторного запроса' : 'Отчёт собирается'" :description="`Прогресс обновляется автоматически каждые пять секунд. Сбор выполняется при запуске команды.${snapshot.run.error_code ? ' Код: ' + snapshot.run.error_code + '.' : ''}${snapshot.run.retry_at ? ' Следующий повтор: ' + retryDate(snapshot.run.retry_at) + '.' : ''}`" />
        <B24Alert v-if="progressError" role="alert" color="air-primary-warning" title="Не удалось обновить прогресс" description="Сохранённые данные остаются на странице. Повторная попытка через пять секунд." />
        <B24Card v-if="snapshot.errors.length">
            <h2 class="text-h2">Ошибки сбора</h2>
            <ul class="mt-3 space-y-2 text-sm"><li v-for="error in snapshot.errors" :key="error.id" class="break-words">Задача {{ error.task_id || '—' }} {{ error.task_title }} · {{ stageLabel(error.stage) }} · {{ error.error_code }} · попытки: {{ error.attempts }} · {{ stateLabel(error.state) }}<span v-if="error.retry_at"> · повтор: {{ retryDate(error.retry_at) }}</span></li></ul>
        </B24Card>
        <div class="flex flex-wrap items-center gap-3">
            <B24Button :to="page.csvUrl" color="air-primary">Скачать CSV</B24Button>
            <B24Button color="air-secondary-no-accent" :disabled="!page.portalBase || !snapshot.run.employee_id" @click="compactOpen = true; copyMessage = ''">Компактная версия</B24Button>
            <form :action="page.refreshUrl" method="post" @submit="submitting = true">
                <input type="hidden" name="_token" :value="page.csrf">
                <B24Button type="submit" color="air-secondary-no-accent" :loading="submitting">Собрать заново</B24Button>
            </form>
        </div>
        <B24Card>
            <h2 class="text-h2">Калькулятор зарплаты</h2>
            <p class="mt-2 text-sm text-(--ui-color-base-2)">Расчёт только в браузере, не сохраняется. Суммы округляются до целых рублей на каждом этапе. Выплата по премии = премия / 0,94; при отрицательной премии — 0.</p>
            <p class="mt-2 text-sm" role="status">{{ calendarSource === 'loading' ? 'Загрузка календарной нормы…' : calendarSource === 'calendar' ? 'Норма за полный месяц по календарю портала. Проверьте праздники и переносы; это не личный график сотрудника.' : calendarSource === 'multiple_months' ? 'Период включает несколько месяцев: укажите норму вручную. Оклад по месяцам автоматически не распределяется.' : 'Календарь недоступен: подставлено 21. Уточните норму вручную.' }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <B24FormField label="Рабочих дней в месяце"><B24Input v-model="workingDays" aria-label="Рабочих дней в месяце" type="number" min="1" step="1" @update:model-value="daysEdited = true" /></B24FormField>
                <B24FormField label="Фактически отработано дней"><B24Input v-model="actualDays" aria-label="Фактически отработано дней" type="number" min="0" step="0.5" @update:model-value="actualEdited = true" /></B24FormField>
                <B24FormField label="Оклад (рублей)"><B24Input v-model="salary" aria-label="Оклад (рублей)" type="number" min="0" step="1" /></B24FormField>
                <B24FormField label="Отработано часов"><B24Input :model-value="Number(hours.toFixed(4))" aria-label="Отработано часов" type="number" step="0.5" readonly /></B24FormField>
                <B24FormField label="Ставка часа"><B24Input v-model="hourlyRate" aria-label="Ставка часа" type="number" min="0" step="1" /></B24FormField>
            </div>
            <div v-if="pay" class="mt-4 space-y-2">
                <p>Окладная выплата: {{ pay.salary }} р.</p>
                <p>Наработка: {{ pay.earned }} р.</p>
                <p>Премия: {{ pay.bonus }} р.</p>
                <p>Выплата по премии: {{ pay.payout }} р.</p>
                <p v-if="pay.bonus < 0" role="alert">Премия отрицательная: наработка меньше окладной выплаты. Выплата по премии — 0 р.</p>
                <p data-testid="salary-summary">Итого к выплате {{ pay.salary }} р. — оклад, {{ pay.payout }} р. — премия.</p>
            </div>
            <p v-else data-testid="salary-summary" class="mt-4">Проверьте значения: норма должна быть больше нуля, остальные поля — неотрицательные числа. Заполните оклад и ставку часа.</p>
            <p v-if="snapshot.run.status !== 'complete'" class="mt-2">Расчёт предварительный: часы получены из неполного отчёта.</p>
        </B24Card>
        <B24TableWrapper rounded bordered class="max-w-full overflow-x-auto">
            <table class="w-full">
                <caption class="sr-only">Задачи и время сотрудника за период</caption>
                <thead><tr><th scope="col">ID</th><th scope="col">Задача</th><th scope="col">Статус Б24</th><th scope="col">Исполнитель ID</th><th scope="col">Роль сейчас</th><th scope="col">Время (ч:м)</th></tr></thead>
                <tbody>
                    <template v-for="row in snapshot.rows" :key="row.id">
                        <tr>
                            <td>{{ row.id }}</td>
                            <td class="max-w-xs break-words"><a v-if="page.portalBase" :href="`${page.portalBase}/company/personal/user/${snapshot.run.employee_id || row.responsibleId}/tasks/task/view/${row.id}/`" target="_blank" rel="noopener noreferrer" class="text-primary">{{ row.title }}</a><span v-else>{{ row.title }}</span></td>
                            <td>{{ row.status }}</td><td>{{ row.responsibleId }}</td>
                            <td><B24Badge color="air-secondary-no-accent">{{ row.role }}</B24Badge></td>
                            <td class="whitespace-nowrap tabular-nums">{{ formatDuration(row.seconds) }}</td>
                        </tr>
                        <tr><td colspan="6">
                            <details><summary class="cursor-pointer text-primary">Записи времени ({{ row.entries.length }})</summary>
                                <ul class="mt-3 space-y-2 text-sm"><li v-for="entry in row.entries" :key="entry.ID" class="break-words">{{ formatEntryDate(entry.CREATED_DATE, snapshot.run.timezone) }} · {{ formatDuration(entry.SECONDS) }} {{ entry.COMMENT_TEXT || '' }}</li></ul>
                            </details>
                        </td></tr>
                    </template>
                    <tr v-if="!snapshot.rows.length"><td colspan="6">{{ snapshot.run.status === 'complete' ? 'В этом периоде списаний времени не найдено.' : 'Сохранённых списаний времени пока нет.' }}</td></tr>
                </tbody>
                <tfoot><tr><th scope="row" colspan="5">{{ snapshot.run.status === 'complete' ? 'Итого' : 'Промежуточный итог (неполный)' }}</th><td class="whitespace-nowrap font-semibold tabular-nums">{{ snapshot.total }}</td></tr></tfoot>
            </table>
        </B24TableWrapper>
        <B24Card>
            <div class="space-y-3 text-sm text-(--ui-color-base-2)">
                <p>Создан: {{ formatEntryDate(snapshot.run.created_at, snapshot.run.timezone) }} · начало сбора: {{ formatEntryDate(snapshot.run.started_at, snapshot.run.timezone) }} · окончание: {{ formatEntryDate(snapshot.run.finished_at, snapshot.run.timezone) }} · последнее действие: {{ formatEntryDate(snapshot.run.last_processed_at, snapshot.run.timezone) }}.</p>
                <p>Отчёт включает только задачи и записи, доступные владельцу вебхука. Недоступные и удалённые задачи не восстановлены.</p>
                <p>Роли и состояние задач текущие, не исторические. Сохранённый отчёт автоматически не обновляется.</p>
            </div>
        </B24Card>
        <B24Modal v-model:open="compactOpen" title="Компактная версия" description="Ссылки на задачи и списанное время в дробных часах; округление до четырёх знаков.">
            <template #body>
                <textarea ref="compactTextArea" aria-label="Текст компактного отчёта" :value="compactText" readonly rows="10" class="w-full resize-y rounded border border-(--ui-color-base-7) p-3 font-mono text-sm" />
                <p role="status" aria-live="polite" class="mt-3 text-sm">{{ copyMessage }}</p>
            </template>
            <template #footer><B24Button color="air-primary" @click="copyCompact">Скопировать</B24Button></template>
        </B24Modal>
    </div>
</template>
