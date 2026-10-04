import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// BizyMoney is a separate bundle that runs inside BizyCore's page: Vue is not bundled again, it is BizyCore's window.Vue.
export default defineConfig( {
	root: 'ui',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"' },
	build: {
		outDir: '../build',
		emptyOutDir: true,
		cssCodeSplit: false,
		lib: { entry: 'main.js', name: 'BizyMoney', formats: [ 'iife' ], fileName: () => 'bizymoney.js', cssFileName: 'bizymoney' },
		rollupOptions: { external: [ 'vue' ], output: { globals: { vue: 'Vue' } } },
	},
} );
