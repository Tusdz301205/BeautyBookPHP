import tailwind from 'tailwindcss-beautybook';
import autoprefixer from 'autoprefixer';
import { resolve } from 'node:path';

// Keep legacy pink utility names compatible while sharing the rose brand tokens.
const brandUtilities = Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => {
    const tokenShade = ({ 300: 200, 800: 700, 950: 900 })[shade] || shade;
    return [shade, `oklch(from var(--color-berry-${tokenShade}) l c h / <alpha-value>)`];
}));

// Isolated Tailwind 3 pipeline: the Laravel course views keep Tailwind 4.
export default {
    base: '/beautybook-build/',
    publicDir: false,
    esbuild: { jsx: 'automatic' },
    define: {
        'import.meta.env.VITE_API_BASE': JSON.stringify('/api/v1'),
    },
    css: {
        postcss: {
            plugins: [tailwind({
                content: ['./resources/beautybook/src/**/*.{js,jsx}'],
                theme: { extend: { colors: { pink: brandUtilities } } },
                plugins: [],
            }), autoprefixer()],
        },
    },
    build: {
        outDir: 'public/beautybook-build',
        emptyOutDir: true,
        manifest: 'manifest.json',
        rollupOptions: {
            input: resolve('resources/beautybook/src/main.jsx'),
            output: {
                manualChunks: {
                    'react-vendor': ['react', 'react-dom', 'react-router-dom', 'zustand'],
                    charts: ['recharts'],
                    icons: ['lucide-react'],
                    'date-vendor': ['date-fns'],
                },
            },
        },
    },
};
