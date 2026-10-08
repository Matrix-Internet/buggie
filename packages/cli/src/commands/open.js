import { findProjectRoot, issueUrl, loadConfig } from '../config.js';

/**
 * @param {string[]} args
 */
export async function openCommand(args) {
    const key = args[0];
    if (!key) {
        throw new Error('Usage: buggie open <KEY>');
    }

    const config = loadConfig(findProjectRoot());
    console.log(issueUrl(config, key));
}
