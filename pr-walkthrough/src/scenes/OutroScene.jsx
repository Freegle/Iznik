import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { COLORS } from '../theme.js';
import { SANS, MONO } from '../fonts.js';

function Rise({ delay, children }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const s = spring({ frame: frame - delay, fps, config: { damping: 200 }, durationInFrames: 20 });
  return <div style={{ opacity: s, transform: `translateY(${interpolate(s, [0, 1], [22, 0])}px)` }}>{children}</div>;
}

export function OutroScene({ scene, meta }) {
  return (
    <AbsoluteFill style={{ background: `linear-gradient(150deg, ${COLORS.green} 0%, ${COLORS.greenDeep} 80%)`, fontFamily: SANS, color: '#fff', padding: '0 150px', justifyContent: 'center' }}>
      <AbsoluteFill style={{ background: 'radial-gradient(55% 55% at 15% 90%, rgba(255,255,255,0.12), transparent)' }} />

      <Rise delay={0}>
        <div style={{ fontSize: 64, fontWeight: 800, letterSpacing: -1, marginBottom: 40 }}>{scene.title || 'What shipped'}</div>
      </Rise>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
        {(scene.bullets || []).map((b, i) => (
          <Rise key={i} delay={10 + i * 10}>
            <div style={{ display: 'flex', gap: 20, alignItems: 'center', fontSize: 36, fontWeight: 500 }}>
              <span style={{ width: 38, height: 38, borderRadius: 99, background: 'rgba(255,255,255,0.18)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flex: '0 0 auto' }}>
                <svg width="22" height="22" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" stroke="#fff" strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>
              </span>
              <span>{b}</span>
            </div>
          </Rise>
        ))}
      </div>

      <Rise delay={12 + (scene.bullets || []).length * 10}>
        <div style={{ marginTop: 56, display: 'inline-flex', alignItems: 'center', gap: 16, background: 'rgba(0,0,0,0.18)', padding: '16px 26px', borderRadius: 14, fontFamily: MONO, fontSize: 28, fontWeight: 600 }}>
          {scene.url || meta.url}
        </div>
      </Rise>
    </AbsoluteFill>
  );
}
