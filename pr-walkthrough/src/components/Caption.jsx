import React from 'react';
import { useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

// Subtitle-style lower-third. The narration of the walkthrough lives here: it eases up
// into place at the start of the scene and dwells, so it is comfortably readable.
export function Caption({ children, maxWidth = 1280 }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const enter = spring({ frame, fps, config: { damping: 200 }, durationInFrames: 18 });
  const y = interpolate(enter, [0, 1], [40, 0]);

  return (
    <div
      style={{
        position: 'absolute',
        bottom: 96,
        left: 0,
        width: '100%',
        display: 'flex',
        justifyContent: 'center',
        opacity: enter,
        transform: `translateY(${y}px)`,
      }}
    >
      <div
        style={{
          maxWidth,
          margin: '0 80px',
          background: COLORS.captionBg,
          color: COLORS.captionInk,
          fontFamily: SANS,
          fontSize: 34,
          fontWeight: 600,
          lineHeight: 1.32,
          padding: '20px 34px',
          borderRadius: 18,
          backdropFilter: 'blur(2px)',
          boxShadow: '0 12px 40px rgba(0,0,0,0.28)',
          textAlign: 'center',
        }}
      >
        {children}
      </div>
    </div>
  );
}
