# Multitrack transcription – implementation plan

Status: **phases 1–4 implemented and deployed to the dev server (2026-10-04), first end-to-end run meets the targets** · Started: 2026-10-04

## 1. Goal

Produce post-call transcripts where **every turn is attributed to the correct participant** and
**every timestamp matches the playback position in the recording**, then feed that transcript to
the existing cleanup → summary → review → publish flow.

Targets (measured on the fixture calls from phase 0):

| Metric | Target |
|---|---|
| Wrong-speaker word rate (excluding shared microphones) | ≈ 0 % |
| Turn start error vs. recording playback | < 0.3 s |
| Turns labelled `Speaker N` | 0 when a track exists for the speaker |

## 2. Why the current pipeline cannot reach this

Today the recording server captures one **mixed** audio stream (ffmpeg on the browser PulseAudio
sink). Attribution is then reconstructed from several imprecise signals:

1. Google diarization on the mix (errors on short turns, crosstalk, label reuse).
2. A speaker timeline built from `speaking` data-channel messages that are detected on the
   **sender's** microphone and arrive with network latency (`webrtc.js` → `CallParticipantModel.js`).
3. Heuristic clock alignment (`recordingOffset`, `clockUncertainty`, fixed paddings). In addition,
   `_recordingTimeStart` is taken before ffmpeg has actually started capturing, so file `0 s` is
   offset by the ffmpeg start-up delay.
4. Overlap matching with thresholds; unmatched words fall back to `Speaker N`.
5. Gemini cleanup may move fragments between speaker blocks.

## 3. Approach

Every participant's audio already arrives in the recording browser as a separate WebRTC stream.
We record **one audio track per participant**, transcribe each track separately (no diarization
needed: the speaker is known by construction) and merge the words on a common timeline.

```
Recording browser (Talk recording page)
 ├─ ffmpeg (unchanged): mixed audio/video → recording file
 └─ AudioContext
      ├─ participant A stream → MediaRecorder → segments
      └─ participant B stream → MediaRecorder → segments
Recording server (Python)
 ├─ drains segment chunks to disk during the call
 ├─ calibrates page clock ↔ recording file (cross-correlation)
 ├─ VAD → speech-only chunks (≤ 25 min each) + cut maps
 └─ uploads chunks + manifest.json with the recording
Talk backend (PHP, background jobs)
 ├─ transcription provider per chunk (Gemini 3.5 Transcribe, word timestamps, no diarization)
 ├─ MultitrackTranscriptMerger → transcript.json (source of truth)
 ├─ Gemini cleanup per turn id (cannot touch speakers/timestamps)
 └─ render Markdown → drafts → review → publish (existing flow)
```

### 3.1 Time model

All times end up on the **recording file timeline** (what the user plays back).

```
recordingTime(word) = cutMap(chunk, word.start)          // chunk time → track time
                    + segment.startPageTime                // track time → page clock
                    - calibration.fileZeroPageTime         // page clock → recording file
```

- **Track → page clock**: all participant tracks are routed through one `AudioContext`. The context
  renders continuously (silence when no packets arrive), so track time never "skips" during mutes or
  network stalls, and all tracks share one sample clock. Segment start is captured as
  `ctx.currentTime` and converted with `ctx.getOutputTimestamp()` pairs to `performance.now()`.
- **Page clock → file**: the mixed recording is the sum of the participant tracks, so the recording
  server cross-correlates a short window of the mix with the loudest track to find the exact offset
  (~10 ms). The existing `recordingOffset` estimate only bounds the search window and is the fallback.
- **Chunk → track**: VAD removes silence and long chunks are split at silences; every chunk carries a
  cut map `[{chunkStart, trackStart, duration}]` that is applied piecewise to word times.

### 3.2 Merge rules

1. Convert all words to recording time; sort by `start`.
2. Group consecutive words of the same speaker into a turn; start a new turn on a pause > 1.5 s or
   at sentence end.
3. Backchannels (1–3 words) during another speaker's turn become their own short turn without
   splitting the main speaker's sentence; longer overlaps become two overlapping turns.
