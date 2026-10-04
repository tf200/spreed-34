#!/usr/bin/env python3
#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#

"""
Generates the audio of each participant of a scripted call.

The scenario describes the participants and the turns of the call (who says
what and when). Each turn is synthesized with text-to-speech and placed at its
scripted start time in the audio file of its speaker, so the audio files of all
the participants played at the same time reproduce the scripted call.

The generated "expected.json" is the ground truth used by evaluate.py; the end
of each turn is its start plus the duration of the synthesized speech.

Usage:
    ./generate_audio.py scenario.json OUTPUT_DIRECTORY [--tts google|espeak]

For Google Text-to-Speech an access token is got from "gcloud auth
print-access-token [--account ACCOUNT]" unless given with --access-token; the
project to bill must be given with --project.
"""

import argparse
import base64
import io
import json
import os
import subprocess
import tempfile
import wave

import numpy
import requests


def readWav(data):
    with wave.open(io.BytesIO(data)) as wavFile:
        if wavFile.getsampwidth() != 2:
            raise ValueError('Only 16 bit WAV files are supported')
        samples = numpy.frombuffer(wavFile.readframes(wavFile.getnframes()), dtype=numpy.int16).astype(numpy.float32) / 32768
        channels = wavFile.getnchannels()
        if channels > 1:
            samples = samples.reshape(-1, channels).mean(axis=1)
        return samples, wavFile.getframerate()


def writeWav(fileName, samples, sampleRate):
    with wave.open(fileName, 'wb') as wavFile:
        wavFile.setnchannels(1)
        wavFile.setsampwidth(2)
        wavFile.setframerate(sampleRate)
        wavFile.writeframes((numpy.clip(samples, -1, 1) * 32767).astype(numpy.int16).tobytes())


def resample(samples, fromRate, toRate):
    if fromRate == toRate:
        return samples
    duration = len(samples) / fromRate
    targetTimes = numpy.arange(int(duration * toRate)) / toRate
    return numpy.interp(targetTimes, numpy.arange(len(samples)) / fromRate, samples).astype(numpy.float32)


def trimSilence(samples, sampleRate, threshold=0.01):
    """Trims leading and trailing silence so turn times match the speech."""
    loud = numpy.flatnonzero(numpy.abs(samples) > threshold)
    if len(loud) == 0:
        return samples
    margin = int(0.02 * sampleRate)
    return samples[max(0, loud[0] - margin):loud[-1] + margin]


class GoogleTts:

    def __init__(self, accessToken, project, account):
        if not accessToken:
            command = ['gcloud', 'auth', 'print-access-token']
            if account:
                command += ['--account', account]
            result = subprocess.run(command, capture_output=True, text=True, check=False)
            if result.returncode != 0:
                raise SystemExit('Could not get a Google access token from gcloud:\n' + result.stderr.strip())
            accessToken = result.stdout.strip()
        self._accessToken = accessToken
        self._project = project

    def synthesize(self, text, language, voice, sampleRate):
        headers = {'Authorization': 'Bearer ' + self._accessToken}
        if self._project:
            headers['x-goog-user-project'] = self._project
        body = {
            'input': {'text': text},
            'voice': {'languageCode': language},
            'audioConfig': {'audioEncoding': 'LINEAR16', 'sampleRateHertz': sampleRate},
        }
        if voice:
            body['voice']['name'] = voice
            # Google voice names start with their language code (for example,
            # "en-GB-Neural2-A"), which may differ from the scenario language.
            body['voice']['languageCode'] = '-'.join(voice.split('-')[:2])
        response = requests.post('https://texttospeech.googleapis.com/v1/text:synthesize', json=body, headers=headers, timeout=60)
        if response.status_code != 200:
            try:
                message = response.json()['error']['message']
            except (ValueError, KeyError, TypeError):
                message = response.text[:500]
            raise SystemExit(f'Google Text-to-Speech failed with HTTP {response.status_code}: {message}')
        return readWav(base64.b64decode(response.json()['audioContent']))


class EspeakTts:

    def synthesize(self, text, language, voice, sampleRate):
        with tempfile.NamedTemporaryFile(suffix='.wav') as wavFile:
            subprocess.run(['espeak-ng', '-v', voice or language.split('-')[0], '-s', '160', '-w', wavFile.name, text], check=True)
            with open(wavFile.name, 'rb') as wavData:
                return readWav(wavData.read())


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('scenario')
    parser.add_argument('output')
    parser.add_argument('--tts', choices=['google', 'espeak'], default='google')
    parser.add_argument('--access-token')
    parser.add_argument('--project', help='Google Cloud project for quota and billing')
    parser.add_argument('--account', help='gcloud account to get the access token for (defaults to the active one)')
    args = parser.parse_args()

    if args.tts == 'google' and not args.project:
        parser.error('--project is required for Google Text-to-Speech (for example: '
                     '--project nextcloud-talk-transcription --account farjiataha@gmail.com)')

    with open(args.scenario, encoding='utf-8') as scenarioFile:
        scenario = json.load(scenarioFile)

    sampleRate = scenario.get('sampleRate', 16000)
    language = scenario.get('language', 'en-US')
    participants = {participant['id']: participant for participant in scenario['participants']}
    tts = GoogleTts(args.access_token, args.project, args.account) if args.tts == 'google' else EspeakTts()

    turns = []
    for index, turn in enumerate(scenario['turns']):
        participant = participants[turn['speaker']]
        voice = participant.get('voice', {}).get(args.tts) if isinstance(participant.get('voice'), dict) else participant.get('voice')
        samples, rate = tts.synthesize(turn['text'], language, voice, sampleRate)
        samples = trimSilence(resample(samples, rate, sampleRate), sampleRate)
        turns.append({
            'id': index + 1,
            'speaker': turn['speaker'],
            'name': participant['name'],
            'start': float(turn['start']),
            'end': round(float(turn['start']) + len(samples) / sampleRate, 3),
            'text': turn['text'],
            'samples': samples,
        })

    for participant in scenario['participants']:
        ownTurns = sorted((turn for turn in turns if turn['speaker'] == participant['id']), key=lambda turn: turn['start'])
        for previous, following in zip(ownTurns, ownTurns[1:]):
            if following['start'] < previous['end']:
                raise SystemExit(f"Turn {following['id']} of {participant['name']} starts at {following['start']} s "
                                 f"but the previous turn ends at {previous['end']} s; move it later in the scenario")

    duration = max(turn['end'] for turn in turns) + 2
    os.makedirs(args.output, exist_ok=True)
    for participant in scenario['participants']:
        track = numpy.zeros(int(duration * sampleRate), dtype=numpy.float32)
        for turn in turns:
            if turn['speaker'] == participant['id']:
                start = int(turn['start'] * sampleRate)
                track[start:start + len(turn['samples'])] += turn['samples']
        writeWav(os.path.join(args.output, participant['id'] + '.wav'), track, sampleRate)

    with open(os.path.join(args.output, 'expected.json'), 'w', encoding='utf-8') as expectedFile:
        json.dump({
            'language': language,
            'duration': round(duration, 3),
            'participants': [{'id': participant['id'], 'name': participant['name']} for participant in scenario['participants']],
            'turns': [{key: value for key, value in turn.items() if key != 'samples'} for turn in turns],
        }, expectedFile, indent=2)

    print(f"Generated {len(scenario['participants'])} tracks of {duration:.1f} s in {args.output}")


if __name__ == '__main__':
    main()
