import { useEffect, useState } from 'react';

export function useMediaQuery(query: string): boolean {
  const [ok, setOk] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const fn = (e: MediaQueryListEvent) => setOk(e.matches);
    mq.addEventListener('change', fn);
    return () => mq.removeEventListener('change', fn);
  }, [query]);
  return ok;
}

export const useEsMovil = () => useMediaQuery('(max-width: 767px)');
