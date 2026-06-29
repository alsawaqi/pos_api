import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

/**
 * pos_api is primarily a JSON API, but it ships a Laravel/Vite scaffold. Pin the
 * dev server to its OWN port (5176, 1:1 host:container) so it never collides
 * with marketing-api's vite (5173) on the shared docker host. Reads VITE_PORT /
 * VITE_DEV_SERVER_URL / VITE_HMR_* from .env.
 */
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const vitePort = Number(env.VITE_PORT || 5176);
    const devServerUrl = env.VITE_DEV_SERVER_URL || `http://localhost:${vitePort}`;
    const hmrHost = env.VITE_HMR_HOST || 'localhost';
    const hmrPort = Number(env.VITE_HMR_PORT || vitePort);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: vitePort,
            strictPort: true,
            origin: devServerUrl,
            hmr: {
                host: hmrHost,
                port: hmrPort,
                protocol: 'ws',
            },
            watch: {
                usePolling: true,
                interval: 200,
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
