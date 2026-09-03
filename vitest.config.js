import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        // Nettoyage automatique entre chaque test
        clearMocks: true,
        restoreMocks: true,
        unstubGlobals: true,
    },
});
