<script setup>
import { reactive, ref } from 'vue';

const props = defineProps({ page: { type: Object, required: true } });
const values = reactive({ username: props.page.username, password: '' });
const submitting = ref(false);
</script>

<template>
    <section class="mx-auto max-w-md space-y-6 py-6 sm:py-12">
        <div class="space-y-2">
            <h1 class="text-h1">Вход в отчёты</h1>
            <p class="text-(--ui-color-base-2)">Задачи, роли и списанное время сотрудника за выбранный период.</p>
        </div>
        <B24Alert v-if="page.error" role="alert" color="air-primary-alert" title="Не удалось войти" :description="page.error" />
        <B24Card>
            <form data-form="login" :action="page.action" method="post" class="space-y-5" @submit="submitting = true">
                <B24FormField label="Логин" name="_username" required>
                    <B24Input v-model="values.username" name="_username" autocomplete="username" required class="w-full" />
                </B24FormField>
                <B24FormField label="Пароль" name="_password" required>
                    <B24Input v-model="values.password" name="_password" type="password" autocomplete="current-password" required class="w-full" />
                </B24FormField>
                <input type="hidden" name="_csrf_token" :value="page.csrf">
                <B24Button type="submit" color="air-primary" :loading="submitting" class="w-full justify-center">Войти</B24Button>
            </form>
        </B24Card>
    </section>
</template>
