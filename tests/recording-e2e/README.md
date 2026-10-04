<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Recording transcription end-to-end tests

Measures how accurately recording transcripts attribute words to speakers and how
well their timestamps match the recording, using scripted calls instead of real
people. See `docs/multitrack-transcription-plan.md` for the context.

1. `generate_audio.py` turns a scenario (who says what and when) into one
   text-to-speech audio file per participant and an `expected.json` with the
   ground truth.
2. `run_call.py` starts one browser per participant, each one a Talkbuchet
   virtual participant (`docs/Talkbuchet.js`) publishing its generated audio in a
   real call. All the audio starts at the same wall clock time. The call is
   recorded through the Talk API and, optionally, the transcript and summary
   drafts are downloaded.
3. `evaluate.py` compares the transcript with `expected.json`.

## Requirements

- Python 3 with `numpy`, `requests`, `selenium` (and `pytest` for the tests).
- Chrome/Chromium with chromedriver, or Firefox with geckodriver.
- For Google Text-to-Speech: `gcloud` logged in (or `--access-token`) and the
  Text-to-Speech API enabled; alternatively `espeak-ng` for offline voices.
- A Nextcloud server with Talk, a High Performance Backend and a recording
  server; recording transcription enabled.
- A conversation that guests can join (or test users with app passwords in a
  credentials file), and a moderator account (app password) that starts the
  recording and owns the transcript.

## Usage

```bash
./generate_audio.py scenarios/three-people.json out/three-people --tts google

./run_call.py out/three-people \
    --url https://cloud.example.com --token abcd1234 \
    --moderator admin --moderator-password APP_PASSWORD \
    --headless --wait-transcript 30

./evaluate.py out/three-people/expected.json out/three-people/transcript.md --run out/three-people/run.json
```

`credentials.json` (optional, keep it out of git) maps participant ids to users:

```json
{ "alice": { "user": "alice", "appToken": "..." } }
```

Participants without credentials join as guests; their guest name is set and
also sent through the data channel. `run.json` records each participant's actor
id, so `evaluate.py --run` can map ids shown in the transcript to names.

Like real clients, the bots send "speaking" / "stoppedSpeaking" data channel
messages (0.15 s after a turn starts, 1.5 s after it ends; see
`--speaking-start-latency` and `--speaking-stop-latency`). Use
`--no-speaking-events` to leave them out.

After the recording stopped the participants stay in the call for `--linger`
seconds (20 by default) while the recording is uploaded, as people do in a
real meeting. Talk resolves the names of the participant tracks during the
upload, and guests are removed from the conversation when they leave.

## Scenarios

```json
{
	"language": "en-US",
	"sampleRate": 16000,
	"participants": [{ "id": "alice", "name": "Alice", "voice": { "google": "en-US-Neural2-F", "espeak": "en-us+f3" } }],
	"turns": [{ "speaker": "alice", "start": 1.0, "text": "Good morning everyone." }]
}
```

Turns of different participants may overlap (interruptions); turns of the same
participant may not.

## Metrics

- **WER**: word error rate of the transcript.
- **Wrong speaker / unattributed**: share of correctly recognized words that are
  attributed to another participant / to an anonymous `Speaker N`.
- **Turn start error**: residual error of the turn start times after fitting one
  global offset (the scripted times are relative to the start of the audio).
  Markdown transcripts have a resolution of one second.

## Tests

```bash
python3 -m pytest test_evaluate.py
```
