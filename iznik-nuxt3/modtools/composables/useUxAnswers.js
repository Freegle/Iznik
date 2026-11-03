// What members said, in figures and sentences for the What members say section of the SysAdmin UX tab.
// Pure functions that import nothing, so each is tested alone. The sentences give every figure its base:
// "14" is always "14 of the 120 written answers".

// Below this many answers a figure is a count out of its base and never a percentage. The members who
// answered chose to answer, and a share of a few people says more than the answers hold.
export const PERCENT_MIN_ANSWERS = 100

// "18 of 40" below the threshold, "18 of 120 (15%)" at or above it. The base is the number who
// answered (or, for one option in a ranking, the number of times it was shown, in which case
// `answers` is still how many members answered, because that is what decides whether a percentage
// is fair).
export function ofAnswered(n, total, answers = total) {
  const count = Number(n) || 0
  const base = Number(total) || 0
  if (!base) return String(count)
  const of = `${count.toLocaleString()} of ${base.toLocaleString()}`
  if ((Number(answers) || 0) < PERCENT_MIN_ANSWERS) return of
  return `${of} (${Math.round((count / base) * 100)}%)`
}

/** A number with its noun: "1 problem", "3 problems", "1,200 answers". */
export function countOf(n, one, many = `${one}s`) {
  const v = Number(n) || 0
  return `${v.toLocaleString()} ${v === 1 ? one : many}`
}

// The base of a problem or a value is the number of written answers that were analysed for the question
// it came from. It is not the number of members who answered that question: those also hold the ones
// who wrote nothing, or who did not understand it, and the Questions view counts them. So the sentences
// below say "written answers", and "who answered" is left to the questions.

/**
 * "14 of 45 written answers" below 100 answers, "14 of 120 written answers, 12%" from 100. For a line
 * that is already in brackets, where ofAnswered would put a percentage in brackets inside them. With no
 * base (a problem that did not come from a question) there is no "of": "14 members".
 */
export function ofWrittenAnswers(n, answers) {
  const count = Number(n) || 0
  const base = Number(answers) || 0
  if (!(base > 0)) return countOf(count, 'member')
  const pct =
    base >= PERCENT_MIN_ANSWERS ? `, ${Math.round((count / base) * 100)}%` : ''
  return `${count.toLocaleString()} of ${countOf(base, 'written answer')}${pct}`
}

/**
 * "18 of 45" or "18 of 120 (15%)" for a count with a base, and "18 members" for one with none. A
 * problem or a value that did not come from a question has no base, and a bare number there would have
 * no unit.
 */
export function mentionCount(n, answers) {
  return Number(answers) > 0 ? ofAnswered(n, answers) : countOf(n, 'member')
}

/**
 * How many written answers named a problem, out of the written answers analysed for the question it came
 * from. With no base (a problem that did not come from a question) there is no "of", only the count.
 */
export function mentionedIn(mentions, answers) {
  const n = Number(mentions) || 0
  const base = Number(answers) || 0
  if (!(base > 0)) return `Mentioned by ${countOf(n, 'member')}.`
  const pct =
    base >= PERCENT_MIN_ANSWERS ? ` (${Math.round((n / base) * 100)}%)` : ''
  return `Mentioned in ${n.toLocaleString()} of the ${countOf(base, 'written answer')}${pct}.`
}

/**
 * How other members ranked a problem in the follow-up question, or an empty string when none has
 * been asked about it yet. `times_shown` is how often it was on the screen and `most` how often it
 * was picked as the biggest problem. The percentage is added from 100 members asked.
 */
export function rankedLine(problem) {
  const shown = Number(problem?.times_shown)
  if (!(shown > 0)) return ''
  const picked = Number(problem.most) || 0
  const pct =
    (Number(problem.asked) || 0) >= PERCENT_MIN_ANSWERS
      ? ` (${Math.round((picked / shown) * 100)}%)`
      : ''
  return `Other members were shown it ${countOf(shown, 'time')} and ${picked.toLocaleString()} picked it as the biggest problem${pct}.`
}

