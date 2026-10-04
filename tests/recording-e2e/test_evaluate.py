#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#

# pylint: disable=missing-docstring

from evaluate import evaluate, parseMarkdown

EXPECTED = {
    'participants': [{'id': 'alice', 'name': 'Alice'}, {'id': 'bob', 'name': 'Bob'}],
    'turns': [
        {'name': 'Alice', 'start': 1.0, 'text': 'Good morning everyone.'},
        {'name': 'Bob', 'start': 4.0, 'text': 'Thanks Alice, the budget is ready.'},
        {'name': 'Alice', 'start': 9.0, 'text': 'Great, thank you.'},
    ],
}


def testParseMarkdown():
    turns = parseMarkdown('**Alice** · 00:03\nGood morning\n\n**Ana\\_Maria** · 01:02:03\nHello\nagain\n\nTranscript is AI generated and may contain mistakes\n')

    assert turns == [
        {'name': 'Alice', 'start': 3.0, 'text': 'Good morning'},
        {'name': 'Ana_Maria', 'start': 3723.0, 'text': 'Hello again'},
    ]


def testPerfectTranscript():
    transcript = [
        {'name': 'Alice', 'start': 3.0, 'text': 'Good morning, everyone!'},
        {'name': 'Bob', 'start': 6.0, 'text': 'Thanks, Alice. The budget is ready.'},
        {'name': 'Alice', 'start': 11.0, 'text': 'Great, thank you.'},
    ]

    metrics = evaluate(EXPECTED, transcript)

    assert metrics['wer'] == 0
    assert metrics['correctSpeakerRate'] == 1
    assert metrics['turnsWithOwnStart'] == 3
    assert metrics['globalOffset'] == 2
    assert metrics['turnStartErrorMax'] == 0


def testWrongAndAnonymousSpeakers():
    transcript = [
        {'name': 'Alice', 'start': 1.0, 'text': 'Good morning everyone thanks Alice'},
        {'name': 'Speaker 1', 'start': 5.0, 'text': 'the budget is ready'},
        {'name': 'Alice', 'start': 9.0, 'text': 'great thank you'},
    ]

    metrics = evaluate(EXPECTED, transcript)

    assert metrics['wer'] == 0
    assert metrics['wrongSpeakerRate'] == round(2 / 12, 4)
    assert metrics['unattributedRate'] == round(4 / 12, 4)
    assert metrics['speakerConfusions'] == {'Bob -> Speaker 1': 4, 'Bob -> Alice': 2}
    # Bob's turn does not start a transcript turn.
    assert metrics['turnsWithOwnStart'] == 2


def testWordErrors():
    transcript = [
        {'name': 'Alice', 'start': 1.0, 'text': 'Good morning every one.'},
        {'name': 'Bob', 'start': 4.0, 'text': 'Thanks Alice, the budget is ready.'},
        {'name': 'Alice', 'start': 9.0, 'text': 'Great.'},
    ]

    metrics = evaluate(EXPECTED, transcript)

    # "everyone" -> "every one" (1 substitution + 1 insertion), "thank you" deleted.
    assert (metrics['substitutions'], metrics['insertions'], metrics['deletions']) == (1, 1, 2)
    assert metrics['wer'] == round(4 / 12, 4)
