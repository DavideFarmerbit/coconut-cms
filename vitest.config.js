import {defineConfig} from 'vitest/config';

export default defineConfig({
    test: {
        include: ['src/**/*.test.js'],
        exclude: ['vendor/**', 'node_modules/**'],
    },
});
