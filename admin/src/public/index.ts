/**
 * "@coconut-cms/admin": the admin's public module, what plugins import.
 * Everything exported here is the plugin surface and is versioned by
 * ADMIN_API_VERSION; everything else in the admin is internal. The admin
 * page's import map resolves this name to the running app's own copy, so a
 * plugin's build marks it external and never bundles it.
 */
import {AdminRuntime} from './AdminRuntime.ts';

export type {AdminPlugin, AdminPluginClass} from './AdminPlugin.ts';
export {AdminRuntime} from './AdminRuntime.ts';
export {ApiClient, type Fetcher} from './api/ApiClient.ts';
export {ApiFailure} from './api/ApiFailure.ts';
export {ErrorCode, isErrorCode} from './api/ErrorCode.ts';
export type {OperationName} from './api/OperationName.ts';
export type {Me} from './auth/Me.ts';
export type {User} from './auth/User.ts';
export type {EntityChange, EntityListener} from './entities/EntityChange.ts';
export type {EntityData} from './entities/EntityData.ts';
export {EntityHold} from './entities/EntityHold.ts';
export type {ConditionOperator, EntityCondition, EntityPage, EntityQuery, EntitySort} from './entities/EntityQuery.ts';
export {EntitySession, type SessionState} from './entities/EntitySession.ts';
export {EntityStore} from './entities/EntityStore.ts';
export type {SessionChange, SessionListener} from './entities/SessionChange.ts';
export type {UnsavedAnswer, UnsavedQuestion} from './entities/UnsavedQuestion.ts';
export type {CollectionItems} from './properties/CollectionItems.ts';
export {EntityEditor} from './properties/EntityEditor.ts';
export {EntityLabels} from './properties/EntityLabels.ts';
export type {PropertyContext} from './properties/PropertyContext.ts';
export type {PropertyEditor, PropertyEditorClass} from './properties/PropertyEditor.ts';
export type {PropertyInfo} from './properties/PropertyInfo.ts';
export {PropertyEditors} from './properties/PropertyEditors.ts';
export type {PropertyMatch} from './properties/PropertyMatch.ts';
export {PropertyPath} from './properties/PropertyPath.ts';
export type {PropertySlot} from './properties/PropertySlot.ts';
export type {PropertyStatus} from './properties/PropertyStatus.ts';
export {isObjectValue, isReferenceValue, type ObjectValue, type ReferenceValue} from './properties/RecordValues.ts';
export {TypeCatalog, type CatalogField} from './types/TypeCatalog.ts';
export type {FieldDescription, TypeDescription, TypeList, ValueDescription, ValueKind} from './types/TypeDescription.ts';
export {tabId, tabTypeId, toolId, type TabId, type TabTypeId, type ToolId} from './workspace/Ids.ts';
export type {JsonValue} from './workspace/Json.ts';
export type {PanelView, PanelViewClass} from './workspace/PanelView.ts';
export {SECTIONS, SIDES, type Placement, type Section, type Side} from './workspace/Placement.ts';
export {Registry} from './workspace/Registry.ts';
export type {TabOpening} from './workspace/TabOpening.ts';
export type {TabTypeDefinition} from './workspace/TabTypeDefinition.ts';
export type {ToolDefinition} from './workspace/ToolDefinition.ts';
export type {ViewContext} from './workspace/ViewContext.ts';
export type {ViewState} from './workspace/ViewState.ts';
export type {Workspace} from './workspace/Workspace.ts';

/** The public module's version: raised on any change a plugin could notice. */
export const ADMIN_API_VERSION = 1;

/** The running admin. */
export const admin = new AdminRuntime();
