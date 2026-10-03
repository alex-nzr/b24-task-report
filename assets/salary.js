export function calculateSalary(salary, days, actual, hours, rate) {
    const values = [salary, days, actual, hours, rate];
    if (values.some(value => value === '' || value == null || !Number.isFinite(Number(value)) || Number(value) < 0 || Number(value) > 1e9) || Number(days) <= 0) return null;
    const salaryPay = Math.round(Number(salary) / Number(days) * Number(actual));
    const earned = Math.round(Number(hours) * Number(rate));
    const bonus = earned - salaryPay;
    const payout = bonus < 0 ? 0 : Math.round(bonus * 1.06);
    if (![salaryPay, earned, bonus, payout].every(Number.isSafeInteger)) return null;
    return { salary: salaryPay, earned, bonus, payout };
}

export function salaryText(salary, days, actual, hours, rate, result) {
    if (!result) return '';
    const number = value => String(value).replace('.', ',');
    return `оклад/(кол-во раб.дней в месяце) * (фактическое количество раб.дней)
${number(salary)} / ${number(days)} * ${number(actual)} = ${result.salary} р.

Наработка по часам
${number(Number(hours.toFixed(4)))}ч * ${number(rate)} р. = ${result.earned} р.

Премия
${result.earned} - ${result.salary} = ${result.bonus} р.
Премия с учётом налога самозанятого
${result.bonus < 0 ? 'Премия отрицательная: выплата по премии = 0 р.' : `${result.bonus}*1.06=${result.payout} р.`}

Итоговая выплата
${result.salary} р. - оклад, ${result.payout} р. - премия самозанятому.`;
}
