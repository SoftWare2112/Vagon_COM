import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// En desarrollo, Vite reenvía /api y /sanctum a Laravel: mismo origen = cookies sin problemas de CORS.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
      '/sanctum': { target: 'http://localhost:8000', changeOrigin: false },
    },
  },
});
