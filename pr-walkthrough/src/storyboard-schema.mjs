// The storyboard is the contract between `analyze` (decide what to show) and `render`
// (draw it). Keep validation dependency-free so it runs anywhere (tests, CLI, CI).
//
// A storyboard: { meta, scenes[] }. Coordinates (focus, callout boxes, pan targets)
// are FRACTIONS of the referenced image's natural size, in [0, 1], so they are
// resolution-independent and authorable by eye.

const SCENE_TYPES = new Set(['title', 'narration', 'screenshot', 'code', 'outro']);
const ARROWS = new Set(['left', 'right', 'up', 'down', 'none']);

function fail(errors, path, msg) {
  errors.push(`${path}: ${msg}`);
}

function isFrac(v) {
  return typeof v === 'number' && v >= 0 && v <= 1;
}

function checkBox(errors, path, box) {
  if (typeof box !== 'object' || box === null) {
    fail(errors, path, 'must be an object {x,y,w,h}');
    return;
  }
  for (const k of ['x', 'y', 'w', 'h']) {
    if (!isFrac(box[k])) fail(errors, `${path}.${k}`, 'must be a fraction in [0,1]');
  }
  if (typeof box.x === 'number' && typeof box.w === 'number' && box.x + box.w > 1.0001) {
    fail(errors, path, `x+w (${box.x + box.w}) exceeds 1`);
  }
  if (typeof box.y === 'number' && typeof box.h === 'number' && box.y + box.h > 1.0001) {
    fail(errors, path, `y+h (${box.y + box.h}) exceeds 1`);
  }
}

/**
 * Validate a storyboard object. Returns { ok, errors }.
 * `assetExists(src)` is an optional predicate used to verify screenshot files exist.
 */
export function validateStoryboard(sb, assetExists = null) {
  const errors = [];

  if (typeof sb !== 'object' || sb === null) {
    return { ok: false, errors: ['storyboard must be an object'] };
  }

  const meta = sb.meta;
  if (typeof meta !== 'object' || meta === null) {
    fail(errors, 'meta', 'is required');
  } else {
    if (meta.width != null && (typeof meta.width !== 'number' || meta.width <= 0)) {
      fail(errors, 'meta.width', 'must be a positive number');
    }
    if (meta.height != null && (typeof meta.height !== 'number' || meta.height <= 0)) {
      fail(errors, 'meta.height', 'must be a positive number');
    }
    if (meta.fps != null && (typeof meta.fps !== 'number' || meta.fps <= 0)) {
      fail(errors, 'meta.fps', 'must be a positive number');
    }
  }

  if (!Array.isArray(sb.scenes) || sb.scenes.length === 0) {
    fail(errors, 'scenes', 'must be a non-empty array');
    return { ok: errors.length === 0, errors };
  }

  sb.scenes.forEach((scene, i) => {
    const p = `scenes[${i}]`;
    if (typeof scene !== 'object' || scene === null) {
      fail(errors, p, 'must be an object');
      return;
    }
    if (!SCENE_TYPES.has(scene.type)) {
      fail(errors, `${p}.type`, `must be one of ${[...SCENE_TYPES].join(', ')}`);
    }
    if (typeof scene.seconds !== 'number' || scene.seconds <= 0) {
      fail(errors, `${p}.seconds`, 'must be a positive number');
    }

    if (scene.type === 'screenshot') {
      if (typeof scene.src !== 'string' || !scene.src) {
        fail(errors, `${p}.src`, 'is required for screenshot scenes');
      } else if (assetExists && !assetExists(scene.src)) {
        fail(errors, `${p}.src`, `asset not found: ${scene.src}`);
      }
      if (scene.focus) checkBox(errors, `${p}.focus`, scene.focus);
      if (scene.pan != null && !['down', 'up', 'none'].includes(scene.pan)) {
        fail(errors, `${p}.pan`, 'must be one of down, up, none');
      }
      if (scene.callouts != null) {
        if (!Array.isArray(scene.callouts)) {
          fail(errors, `${p}.callouts`, 'must be an array');
        } else {
          scene.callouts.forEach((c, j) => {
            const cp = `${p}.callouts[${j}]`;
            // A callout positions itself by an explicit fractional `box`, OR by `ref` — the
            // label of a tool-measured box (resolved from <shot>.boxes.json at render time).
            if (c.box) checkBox(errors, `${cp}.box`, c.box);
            else if (typeof c.ref !== 'string' || !c.ref) {
              fail(errors, cp, 'needs either `box` (fractions) or `ref` (a measured-box label)');
            }
            if (typeof c.at !== 'number' || c.at < 0) fail(errors, `${cp}.at`, 'must be >= 0');
            if (typeof c.until !== 'number' || c.until <= c.at) {
              fail(errors, `${cp}.until`, 'must be greater than `at`');
            }
            if (c.until > scene.seconds + 0.001) {
              fail(errors, `${cp}.until`, `exceeds scene length (${scene.seconds}s)`);
            }
            if (c.arrow != null && !ARROWS.has(c.arrow)) {
              fail(errors, `${cp}.arrow`, `must be one of ${[...ARROWS].join(', ')}`);
            }
            if (!c.ref && (typeof c.label !== 'string' || !c.label)) {
              fail(errors, `${cp}.label`, 'is required (or use `ref` to inherit the measured label)');
            }
          });
        }
      }
    }

    if (scene.type === 'code') {
      if (typeof scene.code !== 'string' || !scene.code) {
        fail(errors, `${p}.code`, 'is required for code scenes');
      }
      if (scene.highlight != null && !Array.isArray(scene.highlight)) {
        fail(errors, `${p}.highlight`, 'must be an array of line numbers');
      }
    }
  });

  return { ok: errors.length === 0, errors };
}

/** Total composition length in frames, accounting for cross-fade overlaps. */
export function totalDurationInFrames(sb, fps, transitionFrames) {
  const sceneFrames = sb.scenes.map((s) => Math.round(s.seconds * fps));
  const sum = sceneFrames.reduce((a, b) => a + b, 0);
  const overlaps = Math.max(0, sb.scenes.length - 1) * transitionFrames;
  return { sceneFrames, total: Math.max(1, sum - overlaps) };
}
