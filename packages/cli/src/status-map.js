/**
 * Default local → Buggie status names (matched case-insensitively on push).
 * Override in .buggie/config.json under "status_map".
 */
export const DEFAULT_STATUS_MAP = {
    todo: 'Todo',
    triage: 'New',
    in_progress: 'In Progress',
    ready_for_review: 'In Review',
    review: 'In Review',
    done: 'Done',
    canceled: "Won't Fix",
    cancelled: "Won't Fix",
};

/**
 * @param {Record<string, string> | undefined} fromConfig
 * @returns {Record<string, string>}
 */
export function statusMap(fromConfig) {
    return { ...DEFAULT_STATUS_MAP, ...(fromConfig ?? {}) };
}

/**
 * Map a local_status value to the needle passed to resolveStatusId
 * (Buggie status name or category).
 * @param {string} localStatus
 * @param {Record<string, string>} map
 */
export function remoteNeedleFor(localStatus, map) {
    const key = localStatus.trim().toLowerCase().replace(/\s+/g, '_');
    if (map[key]) {
        return map[key];
    }
    // Already a Buggie name / category
    return localStatus.trim();
}
