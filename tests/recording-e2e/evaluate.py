#!/usr/bin/env python3
#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#

"""
Compares a recording transcript with the scripted ground truth.

The transcript can be the Markdown draft ("**Name** · MM:SS" blocks) or a
transcript.json (version 2, with turns and word times).

Metrics:
- WER: word error rate of the transcript text.
- Wrong speaker: share of correctly recognized words attributed to another
  participant (anonymous "Speaker N" labels are reported separately as
  unattributed).
- Turn start error: difference between the time of the transcript turn that
  starts with the first word of each scripted turn and the scripted start. The
  scripted times are relative to the start of the audio, not of the recording,
  so a single global offset is fitted (median) and the residual errors are
  reported. Markdown timestamps have a resolution of one second.

Usage:
    ./evaluate.py expected.json transcript.md [--json]
"""

import argparse
import json
import re
import statistics
import sys
import unicodedata

HEADER = re.compile(r'^\*\*(.+?)\*\* · ((?:\d+:)?\d+:\d{2})\s*$')
ANONYMOUS = re.compile(r'^speaker \d+$', re.IGNORECASE)


def normalizeToken(token):
    token = unicodedata.normalize('NFKC', token).lower()
    return re.sub(r"[^\w']+", '', token).strip("'")


def tokenize(text):
    return [token for token in (normalizeToken(word) for word in text.split()) if token]


def unescapeMarkdown(text):
    return re.sub(r'\\([\\*_`])', r'\1', text)


def parseTime(value):
    seconds = 0
    for part in value.split(':'):
        seconds = seconds * 60 + int(part)
    return float(seconds)


# Notice appended by Talk to the AI generated drafts; it is not part of any turn.
AI_NOTICE = re.compile(r'^(Transcript|Summary) is AI generated and may contain mistakes$')


def parseMarkdown(content):
    turns = []
    for line in content.splitlines():
        if AI_NOTICE.match(line.strip()):
            continue
        match = HEADER.match(line.strip())
        if match:
            turns.append({'name': unescapeMarkdown(match.group(1)), 'start': parseTime(match.group(2)), 'text': ''})
        elif turns and line.strip():
            turns[-1]['text'] = (turns[-1]['text'] + ' ' + unescapeMarkdown(line.strip())).strip()
    return turns


def parseTranscript(fileName):
    with open(fileName, encoding='utf-8') as transcriptFile:
        content = transcriptFile.read()
    if fileName.endswith('.json'):
        transcript = json.loads(content)
        speakers = {speaker['id']: speaker.get('displayName') or speaker['id'] for speaker in transcript.get('speakers', [])}
        return [{'name': speakers.get(turn['speaker'], turn['speaker']), 'start': float(turn['start']), 'text': turn['text']}
                for turn in transcript['turns']]
    return parseMarkdown(content)


def align(reference, hypothesis):
    """
    Levenshtein alignment of two token lists.

    :return: tuple with (substitutions, deletions, insertions) and the list of
             (referenceIndex, hypothesisIndex) pairs of equal tokens.
    """

    rows = len(reference) + 1
    columns = len(hypothesis) + 1
    costs = [[0] * columns for _ in range(rows)]
    for row in range(rows):
        costs[row][0] = row
    for column in range(columns):
        costs[0][column] = column
    for row in range(1, rows):
        for column in range(1, columns):
            same = reference[row - 1] == hypothesis[column - 1]
            costs[row][column] = min(
                costs[row - 1][column - 1] + (0 if same else 1),
                costs[row - 1][column] + 1,
                costs[row][column - 1] + 1,
            )

    substitutions = deletions = insertions = 0
    matches = []
    row, column = rows - 1, columns - 1
    while row > 0 or column > 0:
        if row > 0 and column > 0 and costs[row][column] == costs[row - 1][column - 1] + (0 if reference[row - 1] == hypothesis[column - 1] else 1):
            if reference[row - 1] == hypothesis[column - 1]:
                matches.append((row - 1, column - 1))
            else:
                substitutions += 1
            row, column = row - 1, column - 1
        elif row > 0 and costs[row][column] == costs[row - 1][column] + 1:
            deletions += 1
            row -= 1
        else:
            insertions += 1
            column -= 1

    matches.reverse()
    return (substitutions, deletions, insertions), matches


