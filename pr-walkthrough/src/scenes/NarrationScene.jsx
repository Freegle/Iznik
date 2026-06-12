import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';
import { Eyebrow } from '../components/Eyebrow.jsx';
import { Caption } from '../components/Caption.jsx';

// A text scene for "why" / "approach" / per-layer summaries: a heading and a set of
// bullets that reveal one at a time, with an optional lower-third caption.
export function NarrationScene({ scene }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const bullets = scene.bullets || [];

  const headSpring = spring({ frame, fps, config: { damping: 200 }, durationInFrames: 20 });

  return (
    <AbsoluteFill style={{ background: `radial-gradient(120% 120% at 50% 0%, ${COLORS.paper}, ${COLORS.paperEdge})`, fontFamily: SANS, color: COLORS.ink }}>
      {scene.chapter && <Eyebrow>{scene.chapter}</Eyebrow>}

      <div style={{ position: 'absolute', top: 200, left: 150, right: 150 }}>
        {scene.heading && (
          <div style={{ fontSize: 64, fontWeight: 800, letterSpacing: -1, lineHeight: 1.1, opacity: headSpring, transform: `translateY(${interpolate(headSpring, [0, 1], [24, 0])}px)`, maxWidth: 1500 }}>
            {scene.heading}
          </div>
        )}

        <div style={{ marginTop: 56, display: 'flex', flexDirection: 'column', gap: 30 }}>
          {bullets.map((b, i) => {
            const delay = 16 + i * 14;
            const s = spring({ frame: frame - delay, fps, config: { damping: 200 }, durationInFrames: 20 });
            return (
              <div key={i} style={{ display: 'flex', gap: 24, alignItems: 'flex-start', opacity: s, transform: `translateX(${interpolate(s, [0, 1], [-26, 0])}px)` }}>
                <div style={{ marginTop: 14, flex: '0 0 auto', width: 18, height: 18, borderRadius: 6, background: COLORS.green, boxShadow: `0 0 0 6px ${COLORS.highlightSoft}` }} />
                <div style={{ fontSize: 38, fontWeight: 500, lineHeight: 1.35, color: COLORS.ink, maxWidth: 1400 }}>{b}</div>
              </div>
            );
          })}
        </div>
      </div>

      {scene.caption && <Caption>{scene.caption}</Caption>}
    </AbsoluteFill>
  );
}