// What kind of question a row of the questions route is. A survey whose options are keyed problem:<id>
// is the follow-up that ranks the biggest problems. Any other survey is the area survey. No name uses the
// word open: Open and Closed are the state of a question, and sit beside its kind.
export const KIND_LABELS = {
  problems: 'Problem ranking',
  areas: 'Area ranking',
  awareness: 'Awareness',
  open: 'Written answers',
}

export function kindOf(question) {
  if (question?.kind === 'open') return 'open'
  if (question?.kind === 'awareness') return 'awareness'
  return (question?.tallies || []).some((t) => /^problem:/.test(t.key))
    ? 'problems'
    : 'areas'
}

export const kindLabel = (question) => KIND_LABELS[kindOf(question)]

// ---- the balance of the area survey ----

// Each member is shown four of the seven areas and picks the one that works best, then (if they want to)
// one more that most needs improving. The balance of an area is the first count less the second: the
// number of members who picked it as working best, less the number who picked it as most needing
// improvement. It is a count and is never a percentage. The balance of the whole survey is not added
// up: the second pick is optional, so there are always fewer of those than of the first and the total
// would come out good whatever members felt.

// The minus sign (U+2212), which a screen reader says as "minus". A hyphen is often read as "dash". Built
// from its code so that nobody mistakes it for a hyphen when reading the source.
export const MINUS = String.fromCharCode(0x2212)

/** "+17", "minus 17" written with the minus sign, or "0". */
export function signed(n) {
  const v = Math.round(Number(n) || 0)
  if (v > 0) return `+${v.toLocaleString()}`
  if (v < 0) return `${MINUS}${Math.abs(v).toLocaleString()}`
  return '0'
}

/** What a balance means, in words, so it never rests on colour or on a sign alone. */
export function balanceWord(best, improve) {
  const net = (Number(best) || 0) - (Number(improve) || 0)
  if (net > 0) return 'works well'
  if (net < 0) return 'needs improving'
  return best || improve ? 'even' : 'no picks'
}

// The part of a half-width bar that a bar of this size fills. A bar for something that was picked is
// never so short that it cannot be seen.
const MIN_BAR = 3

/**
 * The rows of the balance list: the lowest balance first, which is the area that most needs looking
 * at. `tallies` is the survey's tallies, `answered` the members who answered. Each option is judged
 * against the times it was on screen where the server says (each member sees only some of the areas),
 * and against the members who answered where it does not. The sort is on the balance as a share of
 * that base, so two areas shown a different number of times are fairly ranked. A tie goes to the
 * area with more picks for improvement, then by name, so the order is the same every time.
 *
 * `bestWidth` and `improveWidth` are the length of each side of the bar, 0 to 100 (of its half), on one
 * scale for the whole list: the longest side is the full half.
 */
export function areaBalances(tallies, answered) {
  const rows = (tallies || []).map((t) => {
    const best = Number(t.most) || 0
    const improve = Number(t.least) || 0
    const shown = Number(t.times_shown) > 0 ? Number(t.times_shown) : 0
    const base = shown || Number(answered) || 0
    const net = best - improve
    return {
      key: t.key,
      label: t.label,
      best,
      improve,
      shown,
      base,
      net,
      share: base ? net / base : 0,
      bestShare: base ? best / base : 0,
      improveShare: base ? improve / base : 0,
      word: balanceWord(best, improve),
      tone:
        net > 0 ? 'good' : net < 0 ? 'bad' : best || improve ? 'even' : 'none',
    }
  })
  const longest = Math.max(
    0,
    ...rows.map((r) => Math.max(r.bestShare, r.improveShare))
  )
  const width = (share) =>
    share > 0 && longest > 0
      ? Math.max(MIN_BAR, Math.round((share / longest) * 1000) / 10)
      : 0
  return rows
    .map((r) => ({
      ...r,
      bestWidth: width(r.bestShare),
      improveWidth: width(r.improveShare),
    }))
    .sort(
      (a, b) =>
        a.share - b.share ||
        b.improve - a.improve ||
        a.label.localeCompare(b.label)
    )
}

