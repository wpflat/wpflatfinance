import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// WPFlatFinance is a separate bundle that runs inside WPFlatCore's page: Vue is not bundled again, it is WPFlatCore's window.Vue.
export default defineConfig( {
	root: 'ui',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"' },
	build: {
		outDir: '../build',
		emptyOutDir: true,
		cssCodeSplit: false,
		lib: { entry: 'main.js', name: 'WPFlatFinance', formats: [ 'iife' ], fileName: () => 'wpflatfinance.js', cssFileName: 'wpflatfinance' },
		rollupOptions: { external: [ 'vue' ], output: { globals: { vue: 'Vue' } } },
	},
} );
