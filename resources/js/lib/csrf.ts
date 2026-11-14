/** Header CSRF untuk fetch() POST ke Laravel (di luar router Inertia). */
export function csrfHeaders(): Record<string, string> {
    const xsrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
    return xsrf
        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
        : { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' };
}
