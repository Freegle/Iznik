import React from 'react';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

// A small, honest brand lockup (not the official logo asset): a green leaf mark plus
// the wordmark, with a subtitle. Sits persistently in the top-left of every frame.
export function Brand({ name = 'freegle', subtitle = 'PR walkthrough' }) {
  return (
    <div
      style={{
        position: 'absolute',
        top: 40,
        left: 56,
        display: 'flex',
        alignItems: 'center',
        gap: 16,
        fontFamily: SANS,
      }}
    >
      <div
        style={{
          width: 48,
          height: 48,
          borderRadius: 14,
          background: `linear-gradient(135deg, ${COLORS.green}, ${COLORS.greenDeep})`,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          boxShadow: '0 6px 18px rgba(60,111,31,0.35)',
        }}
      >
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path
            d="M12 21c5-2 8-6 8-12V5l-7-2-7 2v4c0 6 3 10 6 12z"
            fill="rgba(255,255,255,0.16)"
          />
          <path
            d="M7 12c2.5.2 5 1.8 5 6 .3-5 2.6-7.5 5.5-8.2C14 10 12.5 8 12 4.5 11.4 8 9.5 10.5 7 12z"
            fill="#ffffff"
          />
        </svg>
      </div>
      <div style={{ lineHeight: 1.05 }}>
        <div style={{ fontSize: 30, fontWeight: 800, color: COLORS.greenDeep, letterSpacing: -0.5 }}>
          {name}
        </div>
        <div style={{ fontSize: 16, fontWeight: 600, color: COLORS.inkSoft, letterSpacing: 0.5 }}>
          {subtitle}
        </div>
      </div>
    </div>
  );
}
