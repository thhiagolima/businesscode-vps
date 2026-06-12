// Backward compatibility — import from specific stores instead.
// These focused stores replace the old monolithic admin store.
export { useInfobipStore } from './adminInfobip'
export type { InfobipForm, InfobipSettings } from './adminInfobip'

export { useAiSettingsStore } from './adminAi'
export type { AiForm, AiSettings, AiTestResponse } from './adminAi'

export { useElevenLabsStore } from './adminElevenLabs'
export type { ElevenLabsForm, ElevenLabsSettings, ElevenLabsVoice } from './adminElevenLabs'

export { useTenantsStore } from './adminTenants'
export type { Plan, Tenant, TenantChannelInfo, TenantFilters, TenantsListResponse, TenantPaginationMeta } from './adminTenants'

// Legacy: re-export the combined store for existing consumers
export { useAdminStore } from './adminLegacy'
