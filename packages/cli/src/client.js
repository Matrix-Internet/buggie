/**
 * Thin fetch wrapper for the Buggie token API.
 * @param {{ host: string, token: string }} opts
 */
export function createClient({ host, token }) {
    const base = host.replace(/\/$/, '');

    /**
     * @param {string} method
     * @param {string} path
     * @param {unknown} [body]
     */
    async function request(method, path, body) {
        const url = `${base}/api/v1${path.startsWith('/') ? path : `/${path}`}`;
        const response = await fetch(url, {
            method,
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        const text = await response.text();
        /** @type {unknown} */
        let json = null;
        if (text) {
            try {
                json = JSON.parse(text);
            } catch {
                json = { message: text };
            }
        }

        if (!response.ok) {
            const message =
                json && typeof json === 'object' && 'message' in json
                    ? String(/** @type {{ message: unknown }} */ (json).message)
                    : `${response.status} ${response.statusText}`;
            throw new Error(`${method} ${path}: ${message}`);
        }

        return json;
    }

    return {
        /**
         * @param {string} [q]
         * @param {number} [page]
         */
        async listIssues(q, page = 1) {
            const params = new URLSearchParams({ page: String(page), per_page: '100' });
            if (q) {
                params.set('q', q);
            }
            return request('GET', `/issues?${params}`);
        },

        /** @param {string} key */
        async getIssue(key) {
            return request('GET', `/issues/${encodeURIComponent(key)}`);
        },

        /** @param {string} key */
        async getProject(key) {
            return request('GET', `/projects/${encodeURIComponent(key)}`);
        },

        /**
         * @param {string} key
         * @param {Record<string, unknown>} patch
         */
        async updateIssue(key, patch) {
            return request('PATCH', `/issues/${encodeURIComponent(key)}`, patch);
        },
    };
}
