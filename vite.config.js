import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        // The stock Laravel skeleton fetches 'Instrument Sans' from fonts.bunny.net
        // at build time. That is removed here so the build has no external network
        // dependency: it is blocked by egress policy in this environment, and it
        // would make CI builds fail on any CDN outage. The --font-sans stack in
        // resources/css/app.css still names Instrument Sans first and falls back to
        // system fonts, so self-hosting the files later needs no config change.
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
