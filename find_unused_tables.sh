#!/bin/bash
TABLES=(
audit_logs companies city_ledger bookings finance_transactions folio_ledger guests room_categories rooms sliding_rates staff_users system_settings wa_automation_events wa_automations wa_conversations wa_messages wa_templates wa_delivery_logs idempotency_keys sequence_counters housekeeping_checklist_items housekeeping_logs housekeeping_log_items properties team_invitations saas_feature_flags booking_notes night_audit_log room_maintenance inventory_items pos_orders pos_order_items pos_outlets staff_properties login_attempts payment_gateway_configs saas_subscriptions jobs_queue pos_inventory
)

for table in "${TABLES[@]}"; do
    count=$(grep -r -i "$table" public_html/ pms_core/ scripts/ | wc -l)
    if [ "$count" -eq 0 ]; then
        echo "UNUSED: $table"
    else
        echo "USED: $table ($count occurrences)"
    fi
done
