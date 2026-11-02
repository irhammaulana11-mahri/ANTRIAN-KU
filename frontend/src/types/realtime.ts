export interface RealtimeEvent {
  event_id: string
  occurred_at: string
  branch_id: string
  queue_id?: string
  queue_number?: string
  customer_name?: string
  service_type_id?: string
  status?: string
  employee_id?: string
  counter_id?: string
  counter_code?: string | null
  counter_name?: string | null
  service_name?: string | null
  called_at?: string | null
  event_name?: string
}
