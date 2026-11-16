# Using IVR Manager

IVR Manager (**Applications > IVR Manager**) is a simpler front end to
FusionPBX's IVR menus. From one page per menu you can:

- create a menu,
- put its options in order by dragging them,
- record, generate, play, copy and delete greetings,
- schedule when the menu answers: business hours, after hours, holidays and
  one-off closures.

It works on the same IVR menus, recordings, phrases and time conditions as the
stock FusionPBX pages, so anything made here also shows up there.

## Before you start

**Who can do what.** These permissions are given to the admin and superadmin
groups on install:

| Permission | Allows |
|---|---|
| `x_ivr_manager_view` | Opening IVR Manager and the list of menus |
| `x_ivr_manager_add` | Creating IVR menus |
| `x_ivr_manager_edit` | Ordering options, choosing, removing and deleting greetings |
| `x_ivr_manager_record` | Recording greetings in the browser |
| `x_ivr_manager_tts` | Text to speech greetings with the speech app |
| `x_ivr_manager_phrase` | Building phrases with Google Text-to-Speech |
| `x_ivr_manager_schedule` | Creating, changing and removing schedules |

A few stock permissions matter too:

- **Playing greetings** in the browser needs `recording_play` or
  `recording_download`.
- **Deleting** needs `recording_delete` for a recording, or `phrase_delete`
  for a phrase.
- **The IVR Menu and Time Condition buttons**, which open the stock editors,
  need `ivr_menu_edit` and `time_condition_edit`.

**What you need for each greeting type.**

- **Recording in the browser:** the page must be opened over HTTPS, since
  browsers only allow the microphone on secure pages. Allow the microphone
  when asked.
- **Text to speech:** the upstream speech app, set up in Default Settings
  (`speech > enabled` and `speech > engine`).
