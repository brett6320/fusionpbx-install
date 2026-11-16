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

$text['description-schedule']['en-us'] = "Route calls to this IVR menu only between the start and end below. Outside that window calls go to the fallback destination. The schedule gets its own extension: send calls to it, not to the IVR menu, for the schedule to apply.";

$text['label-schedule_name']['en-us'] = "Name";

$text['label-schedule_number']['en-us'] = "Schedule Extension";

$text['description-schedule_number']['en-us'] = "A new, unused extension number for this schedule (digits only), suggested from the time condition range. This is not a destination: it is the number calls are sent to. In Dialplan > Inbound Routes, set the destination to this schedule (listed under Time Conditions) so calls follow it.";

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

$text['header-select_greeting']['en-us'] = "Choose a Recording or Phrase";

$text['description-select_greeting']['en-us'] = "Play an existing recording or phrase of this domain.";

$text['label-greeting']['en-us'] = "Greeting";

$text['label-recordings']['en-us'] = "Recordings";

$text['label-phrases']['en-us'] = "Phrases";

$text['label-phrase']['en-us'] = "Phrase";

$text['label-disabled']['en-us'] = "disabled";

$text['button-set_greeting']['en-us'] = "Set Greeting";

$text['message-greeting_saved']['en-us'] = "Greeting saved.";

$text['message-greeting_invalid']['en-us'] = "Choose a recording or phrase of this domain.";

$text['header-new_phrase']['en-us'] = "New Phrase (Google Text-to-Speech)";

$text['description-new_phrase']['en-us'] = "Each text part is spoken by Google Text-to-Speech and saved as a recording; pauses go between them. The phrase can be reused in other IVR menus and in Phrases.";

$text['label-phrase_name']['en-us'] = "Phrase Name";

$text['label-parts']['en-us'] = "Parts";

$text['label-part_text']['en-us'] = "Text";

$text['label-part_pause']['en-us'] = "Pause (seconds)";

$text['label-target_none']['en-us'] = "Do not set as a greeting";

$text['button-add_text']['en-us'] = "Add Text";

$text['button-add_pause']['en-us'] = "Add Pause";

$text['button-create_phrase']['en-us'] = "Create Phrase";

$text['description-phrase_tts_setup']['en-us'] = "Google Text-to-Speech is not set up. Place a service account key file at /etc/fusionpbx/google-tts.json (readable by www-data), or set x_ivr_manager > google_tts_api_key in Default Settings.";

$text['message-phrase_saved']['en-us'] = "Phrase created.";

$text['message-phrase_app_missing']['en-us'] = "The Phrases app is not installed.";

$text['message-phrase_tts_disabled']['en-us'] = "Google Text-to-Speech is not set up.";

$text['message-phrase_tts_rejected']['en-us'] = "Google rejected the request. Check the credentials and that the Text-to-Speech API is enabled for the project.";

$text['message-phrase_tts_failed']['en-us'] = "Google Text-to-Speech failed. Try again later.";

$text['message-phrase_name_required']['en-us'] = "A phrase name is required.";

$text['message-phrase_voice_invalid']['en-us'] = "Choose a valid voice.";

$text['message-phrase_text_invalid']['en-us'] = "The text of a phrase can be at most 5000 bytes in total.";

$text['message-phrase_text_required']['en-us'] = "Add at least one text part.";

$text['message-phrase_part_invalid']['en-us'] = "Pauses must be between 0.1 and 10 seconds.";

$text['message-phrase_too_many_parts']['en-us'] = "A phrase can have at most 20 parts.";

$text['message-phrase_save_failed']['en-us'] = "The phrase could not be saved.";

$text['message-phrase_greeting_failed']['en-us'] = "The phrase was created, but the greeting could not be set.";

$text['button-remove_part']['en-us'] = "Remove";

$text['message-phrase_disabled']['en-us'] = "This phrase is disabled. Enable it in Phrases first.";

$text['message-phrase_language_mismatch']['en-us'] = "This phrase is in a different language from the IVR menu, so callers would hear nothing.";

$text['label-schedule_routes']['en-us'] = "Inbound routes using this schedule:";

$text['label-schedule_routes_none']['en-us'] = "none yet. Calls reach the IVR menu by schedule only through this extension.";

$text['message-schedule_number_in_use']['en-us'] = "Extension %s is already used by %s. Choose an unused number for the schedule.";

$text['button-play']['en-us'] = "Play";

$text['button-copy_text']['en-us'] = "Copy Text";

$text['button-copied']['en-us'] = "Copied";

$text['description-preview_incomplete']['en-us'] = "Parts of this greeting cannot be played here (missing recordings or actions other than recordings and pauses).";

$text['placeholder-greeting_name']['en-us'] = "Leave empty to name it after the menu, the greeting and the time";

$text['button-remove_greeting']['en-us'] = "Remove from Menu";

$text['button-delete_greeting']['en-us'] = "Delete";

$text['confirm-greeting_remove']['en-us'] = "Remove the greeting \"%s\" from this menu? The recording or phrase itself is kept.";

$text['confirm-recording_delete']['en-us'] = "Permanently delete the recording \"%s\" and its audio file? This cannot be undone.";

$text['confirm-phrase_delete']['en-us'] = "Permanently delete the phrase \"%s\"? Recordings created for it are deleted too when you may delete recordings and nothing else uses them. This cannot be undone.";

$text['message-greeting_removed']['en-us'] = "Greeting removed from the menu.";

$text['message-greeting_deleted']['en-us'] = "Greeting deleted.";

$text['message-greeting_in_use']['en-us'] = "Not deleted, still in use by: %s. Remove it there first.";

$text['message-greeting_delete_failed']['en-us'] = "The greeting could not be deleted.";

$text['title-ivr_manager_add']['en-us'] = "Add IVR Menu";

$text['description-ivr_manager_add']['en-us'] = "Create an IVR menu, then set its greetings, options and schedule in IVR Manager.";

$text['description-ivr_menu_name']['en-us'] = "The name of the IVR menu, shown in destination lists.";

$text['description-ivr_menu_extension']['en-us'] = "A random, unused 22 digit number is filled in that no outbound route matches. Nobody needs to dial it: send calls to the menu from Inbound Routes, a schedule or another menu. Any other unused number (digits only) works as well.";

$text['message-ivr_created']['en-us'] = "IVR menu created. Set its greeting and options below.";

$text['message-ivr_name_invalid']['en-us'] = "Enter a name for the IVR menu (up to 255 characters).";

$text['message-ivr_extension_invalid']['en-us'] = "The extension must contain digits only (up to 32).";

$text['message-ivr_description_invalid']['en-us'] = "The description can be up to 255 characters.";

$text['message-ivr_extension_in_use']['en-us'] = "Extension %s is already used by %s. Choose an unused number.";

$text['message-ivr_extension_routable']['en-us'] = "Extension %s matches the outbound route %s, so calls to the menu could go out to a carrier while it is disabled. Choose another number.";

$text['message-ivr_limit']['en-us'] = "The maximum number of IVR menus for this domain has been reached.";

$text['message-ivr_create_failed']['en-us'] = "The IVR menu could not be created.";

$text['message-ivr_extension_generate_failed']['en-us'] = "No number could be generated, enter one.";

?>
