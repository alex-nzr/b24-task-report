import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/ui',
    outputDir: '.ai/.tmp/ui-test-results',
    workers: 1,
    use: { baseURL: 'http://127.0.0.1:8091', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
        { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
    ],
    webServer: { command: 'node tests/ui/server.mjs', url: 'http://127.0.0.1:8091/login', reuseExistingServer: false },
});