- **Phrases:** Google Text-to-Speech credentials. The README section
  [IVR phrases](../README.md#ivr-phrases-google-text-to-speech) explains how
  to set them up.

## How calls reach a menu

```
Inbound Route ──> Schedule (time condition) ──┬─ a rule matches ──> IVR menu
                                              └─ otherwise ───────> Fallback destination
```

- **Without a schedule**, point the inbound route (or another menu, a ring
  group, and so on) straight at the IVR menu.
- **With a schedule**, point the inbound route at the **schedule**, not at the
  menu. The schedule then decides, call by call, whether the menu or the
  fallback answers.

Both the menu and the schedule have an extension number. Nobody has to dial
these numbers; they are what other parts of the system send calls to.

## Create an IVR menu

1. In IVR Manager, click **Add**.
2. Enter a **Name**. It is how the menu shows up in destination lists, e.g.
   "Main Menu" or "After Hours".
3. Keep the **Extension** that is filled in. It's a random, unused 22 digit
   number. You can type your own unused number instead (digits only).
4. Optionally add a **Description**, then **Save**.

The menu opens in IVR Manager. It has no greeting or options yet: until you
set them, a caller hears nothing and the call ends after the menu's timeouts.

> **Why 22 digits?** If a menu is ever disabled, a call sent to its number
> falls through to the outbound routes. The stock "International" route
> matches any 12 to 20 digit number and would send the call to your carrier.
> 22 digits is longer than any stock route matches. IVR Manager also refuses
> any number that one of your outbound routes matches.

The domain's IVR menu limit (`limit > ivr_menus`), if set, applies here too.

## Options

Options (press 1 for sales, and so on) are added in the stock editor: click
**IVR Menu** at the top of the page, add them there, and come back.

In IVR Manager, **Option Order** lists the options. Drag them into the order you
want and click **Save Order**. The order numbers are updated for you.

## Greetings

A menu has two greetings:

- **Greet Long** plays when a caller reaches the menu.
- **Greet Short** plays when the menu repeats, e.g. after an invalid entry.
  If it is empty, the long greeting is used.

For each greeting the page shows:

- what is set now,
- **Play**,
- for text to speech greetings, the text with a **Copy Text** button,
- **Remove from Menu**.

### Use an existing recording or phrase

Under **Choose a Recording or Phrase**, pick one of the domain's recordings or
phrases. You can **Play** it and see its text before you choose. Then pick Greet
Long or Greet Short and click **Set Greeting**.

A phrase only plays when it is enabled and in the menu's language, so the
picker refuses others.

### Record in the browser

1. Click **Record**, speak, then click **Stop**.
2. Listen to the preview.
3. Optionally give it a name, then click **Save Greeting**.

It is saved as a recording of the domain and set on the menu.

These Default Settings in `x_ivr_manager` control recordings:

| Setting | Default |
|---|---|
| `recording_sample_rate` | 8000 (or 16000) |
| `recording_max_seconds` | 300 |
| `recording_max_size` | 10 MB |

Audio is stored as mono WAV.

### Text to speech

Type the greeting, choose a voice and click **Generate**. The audio is saved as
a recording, set on the menu, and keeps its text so you can read or copy it
later.

### Phrase (Google Text-to-Speech)

Use a phrase for a greeting built from parts:

1. Under **New Phrase**, add text parts and pauses in the order they should
   play (**Add Text**, **Add Pause**).
2. Choose a voice and click **Create Phrase**.

Each text part becomes a recording named "<phrase> - Part 1", "Part 2", and so
on. The phrase plays the parts in order with the pauses. Its description keeps
the whole script on one line, pauses included. You can reuse the phrase in other
menus and edit it in **Applications > Phrases**.

### Names

A greeting you don't name gets a descriptive name such as
"Main Menu - Long Greeting - 2026-11-16 14:05", in the domain time zone.

### Remove or delete

- **Remove from Menu** takes the greeting off this menu. The recording or
  phrase is kept.
- **Delete**, next to the picker, deletes a recording (and its audio file) or a
  phrase for good, after you confirm.

Delete is refused while anything still plays the greeting, and the message
lists what does. Checked places:

- IVR menus (greetings, sounds, ringback) and menu options,
- phrases,
- ring groups, call center queues, call flows, conference centers,
- dialplans.

Deleting a phrase also deletes the recordings made for it, unless something
else uses them.

## Schedules

A schedule makes the menu answer only at certain times, and sends calls to a
**fallback destination** at all other times. It is a FusionPBX time condition,
listed in **Dialplan > Time Conditions**.

### Set up a schedule

1. In the menu's **Schedule** section, enter a **Name**, or keep the one
   suggested.
2. Keep the suggested **Schedule Extension** (a random, unused 22 digit number).
   If `time_conditions > extension_range` is enabled, the suggestion is the
   first free number in that range instead.
3. Add one or more **Rules** (see below). The menu answers while **any** rule
   matches.
4. Choose the **Fallback Destination**: where calls go when no rule matches.
5. Click **Save**.
6. In **Dialplan > Inbound Routes**, set the destination of the number to the
   schedule; it is listed under Time Conditions.

The page shows:

- which inbound routes use the schedule,
- whether it is **Active now**,
- the next date of every annual rule, so you can check holidays at a glance.

**Remove Schedule** deletes the time condition. Inbound routes that pointed at
it must then be changed.

### Rules

Each rule has a type and an optional label, such as "Thanksgiving":

- **One time:** a start and end date and time. Use it for a closure, a
  holiday that moves with Easter, or any single occasion.
- **Weekly:** tick the days, then either **All day** or **From** and **to**
  (24 hour, e.g. 08:00 to 17:00; use 24:00 to end at midnight).
- **Annual**, one of:
  - **On a date:** a month and day, e.g. December 25. Tick **Also the observed
    day** to also cover the Friday before when the date is a Saturday, and the
    Monday after when it is a Sunday.
  - **On the** 1st, 2nd, 3rd, 4th or last weekday of a month, e.g. the 4th
    Thursday of November. Optionally shift it by up to six days, e.g. "1 day
    after" for the day after Thanksgiving.

  Either can be all day or from one time to another. The **US holiday preset**
  list fills in the fields and the label for you.

Annual rules repeat every year by themselves; FreeSWITCH works out the floating
dates, so there is nothing to update each year.

### Example: business hours, after hours and holidays

Rules only add times when the menu answers; they can't make exceptions. So put
the schedule on the menu for the **closed** times, and make the open-hours menu
the fallback:

- **Menus:** "Main Menu" (open) and "After Hours" (closed).
- **Schedule:** on **After Hours**, with fallback destination **Main Menu**.
- **Rules:**

  | Type | Rule | Covers |
  |---|---|---|
  | Weekly | Mon, Tue, Wed, 00:00 to 08:00 | before opening |
  | Weekly | Mon, Tue, Wed, 17:00 to 24:00 | after closing |
  | Weekly | Thu, 00:00 to 07:00 | before opening |
  | Weekly | Thu, 15:00 to 24:00 | after closing |
  | Weekly | Fri, Sat, Sun, all day | the weekend |
  | Annual | New Year's Day, Memorial Day, Independence Day, Labor Day, Thanksgiving Day, Day after Thanksgiving, Christmas Day (presets) | holidays |
  | One time | 2026-12-28 00:00 to 2026-12-30 00:00, "Office move" | a closure |

- **Inbound route:** the main number goes to the schedule.

If you only need the open hours, the opposite works too: put the schedule on
**Main Menu** with Mon–Wed 08:00–17:00, Thu 07:00–15:00 and fallback **After
Hours**. Holidays can't be added that way, because they would have to *remove*
open hours.

### Times and time zones

- Dates and times are in the domain time zone (**Default Settings** or
  **Domain Settings > domain > time_zone**), shown next to the rules.
- After changing the domain time zone, open the schedule and save it again.

## Limitations

The full list is in the README under
[Schedule rule limitations](../README.md#schedule-rule-limitations). In short:

**Holidays and dates**
- Holidays that follow Easter or a lunar calendar can't repeat by themselves.
  Add them as one-time rules each year.
- Observed days follow the US rule only (Saturday → Friday, Sunday → Monday).
  They are refused for Feb 28, Feb 29 and Mar 1.
- The last weekday of February isn't supported. A day shift can't move a date
  into another month.
- Presets cover US holidays only. Enter other holidays by hand.

**Rules**
- One time window per rule, and no window past midnight. Use two rules, one
  ending at 24:00 and one starting at 00:00.
- Rules only add time; they can't make exceptions (see the example above).
- At most 40 rules per schedule.

**Other places and other times**
- A schedule whose times or destinations were changed in **Dialplan > Time
  Conditions** can't be shown as rules any more. IVR Manager asks before
  replacing it with the rules you enter.
- The rule fields are labelled with the schedule's time zone. After the domain
  time zone changes, the page says so; saving applies the new zone.
- "Active now" and the next dates are worked out by IVR Manager, not reported
  by FreeSWITCH. They can differ if FreeSWITCH's time zone data is missing or
  outdated for your zone.

## Troubleshooting

- **Callers always get the fallback (or never do).**
  - Check that the inbound route points at the **schedule**, not the menu.
  - Check the status and next dates shown on the page.
  - Check the domain time zone.
- **The menu answers but plays nothing.** No greeting is set, or the greeting
  is a phrase in another language than the menu. Set a recording, or a phrase
  in the menu's language.
- **Record does nothing.** Open the page over HTTPS and allow the microphone.
- **A greeting can't be deleted.** The message lists what still uses it.
  Remove it there first.
- **"Matches the outbound route" when saving a number.** One of your outbound
  routes would match that number. Keep the suggested number or pick another.
- **The schedule shows no rules and asks to replace conditions.** It was changed
  in Time Conditions. Enter the rules again and tick the confirmation to replace
  it.
