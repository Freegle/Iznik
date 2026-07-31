// Bound the workflow instance store. The driver creates a FRESH instance every run and
// never resumes an old one ("Fresh instance per driver run" in driver.ts), so completed /
// errored / abandoned instances just accumulate in the JSON store. Left unchecked it bloated
// to ~100MB / 1165 instances, which slows every load+save and eventually killed the loop.
// Keep only the `keep` most recent instances (by updatedAt); delete the rest.

export interface PrunableInstance {
  id: string
  updatedAt: string
}

export interface InstanceLister {
  listInstances(): Promise<PrunableInstance[]>
}

export interface InstanceDeleter {
  deleteInstance(id: string): Promise<void>
}

/**
 * Delete all but the `keep` most-recent instances (by updatedAt). Returns the number
 * deleted. Best-effort per delete: a single failing deleteInstance doesn't abort the rest.
 * The caller runs this at startup BEFORE creating the run's new instance, so the current
 * run is never a candidate for deletion.
 */
export async function pruneInstances(
  lister: InstanceLister,
  deleter: InstanceDeleter,
  keep = 20,
): Promise<number> {
  const existing = await lister.listInstances()
  if (existing.length <= keep) {
    return 0
  }

  const stale = existing
    .slice()
    // Most recent first; missing/empty updatedAt sorts last (oldest), so it's pruned first.
    .sort((a, b) => (b.updatedAt ?? '').localeCompare(a.updatedAt ?? ''))
    .slice(keep)

  let deleted = 0
  for (const inst of stale) {
    try {
      await deleter.deleteInstance(inst.id)
      deleted++
    } catch {
      // Best-effort: keep going so one bad row doesn't leave the store bloated.
    }
  }
  return deleted
}
