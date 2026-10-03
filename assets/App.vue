<script setup>
import { ru } from '@bitrix24/b24ui-nuxt/locale';
defineProps({ data: { type: Object, required: true }, pageComponent: { type: Object, required: true } });
</script>

<template>
    <B24App :locale="ru">
        <div data-ui-ready class="min-h-screen min-w-0">
            <header class="border-b border-default bg-default">
                <B24Container class="flex items-center justify-between gap-4 py-4">
                    <a :href="data.homeUrl" class="text-h4 text-primary">Задачи · время</a>
                    <form v-if="data.authenticated" :action="data.logoutUrl" method="post">
                        <input type="hidden" name="_csrf_token" :value="data.logoutToken">
                        <B24Button type="submit" color="air-secondary-no-accent" size="sm">Выйти</B24Button>
                    </form>
                </B24Container>
            </header>
            <B24Container as="main" class="min-w-0 py-6 sm:py-8">
                <template v-for="(messages, type) in data.flashes" :key="type">
                    <B24Alert v-for="(message, index) in messages" :key="index" role="alert" color="air-primary-warning" :description="message" class="mb-4" />
                </template>
                <component :is="pageComponent" :page="data.pageData" />
            </B24Container>
            <B24Container as="footer" class="py-6 text-sm text-(--ui-color-base-2)">Отчёты по задачам Битрикс24 · данные доступны в пределах прав вебхука</B24Container>
        </div>
    </B24App>
</template>