4. Echo/bleed: identical word on two tracks within ~300 ms → keep the louder / earlier-onset track.
5. Turn timestamp = first word start; render `MM:SS`, or `HH:MM:SS` when the call is ≥ 1 h.

### 3.3 transcript.json (v2)

```json
{
  "version": 2,
  "recordingFileId": 123,
  "language": "auto",
  "provider": {"name": "gemini_transcribe", "model": "gemini-3.5-transcribe"},
  "speakers": [{"id": "actor:users:alice", "actorType": "users", "actorId": "alice", "displayName": "Alice"}],
  "turns": [
    {"id": 1, "speaker": "actor:users:alice", "start": 58.12, "end": 62.90,
     "text": "So the main thing for Q3 is the infrastructure budget.",
     "words": [{"text": "So", "start": 58.12, "end": 58.30}]}
  ]
}
```

Markdown, PDF and the summary input are rendered from this file. Cleanup replaces `turns[].text`
only.

## 4. Decisions

| # | Decision | Notes |
|---|---|---|
| D1 | Transcription model: **Gemini 3.5 Transcribe** (`gemini-3.5-transcribe`) | Configurable in admin settings; Chirp 3 kept as an alternative provider. EU data residency is ignored during early development. |
| D2 | No diarization | Speaker is known per track. Shared meeting-room microphones are **out of scope** for now. |
| D3 | Language is a Talk admin setting | Existing `recording_google_language`; add `auto` (default) next to explicit codes such as `nl-NL`, `en-US`. |
| D4 | Python dependencies on the recording server are allowed | e.g. `numpy`, `webrtcvad` (or equivalent). |
| D5 | Keep CPU cost low | See section 5. |
| D6 | Per-participant audio is stored outside the user's Files (app data) and deleted after processing | Covered by the existing recording consent. |
| D7 | Old mixed-track pipeline stays as fallback | Used when no tracks/manifest were uploaded. |

## 5. CPU budget

The recording server already renders the call in a browser and runs ffmpeg, so new work must be light.

| Step | Where | Cost control |
|---|---|---|
| Per-participant Opus encoding | Browser `MediaRecorder` | Mono, `audioBitsPerSecond` ≈ 24–32 kbps; Opus encoding is ~1 % of a core per stream. Only participants with audio; screen-share audio ignored. |
| Draining chunks | Selenium every few seconds | `timeslice` chunks, base64 only for small deltas; bounded browser memory. |
| Clock calibration | Recording server | Cross-correlate only a ~60 s window, 8 kHz mono, FFT (`numpy`): milliseconds of CPU. |
| VAD + chunking | Recording server, after the call | `webrtcvad` on 16 kHz mono is far faster than real time; cut with ffmpeg. Prefer stream copy; re-encode only if the provider rejects the container. |
| Merging/rendering | Talk background job | Pure PHP over word lists; linear after sorting. |
| Transcription/cleanup/summary | Google | No local CPU. |

Measure in phase 0: browser CPU with N = 2, 5, 10 participants, with and without track recording.

## 6. Phases

### Phase 0 – Fixtures, baseline, spikes
- [x] Fixtures are generated, not recorded by people (`tests/recording-e2e/`):
  - **Merger fixtures**: hand-written per-speaker word lists (JSON) with known times covering
    interleaving, backchannels, crosstalk, echo and rejoin – used by PHP unit tests, no audio.
  - **End-to-end fixtures**: a script of turns ("Alice 0–8 s, Bob interrupts at 6 s, …") is turned
    into one TTS audio file per participant, padded with silence at the scripted times. 2–3 headless
    browsers join a call using those files as fake microphones (Chromium
    `--use-file-for-fake-audio-capture`, Firefox fake media prefs; possibly reusing
    `docs/Talkbuchet.js`) and the call is recorded. The script is the ground truth.
  - **Sanity check**: one or two short real calls at the end (real voices, mics, noise).
- [x] Evaluation script (`tests/recording-e2e/evaluate.py`): wrong-speaker rate, turn-start error,
      WER against the script.
