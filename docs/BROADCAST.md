# Broadcasting

Write a message once, and everyone the bot can still reach gets it —
privately, as a normal message from the bot, not a channel post and not
a forward.

The feature is small on the surface and careful underneath, because
sending to everybody is the one thing a bot does that cannot be taken
back and the one thing that can break everything else it does.

- [Sending one](#sending-one)
- [What you can send](#what-you-can-send)
- [Who gets it](#who-gets-it)
- [The rate limit](#the-rate-limit)
- [How it runs](#how-it-runs)
- [Pausing and stopping](#pausing-and-stopping)
- [The report](#the-report)
- [From the console](#from-the-console)
- [Settings](#settings)

## Sending one

In Telegram: **/admin → 📣 Broadcast → New broadcast**. Send the message
you want people to get, then confirm.

```
📣 Broadcast

Reaches 8,412 people at 15/second.
203 left out - they blocked the bot or their account is gone.

Nothing is going out right now.

[ 📣 New broadcast ]
```

The confirmation is not skippable. There is no undo for a message that
has arrived on eight thousand phones, so the panel restates the audience
and the time it will take and waits for one more tap.

It also offers **Send it quietly**, which delivers without a
notification sound. Worth using more often than not: an announcement
arrives everywhere at once, and the difference between a bot that buzzes
every phone at midnight and one that does not is whether people keep it.

**The worker has to be running.** Queueing writes one row; the delivery
happens in `php bin/console jobs:work`. Nothing is sent from a webhook.

## What you can send

Whatever you can send the bot. Text, a photo, a video, a document, a
poster with a caption, formatting, buttons — the message is *copied* to
each person exactly as it looks in your own chat with the bot.

That is a deliberate choice over a text box. An announcement is as often
a picture of a price list as it is a paragraph, and an admin who has to
describe their poster in words is going to paste it somewhere else
instead.

A copy is not a forward: it carries no "forwarded from" header and no
link back. Each recipient sees a message written to them.

It is also what makes a large send cheap. Telegram already holds the
photo, so every recipient costs one small API call whatever the message
weighs — nothing is re-uploaded.

## Who gets it

Every user who has started the bot, except two groups:

- **Users an admin blocked.** A bot that refuses to serve someone and
  goes on announcing things to them is not what "blocked" means.
- **Users Telegram will not deliver to.** They blocked the bot, or the
  account is gone.

That second list is built by broadcasting. When Telegram answers 403, or
400 with `chat not found`, the user is marked unreachable — in a column
of its own, `users.unreachable_at`, never by setting their status to
blocked. Those are different facts: one is your decision about a person,
the other is a person's decision about your bot, and letting the second
overwrite the first would mean somebody blocking your bot silently banned
themselves from it, and an admin having to undo a ban they never applied.

Being unreachable is not permanent. Anyone who comes back — which in
practice means pressing the **RESTART** button a blocked chat shows, and
that sends `/start` — is cleared and back in the audience. Telegram never
announces that a block was lifted, so turning up again is the only
evidence there is.

The panel says how many are left out, rather than quietly shrinking the
number: an admin comparing the audience to their user count deserves to
know why it is smaller before they go looking for a bug.

## The rate limit

**15 messages a second**, and the number matters more than it looks.

Telegram will take roughly 30 a second from a bot before it starts
answering `429`. What happens then is the part worth knowing: the flood
wait applies to *everything the bot sends*, not just the announcement. A
customer halfway through a top-up stops getting answers because a
broadcast was in a hurry.

So the default is half the allowance, and it is a configured number
rather than a constant because a bot with a lot of other traffic may want
less. Whatever is configured, it is clamped to 30 — a setting cannot open
the hole it exists to keep shut.

Pacing is done against a moving deadline rather than by sleeping a fixed
amount after each send. The API call itself takes time, so "send, then
wait 66ms" drifts to whatever the network is doing, while "the next send
is due at T + 66ms" holds the real rate at the configured one. If a send
falls behind, the next one is due *now* rather than in the past —
otherwise a stall would be repaid with a burst, which is exactly the
shape Telegram counts as flooding.

A `429` that arrives anyway is obeyed: the sender waits out the
`retry_after` Telegram asked for, then gives that one recipient a second
try. The wait is capped at a minute, because it arrives over the network
and a worker sitting still for an hour looks like a hung process.

## How it runs

A broadcast is a row, not a queue. There is no table with an entry per
recipient — that would mean writing a million rows to send a million
messages. What is stored instead is a **cursor**: the highest `users.id`
already tried. Resuming after a pause, a crash or a worker restart is
then a `WHERE` clause rather than state, and costs one indexed range scan
per batch.

The trade is that someone who presses `/start` mid-broadcast is included
and someone who joined before it started is not missed, which is the
behaviour you want from an announcement anyway.

Delivery happens in **slices**. The worker is a single process running
every schedule in turn, so a broadcast to a hundred thousand people
cannot simply hold it for two hours — everything else the bot has
scheduled would stop. Each run sends for about thirty seconds, writes
down where it got to, and hands the worker back.

The job doing this is a standing one, `core:broadcast`, armed once and
left alone like the prune job beside it. That shape is chosen over a job
per broadcast for a concrete reason: a handler cannot re-arm its own row,
because the worker writes the run's outcome *after* the handler returns
and overwrites anything the handler did to it. Letting the worker own the
schedule, and having the job ask "is there anything to send?", avoids the
argument entirely. So a broadcast starts by writing a row and stops by
writing a different status; none of it has to find a job.

The worker's lease heartbeat is passed into the sender, so a worker that
has lost its lease stops mid-slice rather than two processes delivering
the same announcement to the same people.

## Pausing and stopping

From the Broadcast screen, while it runs:

```
📤 #14 Sending - 40%
▰▰▰▰▱▱▱▱▱▱

✅ 3,310   🚫 82   👻 11   ⚠️ 2
3,405 of 8,412 tried, 5,007 to go
About 5m 34s left.

[ ⏸ Pause ]  [ ✖️ Stop ]
[ 🔄 Refresh ]
```

**Pause** holds it where it is and keeps the cursor, so **Resume** picks
up from the next untried recipient rather than starting again. It takes
effect at the next batch boundary — within a few seconds — and never
mid-message.

**Stop** is final. What was already delivered stays delivered; there is
no such thing as un-sending it.

Every one of those is a conditional `UPDATE`, so a button pressed on a
screen that has gone stale — Pause on a send that finished two seconds
ago, or two admins pressing at once — is answered honestly instead of
reviving anything.

Only one broadcast runs at a time, refused at the moment of queueing. Two
would each pace themselves to the configured rate and together send at
twice it, which is the single mistake this whole subsystem exists to
avoid.

## The report

When it finishes, the admin who composed it gets the numbers — in their
own chat, because by the time a hundred thousand messages have gone out
nobody is still looking at the screen that launched them.

```
✅ Broadcast #14 - Done

✅ Delivered: 8,104
🚫 Blocked the bot: 271
👻 Account gone: 33
⚠️ Failed: 4

8,412 of 8,412 accounted for (100%)
Took 9m 21s
```

Every outcome is there, including the ones nobody wants to see. A report
that says only "8,104 sent" is worse than no report: it is the number
that makes a bot look healthy while its real reach halves.

The four are told apart by Telegram's own error codes rather than guessed
from a boolean:

| What came back | Counted as |
| --- | --- |
| `ok` | delivered |
| `403` | blocked the bot |
| `400` with `chat not found`, `user is deactivated`, … | account gone |
| `429` | waited out and retried once |
| anything else, or no answer at all | failed |

A fifth number, **skipped**, appears when it is not zero: people who
were in the audience when the send began and had left it by the time
their turn came -- an admin blocked them, or an earlier message in this
same broadcast found them unreachable. They are written down when the
broadcast closes, so a finished send reads 100% instead of sitting at 98%
and looking stuck.

The last row is the one that keeps the report honest. A single malformed
message answers `400` for every recipient, and counting those as deleted
accounts would look like your whole audience vanishing overnight. Only
the specific descriptions above mean the chat is gone; everything else is
a failure worth investigating, and `last_error` on the row says what
Telegram actually objected to.

## From the console

Sending is in the CLI as well as the panel, because a bot is often told
to announce something by a deploy script or cron, and neither holds a
Telegram account.

```bash
php bin/console broadcast:send "<b>Maintenance</b> tonight at 02:00." --yes
php bin/console broadcast:send "Back up." --quiet --yes
php bin/console broadcast:list
php bin/console broadcast:show 14
php bin/console broadcast:pause 14
php bin/console broadcast:resume 14
php bin/console broadcast:cancel 14
```

`broadcast:send` takes HTML and prints what it is about to do. Without
`--yes` it queues nothing — the confirmation is not optional here either,
only automatable. The report goes to the first configured admin.

Like the panel, it only writes a row. `jobs:work` does the sending.

## Settings

```php
'broadcast' => [
    'rate' => 15,   // messages a second; capped at 30
    'slice' => 30,  // seconds of sending per worker run
],
```

Both read from the environment (`BROADCAST_RATE`, `BROADCAST_SLICE`).

A longer slice means less overhead and a slower response to Pause; a
shorter one means the opposite. Neither can be set to a value that
breaks the send: the rate is clamped to 1–30 and the slice to 5–300
seconds, so a slice can never outlast the job lease and a rate can never
be zero.

Related: [EXTENSIONS.md](EXTENSIONS.md) for the jobs system the delivery
runs on, and [UPDATING.md](UPDATING.md) for keeping the core current.
