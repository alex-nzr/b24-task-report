import './main.css';
import { createApp } from 'vue';
import b24UiPlugin from '@bitrix24/b24ui-nuxt/vue-plugin';
import App from './App.vue';

const payload = document.getElementById('app-data');
if (payload) {
    const data = JSON.parse(payload.textContent);
    const pages = {
        login: () => import('./pages/LoginPage.vue'),
        index: () => import('./pages/IndexPage.vue'),
        report: () => import('./pages/ReportPage.vue'),
    };
    const loadPage = pages[data.pageData.page];
    if (loadPage) {
        loadPage().then(({ default: pageComponent }) => {
            createApp(App, { data, pageComponent }).use(b24UiPlugin).mount('#app');
        }).catch(() => {
            // Do not replace usable server HTML when a page chunk cannot be loaded.
        });
    }
}
