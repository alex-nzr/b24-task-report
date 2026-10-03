import { test, expect } from '@playwright/test';

test('report deletion uses AJAX and retains the row when server rejects it', async ({ page }) => {
    const run = { id: 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', employee_name: 'Delete me', date_from: '2026-10-01', date_to: '2026-10-30', status: 'complete', deleteCsrf: 'test-csrf' };
    await page.route('http://127.0.0.1:8091/', async route => {
        const response = await route.fetch();
        const html = (await response.text()).replace(/(<script[^>]*id="app-data"[^>]*>)([\s\S]*?)(<\/script>)/, (_, before, json, after) => {
            const data = JSON.parse(json);
            data.pageData.runs = [run];
            return before + JSON.stringify(data).replaceAll('<', '\\u003c') + after;
        });
        await route.fulfill({ response, body: html });
    });
    let requests = 0;
    await page.route('**/reports/*/delete', async route => {
        expect(route.request().method()).toBe('POST');
        expect(route.request().postData()).toContain('_token=test-csrf');
        requests++;
        await route.fulfill(requests === 1 ? { status: 409, json: { error: 'Сборщик занят' } } : { json: { runs: [] } });
    });
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/');
    await page.getByRole('button', { name: 'Удалить', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Сборщик занят' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Delete me', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Удалить', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Delete me', exact: true })).toHaveCount(0);
    await expect(page.getByText('Отчётов пока нет.', { exact: false })).toBeVisible();
    expect(requests).toBe(2);
    expect(new URL(page.url()).pathname).toBe('/');
});

test('negative bonus pays zero on screen and in compact text', async ({ page }) => {
    await page.route('**/workdays', route => route.fulfill({ json: { days: 21, source: 'calendar' } }));
    await reportFixture(page);
    await page.getByLabel('Оклад (рублей)', { exact: true }).fill('40000');
    await page.getByLabel('Ставка часа', { exact: true }).fill('1000');
    await expect(page.getByTestId('salary-summary')).toHaveText('Итого к выплате 40000 р. — оклад, 0 р. — премия.');
    await expect(page.getByText('Премия: -18500 р.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Компактная версия', exact: true }).click();
    await expect(page.getByLabel('Текст компактного отчёта', { exact: true })).toHaveValue(/40000 р\. - оклад, 0 р\. - премия самозанятому/);
});

test('calendar norm synchronizes actual days only until manual edit', async ({ page }) => {
    await page.route('**/workdays', route => route.fulfill({ json: { days: 22, source: 'calendar' } }));
    await reportFixture(page);
    await expect(page.getByLabel('Рабочих дней в месяце', { exact: true })).toHaveValue('22');
    await expect(page.getByLabel('Фактически отработано дней', { exact: true })).toHaveValue('22');
    await page.getByLabel('Рабочих дней в месяце', { exact: true }).fill('20');
    await expect(page.getByLabel('Фактически отработано дней', { exact: true })).toHaveValue('20');
    await page.getByLabel('Фактически отработано дней', { exact: true }).fill('7');
    await page.getByLabel('Рабочих дней в месяце', { exact: true }).fill('21');
    await expect(page.getByLabel('Фактически отработано дней', { exact: true })).toHaveValue('7');
});

test('salary calculator updates live and appends calculation to compact report', async ({ page }) => {
    await page.route('**/workdays', route => route.fulfill({ json: { days: 21, source: 'calendar' } }));
    await reportFixture(page);
    await page.getByLabel('Оклад (рублей)', { exact: true }).fill('40000');
    await page.getByLabel('Фактически отработано дней', { exact: true }).fill('7');
    await page.getByLabel('Ставка часа', { exact: true }).fill('1100');
    await expect(page.getByTestId('salary-summary')).toContainText('13333');
    await expect(page.getByTestId('salary-summary')).toContainText('10936');
    await expect(page.getByLabel('Отработано часов', { exact: true })).toHaveValue('21.5');
    await expect(page.getByLabel('Отработано часов', { exact: true })).toHaveAttribute('readonly', '');
    await page.getByRole('button', { name: 'Компактная версия', exact: true }).click();
    await expect(page.getByLabel('Текст компактного отчёта', { exact: true })).toHaveValue(/13333 р\. - оклад, 10936 р\. - премия самозанятому/);
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await page.getByLabel('Рабочих дней в месяце', { exact: true }).fill('0');
    await expect(page.getByTestId('salary-summary')).toContainText('Проверьте');
});

