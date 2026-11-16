<?php

//English email templates: upstream's text (core/email_templates/app_defaults.php at the pinned commit) and ours.
//Generated; app_defaults.php replaces a template only while it still has upstream's exact subject and body.
return [
	'fbd0c8ea-6adb-4f8b-92cf-00e9087e3568' => [
		'category' => 'voicemail',
		'subcategory' => 'default',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Voicemail from ${caller_id_name} <${caller_id_number}> ${message_duration}',
		'old_body' => '<html>
<body>
Voicemail from ${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a><br />
<br />
To ${voicemail_name_formatted}<br />
Received ${message_date}<br />
Length ${message_duration}<br />
Message ${message}<br />
</body>
</html>
',
		'subject' => 'New voicemail from ${caller_id_name} (${caller_id_number}), ${message_duration}',
		'body' => '<html>
<body>
<p>You have a new voicemail.</p>
<table cellpadding="4" cellspacing="0" border="0">
<tr><td valign="top"><strong>From</strong></td><td>${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a></td></tr>
<tr><td valign="top"><strong>Mailbox</strong></td><td>${voicemail_name_formatted}</td></tr>
<tr><td valign="top"><strong>Received</strong></td><td>${message_date}</td></tr>
<tr><td valign="top"><strong>Length</strong></td><td>${message_duration}</td></tr>
<tr><td valign="top"><strong>Message</strong></td><td>${message}</td></tr>
</table>
</body>
</html>
',
	],
	'56bb3416-53fc-4a3d-936d-9e3ba869081d' => [
		'category' => 'voicemail',
		'subcategory' => 'default',
		'type' => 'text',
		'description' => '',
		'old_subject' => 'Voicemail from ${caller_id_name} <${caller_id_number}> ${message_duration}',
		'old_body' => 'Voicemail from ${caller_id_name} <${caller_id_number}>

To ${voicemail_name_formatted}
Received ${message_date}
Length ${message_duration}
Message ${message}
',
		'subject' => 'New voicemail from ${caller_id_name} (${caller_id_number}), ${message_duration}',
		'body' => 'You have a new voicemail.

From:     ${caller_id_name} (${caller_id_number})
Mailbox:  ${voicemail_name_formatted}
Received: ${message_date}
Length:   ${message_duration}
Message:  ${message}
',
	],
	'233135c9-7e3e-48d6-b6ad-ba1a383c0ac4' => [
		'category' => 'voicemail',
		'subcategory' => 'transcription',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Voicemail from ${caller_id_name} <${caller_id_number}> ${message_duration}',
		'old_body' => '<html>
<body>
Voicemail from ${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a><br />
<br />
To ${voicemail_name_formatted}<br />
Received ${message_date}<br />
Length ${message_duration}<br />
Message ${message}<br />
<br />
Transcription<br />
${message_text}
</body>
</html>
',
		'subject' => 'New voicemail from ${caller_id_name} (${caller_id_number}), ${message_duration}',
		'body' => '<html>
<body>
<p>You have a new voicemail.</p>
<table cellpadding="4" cellspacing="0" border="0">
<tr><td valign="top"><strong>From</strong></td><td>${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a></td></tr>
<tr><td valign="top"><strong>Mailbox</strong></td><td>${voicemail_name_formatted}</td></tr>
<tr><td valign="top"><strong>Received</strong></td><td>${message_date}</td></tr>
<tr><td valign="top"><strong>Length</strong></td><td>${message_duration}</td></tr>
<tr><td valign="top"><strong>Message</strong></td><td>${message}</td></tr>
</table>
<p><strong>Transcription</strong><br />
${message_text}</p>
</body>
</html>
',
	],
	'c8f14f37-4998-41a2-9c7b-7e810c77c570' => [
		'category' => 'voicemail',
		'subcategory' => 'transcription',
		'type' => 'text',
		'description' => '',
		'old_subject' => 'Voicemail from ${caller_id_name} <${caller_id_number}> ${message_duration}',
		'old_body' => 'Voicemail from ${caller_id_name} <${caller_id_number}>

To ${voicemail_name_formatted}
Received ${message_date}
Length ${message_duration}
Message ${message}

Transcription
${message_text}
',
		'subject' => 'New voicemail from ${caller_id_name} (${caller_id_number}), ${message_duration}',
		'body' => 'You have a new voicemail.

From:     ${caller_id_name} (${caller_id_number})
Mailbox:  ${voicemail_name_formatted}
Received: ${message_date}
Length:   ${message_duration}
Message:  ${message}

Transcription:
${message_text}
',
	],
	'133860ce-175f-4a6f-bfa3-ef7322e80b98' => [
		'category' => 'missed',
		'subcategory' => 'default',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Missed Call from ${caller_id_name} <${caller_id_number}>',
		'old_body' => '<html>
<body>
Missed Call from ${caller_id_name} &lt;<a href="tel:${caller_id_number}">${caller_id_number}</a>&gt; to ${sip_to_user} ext ${dialed_user}
</body>
</html>
',
		'subject' => 'Missed call from ${caller_id_name} (${caller_id_number})',
		'body' => '<html>
<body>
<p>You missed a call.</p>
<table cellpadding="4" cellspacing="0" border="0">
<tr><td valign="top"><strong>From</strong></td><td>${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a></td></tr>
<tr><td valign="top"><strong>To</strong></td><td>${sip_to_user} (extension ${dialed_user})</td></tr>
</table>
</body>
</html>
',
	],
	'890626c4-907b-44ad-9cf6-02d0b0a2379d' => [
		'category' => 'missed',
		'subcategory' => 'default',
		'type' => 'text',
		'description' => '',
		'old_subject' => 'Missed Call from ${caller_id_name} <${caller_id_number}>',
		'old_body' => 'Missed Call from ${caller_id_name} &lt;${caller_id_number}&gt; to ${sip_to_user} ext ${dialed_user}
',
		'subject' => 'Missed call from ${caller_id_name} (${caller_id_number})',
		'body' => 'You missed a call.

From: ${caller_id_name} (${caller_id_number})
To:   ${sip_to_user} (extension ${dialed_user})
',
	],
	'b1eefbfc-c008-4c82-b93f-6a6df237aeaa' => [
		'category' => 'fax',
		'subcategory' => 'success_default',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Subject, Fax to: ${number_dialed} SENT',
		'old_body' => '<html>
<body>
We are happy to report the fax was sent successfully. It has been attached for your records.
</body>
</html>
',
		'subject' => 'Fax to ${number_dialed} sent',
		'body' => '<html>
<body>
<p>Your fax to ${number_dialed} was sent successfully. A copy is attached for your records.</p>
</body>
</html>
',
	],
	'48ce4fef-e6bd-4be6-9c76-1590e8498408' => [
		'category' => 'fax',
		'subcategory' => 'fail_default',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Fax to: ${number_dialed} has Failed',
		'old_body' => '<html>
<body>
We are sorry the fax failed to go through. It has been attached. Please check the number ${number_dialed}, and if it was correct you might consider emailing it instead.
</body>
</html>
',
		'subject' => 'Fax to ${number_dialed} failed',
		'body' => '<html>
<body>
<p>Your fax to ${number_dialed} could not be delivered. A copy is attached.</p>
<p>Please check the number and try again. If the problem continues, consider sending the document by email instead.</p>
</body>
</html>
',
	],
	'd64d9a03-affd-4dbf-ba04-5ad5accae4d9' => [
		'category' => 'fax',
		'subcategory' => 'fail_busy',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Fax to: ${number_dialed} was Busy',
		'old_body' => '<html>
<body>
We tried sending, but the call was busy ${fax_busy_attempts} of those times.
</body>
</html>
',
		'subject' => 'Fax to ${number_dialed} not sent: line busy',
		'body' => '<html>
<body>
<p>Your fax to ${number_dialed} could not be sent because the receiving line was busy (${fax_busy_attempts} attempts).</p>
<p>Please try again later.</p>
</body>
</html>
',
	],
	'29729743-28bc-4f7b-88e1-0bdf6ff33cce' => [
		'category' => 'fax',
		'subcategory' => 'fail_invalid',
		'type' => 'html',
		'description' => '',
		'old_subject' => 'Fax to: ${number_dialed} was Invalid',
		'old_body' => '<html>
<body>
We tried sending, but the number entered was not a working phone number.
</body>
</html>
',
		'subject' => 'Fax to ${number_dialed} not sent: invalid number',
		'body' => '<html>
<body>
<p>Your fax could not be sent because ${number_dialed} is not a working fax number.</p>
<p>Please check the number and try again.</p>
</body>
</html>
',
	],
	'8928e8d4-78ff-11ee-ac06-005056a27559' => [
		'category' => 'plugin',
		'subcategory' => 'emergency',
		'type' => 'html',
		'description' => '',
		'old_subject' => '${event}',
		'old_body' => '<html>
<body>
<strong>From:</strong> ${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a><br />
<br />
<strong>Emergency Name:</strong> ${emergency_caller_id_name}<br />
<strong>Emergency Number:</strong> ${emergency_caller_id_number}<br />
<strong>Received:</strong> ${message_date}<br />
<strong>Event:</strong> ${event}<br />
</body>
</html>
',
		'subject' => '${event}',
		'body' => '<html>
<body>
<p>A phone on your system dialed an emergency service number.</p>
<table cellpadding="4" cellspacing="0" border="0">
<tr><td valign="top"><strong>Event</strong></td><td>${event}</td></tr>
<tr><td valign="top"><strong>From</strong></td><td>${caller_id_name} <a href="tel:${caller_id_number}">${caller_id_number}</a></td></tr>
<tr><td valign="top"><strong>Emergency caller ID name</strong></td><td>${emergency_caller_id_name}</td></tr>
<tr><td valign="top"><strong>Emergency caller ID number</strong></td><td>${emergency_caller_id_number}</td></tr>
<tr><td valign="top"><strong>Time</strong></td><td>${message_date}</td></tr>
</table>
</body>
</html>
',
	],
	'54616982-68ae-4e55-99f4-e45ffa68e379' => [
		'category' => 'email',
		'subcategory' => 'test',
		'type' => 'html',
		'description' => 'Message sent by the Email Test feature found at the top of the Email Queue app.',
		'old_subject' => 'Test Message',
		'old_body' => '<html>
<body>
<strong>Test Message</strong><br />
<br />
This message is a test of the SMTP settings configured within your PBX.<br />
If you received this message, your current SMTP settings are valid.<br /><br />
</body>
</html>
',
		'subject' => 'Test message from your phone system',
		'body' => '<html>
<body>
<p>This is a test message sent with the SMTP settings configured on your phone system.</p>
<p>If you received it, those settings are working.</p>
</body>
</html>
',
	],
];

?>
