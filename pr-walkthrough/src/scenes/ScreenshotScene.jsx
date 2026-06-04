import React from 'react';
import { AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, Easing } from 'remotion';
import { COLORS } from '../theme.js';
import { Caption } from '../components/Caption.jsx';
import { Callout } from '../components/Callout.jsx';
import { Eyebrow } from '../components/Eyebrow.jsx';

// Stage geometry: a browser window the screenshot lives inside.
const STAGE = { x: 60, y: 124, w: 1800, h: 906 };
const CHROME = 46; // browser title-bar height
const VIEW = { w: STAGE.w, h: STAGE.h - CHROME }; // the actual screenshot viewport

const easeIO = Easing.bezier(0.4, 0, 0.2, 1);

// Compute the uniform scale `k` and translate (tx,ty) that map the image into the
// viewport for the chosen mode, with a gentle Ken-Burns zoom over the scene.
function projection(scene, natW, natH, t) {
  const z = interpolate(t, [0, 1], [1, 1.05], { easing: easeIO, extrapolateRight: 'clamp' });

  if (scene.pan === 'down' || scene.pan === 'up') {
    // Establishing scroll over a tall image: fit width, glide top→bottom.
    const k = VIEW.w / natW;
    const dispH = k * natH;
    const span = Math.min(0, VIEW.h - dispH); // negative when taller than viewport
    const p = interpolate(t, [0.05, 0.95], [0, 1], { easing: easeIO, extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
    const ty = scene.pan === 'down' ? span * p : span * (1 - p);
    return { k, tx: (VIEW.w - k * natW) / 2, ty };
  }

  const focus = scene.focus || { x: 0, y: 0, w: 1, h: 1 };
  let k;
  if (!scene.focus) {
    // Contain the whole image.
    k = Math.min(VIEW.w / natW, VIEW.h / natH) * z;
  } else {
    // Fit the focus band to the viewport width (keeps UI text legible).
    k = (VIEW.w / (focus.w * natW)) * z;
  }
  const cx = (focus.x + focus.w / 2) * natW;
  const cy = (focus.y + focus.h / 2) * natH;
  let tx = VIEW.w / 2 - k * cx;
  let ty = VIEW.h / 2 - k * cy;

  // A whisker of vertical drift for life (only when focused, so it reads as intentional).
  if (scene.focus) {
    ty += interpolate(t, [0, 1], [16, -16], { easing: easeIO, extrapolateRight: 'clamp' });
  }
  return { k, tx, ty };
}

function calloutEnter(frame, fps, at, until) {
  const a = at * fps;
  const u = until * fps;
  const inEnd = a + Math.round(fps * 0.55);
  const outStart = u - Math.round(fps * 0.4);
  if (frame < a || frame > u) return 0;
  const up = interpolate(frame, [a, inEnd], [0, 1], { easing: easeIO, extrapolateRight: 'clamp' });
  const down = interpolate(frame, [outStart, u], [1, 0], { easing: easeIO, extrapolateLeft: 'clamp' });
  return Math.min(up, down);
}

export function ScreenshotScene({ scene }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  // Inside a TransitionSeries.Sequence, frame is rebased to 0 but useVideoConfig's
  // durationInFrames is the whole composition — so derive scene progress from the
  // scene's own length.
  const sceneDur = Math.max(1, Math.round((scene.seconds || 6) * fps));
  const t = interpolate(frame, [0, sceneDur - 1], [0, 1], { extrapolateRight: 'clamp' });

  const natW = scene.natW || 1385;
  const natH = scene.natH || 1000;
  const { k, tx, ty } = projection(scene, natW, natH, t);

  const callouts = (scene.callouts || [])
    .map((c) => ({ c, enter: calloutEnter(frame, fps, c.at, c.until) }))
    .filter((x) => x.enter > 0.001);

  return (
    <AbsoluteFill style={{ background: `radial-gradient(120% 120% at 50% 0%, ${COLORS.paper}, ${COLORS.paperEdge})` }}>
      {scene.chapter && <Eyebrow>{scene.chapter}</Eyebrow>}

      {/* Browser window frame */}
      <div
        style={{
          position: 'absolute',
          left: STAGE.x,
          top: STAGE.y,
          width: STAGE.w,
          height: STAGE.h,
          borderRadius: 18,
          background: COLORS.white,
          boxShadow: '0 30px 80px rgba(20,40,12,0.22)',
          overflow: 'hidden',
          border: `1px solid ${COLORS.paperEdge}`,
        }}
      >
        {/* Title bar */}
        <div style={{ height: CHROME, background: '#eef2ea', display: 'flex', alignItems: 'center', paddingLeft: 18, gap: 9, borderBottom: `1px solid ${COLORS.paperEdge}` }}>
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#ff5f57' }} />
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#febc2e' }} />
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#28c840' }} />
          <div style={{ marginLeft: 18, height: 26, flex: 1, marginRight: 18, background: '#fff', borderRadius: 8, border: `1px solid ${COLORS.paperEdge}`, display: 'flex', alignItems: 'center', paddingLeft: 14, fontSize: 14, color: COLORS.inkSoft, fontFamily: 'system-ui' }}>
            {scene.url || 'www.ilovefreegle.org'}
          </div>
        </div>

        {/* Screenshot viewport (coordinate origin for the image + callouts) */}
        <div style={{ position: 'absolute', left: 0, top: CHROME, width: VIEW.w, height: VIEW.h, overflow: 'hidden', background: '#fff' }}>
          <Img
            src={staticFile(scene.src)}
            style={{ position: 'absolute', left: tx, top: ty, width: k * natW, height: k * natH }}
          />
          {callouts.map(({ c, enter }, i) => {
            const rect = {
              left: tx + k * (c.box.x * natW),
              top: ty + k * (c.box.y * natH),
              width: k * (c.box.w * natW),
              height: k * (c.box.h * natH),
            };
            return <Callout key={i} rect={rect} label={c.label} side={c.arrow || 'up'} enter={enter} viewport={VIEW} />;
          })}
        </div>
      </div>

      {scene.caption && <Caption>{scene.caption}</Caption>}
    </AbsoluteFill>
  );
}
