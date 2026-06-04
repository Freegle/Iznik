// A capture plan tells `capture.mjs` how to drive a LIVE, already-running app to each
// screen we want to film, and what to screenshot. It is derived from the PR's flows —
// especially its Playwright/E2E tests, which encode the journeys worth showing.
//
// Selectors accept three forms:
//   "testid=clearance-title"  → [data-testid="clearance-title"]   (preferred — stable)
//   "text=Add these items"    → Playwright getByText (visible text)
//   any other string          → a raw CSS selector
//
// A shot:
//   { name, route, viewport?, fullPage?, clip?(selector), steps[] }
// A step is an object with exactly one action key:
//   {goto}, {fill,value}, {type,value}, {click}, {clickText}, {press},
//   {waitFor}, {waitForText}, {waitMs}, {scrollTo}, {setViewport:{width,height}}

const STEP_KEYS = new Set([
  'goto', 'fill', 'type', 'select', 'click', 'clickText', 'press',
  'waitFor', 'waitForText', 'waitMs', 'scrollTo', 'setViewport',
]);

function fail(errors, path, msg) {
  errors.push(`${path}: ${msg}`);
}

export function validateCapturePlan(plan) {
  const errors = [];
  if (typeof plan !== 'object' || plan === null) return { ok: false, errors: ['plan must be an object'] };

  if (plan.baseUrl != null && typeof plan.baseUrl !== 'string') fail(errors, 'baseUrl', 'must be a string');
  if (!Array.isArray(plan.shots) || plan.shots.length === 0) {
    fail(errors, 'shots', 'must be a non-empty array');
    return { ok: errors.length === 0, errors };
  }

  const names = new Set();
  plan.shots.forEach((shot, i) => {
    const p = `shots[${i}]`;
    if (typeof shot !== 'object' || shot === null) { fail(errors, p, 'must be an object'); return; }
    if (typeof shot.name !== 'string' || !/\.(png|jpe?g)$/i.test(shot.name)) {
      fail(errors, `${p}.name`, 'must be a filename ending .png/.jpg');
    } else if (names.has(shot.name)) {
      fail(errors, `${p}.name`, `duplicate shot name: ${shot.name}`);
    } else {
      names.add(shot.name);
    }
    if (typeof shot.route !== 'string' || !shot.route) fail(errors, `${p}.route`, 'is required');
    if (shot.viewport) {
      if (!(shot.viewport.width > 0 && shot.viewport.height > 0)) fail(errors, `${p}.viewport`, 'needs positive width/height');
    }
    if (shot.steps != null) {
      if (!Array.isArray(shot.steps)) { fail(errors, `${p}.steps`, 'must be an array'); } else {
        shot.steps.forEach((step, j) => {
          const sp = `${p}.steps[${j}]`;
          if (typeof step !== 'object' || step === null) { fail(errors, sp, 'must be an object'); return; }
          const keys = Object.keys(step).filter((k) => STEP_KEYS.has(k));
          if (keys.length === 0) fail(errors, sp, `unknown step (expected one of ${[...STEP_KEYS].join(', ')})`);
          if ((step.fill != null || step.type != null || step.select != null) && typeof step.value !== 'string') {
            fail(errors, sp, 'fill/type/select needs a string `value`');
          }
        });
      }
    }
  });

  return { ok: errors.length === 0, errors };
}
