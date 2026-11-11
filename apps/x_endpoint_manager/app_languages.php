<?php

$text['title-x_endpoint_manager']['en-us'] = "Endpoint Manager";

$text['title-x_endpoint_reboot_schedules']['en-us'] = "Reboot Schedules";

$text['title-x_endpoint_reboot_schedule']['en-us'] = "Reboot Schedule";

$text['title-x_endpoint_reboot_logs']['en-us'] = "Reboot Log";

$text['description-x_endpoint_manager']['en-us'] = "Reboot the devices of this domain on demand. Devices with a line on an active call are skipped, and reboots above the per minute rate limit are queued and sent by the worker.";

$text['description-x_endpoint_reboot_schedules']['en-us'] = "Scheduled reboots for the devices of this domain. Times are in the domain time zone";

$text['description-x_endpoint_reboot_schedule']['en-us'] = "Reboot all devices, the devices of a vendor or model, or selected devices once or on a recurring schedule. Times are in the domain time zone";

$text['description-x_endpoint_reboot_logs']['en-us'] = "History of reboots requested on demand or by a schedule, with the result of each. Times are in the domain time zone";

$text['button-reboot']['en-us'] = "Reboot";

$text['button-schedules']['en-us'] = "Schedules";

$text['button-log']['en-us'] = "Log";

$text['confirm-reboot']['en-us'] = "Reboot the selected devices now? Devices on an active call will be skipped.";

$text['label-device']['en-us'] = "Device";

$text['label-device_address']['en-us'] = "MAC Address";

$text['label-device_label']['en-us'] = "Label";

$text['label-device_vendor']['en-us'] = "Vendor";

$text['label-device_model']['en-us'] = "Model";

$text['label-lines']['en-us'] = "Lines";

$text['label-registered']['en-us'] = "Registered";

$text['label-on_call']['en-us'] = "On Call";

$text['label-last_reboot']['en-us'] = "Last Reboot";

$text['label-queued_reboots']['en-us'] = "Queued reboots";

$text['label-schedule_name']['en-us'] = "Name";

$text['description-schedule_name']['en-us'] = "Enter a name for the schedule.";

$text['label-target']['en-us'] = "Target";

$text['description-target']['en-us'] = "Select which devices to reboot. Only enabled devices are rebooted by a schedule.";

$text['label-target_vendor']['en-us'] = "Vendor";

$text['description-target_vendor']['en-us'] = "Select the vendor and optionally enter a model.";

$text['label-target_model']['en-us'] = "Model (optional)";

$text['label-target_devices']['en-us'] = "Devices";

$text['description-target_devices']['en-us'] = "Select one or more devices (hold Ctrl or Cmd to select several).";

$text['label-recurrence']['en-us'] = "Recurrence";

$text['description-recurrence']['en-us'] = "Run once, every day or on selected days of the week.";

$text['label-run_date']['en-us'] = "Date";

$text['description-run_date']['en-us'] = "Date of a one-time reboot.";

$text['label-run_days']['en-us'] = "Days";

$text['description-run_days']['en-us'] = "Days of the week for a weekly reboot.";

$text['label-run_time']['en-us'] = "Time";

$text['description-run_time']['en-us'] = "Time of day in the domain time zone.";

$text['label-next_run']['en-us'] = "Next Run";

$text['label-last_run']['en-us'] = "Last Run";

$text['label-schedule_status']['en-us'] = "Status";

$text['label-monday']['en-us'] = "Monday";

$text['label-tuesday']['en-us'] = "Tuesday";

$text['label-wednesday']['en-us'] = "Wednesday";

$text['label-thursday']['en-us'] = "Thursday";

$text['label-friday']['en-us'] = "Friday";

$text['label-saturday']['en-us'] = "Saturday";

$text['label-sunday']['en-us'] = "Sunday";

$text['label-request_date']['en-us'] = "Requested";

$text['label-process_date']['en-us'] = "Processed";

$text['label-sip_user']['en-us'] = "Registration";

$text['label-trigger']['en-us'] = "Trigger";

$text['label-requested_by']['en-us'] = "Requested By";

$text['label-result']['en-us'] = "Result";

$text['label-detail']['en-us'] = "Detail";

$text['option-target_all']['en-us'] = "All devices";

$text['option-target_vendor']['en-us'] = "Vendor / model";

$text['option-target_devices']['en-us'] = "Selected devices";

$text['option-recurrence_once']['en-us'] = "Once";

$text['option-recurrence_daily']['en-us'] = "Daily";

$text['option-recurrence_weekly']['en-us'] = "Weekly";

$text['option-trigger_manual']['en-us'] = "On demand";

$text['option-trigger_schedule']['en-us'] = "Schedule";

$text['option-status_active']['en-us'] = "Active";

$text['option-status_completed']['en-us'] = "Completed";

$text['option-status_pending']['en-us'] = "Queued";

$text['option-status_processing']['en-us'] = "Processing";

$text['option-status_sent']['en-us'] = "Sent";

$text['option-status_failed']['en-us'] = "Failed";

$text['option-status_skipped_busy']['en-us'] = "Skipped (on a call)";

$text['option-status_skipped_unregistered']['en-us'] = "Skipped (not registered)";

$text['option-status_skipped_unsupported']['en-us'] = "Skipped (unsupported vendor)";

$text['option-status_expired']['en-us'] = "Expired";

$text['option-status_missed']['en-us'] = "Missed";

$text['message-reboot_sent']['en-us'] = "Sent";

$text['message-reboot_skipped_busy']['en-us'] = "Skipped (on a call)";

$text['message-reboot_skipped_other']['en-us'] = "Skipped (not registered or unsupported)";

$text['message-reboot_failed']['en-us'] = "Failed";

$text['message-reboot_queued']['en-us'] = "Queued (rate limit)";

$text['message-reboot_already_queued']['en-us'] = "Already queued";

$text['message-switch_unavailable']['en-us'] = "The switch is not reachable. Queued reboots will be sent when it is available.";

$text['message-schedule_not_found']['en-us'] = "Schedule not found.";

$text['message-invalid_time']['en-us'] = "Enter a valid time (HH:MM).";

$text['message-invalid_date']['en-us'] = "Enter a valid date.";

$text['message-date_in_past']['en-us'] = "The date and time must be in the future.";

?>
