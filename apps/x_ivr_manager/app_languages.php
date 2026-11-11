<?php

$text['title-ivr_manager']['en-us'] = "IVR Manager";

$text['title-ivr_manager_edit']['en-us'] = "IVR Manager";

$text['description-ivr_manager']['en-us'] = "Arrange IVR menu options, record or generate greetings, and schedule when an IVR menu is active.";

$text['label-greet_long']['en-us'] = "Greet Long";

$text['label-greet_short']['en-us'] = "Greet Short";

$text['label-schedule']['en-us'] = "Schedule";

$text['label-options']['en-us'] = "Options";

$text['label-not_set']['en-us'] = "Not set";

$text['label-schedule_none']['en-us'] = "None";

$text['label-schedule_active']['en-us'] = "Active";

$text['label-schedule_pending']['en-us'] = "Starts later";

$text['label-schedule_expired']['en-us'] = "Ended";

$text['message-ivr_not_found']['en-us'] = "IVR menu not found.";

$text['button-open_ivr_menu']['en-us'] = "IVR Menu";

$text['button-open_time_condition']['en-us'] = "Time Condition";

$text['header-options']['en-us'] = "Option Order";

$text['description-options']['en-us'] = "Drag the options into the order you want, then save. The numeric order is updated automatically. The stock IVR Menus editor lists options by digits, so this order only shows here and in the order numbers.";

$text['label-options_none']['en-us'] = "This IVR menu has no options yet. Add options in IVR Menus.";

$text['label-digits']['en-us'] = "Digits";

$text['label-destination']['en-us'] = "Destination";

$text['button-save_order']['en-us'] = "Save Order";

$text['message-order_saved']['en-us'] = "Option order saved.";

$text['message-order_invalid']['en-us'] = "The option list changed while you were editing. Reload the page and try again.";

$text['header-greetings']['en-us'] = "Greetings";

$text['description-greetings']['en-us'] = "The greeting plays when a caller reaches the IVR menu. Record one in the browser or generate one from text, it is saved as a recording for this domain and set on the menu.";

$text['label-current']['en-us'] = "Current";

$text['label-target']['en-us'] = "Use As";

$text['label-recording_name']['en-us'] = "Recording Name";

$text['header-record']['en-us'] = "Record in the Browser";

$text['description-record']['en-us'] = "Click Record, speak, then Stop. Listen to the preview and click Save to use it.";

$text['button-record']['en-us'] = "Record";

$text['button-stop']['en-us'] = "Stop";

$text['button-save_greeting']['en-us'] = "Save Greeting";

$text['label-recording_unsupported']['en-us'] = "Browser recording needs a secure (HTTPS) connection and a browser that supports audio recording.";

$text['label-recording_in_progress']['en-us'] = "Recording...";

$text['label-recording_ready']['en-us'] = "Recording ready. Listen to the preview, then save.";

$text['label-uploading']['en-us'] = "Saving...";

$text['label-microphone_denied']['en-us'] = "Microphone access was denied.";

$text['message-recording_saved']['en-us'] = "Recording saved and set as the greeting.";

$text['message-recording_failed']['en-us'] = "The recording could not be saved.";

$text['message-recording_too_large']['en-us'] = "The recording is too large.";

$text['message-recording_unsupported_format']['en-us'] = "The recording format is not supported.";

$text['header-tts']['en-us'] = "Text to Speech";

$text['description-tts']['en-us'] = "Type the greeting and choose a voice. The audio is generated and saved as a recording.";

$text['label-voice']['en-us'] = "Voice";

$text['label-speed']['en-us'] = "Speed";

$text['label-message']['en-us'] = "Message";

$text['button-generate']['en-us'] = "Generate";

$text['message-tts_saved']['en-us'] = "Greeting generated and set on the IVR menu.";

$text['message-tts_failed']['en-us'] = "The greeting could not be generated.";

$text['header-schedule']['en-us'] = "Schedule";

$text['description-schedule']['en-us'] = "Route calls to this IVR menu only between the start and end below. Outside that window calls go to the fallback destination. Point your inbound route at the schedule number.";

$text['label-schedule_name']['en-us'] = "Name";

$text['label-schedule_number']['en-us'] = "Schedule Number";

$text['description-schedule_number']['en-us'] = "Number of the time condition that holds the schedule (digits only). Use it as the destination of your inbound route.";

$text['label-schedule_start']['en-us'] = "Start";

$text['label-schedule_end']['en-us'] = "End";

$text['label-fallback']['en-us'] = "Fallback Destination";

$text['description-fallback']['en-us'] = "Where calls go before the start and after the end.";

$text['description-schedule_time_zone']['en-us'] = "Dates and times are in the domain time zone.";

$text['message-schedule_complex']['en-us'] = "This schedule was changed in Time Conditions and has more than a single start and end. Saving here replaces those conditions.";

$text['button-delete_schedule']['en-us'] = "Remove Schedule";

$text['confirm-delete_schedule']['en-us'] = "Remove the schedule? The time condition is deleted.";

$text['message-schedule_saved']['en-us'] = "Schedule saved.";

$text['message-schedule_deleted']['en-us'] = "Schedule removed.";

$text['message-schedule_name_required']['en-us'] = "A schedule name is required.";

$text['message-schedule_number_invalid']['en-us'] = "The schedule number must contain digits only.";

$text['message-schedule_date_invalid']['en-us'] = "Enter a valid start and end date and time.";

$text['message-schedule_range_invalid']['en-us'] = "The end must be after the start.";

$text['message-schedule_ivr_invalid']['en-us'] = "This IVR menu is not available as a destination. Make sure it is enabled.";

$text['message-schedule_fallback_invalid']['en-us'] = "Choose a valid fallback destination.";

$text['message-schedule_complex_confirm']['en-us'] = "This schedule has conditions added in Time Conditions. Tick the confirmation box to replace them, or edit it in Time Conditions.";

$text['label-confirm_replace']['en-us'] = "Replace the existing conditions";

$text['message-schedule_ivr_disabled']['en-us'] = "Enable this IVR menu before scheduling it.";

?>
