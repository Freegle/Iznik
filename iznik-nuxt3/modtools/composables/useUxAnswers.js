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
