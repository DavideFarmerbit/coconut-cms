import {describe, expect, it} from 'vitest';
import {AdminConfig} from './AdminConfig.ts';

describe('AdminConfig', () => {
    it('reads the API URL, the CSRF token and the plugins', () => {
        const config = AdminConfig.parse('{"apiUrl": "/api", "csrfToken": "abc", "plugins": [{"module": "demo", "script": "/dist/admin/assets/plugin-demo.js"}]}');

        expect(config.apiUrl).toBe('/api');
        expect(config.csrfToken).toBe('abc');
        expect(config.plugins).toEqual([{module: 'demo', script: '/dist/admin/assets/plugin-demo.js'}]);
        expect(AdminConfig.parse('{"apiUrl": "/api", "csrfToken": "abc"}').plugins).toEqual([]);
    });

    it.each([
        ['not JSON', '{', 'isn\'t JSON'],
        ['not an object', '"x"', 'isn\'t an object'],
        ['no API URL', '{"csrfToken": "abc"}', 'no apiUrl'],
        ['an empty token', '{"apiUrl": "/api", "csrfToken": ""}', 'no csrfToken'],
        ['a broken plugin entry', '{"apiUrl": "/api", "csrfToken": "abc", "plugins": [{"module": "demo"}]}', 'plugins aren\'t a list'],
    ])('refuses %s', (_name, json, message) => {
        expect(() => AdminConfig.parse(json)).toThrow(message);
    });
});
