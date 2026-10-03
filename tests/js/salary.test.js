import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calculateSalary, salaryText } from '../../assets/salary.js';

test('salary sample uses whole rubles at each displayed stage', () => {
    const result = calculateSalary(40000, 21, 7, 21.5, 1100);
    assert.deepEqual(result, { salary: 13333, earned: 23650, bonus: 10317, payout: 10936 });
    assert.match(salaryText(40000, 21, 7, 21.5, 1100, result), /13333 р\. - оклад, 10936 р\. - премия самозанятому/);
});
test('invalid and empty inputs never produce NaN or divide by zero', () => {
    for (const days of [0, '', -1, Infinity]) assert.equal(calculateSalary(40000, days, 7, 21.5, 1100), null);
    assert.equal(calculateSalary('', 21, 7, 21.5, 1100), null);
    assert.equal(calculateSalary(1e9, 0.000001, 1e9, 21.5, 1100), null);
});
test('negative bonus is retained but its payout and copied summary are zero', () => {
    const result = calculateSalary(40000, 20, 20, 1, 1000);
    assert.deepEqual(result, { salary: 40000, earned: 1000, bonus: -39000, payout: 0 });
    const text = salaryText(40000, 20, 20, 1, 1000, result);
    assert.match(text, /40000 р\. - оклад, 0 р\. - премия самозанятому/);
    assert.doesNotMatch(text, /-39000\*1\.06=0/);
});
test('copied hours are readable without discarding seconds in the calculation', () => {
    const result = calculateSalary(0, 21, 0, 3623 / 3600, 3600);
    assert.equal(result.earned, 3623);
    assert.match(salaryText(0, 21, 0, 3623 / 3600, 3600, result), /1,0064ч/);
});
