import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

function Rise({ delay, children, dy = 28 }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const s = spring({ frame: frame - delay, fps, config: { damping: 200 }, durationInFrames: 22 });
  return <div style={{ opacity: s, transform: `translateY(${interpolate(s, [0, 1], [dy, 0])}px)` }}>{children}</div>;
}

export function TitleScene({ scene, meta }) {
  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(150deg, ${COLORS.green} 0%, ${COLORS.greenDeep} 78%)`,
        fontFamily: SANS,
        color: '#fff',
        padding: '0 150px',
        justifyContent: 'center',
      }}
    >
      {/* faint texture */}
      <AbsoluteFill style={{ background: 'radial-gradient(60% 60% at 80% 10%, rgba(255,255,255,0.12), transparent)' }} />

      <Rise delay={0}>
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 14, background: 'rgba(255,255,255,0.16)', padding: '10px 22px', borderRadius: 999, fontSize: 26, fontWeight: 700, marginBottom: 34 }}>
          <span style={{ opacity: 0.85 }}>{meta.repo}</span>
          <span style={{ opacity: 0.5 }}>·</span>
          <span>PR #{meta.pr}</span>
        </div>
      </Rise>

      <Rise delay={8}>
        <div style={{ fontSize: 78, fontWeight: 800, lineHeight: 1.08, letterSpacing: -1, maxWidth: 1500 }}>
          {scene.title || meta.title}
        </div>
      </Rise>

      {scene.subtitle && (
        <Rise delay={18}>
          <div style={{ fontSize: 36, fontWeight: 500, opacity: 0.92, marginTop: 28, maxWidth: 1300, lineHeight: 1.35 }}>
            {scene.subtitle}
          </div>
        </Rise>
      )}

      <Rise delay={30}>
        <div style={{ display: 'flex', gap: 18, marginTop: 52, alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Diff stats are about the code, not the user-facing function — off by default. */}
          {scene.showStats && (
            <>
              <Stat value={`+${meta.additions.toLocaleString()}`} label="added" tone="#d7ffbe" />
              <Stat value={`−${meta.deletions.toLocaleString()}`} label="removed" tone="#ffd9d2" />
              <Stat value={meta.files} label="files changed" tone="#ffffff" />
            </>
          )}
          <div style={{ fontSize: 28, fontWeight: 600, opacity: 0.9 }}>by {meta.author}</div>
        </div>
      </Rise>
    </AbsoluteFill>
  );
}

function Stat({ value, label, tone }) {
  return (
    <div style={{ background: 'rgba(0,0,0,0.16)', borderRadius: 16, padding: '16px 26px', display: 'flex', flexDirection: 'column', minWidth: 150 }}>
      <div style={{ fontSize: 44, fontWeight: 800, color: tone, lineHeight: 1 }}>{value}</div>
      <div style={{ fontSize: 22, fontWeight: 600, opacity: 0.85, marginTop: 6 }}>{label}</div>
    </div>
  );
}