async function reportFixture(page, status = 'complete') {
    await page.route('**/reports/cccccccccccccccccccccccccccccccc', async route => {
        const response = await route.fetch();
        let html = await response.text();
        html = html.replace(/(<script[^>]*id="app-data"[^>]*>)([\s\S]*?)(<\/script>)/, (_, before, json, after) => {
            const data = JSON.parse(json);
            Object.assign(data.pageData.run, { employee_id: 993, checked_tasks: 13, total_tasks: 15, status, timezone: 'Asia/Yekaterinburg' });
            data.pageData.portalBase = 'https://portal.ru';
            data.pageData.rows = [
                { id: 151475, title: 'First', status: 'completed', responsibleId: 7, role: 'Соисполнитель', seconds: 57600, entries: [{ ID: 1, CREATED_DATE: '2026-09-10T10:00:00Z', SECONDS: 57600 }] },
                { id: 151476, title: 'Second', status: 'completed', responsibleId: 993, role: 'Исполнитель', seconds: 14400, entries: [] },
                { id: 151477, title: 'Third', status: 'completed', responsibleId: 993, role: 'Исполнитель', seconds: 5400, entries: [] },
            ];
            data.pageData.total = '21:30';
            return before + JSON.stringify(data).replaceAll('<', '\\u003c') + after;
        });
        await route.fulfill({ response, body: html });
    });
    await page.goto('/reports/cccccccccccccccccccccccccccccccc');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
}

test('time entries and initial task total are human readable', async ({ page }) => {
    await reportFixture(page);
    await expect(page.getByTestId('task-progress')).toHaveText('13 из 15');
    await expect(page.locator('tbody tr').first()).toContainText('16:00');
    await page.getByText('Записи времени (1)', { exact: true }).click();
    await expect(page.locator('details[open]')).toContainText('10.09.2026 15:00:00 · 16:00');
});

test('collection feedback survives Vue replacing server markup', async ({ page }) => {
    await page.route('**/reports/dddddddddddddddddddddddddddddddd', async route => {
        const response = await route.fetch();
        const html = (await response.text()).replace(/(<script[^>]*id="app-data"[^>]*>)([\s\S]*?)(<\/script>)/, (_, before, json, after) => {
            const data = JSON.parse(json);
            data.flashes = { warning: ['Сбор уже выполняется. Новый отчёт сохранён в очереди.'] };
            return before + JSON.stringify(data).replaceAll('<', '\\u003c') + after;
        });
        await route.fulfill({ response, body: html });
    });
    await page.goto('/reports/dddddddddddddddddddddddddddddddd');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('Новый отчёт сохранён в очереди');
});

test('compact report uses employee links and copies decimal hours', async ({ page }) => {
    await reportFixture(page);
    await page.getByRole('button', { name: 'Компактная версия', exact: true }).click();
    const dialog = page.getByRole('dialog');
    const expected = 'https://portal.ru/company/personal/user/993/tasks/task/view/151475/ 16ч\nhttps://portal.ru/company/personal/user/993/tasks/task/view/151476/ 4ч\nhttps://portal.ru/company/personal/user/993/tasks/task/view/151477/ 1.5ч\nИтого 21.5ч';
    await expect(dialog.getByLabel('Текст компактного отчёта')).toHaveValue(expected);
    await page.evaluate(() => { navigator.clipboard.writeText = async text => { window.copiedReport = text; }; });
    await dialog.getByRole('button', { name: 'Скопировать', exact: true }).click();
    expect(await page.evaluate(() => window.copiedReport)).toBe(expected);
    await expect(dialog.getByRole('status')).toContainText('Скопировано');
});

test('partial compact report remains labelled and clipboard failure offers manual copy', async ({ page }) => {
    await reportFixture(page, 'waiting');
    await page.getByRole('button', { name: 'Компактная версия', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Текст компактного отчёта')).toHaveValue(/^Неполный отчёт/);
    await page.evaluate(() => { navigator.clipboard.writeText = async () => { throw new Error('Denied'); }; });
    await dialog.getByRole('button', { name: 'Скопировать', exact: true }).click();
    await expect(dialog.getByRole('status')).toContainText('Ctrl+C');
});

test('immediate checkbox is optional and submits the Symfony field', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    const checkbox = page.getByRole('checkbox', { name: 'Сформировать сразу', exact: true });
    await expect(checkbox).not.toBeChecked();
    await checkbox.check();
    const data = await page.locator('form[action="/reports"]').evaluate(form => Object.fromEntries(new FormData(form)));
    expect(data['report_request[collectNow]']).toBe('1');
    await expect(page.getByText(/не дожидаясь крона/)).toBeVisible();
});

test('partial progress retains expanded rows, survives failure and stops at terminal status without navigation', async ({ page }, testInfo) => {
    await page.clock.install();
    await page.clock.pauseAt(new Date());
    let requests = 0;
    let navigations = 0;
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });
    await page.route('**/progress', async route => {
        requests++;
        if (requests === 1) {
            await route.fulfill({ status: 503, body: '' });
            return;
        }
        const initial = await page.locator('#app-data').textContent();
        const data = JSON.parse(initial).pageData;
        await route.fulfill({ json: { run: { ...data.run, status: 'complete_with_errors' }, rows: data.rows, errors: data.errors, total: data.total, complete: true } });
    });
    await page.goto('/reports/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await expect(page.getByTestId('total')).toHaveText('01:00');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await expect(page.getByText('Получение участников (participants)', { exact: false })).toBeVisible();
    await expect(page.getByText('Повторы завершены (exhausted)', { exact: false })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Ожидание повторного запроса' })).toContainText('QUERY_LIMIT_EXCEEDED');
    await expect(page.getByRole('status').filter({ hasText: 'Ожидание повторного запроса' })).toContainText('02.10.2026, 12:00:00 UTC');
    await page.getByText('Записи времени (1)', { exact: true }).click();
    await page.clock.fastForward(5000);
    await expect(page.getByRole('alert').filter({ hasText: 'Не удалось обновить прогресс' })).toBeVisible({ timeout: 8000 });
    await expect(page.getByTestId('total')).toHaveText('01:00');
    await page.clock.fastForward(5000);
    await expect(page.getByText('Готов с ошибками', { exact: true })).toBeVisible({ timeout: 8000 });
    await expect(page.locator('details')).toHaveAttribute('open', '');
    await page.clock.fastForward(5500);
    expect(requests).toBe(2);
    expect(navigations).toBe(1);
    await expect(page.getByRole('link', { name: 'Скачать CSV', exact: true })).toBeVisible();
    await page.screenshot({ path: `.ai/.tmp/ui-partial-${testInfo.project.name}.png`, fullPage: true });
});

test('a failed page chunk keeps the server login form usable', async ({ page }) => {
    await page.route('**/LoginPage-*.js', route => route.abort());
    await page.goto('/login');
    await expect(page.locator('input[name="_username"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти', exact: true })).toBeEnabled();
    await expect(page.locator('input[name="_csrf_token"]')).toHaveValue(/.+/);
});

