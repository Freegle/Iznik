// DOM Visual Inspector — returns structured layout data instead of a screenshot.
// Run via: evaluate_script with this file's contents.
// Returns: element tree with bounding boxes, computed visual styles, visibility,
// text content, and overlap detection. ~200-500 text tokens vs ~2765 image tokens.
//
// Usage modes (set window.__inspectMode before running):
//   "layout"   — all visible elements with bounding boxes and key styles (default)
//   "element"  — deep inspect a single element: window.__inspectSelector = ".my-class"
//   "diff"     — compare current state against a baseline: window.__inspectBaseline = {...}
//   "problems" — detect visual problems: overlaps, overflow, invisible elements with content

(() => {
  const mode = window.__inspectMode || 'layout';
  const selector = window.__inspectSelector || null;
  const maxElements = 60;

  function getVisualProps(el) {
    const cs = getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) return null;

    return {
      tag: el.tagName.toLowerCase(),
      id: el.id || undefined,
      class: el.className ? String(el.className).split(/\s+/).slice(0, 3).join(' ') : undefined,
      text: el.textContent?.trim().slice(0, 80) || undefined,
      box: {
        x: Math.round(rect.x),
        y: Math.round(rect.y),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
      },
      style: {
        display: cs.display,
        visibility: cs.visibility,
        opacity: cs.opacity !== '1' ? cs.opacity : undefined,
        position: cs.position !== 'static' ? cs.position : undefined,
        zIndex: cs.zIndex !== 'auto' ? cs.zIndex : undefined,
        color: cs.color,
        bg: cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : undefined,
        fontSize: cs.fontSize,
        overflow: cs.overflow !== 'visible' ? cs.overflow : undefined,
        gap: cs.gap !== 'normal' ? cs.gap : undefined,
        padding: cs.padding !== '0px' ? cs.padding : undefined,
        margin: cs.margin !== '0px' ? cs.margin : undefined,
      },
    };
  }

  function cleanUndefined(obj) {
    if (typeof obj !== 'object' || obj === null) return obj;
    if (Array.isArray(obj)) return obj.map(cleanUndefined);
    const clean = {};
    for (const [k, v] of Object.entries(obj)) {
      if (v !== undefined) clean[k] = cleanUndefined(v);
    }
    return clean;
  }

  if (mode === 'element' && selector) {
    const el = document.querySelector(selector);
    if (!el) return JSON.stringify({ error: `Element not found: ${selector}` });

    const props = getVisualProps(el);
    const children = Array.from(el.children).slice(0, 20).map(getVisualProps).filter(Boolean);

    return JSON.stringify(cleanUndefined({
      mode: 'element',
      selector,
      viewport: { w: window.innerWidth, h: window.innerHeight },
      element: props,
      children,
      computedCSS: (() => {
        const cs = getComputedStyle(el);
        const important = [
          'display', 'flexDirection', 'alignItems', 'justifyContent',
          'gridTemplateColumns', 'gridTemplateRows',
          'width', 'height', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight',
          'border', 'borderRadius', 'boxShadow',
          'transform', 'transition', 'animation',
        ];
        const result = {};
        for (const prop of important) {
          const val = cs.getPropertyValue(prop.replace(/[A-Z]/g, m => '-' + m.toLowerCase()));
          if (val && val !== 'none' && val !== 'normal' && val !== '0px' && val !== 'auto') {
            result[prop] = val;
          }
        }
        return result;
      })(),
    }), null, 2);
  }

  if (mode === 'problems') {
    const problems = [];
    const allEls = document.querySelectorAll('*');
    const rects = [];

    for (const el of allEls) {
      const cs = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) continue;

      // Hidden element with text content
      if ((cs.visibility === 'hidden' || cs.opacity === '0' || cs.display === 'none') &&
          el.textContent?.trim().length > 0 && el.children.length === 0) {
        problems.push({
          type: 'hidden-with-content',
          selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className ? '.' + String(el.className).split(/\s+/)[0] : ''),
          text: el.textContent.trim().slice(0, 60),
          reason: cs.display === 'none' ? 'display:none' : cs.visibility === 'hidden' ? 'visibility:hidden' : `opacity:${cs.opacity}`,
        });
      }

      // Overflow clipping content
      if ((cs.overflow === 'hidden' || cs.overflow === 'clip') &&
          (el.scrollHeight > rect.height + 2 || el.scrollWidth > rect.width + 2)) {
        problems.push({
          type: 'overflow-clipped',
          selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
          box: { w: Math.round(rect.width), h: Math.round(rect.height) },
          scrollSize: { w: el.scrollWidth, h: el.scrollHeight },
        });
      }

      // Collect rects for overlap detection (only meaningful elements)
      if (rect.width > 10 && rect.height > 10 && cs.position !== 'static') {
        rects.push({ el, rect, z: parseInt(cs.zIndex) || 0 });
      }
    }

    // Check for overlapping positioned elements
    for (let i = 0; i < Math.min(rects.length, 50); i++) {
      for (let j = i + 1; j < Math.min(rects.length, 50); j++) {
        const a = rects[i].rect, b = rects[j].rect;
        const overlap = !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom);
        if (overlap && Math.abs(rects[i].z - rects[j].z) > 0) {
          problems.push({
            type: 'overlap',
            elements: [
              rects[i].el.tagName.toLowerCase() + (rects[i].el.id ? '#' + rects[i].el.id : ''),
              rects[j].el.tagName.toLowerCase() + (rects[j].el.id ? '#' + rects[j].el.id : ''),
            ],
            zIndices: [rects[i].z, rects[j].z],
          });
        }
      }
    }

    return JSON.stringify(cleanUndefined({
      mode: 'problems',
      viewport: { w: window.innerWidth, h: window.innerHeight },
      problemCount: problems.length,
      problems: problems.slice(0, 20),
    }), null, 2);
  }

  // Default: layout mode
  const viewport = { w: window.innerWidth, h: window.innerHeight, scrollY: Math.round(window.scrollY) };

  // Get meaningful visible elements (not every div)
  const meaningful = 'h1,h2,h3,h4,h5,h6,p,a,button,input,select,textarea,img,nav,header,footer,main,aside,section,article,form,table,ul,ol,li,[role],[data-testid],.btn,.card,.modal,.alert,.badge,.toast';
  const elements = [];
  const seen = new Set();

  for (const el of document.querySelectorAll(meaningful)) {
    if (elements.length >= maxElements) break;
    const rect = el.getBoundingClientRect();
    // Only visible, in-viewport elements
    if (rect.width === 0 || rect.height === 0) continue;
    if (rect.bottom < 0 || rect.top > viewport.h) continue;

    // Deduplicate by approximate position
    const key = `${Math.round(rect.x/10)},${Math.round(rect.y/10)}`;
    if (seen.has(key)) continue;
    seen.add(key);

    const props = getVisualProps(el);
    if (props) elements.push(props);
  }

  return JSON.stringify(cleanUndefined({
    mode: 'layout',
    viewport,
    elementCount: elements.length,
    elements,
  }), null, 2);
})();