def evaluate(expected, transcriptTurns):
    names = {participant['name'].casefold() for participant in expected['participants']}

    referenceTokens = []
    for turnIndex, turn in enumerate(expected['turns']):
        referenceTokens += [(token, turnIndex) for token in tokenize(turn['text'])]

    hypothesisTokens = []
    for turnIndex, turn in enumerate(transcriptTurns):
        hypothesisTokens += [(token, turnIndex) for token in tokenize(turn['text'])]

    (substitutions, deletions, insertions), matches = align(
        [token for token, _ in referenceTokens],
        [token for token, _ in hypothesisTokens],
    )

    correctSpeaker = wrongSpeaker = unattributed = 0
    confusions = {}
    for referenceIndex, hypothesisIndex in matches:
        expectedName = expected['turns'][referenceTokens[referenceIndex][1]]['name']
        actualName = transcriptTurns[hypothesisTokens[hypothesisIndex][1]]['name']
        if actualName.casefold() == expectedName.casefold():
            correctSpeaker += 1
        elif ANONYMOUS.match(actualName) or actualName.casefold() not in names:
            unattributed += 1
            confusions[f'{expectedName} -> {actualName}'] = confusions.get(f'{expectedName} -> {actualName}', 0) + 1
        else:
            wrongSpeaker += 1
            confusions[f'{expectedName} -> {actualName}'] = confusions.get(f'{expectedName} -> {actualName}', 0) + 1

    # First matched word of each scripted turn and whether a transcript turn
    # starts with it.
    firstTokenOfTurn = {}
    for hypothesisIndex, (_, turnIndex) in enumerate(hypothesisTokens):
        firstTokenOfTurn.setdefault(turnIndex, hypothesisIndex)
    differences = {}
    for referenceIndex, hypothesisIndex in matches:
        expectedTurn = referenceTokens[referenceIndex][1]
        if expectedTurn in differences or (referenceIndex > 0 and referenceTokens[referenceIndex - 1][1] == expectedTurn):
            continue
        transcriptTurn = hypothesisTokens[hypothesisIndex][1]
        if firstTokenOfTurn[transcriptTurn] == hypothesisIndex:
            differences[expectedTurn] = transcriptTurns[transcriptTurn]['start'] - expected['turns'][expectedTurn]['start']

    offset = statistics.median(differences.values()) if differences else None
    residuals = sorted(abs(difference - offset) for difference in differences.values()) if differences else []

    matched = len(matches)
    return {
        'words': len(referenceTokens),
        'wer': round((substitutions + deletions + insertions) / max(1, len(referenceTokens)), 4),
        'substitutions': substitutions,
        'deletions': deletions,
        'insertions': insertions,
        'matchedWords': matched,
        'correctSpeakerRate': round(correctSpeaker / max(1, matched), 4),
        'wrongSpeakerRate': round(wrongSpeaker / max(1, matched), 4),
        'unattributedRate': round(unattributed / max(1, matched), 4),
        'speakerConfusions': dict(sorted(confusions.items(), key=lambda item: -item[1])),
        'turns': len(expected['turns']),
        'turnsWithOwnStart': len(differences),
        'globalOffset': None if offset is None else round(offset, 3),
        'turnStartErrorMean': round(statistics.mean(residuals), 3) if residuals else None,
        'turnStartErrorP90': round(residuals[int(0.9 * (len(residuals) - 1))], 3) if residuals else None,
        'turnStartErrorMax': round(residuals[-1], 3) if residuals else None,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('expected')
    parser.add_argument('transcript')
    parser.add_argument('--json', action='store_true', help='Print the metrics as JSON')
    parser.add_argument('--run', help='run.json written by run_call.py; its participant actor ids are mapped to their names')
    args = parser.parse_args()

    with open(args.expected, encoding='utf-8') as expectedFile:
        expected = json.load(expectedFile)

    transcriptTurns = parseTranscript(args.transcript)
    if args.run:
        with open(args.run, encoding='utf-8') as runFile:
            aliases = {participant['actorId']: participant['name']
                       for participant in json.load(runFile)['participants'] if participant.get('actorId')}
        for turn in transcriptTurns:
            turn['name'] = aliases.get(turn['name'], turn['name'])

    metrics = evaluate(expected, transcriptTurns)

    if args.json:
        json.dump(metrics, sys.stdout, indent=2)
        print()
        return

    print(f"Words:              {metrics['words']} (WER {metrics['wer']:.1%})")
    print(f"Correct speaker:    {metrics['correctSpeakerRate']:.1%}")
    print(f"Wrong speaker:      {metrics['wrongSpeakerRate']:.1%}")
    print(f"Unattributed:       {metrics['unattributedRate']:.1%}")
    for confusion, count in list(metrics['speakerConfusions'].items())[:10]:
        print(f'  {confusion}: {count} words')
    print(f"Turns with own start: {metrics['turnsWithOwnStart']}/{metrics['turns']}")
    if metrics['globalOffset'] is not None:
        print(f"Turn start error:   mean {metrics['turnStartErrorMean']} s, p90 {metrics['turnStartErrorP90']} s, "
              f"max {metrics['turnStartErrorMax']} s (offset {metrics['globalOffset']} s)")


if __name__ == '__main__':
    main()
