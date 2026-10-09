// Order job ads at random, but weighted by pay, so better-paid jobs are likelier to come
// first while repeated slots on a page still differ. Weighted sampling without replacement
// (Efraimidis-Spirakis): each job gets the key u^(1/cpc) with u uniform in (0,1], and the
// highest keys come first. It is the same weighting the email digest uses
// (iznik-batch Job::nearLocation), so web and digest favour pay the same way.
//
// A job with no cpc gets a tiny weight rather than none, so it can still be shown.
const MIN_WEIGHT = 1e-6

export function payWeightedOrder(jobs, random = Math.random) {
  return (jobs || [])
    .map((job) => {
      const weight = Math.max(Number(job?.cpc) || 0, MIN_WEIGHT)
      const u = 1 - random() // (0,1], so the key is never 0^x
      return { job, key: Math.pow(u, 1 / weight) }
    })
    .sort((a, b) => b.key - a.key)
    .map((entry) => entry.job)
}