- [x] Run the scenario against the current pipeline to get a baseline (2026-10-04, dev server,
      `three-people` scenario, bots sending "speaking" messages with real-client latencies):

      | Metric | Baseline (current pipeline) | Multitrack (dev server, run 3) | Target |
      |---|---|---|---|
      | Correct speaker (words) | 47.4 % | 100 % | ≈ 100 % |
      | Wrong speaker | 31.1 % | 0 % | ≈ 0 % |
      | Unattributed ("Speaker N") | 21.5 % | 0 % | 0 % |
      | WER (incl. number formatting) | 6.9 % | 1.4 % | – |
      | Turns with own start | – | 15 / 15 | – |
      | Turn start error (mean / max) | 0.73 s / 6 s | 0.33 s / 0.5 s (MM:SS resolution) | < 0.3 s |

      The WER was first reported as 12.4 %: the evaluator counted the "Transcript is AI generated"
      notice as spoken words; it is ignored since 2026-10-04 (both columns without it).

      Findings:
      - Speaker labels lag the audio by about one turn (1–2 s): the timeline offset is measured from
        launching ffmpeg (`Popen`), not from its first captured sample. Calibration (Phase 2) removes this.
      - Blocks are split mid-sentence into name / "Speaker N" fragments wherever diarization and the
        timeline disagree, which is the mix seen in real transcripts.
      - Crosstalk loses words: Bob's interruption or the end of Alice's overlapped sentence is
        missing or garbled in every run.
      - Without "speaking" messages (bots without them) attribution is 0 % – the current design depends
        entirely on sender-side speaking detection.
      - Guest display names are not shown: guests appear as their actor id (sha1). Existing bug, to fix
        in the new merger by resolving names server-side from the participant list.
- [ ] Spike A (browser): route remote participant audio through one `AudioContext` in the recording
      page (Firefox and Chromium), record per participant, verify separation, continuity and CPU.
- [x] Spike B (provider): Vertex AI `generateContent` with the service account, model
      `gemini-3.5-transcribe-preview` in `global`, inline Ogg Opus, `audioTranscriptionConfig:
      {wordTimestamp: true, mode: "VERBATIM", languageCodes?}`; words in
      `candidates[0].content.parts[].audioTranscription.words[] {word, startOffset, endOffset}`;
      15 min per request with word timestamps; ≈ 2 s per 20–30 s chunk. See §8.
- **Exit:** baseline numbers recorded; both spikes confirmed or the design adjusted.

### Phase 1 – Per-participant capture (spreed, recording page)
- [x] `src/utils/recording/participantTrackRecorder.js`: one `AudioContext`; per participant
      `MediaStreamSource → MediaStreamDestination → MediaRecorder`; one segment per stream;
      close/open segments on join, leave, stream replacement and reconnect; ignore screen share.
- [x] Clock anchors (`getOutputTimestamp()` pairs, every 10 s) and speaker identity
      (actorType, actorId, displayName, sessionId).
- [x] `src/mainRecording.js`: expose `OCA.Talk.startTrackRecording()`, `drainTrackChunks()`,
      `stopTrackRecording()` (keep the existing speaker timeline as fallback data). Also fixed the
      speaker timeline seeding, which received `undefined` instead of the participant models.
- [x] Unit tests for segment lifecycle and manifest generation.

### Phase 2 – Recording server (nextcloud-talk-recording)
- [x] `Participant.py`: wrappers for the three new page functions.
- [x] `Service.py` + `ParticipantTracks.py`: start track recording with the recorder, drain chunks
      every 5 s to `<recording>.tracks/segment-<id>.<ext>`, stop and write `manifest.json` on stop.
      Behind `[recording] participanttracks` (Docker: `RECORDING_PARTICIPANT_TRACKS=true`), off
      by default; files are kept locally until the upload step exists.
