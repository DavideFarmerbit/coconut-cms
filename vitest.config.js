import {defineConfig} from 'vitest/config';

export default defineConfig({
    test: {
        include: ['admin/tests/**/*.test.ts'],
        exclude: ['vendor/**', 'node_modules/**'],
    },
});
