export function formatDuration(seconds) {
    return `${String(Math.floor(seconds / 3600)).padStart(2, '0')}:${String(Math.floor(seconds % 3600 / 60)).padStart(2, '0')}`;
}

export function formatEntryDate(value, timezone) {
    if (!value || Number.isNaN(Date.parse(value))) return '—';
    const parts = new Intl.DateTimeFormat('ru-RU', { timeZone: timezone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value));
    const get = type => parts.find(part => part.type === type)?.value;
    return `${get('day')}.${get('month')}.${get('year')} ${get('hour')}:${get('minute')}:${get('second')}`;
}

export function compactReport(rows, run, portalBase) {
    const hours = seconds => String(Number((seconds / 3600).toFixed(4)));
    const lines = run.status === 'complete' ? [] : ['Неполный отчёт: только сохранённые списания, проверьте ошибки.'];
    for (const row of rows) {
        lines.push(`${portalBase}/company/personal/user/${run.employee_id}/tasks/task/view/${row.id}/ ${hours(row.seconds)}ч`);
    }
    lines.push(`Итого ${hours(rows.reduce((sum, row) => sum + row.seconds, 0))}ч`);
    return lines.join('\n');
}