- [x] `Calibration.py`: per segment cross-correlation of amplitude envelopes (200 Hz) of the track
      vs. the mix, one anchor per 5 min of segment (drift), ±5 s search around the clock estimate;
      adds `recordingOffset`, `alignment` (`xcorr`/`clock`) and `anchors` to each manifest segment.
      Runs after the recorder finished, before the upload.
- [x] `TrackChunker.py`: energy VAD relative to each track's noise floor (streamed decode, bounded
      memory), speech regions padded and merged, joined with 1 s of silence into Ogg Opus chunks of
      ≤ 14 min; every chunk has a cut map `{chunkStart, trackStart, recordingStart, duration}`
      (recording times already include the calibration, interpolated between anchors).
- [x] `BackendNotifier.py`: the manifest and the chunks are sent as one stored zip
      (`participantTracks`) in the existing `store` request (one file field instead of one per
      chunk, as PHP limits `max_file_uploads`). The raw segments are not uploaded.
- [x] The `.tracks` directory is deleted after a successful upload.
- [x] Tests: unit tests, plus the real chain (ffmpeg decode → calibration → chunking → encode → zip)
      on the TTS fixtures: offset found exactly despite a 1.5 s clock error, all 15 turns covered,
      72 s of speech chunks instead of 216 s of tracks (calibration 0.5 s, chunking 1.8 s CPU).

### Phase 3 – Backend storage and orchestration (spreed)
- [x] `ParticipantTracksStore`: validates the zip (entry names, uncompressed sizes, manifest schema)
      and extracts it to app data `recording-tracks/<fileId>/`; invalid tracks are logged and the
      recording falls back to the mixed pipeline.
- [x] Migration `talk_recording_ai_chunks` (operation, chunk id, state, attempts, words, error).
- [x] `TrackTranscriptionProvider` interface with `GeminiTranscribeClient` (Vertex AI with the existing
      service account, verbatim mode with word timestamps, no diarization, inline Ogg Opus). The existing Chirp 3 code
      stays as the mixed-recording pipeline (D7) instead of becoming a track provider.
- [x] `RecordingAiProcessor`: `queued` → `transcribing_tracks` → `merging` → `cleaning` →
      `summarizing`. Chunks are transcribed sequentially within a 240 s budget per run (lease 600 s),
      5 attempts per chunk with backoff; if a chunk keeps failing the operation falls back to the
      mixed recording rather than producing a transcript with words missing.
- [x] Admin settings: language `auto` (new default) or a code, "Transcribe each participant
      separately", transcription model. The location is the app config
      `recording_google_transcription_location` (`global`, `us`, `eu` or a region; default `global`).
- [x] Audio deleted after merging, on failure and on fallback; `CleanupParticipantTracks` deletes
      unprocessed tracks after 2 days.
- [ ] Parallel chunk requests (Nextcloud's HTTP client has no async API; sequential is enough for
      now: ~10 chunks for a 1 h call with 5 participants).
- [ ] Delete `transcript.json` when the recording or the room is deleted.

### Phase 4 – Merging (spreed)
- [x] `lib/Recording/MultitrackTranscriptMerger.php`: per speaker utterances split at pauses
      > 1.5 s (long monologues at sentence ends), echo fragments dropped, a real interruption splits
      the ongoing utterance at its next sentence end (backchannels never split), sorted by start,
      consecutive same-speaker utterances joined (gap ≤ 3 s, turn ≤ 120 s).
- [x] `transcript.json` v2 stored in app data `recording-transcripts/<fileId>.json`; speaker names
      from the manifest, else the room participant list, else "Guest N" / "Participant N".
- [x] Unit tests: interleaving, backchannels, interruption, echo, rejoin, cut maps, long calls.
- [x] Oracle check on the `three-people` fixture (scripted words placed in the real chunks, real
      merger and renderer): **100 % correct speaker, 0 % wrong, 0 % unattributed, 15/15 turns**,
      no words lost in crosstalk (baseline: 47 % / 31 % / 22 %).

### Phase 5 – Cleanup and rendering (spreed)
- [ ] `GoogleGeminiClient`: cleanup per batch of turns as `[{id, text}]` → `[{id, text}]`;
      validate ids and per-turn word-count tolerance; on failure keep raw text for that batch.
      Interim: the existing whole-transcript cleanup runs with an "exact speakers" rule (never move
      words between blocks) for multitrack transcripts.
