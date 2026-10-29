/**
 * "@coconut-cms/admin": the admin's public module, what plugins import.
 * Everything exported here is the plugin surface and is versioned by
 * ADMIN_API_VERSION; everything else in the admin is internal. The admin
 * page's import map resolves this name to the running app's own copy, so a
 * plugin's build marks it external and never bundles it.
 */
import {AdminRuntime} from './AdminRuntime.ts';

export {AdminRuntime} from './AdminRuntime.ts';
export {ApiClient, type Fetcher} from './api/ApiClient.ts';
export {ApiFailure} from './api/ApiFailure.ts';
export {ErrorCode, isErrorCode} from './api/ErrorCode.ts';
export type {OperationName} from './api/OperationName.ts';
export type {Me} from './auth/Me.ts';
export type {User} from './auth/User.ts';

/** The public module's version: raised on any change a plugin could notice. */
export const ADMIN_API_VERSION = 1;

/** The running admin. */
export const admin = new AdminRuntime();
