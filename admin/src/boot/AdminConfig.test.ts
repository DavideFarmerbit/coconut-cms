import {describe, expect, it} from 'vitest';
import {AdminConfig} from './AdminConfig.ts';

describe('AdminConfig', () => {
    it('reads the API URL and the CSRF token', () => {
        const config = AdminConfig.parse('{"apiUrl": "/api", "csrfToken": "abc"}');

        expect(config.apiUrl).toBe('/api');
        expect(config.csrfToken).toBe('abc');
    });

    it.each([
        ['not JSON', '{', 'isn\'t JSON'],
        ['not an object', '"x"', 'isn\'t an object'],
        ['no API URL', '{"csrfToken": "abc"}', 'no apiUrl'],
        ['an empty token', '{"apiUrl": "/api", "csrfToken": ""}', 'no csrfToken'],
    ])('refuses %s', (_name, json, message) => {
        expect(() => AdminConfig.parse(json)).toThrow(message);
    });
});
