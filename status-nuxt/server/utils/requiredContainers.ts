import { execSync } from 'child_process'

/**
 * A suite that starts without the services it depends on does not fail honestly: it
 * runs to the end and reports errors that look like the code under test is broken.
 * The Laravel suite did exactly that with spatial-knn down - 5389 passed, 31 errors,
 * every one of them "Resolving timed out ... http://spatial-knn:8195". So refuse to
 * start instead, and say which container is missing.
 *
 * "Up" is not enough on its own: a container that declares a healthcheck is only
 * ready when it reports healthy, and percona in particular accepts connections some
 * seconds after it starts. Containers with no healthcheck are judged on Up alone.
 */
export interface ContainerReadiness {
  ok: boolean
  problems: string[]
}

export function checkContainersReady(
  prefix: string,
  services: string[]
): ContainerReadiness {
  const problems: string[] = []

  for (const service of services) {
    const container = `${prefix}-${service}`

    let status = ''
    try {
      status = execSync(
        `docker ps --filter "name=^/${container}$" --format "{{.Status}}"`,
        { encoding: 'utf8', timeout: 5000 }
      ).trim()
    } catch (e: any) {
      problems.push(`${container}: could not ask docker (${e.message})`)
      continue
    }

    if (!status) {
      problems.push(`${container}: not running (docker-compose up -d ${service})`)
      continue
    }
    if (!status.startsWith('Up')) {
      problems.push(`${container}: ${status}`)
      continue
    }

    // Health, where the container declares it. `docker inspect` prints an empty
    // string for a container without a healthcheck, which is not a problem.
    let health = ''
    try {
      health = execSync(
        `docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' ${container}`,
        { encoding: 'utf8', timeout: 5000 }
      ).trim()
    } catch {
      // Inspect failing after ps succeeded means the container went away mid-check;
      // the ps result above is the more useful thing to report, so let it pass.
    }

    if (health && health !== 'healthy') {
      problems.push(`${container}: health is ${health}, not healthy`)
    }
  }

  return { ok: problems.length === 0, problems }
}

/**
 * Human-readable refusal, listing every missing service rather than the first, so one
 * `docker-compose up -d` fixes the lot.
 */
export function containersNotReadyMessage(
  suite: string,
  readiness: ContainerReadiness
): string {
  return (
    `Cannot run the ${suite} suite - required containers are not ready:\n` +
    readiness.problems.map((p) => `  - ${p}`).join('\n') +
    `\nStart them and try again. Running without them produces errors that look ` +
    `like test failures but are not.`
  )
}