// "A", "A and B", "A, B and C", then "A, B, C and 2 more".
function nameList(rows) {
  const names = rows.map((r) => r.label)
  if (names.length <= 1) return names.join('')
  if (names.length === 2) return `${names[0]} and ${names[1]}`
  if (names.length === 3) {
    return `${names[0]}, ${names[1]} and ${names[2]}`
  }
  return `${names[0]}, ${names[1]}, ${names[2]} and ${names.length - 3} more`
}

/**
 * Two sentences for the top of the balance list. The first names the area with the best balance and the
 * one with the lowest (all of them, when they are level), each with its balance and what it is out of.
 * The second gives the base: how many picked an area that works best, and how many of those also picked
 * one that most needs improving. It is the second that shows why the total is not a balance. Empty
 * when nobody has picked anything.
 */
export function balanceSummary(rows) {
  const all = rows || []
  if (!all.some((r) => r.best || r.improve)) return ''
  // An area nobody picked either way has a balance of nothing, and is still the lowest when every
  // area that was picked is good. One that was never on screen has nothing to compare.
  const scored = all.filter((r) => r.base > 0)
  if (!scored.length) return ''
  const withBest = all.reduce((t, r) => t + r.best, 0)
  const withImprove = all.reduce((t, r) => t + r.improve, 0)
  const top = Math.max(...scored.map((r) => r.share))
  const bottom = Math.min(...scored.map((r) => r.share))
  const at = (share) => scored.filter((r) => r.share === share)
  const show = (list) =>
    `${signed(list[0].net)} of ${list[0].base.toLocaleString()}`
  let first
  if (top === bottom) {
    first = 'The areas are level.'
  } else {
    const best = at(top)
    const low = at(bottom)
    first = `${nameList(best)} ${best.length > 1 ? 'have' : 'has'} the best balance (${show(best)}). ${nameList(low)} ${low.length > 1 ? 'have' : 'has'} the lowest (${show(low)}).`
  }
  return `${first} ${withBest.toLocaleString()} picked an area that works best, and ${withImprove.toLocaleString()} of them also picked one that most needs improving.`
}

/**
 * Where the written-answer questions stand, for a list that is empty. `questions` is `open_questions`
 * from the server: counts only. A server that does not send it (the frontend is live before the Go
 * change) gives `undefined`, and then `fallback` is the whole answer. One sentence for each question.
 */
export function standingText(
  questions,
  fallback,
  none = 'No question has been asked yet.'
) {
  if (!Array.isArray(questions)) return fallback
  if (!questions.length) return none
  return questions
    .map((q) => {
      const name = `"${q.question}"`
      const written = Number(q.written) || 0
      if (written > 0 && q.analysed) {
        return `${countOf(written, 'written answer')} to ${name} ${written === 1 ? 'was' : 'were'} analysed and nothing came out of ${written === 1 ? 'it' : 'them'}.`
      }
      if (written > 0) {
        return `${countOf(written, 'written answer')} to ${name} ${written === 1 ? 'is' : 'are'} waiting to be analysed. Someone runs ux:prompt analyse ${q.prompt_id} to sort ${written === 1 ? 'it' : 'them'}.`
      }
      const responses = Number(q.responses) || 0
      if (!responses) return `Nobody has answered ${name} yet.`
      const parts = []
      if (q.nothing) {
        parts.push(
          `${q.nothing.toLocaleString()} said "${q.nothing_label || 'nothing'}"`
        )
      }
      if (q.dont_understand) {
        parts.push(
          `${q.dont_understand.toLocaleString()} did not understand it`
        )
      }
      if (q.skipped) parts.push(`${q.skipped.toLocaleString()} skipped`)
      return `No written answers yet. ${countOf(responses, 'member')} answered ${name}${parts.length ? ': ' + parts.join(', ') : ''}.`
    })
    .join(' ')
}