test('explanatory text remains readable against its background', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    const colors = await page.locator('main p').first().evaluate(element => {
        let parent = element;
        let background;
        do {
            background = getComputedStyle(parent).backgroundColor;
            parent = parent.parentElement;
        } while (parent && background === 'rgba(0, 0, 0, 0)');
        return [getComputedStyle(element).color, background];
    });
    const luminance = color => color.match(/[\d.]+/g).slice(0, 3).map(Number).map(value => value / 255).map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
    const values = colors.map(luminance).sort((a, b) => b - a);
    expect((values[0] + 0.05) / (values[1] + 0.05)).toBeGreaterThanOrEqual(4.5);
});

test('UI Kit login preserves the Symfony authentication form', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await page.getByLabel('Логин', { exact: true }).fill('operator');
    await page.getByLabel('Пароль', { exact: true }).fill('example-only');
    const formData = await page.locator('form[data-form="login"]').evaluate(form => Object.fromEntries(new FormData(form)));
    expect(formData._username).toBe('operator');
    expect(formData._password).toBe('example-only');
    expect(formData._csrf_token).toBeTruthy();
    expect(await page.getByRole('button', { name: 'Войти', exact: true }).evaluate(button => getComputedStyle(button).backgroundColor)).not.toBe('rgba(0, 0, 0, 0)');
    await page.screenshot({ path: `.ai/.tmp/ui-login-${testInfo.project.name}.png`, fullPage: true });
    expect(errors).toEqual([]);
});

test('employee selection submits original field names and CSRF token', async ({ page }, testInfo) => {
    await page.goto('/');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await page.getByRole('combobox', { name: 'Сотрудник', exact: true }).click();
    await page.getByRole('option', { name: 'Employee (#7)', exact: true }).click();
    await page.getByLabel('С даты', { exact: true }).fill('2026-09-01');
    await page.getByLabel('По дату включительно', { exact: true }).fill('2026-09-30');
    const submitted = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/reports'));
    await page.getByRole('button', { name: 'Сформировать отчёт', exact: true }).click();
    const body = new URLSearchParams((await submitted).postData());
    expect(body.get('report_request[employeeId]')).toBe('7');
    expect(body.get('report_request[from]')).toBe('2026-09-01');
    expect(body.get('report_request[to]')).toBe('2026-09-30');
    expect(body.get('report_request[_token]')).toBeTruthy();
    await page.screenshot({ path: `.ai/.tmp/ui-form-${testInfo.project.name}.png`, fullPage: true });
});

test('server validation remains visible and correction stays enabled', async ({ page }) => {
    await page.goto('/invalid');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('дата начала');
    await expect(page.getByRole('button', { name: 'Сформировать отчёт', exact: true })).toBeEnabled();
});

test('saved report is escaped, accessible and does not overflow the viewport', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/reports/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    await expect(page.locator('[data-ui-ready]')).toBeVisible();
    await expect(page.getByText('Без роли', { exact: true })).toBeVisible();
    await expect(page.getByTestId('total')).toHaveText('01:00');
    await expect(page.getByRole('link', { name: 'Скачать CSV', exact: true })).toHaveAttribute('href', /\/csv$/);
    await expect(page.locator('script:not([src]):not([type="application/json"])')).toHaveCount(0);
    await page.getByText('Записи времени (1)', { exact: true }).click();
    await expect(page.getByText('<img src=x>', { exact: false })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: `.ai/.tmp/ui-report-${testInfo.project.name}.png`, fullPage: true });
    expect(errors).toEqual([]);
});