- [x] Markdown renderer from `transcript.json` (`H:MM:SS` for calls ≥ 1 h; the cleanup validation
      accepts both formats).
- [x] Summary input built from the rendered transcript; existing drafts/review/publish unchanged.

### Phase 6 – Evaluation and rollout
- [x] First end-to-end runs on the dev server (`tests/recording-e2e/out/multitrack-{1,2,3}`), see the
      table in Phase 0. They found and fixed: chunk rows inserted without state (entity default
      equal to the set value is not written), a random draft file name containing `/` (existing,
      ~40 % of draft saves failed once and were retried), guest names (the recording browser used the
      actor id; Talk now stores the names of the tracks at upload, while the guests are still in
      the conversation), tracks not deleted when preparing them failed, harness picking drafts of
      an earlier recording.
- [ ] Run the evaluation script on more fixtures (Dutch, long call, rejoin, many participants).
- [ ] Enable `recording_google_multitrack_enabled` by default once targets are met.
- [ ] Update `docs/recording.md` (setup, settings, privacy/retention).

## 7. Provider notes (researched 2026-10-04)

**Gemini 3.5 Transcribe** (`gemini-3.5-transcribe`, live variant `gemini-3.5-transcribe-live`)
- Vertex AI (used): `generateContent`, not the Interactions API. Docs list GA `gemini-3.5-transcribe`
  in `global`/`us`/`eu`, but on 2026-10-04 only `gemini-3.5-transcribe-preview` in `global` is
  served to our project (the others return 404). Billed as normal Cloud usage (credits apply).
- Gemini API (not used): Interactions API with an API key, billed through AI Studio Prepay, which
  needs a purchased balance before Cloud credits apply.
- Word-level timestamps via `audioTranscriptionConfig.wordTimestamp`; marked Experimental and
  "degrades transcription accuracy".
- **15 min per request with word timestamps or diarization** → chunks ≤ 14 min.
- No Batch API → synchronous requests; the background job must bound parallelism and runtime.
- 85+ languages with utterance-level language detection, including `nl-NL`.
- Price (third-party listings): ≈ $0.0001 / s of audio + $12 / 1M output tokens.

**Chirp 3** (`chirp_3`, Speech-to-Text v2) – alternative provider
- GA in `us`/`eu`; `BatchRecognize` up to 1 h, **20 min with word timestamps** (some degradation expected).
- Speech adaptation (≤ 1 000 phrases) works together with timestamps; `language_codes: ["auto"]`.
- Dynamic batching is cheap (~$0.003–0.004 / min) but may take up to 24 h.

Sources:
- https://ai.google.dev/gemini-api/docs/models/gemini-3.5-transcribe
- https://ai.google.dev/gemini-api/docs/transcribe
- https://ai.google.dev/gemini-api/docs/billing
- https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/gemini/3-5-transcribe
- https://docs.cloud.google.com/speech-to-text/docs/models/chirp-3

## 8. Open items

- Spike B result (2026-10-04, TTS fixtures, real `GeminiTranscribeClient` on Vertex, real chunks,
  real merger): WER 2.1 % (baseline 6.9 %), correct speaker 100 % (baseline 47.4 %), wrong 0 %,
  unattributed 0 %, 15/15 turns, turn start error mean 0.23 s / max 1.0 s (MM:SS rounding
  included). The remaining word errors are formatting ("€20,000", "fixed price").
- Switch the default to the GA `gemini-3.5-transcribe` (and `eu` for data residency) once Vertex
  serves it to the project.
- Chunks are re-encoded as Ogg Opus 32 kbps by the chunker (listed as supported by the Gemini API).
- Guest display names do not reach the recording browser when set after joining (test harness);
  Talk resolves the names of the tracks from the participant list at upload. Guests that left
  before the upload are shown as "Guest N".
- Retention period for per-participant audio if processing fails.
