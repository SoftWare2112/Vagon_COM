import axios, { AxiosError } from 'axios';

/**
 * Cliente HTTP único. Sanctum SPA: cookies HttpOnly + token XSRF.
 * Nunca guardamos tokens en localStorage (vulnerable a XSS).
 */
export const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '',
  withCredentials: true,
  withXSRFToken: true,
  timeout: 60_000,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export const csrf = () => http.get('/sanctum/csrf-cookie');

http.interceptors.response.use(
  (r) => r,
  async (error: AxiosError) => {
    const cfg = error.config as (typeof error.config & { _reintento?: boolean }) | undefined;
    // 419 = token CSRF expirado: se renueva y se reintenta una vez
    if (error.response?.status === 419 && cfg && !cfg._reintento) {
      cfg._reintento = true;
      await csrf();
      return http(cfg);
    }
    if (error.response?.status === 401 && !location.pathname.startsWith('/login')) {
      window.dispatchEvent(new Event('sesion-expirada'));
    }
    return Promise.reject(error);
  },
);

/** Mensaje legible para el usuario a partir de cualquier error. */
export function mensajeDeError(e: unknown): string {
  if (!navigator.onLine) return 'Sin conexión a internet. Revisa tu red e inténtalo de nuevo.';
  const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
  if (err.code === 'ECONNABORTED') return 'La operación tardó demasiado. Inténtalo de nuevo.';
  if (!err.response) return 'No se pudo contactar al servidor. Revisa tu conexión.';
  const data = err.response.data;
  if (data?.errors) return Object.values(data.errors).flat().join(' ');
  if (err.response.status === 403) return data?.message ?? 'No tienes permiso para realizar esta acción.';
  if (err.response.status === 429) return 'Demasiados intentos. Espera un momento.';
  return data?.message ?? 'Ocurrió un error inesperado.';
}
