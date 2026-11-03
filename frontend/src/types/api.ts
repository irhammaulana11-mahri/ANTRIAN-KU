export type Role = string

export interface User {
  id: string
  name: string
  email: string
  branch_id: string | null
  unit_id?: string | null
  branch?: { id: string; branch_code: string; branch_name: string } | null
  unit?: { id: string; unit_code: string; unit_name: string } | null
  roles?: Role[]
  permissions?: string[]
  employee?: { id: string } | null
}

export interface Branch { id: string; branch_code: string; branch_name: string; address?: string | null; phone?: string | null; is_active: boolean; employees_count?: number; counters_count?: number; service_types_count?: number; units_count?: number }
export interface Unit { id: string; branch_id: string; unit_code: string; unit_name: string; address?: string | null; phone?: string | null; is_active: boolean; employees_count?: number; counters_count?: number; deleted_at?: string | null }
export interface Employee { user?: { email?: string; roles?: string[] } | null; id: string; branch_id: string; unit_id?: string | null; unit?: { id: string; unit_code: string; unit_name: string } | null; employee_code: string; employee_name: string; user_id?: string | null; is_active: boolean; service_types?: ServiceType[]; counters?: Counter[] }
export interface Counter { id: string; branch_id: string; unit_id?: string | null; unit?: { id: string; unit_code: string; unit_name: string } | null; counter_code: string; counter_name: string; counter_type: string; is_active: boolean }
export interface ServiceType { id: string; branch_id: string; service_name: string; queue_prefix: string; priority_enabled: boolean; is_active: boolean }
export interface Queue { id: string; branch_id: string; service_type_id: string; service_name?: string; priority_type_id?: string | null; priority_name?: string | null; queue_date: string; sequence_no: number; queue_number: string; customer_name: string; customer_phone?: string; registration_channel: 'WALK_IN' | 'ONLINE'; status: QueueStatus; created_at: string; called_at?: string | null; completed_at?: string | null }
export type QueueStatus = 'WAITING' | 'ASSIGNED' | 'CALLED' | 'SERVING' | 'COMPLETED' | 'SKIPPED' | 'CANCELLED' | 'NO_SHOW'
export interface QueueAssignment { id: string; branch_id: string; queue_id: string; employee_id: string; counter_id: string; assignment_type: 'AUTO' | 'MANUAL' | 'TRANSFER'; assigned_at: string; queue?: Queue; employee?: Employee; counter?: Counter }
export interface Paginated<T> { data: T[]; meta?: { current_page: number; last_page: number; per_page: number; total: number } }
export interface ApiEnvelope<T> { data: T; message?: string; meta?: Paginated<T>['meta'] }

export type EmployeeStatus = 'AVAILABLE' | 'BUSY' | 'BREAK' | 'OFFLINE' | 'CLOSED'
export interface WorkspaceActive {
  queue_id: string; queue_number: string; customer_name: string; service_name?: string | null; service_notes?: string | null; priority_name?: string | null
  status: 'ASSIGNED' | 'CALLED' | 'SERVING'
  counter: { id: string; counter_code?: string | null; counter_name?: string | null }
  registered_at: string; assigned_at?: string | null; called_at?: string | null; service_started_at?: string | null
}
export interface Workspace {
  server_time: string
  employee: { id: string; employee_code: string; employee_name: string; service_types: { id: string; service_name: string; queue_prefix: string }[]; counters: { id: string; counter_code: string; counter_name: string }[] }
  status: EmployeeStatus
  is_available: boolean
  heartbeat_at: string | null
  ready: boolean
  active: WorkspaceActive | null
  waiting_count: number
  next: { queue_id: string; queue_number: string; customer_name: string; service_name?: string | null }[]
}

export interface BranchAvailability {
  is_open: boolean; reason: string | null; message: string | null; opens_at: string | null; closes_at: string | null; holiday_name: string | null
  online_available: boolean; online_reason: string | null; online_message: string | null
  online_quota: { limit: number | null; used: number; remaining: number | null }; waiting_count: number
}
export interface PublicBranch { id: string; branch_code: string; branch_name: string; address?: string | null; phone?: string | null; availability: BranchAvailability }
export interface QueueTracking {
  queue_number: string; branch_name: string | null; service_name: string | null; status: QueueStatus; queue_date: string; customer_name: string
  registration_channel: 'WALK_IN' | 'ONLINE'; created_at: string; called_at: string | null
  position: number | null; ahead: number | null; estimated_wait_seconds: number | null
  counter: { counter_code: string; counter_name: string } | null; can_cancel: boolean
}
export interface PriorityOption { id: string; priority_code: string; priority_name: string; weight: number }

export interface BoardQueue {
  id: string; queue_number: string; customer_name: string; service_type_id: string; service_name: string | null
  priority: { id: string; code: string; name: string } | null; priority_verified: boolean
  status: QueueStatus; registration_channel: 'WALK_IN' | 'ONLINE'; registered_at: string; called_at: string | null
  employee: { id: string; name: string } | null; counter: { id: string; code: string; name: string } | null
}
export interface BoardEmployee { id: string; employee_code: string; employee_name: string; status: EmployeeStatus; is_available: boolean; service_type_ids: string[]; counter_ids: string[]; active_queue_number: string | null }
export interface BoardCounter { id: string; counter_code: string; counter_name: string; counter_type: string; in_use_by: string | null }
export interface SupervisorBoard {
  server_time: string; queues: BoardQueue[]; employees: BoardEmployee[]; counters: BoardCounter[]
  services: { id: string; service_name: string; queue_prefix: string }[]
  priorities: { id: string; priority_code: string; priority_name: string; weight: number }[]
}

export interface DisplayMediaSource { type: 'STREAM' | 'YOUTUBE'; label: string | null; url: string; youtube_kind: 'video' | 'playlist' | null; youtube_id: string | null }
