// Which experiments are waiting for a person now that they have a verdict, and what to tell them.
// The rule is the one the Go API counts for the SysAdmin red number (ux.VerdictCounts):
//   adopted, not closed                                     -> confirm the result, remove the switch
//   reverted, inconclusive or stopped, no cleanup recorded  -> remove the variant code
// An experiment that is closed, or has a cleanup pull request, is done.

const ENDED = ['reverted', 'inconclusive', 'stopped']

// 'confirm', 'remove' or null.
export function verdictOf(experiment) {
  if (!experiment || experiment.cleanup_pr_url) return null
  if (experiment.status === 'adopted') return 'confirm'
  if (ENDED.includes(experiment.status)) return 'remove'
  return null
}

// { confirm, remove, total } over every experiment of the experiments response.
export function verdictCounts(flows) {
  const counts = { confirm: 0, remove: 0, total: 0 }
  for (const f of flows || []) {
    for (const e of [...(f.running || []), ...(f.history || [])]) {
      const v = verdictOf(e)
      if (v) {
        counts[v]++
        counts.total++
      }
    }
  }
  return counts
}

// "2 experiments have a verdict: 1 to confirm, 1 to remove code".
export function verdictTitle(confirm, remove) {
  const total = (confirm || 0) + (remove || 0)
  if (!total) return ''
  const parts = []
  if (confirm) parts.push(`${confirm} to confirm`)
  if (remove) parts.push(`${remove} to remove code`)
  return `${total} experiment${total === 1 ? ' has' : 's have'} a verdict: ${parts.join(', ')}`
}

// Said on the row: short, and the same words as the button the person will press.
export function verdictRowText(experiment) {
  const v = verdictOf(experiment)
  if (v === 'confirm') return 'Confirm, remove the switch, then close it'
  if (v === 'remove') return 'Remove the variant code, then close it'
  return ''
}

// Said in the open experiment: what to do next, and the button or command that closes it. The
// command takes the short name, which is what the batch's own sentences use too.
export function verdictNextStep(experiment) {
  const v = verdictOf(experiment)
  const name = experiment.name || '<name>'
  if (v === 'confirm') {
    return `Waiting for you. Confirm the result, remove the switch from the code. The pull request that removes it can add a line to iznik-batch/config/ux_cleanups.php, and the batch closes it within the hour of its deploy. Or press Mark as cleaned up, or run ux:experiment close ${name} --cleanup-pr=<url>.`
  }
  if (v === 'remove') {
    return `Waiting for you. Remove the variant code. The pull request that removes it can add a line to iznik-batch/config/ux_cleanups.php, and the batch closes it within the hour of its deploy. Or paste the pull request below and press Mark as cleaned up, or run ux:experiment close ${name} --cleanup-pr=<url>.`
  }
  return ''
}
